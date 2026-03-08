<?php

namespace Ikabalzam\LaravelVision\Analyzers;

use Ikabalzam\LaravelVision\Support\AstHelpers;
use Ikabalzam\LaravelVision\Support\AuditResult;
use Ikabalzam\LaravelVision\Support\SchemaRegistry;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Orchestrates the per-file analysis pipeline: reads a PHP file, detects its context,
 * parses it into an AST, and delegates column reference analysis to the specialized
 * call analyzers.
 *
 * FILE ANALYSIS PIPELINE:
 *
 *   1. **Read & parse** — Read the file contents and parse into a PhpParser AST.
 *      Files that fail to parse (syntax errors, non-PHP content) are silently skipped.
 *
 *   2. **Context detection** — Scan the file for model imports (`use App\Models\User`),
 *      model class declarations (`class User extends Model`), resource proxying
 *      (`class UserResource extends BaseResource`), and `@mixin` annotations. This
 *      builds a mapping of short class names to database table names, enabling the
 *      call analyzers to resolve `User::where(...)` without fully-qualified names.
 *
 *   3. **Alias extraction** — Scan for SQL `AS` aliases (`AS total`, `AS count`, etc.)
 *      using regex on the raw file content. These aliases appear as column names in
 *      subsequent queries and should not be flagged as invalid. This uses regex rather
 *      than AST analysis because aliases are embedded in SQL strings, not PHP syntax.
 *
 *   4. **AST preparation** — Set parent references on all AST nodes (required for
 *      upward tree walks, e.g., finding enclosing ClassMethod for @audit-skip checks).
 *
 *   5. **Node discovery** — Find all MethodCall and StaticCall nodes in the AST.
 *      Dynamic method calls (variable method names like `$model->$method(...)`) are
 *      counted but not analyzed — they can't be statically resolved.
 *
 *   6. **Delegation** — Each discovered call is handed to the appropriate analyzer:
 *      - MethodCall nodes → MethodCallAnalyzer
 *      - StaticCall nodes → StaticCallAnalyzer
 *
 * CONTEXT DETECTION DETAILS:
 *
 *   The context array maps short class names to table names:
 *     - `'User' => 'users'` — from `use App\Models\User` import
 *     - `'__self__' => 'users'` — this file defines the User model
 *     - `'Invoice' => 'invoices'` — from `@mixin Invoice` annotation
 *
 *   The `__self__` key is special — it represents "the model defined in this file"
 *   and is used to resolve `$this->...`, `self::`, and `static::` references.
 *   It is set for:
 *     - Model files: `class User extends Model` → __self__ = 'users'
 *     - Resource files: `class UserResource extends BaseResource` → __self__ = 'users'
 *       (because `$this` in a Resource proxies to the underlying model)
 *     - @mixin annotated files: `@mixin User` → __self__ = 'users'
 */
class FileAnalyzer
{
    public function __construct(
        protected readonly SchemaRegistry $schemaRegistry,
        protected readonly MethodCallAnalyzer $methodCallAnalyzer,
        protected readonly StaticCallAnalyzer $staticCallAnalyzer,
    ) {}

    /**
     * Analyze a single PHP file for invalid column references.
     *
     * Reads the file, detects context, parses the AST, and delegates to
     * MethodCallAnalyzer and StaticCallAnalyzer for each discovered call node.
     *
     * @param string      $filePath     Absolute path to the PHP file.
     * @param string      $relativePath Relative path from base_path() (used in error reporting).
     * @param string|null $tableFilter  If set, only report issues for this specific table.
     * @param string|null $modelFilter  If set, only audit files related to this model.
     *                                  (Currently unused at this level — filtering happens
     *                                  at the command level by converting to a tableFilter.)
     * @param AuditResult $result       Accumulator for issues, unresolved refs, and stats.
     */
    public function analyze(
        string $filePath,
        string $relativePath,
        ?string $tableFilter,
        ?string $modelFilter,
        AuditResult $result,
    ): void {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return;
        }

        $result->filesScanned++;

        // Step 2: Detect file context — which models are imported, which model
        // this file defines (if any), and what $this proxies to.
        $fileContext = $this->detectFileContext($content);

        // Step 3: Extract SQL aliases from the raw file content.
        // Regex-based because aliases are inside SQL strings, not PHP AST nodes.
        // Matches patterns like `AS total`, `as count`, `AS `backtick_alias``
        preg_match_all('/\bas\s+[`]?(\w+)[`]?/i', $content, $aliasMatches);
        $fileAliases = array_unique($aliasMatches[1] ?? []);

        // Step 1: Parse the file into an AST.
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($content);
            if (! $ast) {
                return;
            }
        } catch (\Throwable) {
            return; // Parse error — skip file silently
        }

        $lines = explode("\n", $content);

        // Step 4: Set parent references on all nodes — required for upward
        // tree walks (e.g., finding enclosing ClassMethod for @audit-skip).
        AstHelpers::setupParentReferences($ast);

        // Step 5 & 6: Find all method calls and static calls, then delegate.
        $finder = new NodeFinder;

        $methodCalls = $finder->findInstanceOf($ast, Node\Expr\MethodCall::class);
        $staticCalls = $finder->findInstanceOf($ast, Node\Expr\StaticCall::class);

        foreach ($methodCalls as $call) {
            // Track dynamic method calls (variable method names like `$model->$method(...)`).
            // These can't be statically analyzed — the method name is determined at runtime.
            if (! ($call->name instanceof Node\Identifier)) {
                $result->dynamicMethodCalls++;

                continue;
            }

            $this->methodCallAnalyzer->analyze(
                $call, $relativePath, $fileContext, $tableFilter, $fileAliases, $lines, $result
            );
        }

        foreach ($staticCalls as $call) {
            $this->staticCallAnalyzer->analyze(
                $call, $relativePath, $fileContext, $tableFilter, $fileAliases, $lines, $result
            );
        }
    }

    /**
     * Detect the file's context: which models are available and what `$this` resolves to.
     *
     * Scans the file content (raw string, not AST) for several patterns:
     *
     *   1. **use-statement imports** — `use App\Models\User` makes `User` resolvable
     *      to the `users` table. Only imports matching known models (from the model map)
     *      are recorded — non-model imports are ignored.
     *
     *   2. **Model class declaration** — `class User extends Model` marks this file as
     *      a model file. The `__self__` context key is set to the model's table, enabling
     *      `$this->`, `self::`, and `static::` resolution within the model.
     *
     *   3. **Resource class declaration** — `class UserResource extends BaseResource`
     *      indicates that `$this` proxies to the `User` model (Eloquent Resources delegate
     *      property access to the underlying model via `__get()`). The `__self__` key is
     *      set to the model's table so that `$this->status` references are resolvable.
     *
     *   4. **@mixin annotation** — `@mixin User` or `@mixin \App\Models\User` explicitly
     *      declares that `$this` behaves as the annotated model. Common in Resources and
     *      custom query builders. Takes precedence over heuristic detection.
     *
     * @param string $content The raw PHP file content.
     * @return array<string, string> Map of short class name (or '__self__') => table name.
     */
    public function detectFileContext(string $content): array
    {
        $context = [];
        $modelMap = $this->schemaRegistry->getModelMap();

        // Pattern 1: Find `use Namespace\Model` imports that match known models.
        // This allows the analyzers to resolve short class names (e.g., `User`)
        // to tables without needing the fully-qualified class name in every call.
        preg_match_all('/use\s+([\w\\\\]+\\\\(\w+))/', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $fullClass = str_replace('\\\\', '\\', $match[1]);
            if (isset($modelMap[$fullClass])) {
                $context[$match[2]] = $modelMap[$fullClass];
            }
        }

        // Pattern 2: Detect if this IS a model file (`class Xxx extends Model`).
        // The model's own class resolves to __self__, enabling $this resolution.
        if (preg_match('/class\s+(\w+)\s+extends\s+Model/', $content, $classMatch)) {
            $fullClass = $this->schemaRegistry->resolveModelClass($classMatch[1]);
            if ($fullClass && isset($modelMap[$fullClass])) {
                $context['__self__'] = $modelMap[$fullClass];
                $context[$classMatch[1]] = $modelMap[$fullClass];
            }
        }

        // Pattern 3: Detect Resource files (`class XxxResource extends ...`).
        // In Eloquent Resources, $this proxies to the underlying model via __get().
        // So `$this->status` in UserResource actually accesses User->status.
        if (preg_match('/class\s+(\w+)Resource\s+extends\s+/', $content, $resMatch)) {
            $modelName = $resMatch[1];
            $fullClass = $this->schemaRegistry->resolveModelClass($modelName);
            if ($fullClass && isset($modelMap[$fullClass])) {
                $context['__self__'] = $modelMap[$fullClass];
                $context[$modelName] = $modelMap[$fullClass];
            }
        }

        // Pattern 4: Check @mixin annotations — explicit proxy declarations.
        // `@mixin User` or `@mixin \App\Models\User` tells us $this behaves as that model.
        // Common in Resource files and custom query builder classes.
        if (preg_match('/@mixin\s+\\\\?([\w\\\\]+\\\\)?(\w+)/', $content, $mixinMatch)) {
            $modelName = $mixinMatch[2];
            $fullClass = $this->schemaRegistry->resolveModelClass($modelName);
            if ($fullClass && isset($modelMap[$fullClass])) {
                $context['__self__'] = $modelMap[$fullClass];
                $context[$modelName] = $modelMap[$fullClass];
            }
        }

        return $context;
    }
}
