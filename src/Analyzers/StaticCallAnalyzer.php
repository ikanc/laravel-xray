<?php

namespace Ikabalzam\LaravelXray\Analyzers;

use Ikabalzam\LaravelXray\Constants;
use Ikabalzam\LaravelXray\Support\AstHelpers;
use Ikabalzam\LaravelXray\Support\AuditResult;
use Ikabalzam\LaravelXray\Support\ColumnValidator;
use Ikabalzam\LaravelXray\Support\SchemaRegistry;
use PhpParser\Node;

/**
 * Analyzes static method calls for invalid column references.
 *
 * Handles patterns like:
 *   - `User::where('status', 'active')` — Model static call
 *   - `self::where('status', 'active')` — Self-reference in model files
 *   - `static::where('status', 'active')` — Late static binding in model files
 *   - `DB::table('users')->where(...)` — DB facade (the `table()` call is skipped
 *     here; chained methods are handled by MethodCallAnalyzer)
 *
 * DIFFERENCES FROM MethodCallAnalyzer:
 *
 *   Static calls are simpler to resolve — the class name is explicit in the AST node
 *   (e.g., `User::where(...)` tells us the class is `User`). No chain walking or
 *   closure ancestor analysis is needed. The class name is resolved to a table via
 *   the file context (use-statement imports) or the SchemaRegistry's model map.
 *
 *   Static calls also don't need Collection detection — `User::where(...)` always
 *   returns a Builder, never a Collection. Collection operations would appear as
 *   chained instance method calls after a terminal like `::get()`.
 *
 * WHY DB::table() IS SKIPPED:
 *   `DB::table('users')` is a table reference, not a column reference. The `table()`
 *   call itself doesn't contain a column argument — column references appear in the
 *   chained instance methods (e.g., `DB::table('users')->where('status', ...)`).
 *   Those chained calls are MethodCall nodes and are handled by MethodCallAnalyzer.
 *   The DB::table() origin is resolved by TableResolver during chain walking.
 */
class StaticCallAnalyzer
{
    public function __construct(
        protected readonly SchemaRegistry $schemaRegistry,
        protected readonly ColumnValidator $columnValidator,
    ) {}

    /**
     * Analyze a static method call for column references and validate them.
     *
     * Pipeline:
     *   1. Extract method name and check if it's a column method
     *   2. Extract the first string argument as the column name
     *   3. Skip dot-qualified columns (table.column)
     *   4. Check @audit-skip annotation
     *   5. Resolve the class name to a database table
     *   6. Validate the column against the resolved table's schema
     *
     * @param Node\Expr\StaticCall  $call        The static call AST node to analyze.
     * @param string                $file        Relative file path (for error reporting).
     * @param array<string, string> $fileContext  Map of short class name => table name for the file.
     * @param string|null           $tableFilter  If set, only report issues for this specific table.
     * @param array<string>         $fileAliases  SQL aliases found in the file (e.g., from `AS total`).
     * @param array<string>         $lines        The file content split into lines (0-indexed).
     * @param AuditResult           $result       Accumulator for issues, unresolved refs, and stats.
     */
    public function analyze(
        Node\Expr\StaticCall $call,
        string $file,
        array $fileContext,
        ?string $tableFilter,
        array $fileAliases,
        array $lines,
        AuditResult $result,
    ): void {
        // Step 1: Only analyze column methods (where, orderBy, pluck, etc.).
        // Raw SQL methods on static calls are uncommon (Model::whereRaw is rare)
        // and not worth the complexity of raw SQL parsing here.
        $methodName = $call->name instanceof Node\Identifier ? $call->name->toString() : null;
        if (! $methodName || ! in_array($methodName, Constants::COLUMN_METHODS)) {
            return;
        }

        // Step 2: Extract the column name from the first string argument.
        $column = AstHelpers::extractFirstStringArg($call);
        if (! $column) {
            return;
        }

        // Step 3: Skip dot-qualified columns (e.g., `users.email`).
        // These are table-qualified references and don't need single-table validation.
        if (str_contains($column, '.')) {
            return;
        }

        $result->queriesFound++;

        $lineNum = $call->getStartLine();

        // Step 4: Check @audit-skip annotation — developer opt-out.
        if ($this->columnValidator->isAuditSkipped($lineNum, $lines, $call)) {
            $result->skippedByAnnotation++;

            return;
        }

        // Step 5: Resolve the class name to a database table.
        // Static calls have the class name directly in the AST node, making
        // resolution simpler than instance method chains.
        $className = null;
        if ($call->class instanceof Node\Name) {
            $className = $call->class->toString();
            // Handle fully-qualified names by extracting the base class name.
            // e.g., `App\Models\User` → `User`
            $className = class_basename($className);
        }

        if (! $className) {
            return;
        }

        // DB::table() is a table reference, not a column reference.
        // The chained instance methods are handled by MethodCallAnalyzer.
        if ($className === 'DB' && $methodName === 'table') {
            return;
        }

        // Resolve `self::` and `static::` to the current model's table.
        // These appear inside model classes and reference the model's own table.
        if ($className === 'self' || $className === 'static') {
            $table = $fileContext['__self__'] ?? null;
        } else {
            // Try file context first (use-statement imports map class name → table).
            $table = $fileContext[$className] ?? null;

            // Fall back to the global model map via namespace resolution.
            // e.g., `User` → `App\Models\User` → `users`
            if (! $table) {
                $fullClass = $this->schemaRegistry->resolveModelClass($className);
                $table = $fullClass ? ($this->schemaRegistry->getModelMap()[$fullClass] ?? null) : null;
            }
        }

        // If we couldn't resolve the table, or it doesn't match the filter, bail.
        // Unresolved static calls are not tracked — the class name is usually
        // a non-model class (service, controller, etc.) and not worth reporting.
        if (! $table || ($tableFilter && $table !== $tableFilter)) {
            return;
        }

        // Step 6: Validate the column against the resolved table's schema.
        if ($this->columnValidator->isValidColumn($column, $table)) {
            return;
        }

        if ($this->columnValidator->isIgnoredColumn($column)) {
            return;
        }

        if (in_array($column, $fileAliases)) {
            return;
        }

        // INVALID COLUMN — record the issue with optional typo suggestion.
        $context = isset($lines[$lineNum - 1]) ? trim($lines[$lineNum - 1]) : '';
        $suggestion = $this->columnValidator->suggestColumn($column, $table);

        $result->addIssue($file, $lineNum, $column, $table, $context, $suggestion);
    }
}
