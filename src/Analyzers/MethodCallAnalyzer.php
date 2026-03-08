<?php

namespace Ikabalzam\LaravelVision\Analyzers;

use Ikabalzam\LaravelVision\Constants;
use Ikabalzam\LaravelVision\Resolvers\TableResolver;
use Ikabalzam\LaravelVision\Support\AstHelpers;
use Ikabalzam\LaravelVision\Support\AuditResult;
use Ikabalzam\LaravelVision\Support\CollectionDetector;
use Ikabalzam\LaravelVision\Support\ColumnExtractor;
use Ikabalzam\LaravelVision\Support\ColumnValidator;
use Ikabalzam\LaravelVision\Support\ResolutionResult;
use Ikabalzam\LaravelVision\Support\SchemaRegistry;
use PhpParser\Node;

/**
 * Analyzes instance method calls (e.g., `->where('column', $value)`) for invalid
 * column references against the actual database schema.
 *
 * This is the primary analysis engine — most column references in a Laravel codebase
 * come through instance method calls on Eloquent Builders and Query Builders. Static
 * calls (like `User::where(...)`) are handled separately by StaticCallAnalyzer.
 *
 * ANALYSIS PIPELINE (for each MethodCall node):
 *
 *   1. **Method classification** — Is this a column method (where, orderBy, etc.),
 *      a raw SQL method (whereRaw, selectRaw, etc.), or something else entirely?
 *      Non-column methods are ignored immediately.
 *
 *   2. **@audit-skip check** — Developers can suppress warnings with `@audit-skip`
 *      annotations (inline, line above, or method PHPDoc). Respected unconditionally.
 *
 *   3. **Collection detection** — Many Builder method names (where, pluck, sum, etc.)
 *      also exist on Collection. If the call chain passes through a Collection terminal
 *      (get(), all(), first()) or originates from a property access, the call is
 *      operating on in-memory data and is NOT a database query. Skipped to avoid
 *      massive false positives.
 *
 *   4. **Column extraction** — Depending on the method type:
 *      - Raw methods: parse SQL string for embedded column names
 *      - Multi-column methods (select, addSelect, groupBy): extract all string args
 *      - Standard methods: extract first string argument as the column name
 *
 *   5. **Table resolution** — Determine which database table the column belongs to
 *      by walking the method chain, checking closure ancestors, and resolving variables.
 *      Delegated to TableResolver.
 *
 *   6. **Column validation** — Check whether the extracted column exists in the
 *      resolved table's schema. Invalid references are recorded as issues; unresolved
 *      tables are tracked separately.
 *
 * PATTERNS INTENTIONALLY SKIPPED:
 *   - Dot-qualified columns (`table.column`) — cross-table references handled by joins
 *   - JSON arrow notation (`data->key`) — MySQL JSON path, not a real column
 *   - Closure arguments — `->where(function($q) {...})` adds grouped conditions,
 *     the closure's internal calls are analyzed independently by the NodeFinder
 */
class MethodCallAnalyzer
{
    public function __construct(
        protected readonly TableResolver $tableResolver,
        protected readonly CollectionDetector $collectionDetector,
        protected readonly ColumnExtractor $columnExtractor,
        protected readonly ColumnValidator $columnValidator,
        protected readonly SchemaRegistry $schemaRegistry,
    ) {}

    /**
     * Analyze an instance method call for column references and validate them.
     *
     * This is the main entry point called by FileAnalyzer for each MethodCall node
     * found in the AST. It orchestrates the full pipeline: classify → skip-check →
     * collection-check → extract → resolve → validate.
     *
     * @param Node\Expr\MethodCall $call        The method call AST node to analyze.
     * @param string               $file        Relative file path (for error reporting).
     * @param array<string, string> $fileContext Map of short class name => table name for the file.
     * @param string|null          $tableFilter If set, only report issues for this specific table.
     * @param array<string>        $fileAliases SQL aliases found in the file (e.g., from `AS total`).
     * @param array<string>        $lines       The file content split into lines (0-indexed).
     * @param AuditResult          $result      Accumulator for issues, unresolved refs, and stats.
     */
    public function analyze(
        Node\Expr\MethodCall $call,
        string $file,
        array $fileContext,
        ?string $tableFilter,
        array $fileAliases,
        array $lines,
        AuditResult $result,
    ): void {
        // Step 1: Classify the method — is it a column method or raw SQL method?
        $methodName = $call->name instanceof Node\Identifier ? $call->name->toString() : null;
        if (! $methodName) {
            return;
        }

        $isColumnMethod = in_array($methodName, Constants::COLUMN_METHODS);
        $isRawMethod = in_array($methodName, Constants::RAW_SQL_METHODS);

        if (! $isColumnMethod && ! $isRawMethod) {
            return;
        }

        $lineNum = $call->getStartLine();

        // Step 2: Check for @audit-skip annotation — developer opt-out
        if ($this->columnValidator->isAuditSkipped($lineNum, $lines, $call)) {
            $result->skippedByAnnotation++;

            return;
        }

        // Step 3: Detect Collection context — shared method names but not database queries.
        // This MUST run before any column extraction or table resolution to avoid
        // false positives from Collection::where(), Collection::pluck(), etc.
        if ($this->collectionDetector->isCollectionChain($call)) {
            return;
        }

        // Step 4a: Handle raw SQL methods — extract columns from SQL strings.
        // Raw methods embed column names inside SQL expressions like
        // `selectRaw('COUNT(id) as total')`, requiring regex-based extraction.
        if ($isRawMethod) {
            $rawSql = AstHelpers::extractFirstStringArg($call);
            if (! $rawSql) {
                return;
            }

            $columns = $this->columnExtractor->extractColumnsFromRawSql($rawSql);
            if (empty($columns)) {
                return;
            }

            $resolution = $this->tableResolver->resolve($call, $fileContext);
            if ($resolution->isSkip()) {
                return;
            }

            foreach ($columns as $column) {
                $result->queriesFound++;
                $this->validateAndRecordColumn($column, $resolution, $file, $lineNum, $tableFilter, $fileAliases, $lines, $result);
            }

            return;
        }

        // Step 4b: Skip closure arguments — `->where(function($q) {...})` adds
        // grouped conditions. The closure's internal calls will be analyzed
        // independently when the NodeFinder discovers them.
        if (! empty($call->args) && $call->args[0]->value instanceof Node\Expr\Closure) {
            return;
        }

        // Step 4c: Handle multi-column methods — `->select('col1', 'col2')` or
        // `->select(['col1', 'col2'])`. Each column argument is validated individually.
        if (in_array($methodName, Constants::MULTI_COLUMN_METHODS)) {
            $columns = $this->columnExtractor->extractAllColumnArgs($call);
            if (empty($columns)) {
                return;
            }

            $resolution = $this->tableResolver->resolve($call, $fileContext);
            if ($resolution->isSkip()) {
                return;
            }

            foreach ($columns as $column) {
                $result->queriesFound++;
                $this->validateAndRecordColumn($column, $resolution, $file, $lineNum, $tableFilter, $fileAliases, $lines, $result);
            }

            return;
        }

        // Step 4d: Standard single-column method — extract the first string argument.
        $column = AstHelpers::extractFirstStringArg($call);
        if (! $column) {
            return;
        }

        // Skip dot-qualified columns (e.g., `users.email`) — these are explicit
        // table-qualified references typically used in joins. The table part makes
        // them unambiguous and they don't need validation against a single table.
        if (str_contains($column, '.')) {
            return;
        }

        // Skip JSON arrow notation (e.g., `raw_data->stripe_type`) — MySQL JSON
        // path expressions. The base column exists but the arrow path is schema-free.
        if (str_contains($column, '->')) {
            return;
        }

        $result->queriesFound++;

        // Step 5: Resolve which database table this column belongs to.
        $resolution = $this->tableResolver->resolve($call, $fileContext);
        if ($resolution->isSkip()) {
            return;
        }

        // Step 6: Validate the column against the resolved table's schema.
        $this->validateAndRecordColumn($column, $resolution, $file, $lineNum, $tableFilter, $fileAliases, $lines, $result);
    }

    /**
     * Validate a single column reference and record the result.
     *
     * Handles three outcomes from table resolution:
     *
     *   1. **Unresolved table** — The column reference can't be tied to a specific table.
     *      Recorded in `$result->unresolvedReferences` unless:
     *      - The column matches an ignored pattern (e.g., `jobs_count`)
     *      - The column is a known SQL alias from the file
     *      - The file is a Trait AND the column exists in at least one table (traits
     *        are generic — they reference columns that exist on whatever model uses them)
     *
     *   2. **Table resolved, column valid** — No action needed.
     *
     *   3. **Table resolved, column invalid** — This is a real bug. The column doesn't
     *      exist in the resolved table. Recorded as an issue with optional typo suggestion.
     *
     * @param string           $column      The column name to validate.
     * @param ResolutionResult $resolution  The table resolution result (resolved, skip, or unresolved).
     * @param string           $file        Relative file path for error reporting.
     * @param int              $lineNum     1-based line number of the column reference.
     * @param string|null      $tableFilter If set, only report issues for this specific table.
     * @param array<string>    $fileAliases SQL aliases found in the file.
     * @param array<string>    $lines       File content as array of lines (0-indexed).
     * @param AuditResult      $result      Accumulator for issues and unresolved refs.
     */
    public function validateAndRecordColumn(
        string $column,
        ResolutionResult $resolution,
        string $file,
        int $lineNum,
        ?string $tableFilter,
        array $fileAliases,
        array $lines,
        AuditResult $result,
    ): void {
        // Guard: dot-qualified and JSON arrow columns are cross-table or schema-free.
        // This duplicates the check in analyze() as a safety net — validateAndRecordColumn
        // can be called from multiple paths (raw SQL, multi-column, single-column).
        if (str_contains($column, '.') || str_contains($column, '->')) {
            return;
        }

        // UNRESOLVED TABLE — we couldn't determine which table the column belongs to.
        // Track it as an unresolved reference (not necessarily a bug) unless we can
        // determine it's safe to ignore.
        if ($resolution->isUnresolved()) {
            if (! $this->columnValidator->isIgnoredColumn($column) && ! in_array($column, $fileAliases)) {
                // Trait files define reusable query logic that operates on whatever model
                // uses the trait. If the column exists in ANY table, it's likely valid —
                // the trait is just generic and we can't resolve the specific table statically.
                if (str_contains($file, '/Traits/') && $this->schemaRegistry->columnExistsAnywhere($column)) {
                    return;
                }

                $context = isset($lines[$lineNum - 1]) ? trim($lines[$lineNum - 1]) : '';
                $result->addUnresolved($file, $lineNum, $column, $context);
            }

            return;
        }

        // SKIP — collection context or explicitly rejected. Nothing to do.
        if ($resolution->isSkip()) {
            return;
        }

        // RESOLVED TABLE — validate the column against the actual schema.
        $table = $resolution->table;

        // If filtering by a specific table, skip columns on other tables.
        if ($tableFilter && $table !== $tableFilter) {
            return;
        }

        // Column exists in the table — valid reference, nothing to report.
        if ($this->columnValidator->isValidColumn($column, $table)) {
            return;
        }

        // Column matches an ignored pattern (e.g., _count, _sum, pivot_*).
        if ($this->columnValidator->isIgnoredColumn($column)) {
            return;
        }

        // Column is a SQL alias defined elsewhere in the file (e.g., `AS total`).
        if (in_array($column, $fileAliases)) {
            return;
        }

        // INVALID COLUMN — this is a real bug. Record the issue.
        $context = isset($lines[$lineNum - 1]) ? trim($lines[$lineNum - 1]) : '';
        $suggestion = $this->columnValidator->suggestColumn($column, $table);

        $result->addIssue($file, $lineNum, $column, $table, $context, $suggestion);
    }
}
