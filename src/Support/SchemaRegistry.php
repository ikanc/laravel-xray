<?php

namespace Ikabalzam\LaravelXray\Support;

use Illuminate\Support\Str;
use PhpParser\Node;

/**
 * Read-only container and query layer for database schema metadata.
 *
 * SchemaRegistry holds the complete picture of:
 * - Database schema (table => column listings)
 * - Model map (fully-qualified class name => table name)
 * - Relationship map (model class => [method => related model class])
 * - Relationship type map (model class => [method => relationship type])
 * - Discovered model namespaces
 *
 * It is populated once by SchemaLoader at the start of an audit run, then passed
 * to all resolvers as an immutable data source. The resolvers never modify it —
 * they only query it to figure out which table a column reference belongs to.
 *
 * WHY THIS EXISTS:
 * The original AuditDatabaseColumns command stored all this data as instance
 * properties and mixed loading logic with resolution logic. Extracting the
 * registry gives us a clear separation: SchemaLoader writes, resolvers read.
 */
class SchemaRegistry
{
    /**
     * Database schema: table name => list of column names.
     *
     * Example: ['users' => ['id', 'email', 'name', ...], 'jobs' => [...]]
     *
     * @var array<string, array<string>>
     */
    private array $schema = [];

    /**
     * Model class => database table name.
     *
     * Example: ['App\Models\User' => 'users', 'App\Models\Job' => 'jobs']
     *
     * @var array<string, string>
     */
    private array $modelMap = [];

    /**
     * Model class => [relationship method name => related model's fully-qualified class].
     *
     * Example: ['App\Models\User' => ['posts' => 'App\Models\Post', 'company' => 'App\Models\Company']]
     *
     * @var array<string, array<string, string>>
     */
    private array $relationshipMap = [];

    /**
     * Model class => [relationship method name => relationship type string].
     *
     * Example: ['App\Models\User' => ['posts' => 'hasMany', 'company' => 'belongsTo']]
     *
     * @var array<string, string>
     */
    private array $relationshipTypeMap = [];

    /**
     * List of discovered PHP namespaces that contain Eloquent models.
     *
     * Used by resolveModelClass() to expand short class names (e.g., 'User')
     * into fully-qualified names (e.g., 'App\Models\User').
     *
     * @var array<string>
     */
    private array $modelNamespaces = [];

    // =========================================================================
    // SETTERS (used only by SchemaLoader during initialization)
    // =========================================================================

    /**
     * Replace the entire schema map.
     *
     * @param array<string, array<string>> $schema table => columns
     */
    public function setSchema(array $schema): void
    {
        $this->schema = $schema;
    }

    /**
     * Set the column listing for a single table.
     *
     * @param string        $table   Table name
     * @param array<string> $columns Column names
     */
    public function setTableColumns(string $table, array $columns): void
    {
        $this->schema[$table] = $columns;
    }

    /**
     * Replace the entire model map.
     *
     * @param array<string, string> $modelMap class => table
     */
    public function setModelMap(array $modelMap): void
    {
        $this->modelMap = $modelMap;
    }

    /**
     * Register a single model class => table mapping.
     *
     * @param string $class Fully-qualified model class name
     * @param string $table Database table name
     */
    public function setModelTable(string $class, string $table): void
    {
        $this->modelMap[$class] = $table;
    }

    /**
     * Replace the entire relationship map.
     *
     * @param array<string, array<string, string>> $relationshipMap
     */
    public function setRelationshipMap(array $relationshipMap): void
    {
        $this->relationshipMap = $relationshipMap;
    }

    /**
     * Register a single relationship: model class + method => related class.
     *
     * @param string $modelClass   The model that defines the relationship
     * @param string $methodName   The relationship method name (e.g., 'posts')
     * @param string $relatedClass The fully-qualified class of the related model
     */
    public function setRelationship(string $modelClass, string $methodName, string $relatedClass): void
    {
        $this->relationshipMap[$modelClass][$methodName] = $relatedClass;
    }

    /**
     * Replace the entire relationship type map.
     *
     * @param array<string, string> $relationshipTypeMap
     */
    public function setRelationshipTypeMap(array $relationshipTypeMap): void
    {
        $this->relationshipTypeMap = $relationshipTypeMap;
    }

    /**
     * Register the type of a single relationship (e.g., 'hasMany', 'belongsTo').
     *
     * @param string $modelClass The model that defines the relationship
     * @param string $methodName The relationship method name
     * @param string $type       The Eloquent relationship type
     */
    public function setRelationshipType(string $modelClass, string $methodName, string $type): void
    {
        $this->relationshipTypeMap[$modelClass][$methodName] = $type;
    }

    /**
     * Replace the list of discovered model namespaces.
     *
     * @param array<string> $namespaces
     */
    public function setModelNamespaces(array $namespaces): void
    {
        $this->modelNamespaces = $namespaces;
    }

    /**
     * Add a namespace to the list if not already present.
     *
     * @param string $namespace e.g., 'App\Models'
     */
    public function addModelNamespace(string $namespace): void
    {
        if (! in_array($namespace, $this->modelNamespaces)) {
            $this->modelNamespaces[] = $namespace;
        }
    }

    // =========================================================================
    // BASIC ACCESSORS
    // =========================================================================

    /**
     * Get the column listing for a specific table.
     *
     * Returns an empty array if the table is not in the schema, which callers
     * should interpret as "table not found" (not "table has no columns").
     *
     * @param string $table Table name
     * @return array<string> Column names
     */
    public function getTableColumns(string $table): array
    {
        return $this->schema[$table] ?? [];
    }

    /**
     * Check whether a table exists in the loaded schema.
     *
     * @param string $table Table name
     */
    public function hasTable(string $table): bool
    {
        return isset($this->schema[$table]);
    }

    /**
     * Total number of tables in the schema.
     */
    public function tableCount(): int
    {
        return count($this->schema);
    }

    /**
     * Total number of columns across all tables.
     *
     * Useful for summary output ("Loaded X tables with Y columns").
     */
    public function columnCount(): int
    {
        $count = 0;
        foreach ($this->schema as $columns) {
            $count += count($columns);
        }

        return $count;
    }

    /**
     * Get the full model map (class => table).
     *
     * @return array<string, string>
     */
    public function getModelMap(): array
    {
        return $this->modelMap;
    }

    /**
     * Get the full relationship map.
     *
     * @return array<string, array<string, string>>
     */
    public function getRelationshipMap(): array
    {
        return $this->relationshipMap;
    }

    /**
     * Get the full relationship type map.
     *
     * @return array<string, string>
     */
    public function getRelationshipTypeMap(): array
    {
        return $this->relationshipTypeMap;
    }

    /**
     * Get discovered model namespaces.
     *
     * @return array<string>
     */
    public function getModelNamespaces(): array
    {
        return $this->modelNamespaces;
    }

    // =========================================================================
    // RESOLUTION METHODS
    // =========================================================================

    /**
     * Resolve a short model class name to its fully-qualified equivalent.
     *
     * Given a short name like 'User', this searches all discovered model namespaces
     * (e.g., 'App\Models') to find a matching entry in the model map.
     *
     * This is necessary because PHP files use short class names (via `use` imports
     * or same-namespace references), but the model map is keyed by fully-qualified
     * names. Without this resolution step, we couldn't look up 'User' → 'users'.
     *
     * @param string $shortName The unqualified class name (e.g., 'User', 'Invoice')
     * @return string|null The fully-qualified class name, or null if not found
     */
    public function resolveModelClass(string $shortName): ?string
    {
        foreach ($this->modelNamespaces as $namespace) {
            $full = $namespace . '\\' . $shortName;
            if (isset($this->modelMap[$full])) {
                return $full;
            }
        }

        return null;
    }

    /**
     * Resolve a static method call to the database table it operates on.
     *
     * Handles several patterns:
     * 1. DB::table('users')     → 'users' (direct table reference)
     * 2. self::where(...)       → current model's table via fileContext['__self__']
     * 3. static::where(...)     → same as self::
     * 4. User::where(...)       → resolved via fileContext or model map
     * 5. SomeClass::where(...)  → fallback: guess table from class name pluralization
     *
     * The fileContext array maps short class names to table names for the current
     * file being analyzed (populated by use-statement parsing and model detection).
     * The special key '__self__' holds the table for the class defined in the file.
     *
     * CAVEAT: The DB::table() branch calls extractFirstStringArg() to get the table
     * name from the AST node. This is passed in as a separate extraction step because
     * the registry itself shouldn't depend on AST traversal helpers. Instead, we
     * accept the StaticCall node and do minimal AST inspection inline.
     *
     * @param Node\Expr\StaticCall $call        The static call AST node
     * @param array<string, string> $fileContext Map of short class name => table name for the current file
     * @return string|null The resolved table name, or null if unresolvable
     */
    public function resolveStaticCallTable(Node\Expr\StaticCall $call, array $fileContext): ?string
    {
        $className = null;
        if ($call->class instanceof Node\Name) {
            $className = class_basename($call->class->toString());
        }

        if (! $className) {
            return null;
        }

        // DB::table('tablename') — extract the table name from the first string argument
        if ($className === 'DB') {
            $methodName = $call->name instanceof Node\Identifier ? $call->name->toString() : null;
            if ($methodName === 'table') {
                $tableName = $this->extractFirstStringArg($call);
                if ($tableName && isset($this->schema[$tableName])) {
                    return $tableName;
                }
            }

            return null;
        }

        // self::where() and static::where() resolve to the current model's table
        if ($className === 'self' || $className === 'static') {
            return $fileContext['__self__'] ?? null;
        }

        // Model::where() — check if the class is in the current file's context
        if (isset($fileContext[$className])) {
            return $fileContext[$className];
        }

        // Try resolving via the model map using discovered namespaces
        $fullClass = $this->resolveModelClass($className);
        if ($fullClass && isset($this->modelMap[$fullClass])) {
            return $this->modelMap[$fullClass];
        }

        // Fallback for third-party models (e.g., Spatie\Permission\Models\Role):
        // guess the table name by pluralizing and snake_casing the class name
        $guessedTable = Str::snake(Str::pluralStudly($className));
        if (isset($this->schema[$guessedTable])) {
            return $guessedTable;
        }

        return null;
    }

    /**
     * Resolve a relationship method name to the database table it targets.
     *
     * When we encounter `$this->posts()->where('title', ...)`, we need to know
     * that `posts()` returns a relationship to the 'posts' table so we can
     * validate 'title' against that table's columns.
     *
     * Resolution strategy (in priority order):
     * 1. Check models in the current file context — if the file defines a model
     *    (via __self__), look up that model's relationships first
     * 2. Check all other models in the file context (e.g., via use statements)
     * 3. Fall back to checking ALL models' relationships (handles cases where
     *    the relationship is called on an unresolved model)
     * 4. Last resort: guess the table from the method name itself
     *    (e.g., 'payments' → 'payments' table, 'lineItems' → 'line_items' table)
     *
     * CAVEAT: The global fallback (step 3) can produce false matches if two models
     * have relationship methods with the same name pointing to different tables.
     * In practice this is rare and the tradeoff of catching more references is worth it.
     *
     * @param string               $methodName  The relationship method name (e.g., 'posts')
     * @param array<string, string> $fileContext Map of short class name => table name for the current file
     * @return string|null The resolved table name, or null if unresolvable
     */
    public function resolveRelationMethod(string $methodName, array $fileContext): ?string
    {
        // Check models in file context first (most specific)
        foreach ($fileContext as $shortName => $table) {
            if ($shortName === '__self__') {
                // For $this->relation(), look up self's relationships
                $selfClass = array_search($table, $this->modelMap);
                if ($selfClass && isset($this->relationshipMap[$selfClass][$methodName])) {
                    $relatedClass = $this->relationshipMap[$selfClass][$methodName];

                    return $this->modelMap[$relatedClass] ?? null;
                }

                continue;
            }

            $fullClass = $this->resolveModelClass($shortName);
            if ($fullClass && isset($this->relationshipMap[$fullClass][$methodName])) {
                $relatedClass = $this->relationshipMap[$fullClass][$methodName];

                return $this->modelMap[$relatedClass] ?? null;
            }
        }

        // Check ALL models as fallback (less specific but catches more cases)
        foreach ($this->relationshipMap as $modelClass => $relationships) {
            if (isset($relationships[$methodName])) {
                $relatedClass = $relationships[$methodName];

                return $this->modelMap[$relatedClass] ?? null;
            }
        }

        // Last resort: guess from method name (payments() → 'payments' table)
        $guessedTable = Str::snake($methodName);
        if (isset($this->schema[$guessedTable])) {
            return $guessedTable;
        }
        $guessedPlural = Str::plural($guessedTable);
        if (isset($this->schema[$guessedPlural])) {
            return $guessedPlural;
        }

        return null;
    }

    /**
     * Resolve the target table from a relationship definition's first argument.
     *
     * When we encounter `$this->hasMany(Visit::class)`, this extracts 'Visit'
     * from the ClassConstFetch node, resolves it to 'App\Models\Visit', and
     * returns the corresponding table name ('visits').
     *
     * This is used during chain analysis when we detect a relationship definition
     * call and need to know which table the resulting query will operate on.
     *
     * @param Node\Expr\MethodCall $call The method call AST node (e.g., $this->hasMany(...))
     * @return string|null The resolved table name, or null if the argument isn't a class constant
     */
    public function resolveModelFromClassArg(Node\Expr\MethodCall $call): ?string
    {
        if (empty($call->args) || ! ($call->args[0]->value instanceof Node\Expr\ClassConstFetch)) {
            return null;
        }

        $classRef = $call->args[0]->value->class;
        if ($classRef instanceof Node\Name) {
            $className = class_basename($classRef->toString());
            $fullClass = $this->resolveModelClass($className);

            return $fullClass ? ($this->modelMap[$fullClass] ?? null) : null;
        }

        return null;
    }

    /**
     * Check whether a column name exists in ANY table in the schema.
     *
     * Used as a lenient validation for ambiguous cases, such as pivot table
     * columns in belongsToMany relationships. If a column like 'user_id'
     * doesn't exist on the resolved table but does exist somewhere in the
     * database, it's likely a valid reference to a pivot or related table.
     *
     * @param string $column The column name to search for
     * @return bool True if the column exists in at least one table
     */
    public function columnExistsAnywhere(string $column): bool
    {
        foreach ($this->schema as $columns) {
            if (in_array($column, $columns)) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Extract the first string literal argument from a method/static call AST node.
     *
     * Used internally by resolveStaticCallTable() to get the table name from
     * DB::table('users'). Returns null if the first argument isn't a simple string.
     *
     * @param Node\Expr\MethodCall|Node\Expr\StaticCall $call The call node
     * @return string|null The string value, or null
     */
    private function extractFirstStringArg(Node\Expr\MethodCall|Node\Expr\StaticCall $call): ?string
    {
        if (empty($call->args) || ! ($call->args[0] instanceof Node\Arg)) {
            return null;
        }

        $firstArg = $call->args[0]->value;

        if ($firstArg instanceof Node\Scalar\String_) {
            return $firstArg->value;
        }

        return null;
    }
}
