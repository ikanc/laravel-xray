<?php

namespace Ikabalzam\LaravelVision;

use Ikabalzam\LaravelVision\Support\SchemaRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Loads database schema and Eloquent model metadata into a SchemaRegistry.
 *
 * This is the "write" side of the schema data flow. It runs once at the start
 * of an audit, populates a SchemaRegistry with everything the resolvers need,
 * and then hands it off. After load() returns, the registry is treated as
 * read-only by all consumers.
 *
 * Loading happens in two phases:
 * 1. loadSchema()   — Reads the live database via Schema facade to build the
 *                      table => columns map.
 * 2. loadModelMap() — Scans app/Models/*.php files to discover:
 *                      - Which class maps to which table (via $table property
 *                        or Laravel's naming convention)
 *                      - Which namespaces contain models
 *                      - Which relationship methods each model defines, and
 *                        what model class they point to (via AST parsing)
 *
 * WHY AST PARSING:
 * We can't just instantiate models to read their table names and relationships
 * because:
 * - Some models have constructor dependencies or boot logic with side effects
 * - We want to work statically without hitting the database for each model
 * - Reflection alone can't extract relationship target classes without invoking
 *   the relationship methods
 *
 * Instead we use PhpParser to inspect the source code directly. This is more
 * robust and doesn't require the models to be bootable.
 */
class SchemaLoader
{
    /**
     * Orchestrate the full loading process and return a populated registry.
     *
     * Call this once at the start of an audit run. The returned SchemaRegistry
     * contains all schema and model metadata needed by the resolvers.
     *
     * @return SchemaRegistry A fully populated, ready-to-query registry
     */
    public function load(): SchemaRegistry
    {
        $registry = new SchemaRegistry;

        $this->loadSchema($registry);
        $this->loadModelMap($registry);

        return $registry;
    }

    /**
     * Read the live database schema and populate the registry's table => columns map.
     *
     * Uses Laravel's Schema facade to enumerate all tables in the current database,
     * then fetches column listings for each. Cross-database tables (which can appear
     * in some MySQL configurations) are filtered out by comparing against the current
     * database name.
     *
     * @param SchemaRegistry $registry The registry to populate
     */
    public function loadSchema(SchemaRegistry $registry): void
    {
        $dbName = DB::getDatabaseName();

        foreach (Schema::getTables() as $table) {
            // Only include tables from the current database (skip cross-database results)
            if (isset($table['schema']) && $table['schema'] !== $dbName) {
                continue;
            }

            $tableName = $table['name'];
            $registry->setTableColumns($tableName, Schema::getColumnListing($tableName));
        }
    }

    /**
     * Scan models directory to build the model map, namespaces, and relationships.
     *
     * For each PHP file in the models directory:
     * 1. Extract the class name and namespace via regex
     * 2. Determine the table name:
     *    - If the model has an explicit `protected $table = 'xxx'` property, use that
     *    - Otherwise, use Laravel's convention: snake_case + pluralize the class name
     * 3. Parse the file's AST to extract relationship method definitions
     *
     * The namespace is recorded so that resolveModelClass() can later expand short
     * names like 'User' to 'App\Models\User'.
     *
     * @param SchemaRegistry $registry The registry to populate
     */
    public function loadModelMap(SchemaRegistry $registry): void
    {
        $modelPath = config('vision.models_path', app_path('Models'));
        if (! is_dir($modelPath)) {
            return;
        }

        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        foreach (File::allFiles($modelPath) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            // Extract class name — we need a class that extends something (i.e., a model)
            if (! preg_match('/class\s+(\w+)\s+extends\s+/', $content, $classMatch)) {
                continue;
            }

            // Extract namespace, defaulting to App\Models
            $namespace = 'App\\Models';
            if (preg_match('/namespace\s+([\w\\\\]+)/', $content, $nsMatch)) {
                $namespace = $nsMatch[1];
            }

            $registry->addModelNamespace($namespace);

            $fullClass = $namespace . '\\' . $classMatch[1];

            // Determine table name: explicit property or method override takes precedence over convention.
            // Check three sources in order:
            // 1. `protected $table = 'xxx'` — most common explicit table declaration
            // 2. `function getTable() { return 'xxx'; }` — method override (used by e.g. CashierSubscription)
            // 3. Laravel convention: snake_case + pluralize the class name
            if (preg_match('/protected\s+\$table\s*=\s*[\'"](\w+)[\'"]/', $content, $tableMatch)) {
                $registry->setModelTable($fullClass, $tableMatch[1]);
            } elseif (preg_match('/function\s+getTable\s*\(\s*\).*?return\s+[\'"](\w+)[\'"]/s', $content, $getTableMatch)) {
                $registry->setModelTable($fullClass, $getTableMatch[1]);
            } else {
                $registry->setModelTable($fullClass, Str::snake(Str::pluralStudly($classMatch[1])));
            }

            // Use AST to extract relationships from the model's methods
            try {
                $ast = $parser->parse($content);
                if ($ast) {
                    $this->extractRelationships($ast, $fullClass, $registry);
                }
            } catch (\Throwable) {
                // Parse error — skip this model's relationships silently.
                // The model map entry is still valid; we just won't know its relationships.
            }
        }
    }

    /**
     * Extract Eloquent relationship definitions from a model's AST.
     *
     * Walks all class methods looking for calls like `$this->hasMany(Post::class)`.
     * For each match, records:
     * - The method name as the relationship accessor (e.g., 'posts')
     * - The related model class (resolved to fully-qualified via the registry)
     * - The relationship type (e.g., 'hasMany', 'belongsTo')
     *
     * Only the first relationship call per method is recorded (a method should
     * only define one relationship). If the first argument isn't a class constant
     * fetch (e.g., it's a variable or string), the relationship is skipped.
     *
     * Uses Constants::RELATION_TYPES to identify which method calls are relationship
     * definitions vs. regular method calls.
     *
     * @param array          $ast        The parsed AST for the model file
     * @param string         $modelClass The fully-qualified class name of the model
     * @param SchemaRegistry $registry   The registry to populate with relationships
     */
    public function extractRelationships(array $ast, string $modelClass, SchemaRegistry $registry): void
    {
        $finder = new NodeFinder;
        $relationTypes = Constants::RELATION_TYPES;

        // Find all class methods in the AST
        $methods = $finder->findInstanceOf($ast, Node\Stmt\ClassMethod::class);

        foreach ($methods as $method) {
            $methodName = $method->name->toString();

            // Find relationship calls within this method body
            $calls = $finder->findInstanceOf([$method], Node\Expr\MethodCall::class);

            foreach ($calls as $call) {
                // Only consider $this->relationshipType(...) calls
                if (! ($call->var instanceof Node\Expr\Variable) || $call->var->name !== 'this') {
                    continue;
                }

                $callMethodName = $call->name instanceof Node\Identifier ? $call->name->toString() : null;
                if (! $callMethodName || ! in_array($callMethodName, $relationTypes)) {
                    continue;
                }

                // Extract the related model class from the first argument (e.g., Post::class)
                if (empty($call->args) || ! ($call->args[0]->value instanceof Node\Expr\ClassConstFetch)) {
                    continue;
                }

                $classRef = $call->args[0]->value->class;
                if ($classRef instanceof Node\Name) {
                    $relatedClass = $classRef->toString();
                    // Normalize: strip double backslashes and get base name
                    $relatedClass = class_basename(str_replace('\\\\', '\\', $relatedClass));
                    $defaultNs = $registry->getModelNamespaces()[0] ?? 'App\\Models';
                    $relatedFull = $registry->resolveModelClass($relatedClass) ?? "{$defaultNs}\\{$relatedClass}";

                    $registry->setRelationship($modelClass, $methodName, $relatedFull);
                    $registry->setRelationshipType($modelClass, $methodName, $callMethodName);
                }

                // Found the relationship call for this method — stop searching
                break;
            }
        }
    }
}
