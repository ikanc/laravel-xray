<?php

namespace Ikabalzam\LaravelXray\Support;

use Ikabalzam\LaravelXray\Constants;
use PhpParser\Node;

/**
 * Extracts column names from AST nodes in various formats.
 *
 * Query Builder methods accept columns in several forms:
 *   - Single string args: `->where('status', 'active')`
 *   - Multiple string args: `->select('name', 'email', 'phone')`
 *   - Array args: `->select(['name', 'email'])`
 *   - Raw SQL strings: `->selectRaw('COUNT(id) as total')`
 *
 * This class centralizes the extraction logic so that each analyzer doesn't
 * need to handle all these variants independently.
 */
class ColumnExtractor
{
    /**
     * Extract all column name arguments from a method call.
     *
     * Handles two patterns:
     *   1. Multiple string arguments: `->select('col1', 'col2', 'col3')`
     *   2. Array arguments: `->select(['col1', 'col2'])`
     *
     * Columns containing dots (table-qualified like `users.id`), JSON arrows
     * (`meta->key`), or spaces (raw expressions like `id DESC`) are excluded
     * since they require different handling or are not simple column references.
     *
     * DB::raw() arguments are silently skipped — they are not plain columns
     * and would be handled by extractColumnsFromRawSql() instead.
     *
     * @param  Node\Expr\MethodCall  $call  A method call node (e.g., a `select()` or `groupBy()` call).
     * @return array<string> List of plain column names extracted from the arguments.
     */
    public function extractAllColumnArgs(Node\Expr\MethodCall $call): array
    {
        $columns = [];

        foreach ($call->args as $arg) {
            if (! ($arg instanceof Node\Arg)) {
                continue;
            }

            $value = $arg->value;

            // String argument: ->select('col1', 'col2')
            if ($value instanceof Node\Scalar\String_) {
                $col = $value->value;
                if (! str_contains($col, '.') && ! str_contains($col, '->') && ! str_contains($col, ' ')) {
                    $columns[] = $col;
                }
            }

            // Array argument: ->select(['col1', 'col2'])
            if ($value instanceof Node\Expr\Array_) {
                foreach ($value->items as $item) {
                    if ($item instanceof Node\Expr\ArrayItem && $item->value instanceof Node\Scalar\String_) {
                        $col = $item->value->value;
                        if (! str_contains($col, '.') && ! str_contains($col, '->') && ! str_contains($col, ' ')) {
                            $columns[] = $col;
                        }
                    }
                }
            }

            // DB::raw() — skip, not a plain column
        }

        return $columns;
    }

    /**
     * Extract column names from a raw SQL string.
     *
     * Parses SQL expressions found in methods like `selectRaw()`, `whereRaw()`, etc.
     * Handles several patterns:
     *
     *   - Aggregate functions: `COUNT(column)`, `SUM(amount)` → extracts the column inside
     *   - Multi-arg functions: `COALESCE(column, 0)`, `IFNULL(column, default)` → extracts first arg
     *   - Bare column names: `status`, `created_at` → extracted as-is
     *   - Comma-separated expressions: `SUM(amount) AS total, COUNT(id)` → each parsed independently
     *   - AS aliases: `column AS alias` → alias is stripped, column is extracted
     *
     * Intentionally skips:
     *   - Complex SQL with subqueries, JOINs, UNIONs (returns empty array)
     *   - SQL keywords/functions that look like column names (COUNT, SUM, NULL, etc.)
     *   - Wildcard `*`
     *
     * @param  string  $sql  The raw SQL string from a *Raw() method call.
     * @return array<string> List of column names found in the SQL expression.
     */
    public function extractColumnsFromRawSql(string $sql): array
    {
        $columns = [];

        // Skip complex SQL (subqueries, CTEs) — too ambiguous to parse reliably
        if (preg_match('/\b(SELECT|FROM|JOIN|UNION)\b/i', $sql)) {
            return [];
        }

        // Split by commas for multiple expressions: 'COUNT(*) as total, SUM(amount) as sum'
        $expressions = array_map('trim', explode(',', $sql));

        foreach ($expressions as $expr) {
            // Strip AS alias: 'column AS alias' or 'column as alias'
            $expr = preg_replace('/\s+AS\s+\w+\s*$/i', '', $expr);

            // Extract column from aggregate functions: COUNT(column), SUM(column), etc.
            if (preg_match('/^\w+\(([a-zA-Z_]\w*)\)$/i', trim($expr), $m)) {
                $columns[] = $m[1];

                continue;
            }

            // Extract column from COALESCE, IFNULL, etc.: COALESCE(column, 0)
            if (preg_match('/^\w+\(([a-zA-Z_]\w*),/i', trim($expr), $m)) {
                $columns[] = $m[1];

                continue;
            }

            // Bare column name (no spaces, no parens, no operators)
            $expr = trim($expr);
            if (preg_match('/^[a-zA-Z_]\w*$/', $expr)) {
                $columns[] = $expr;
            }
        }

        // Filter out SQL keywords/functions that look like columns
        return array_filter($columns, fn ($c) => ! in_array(strtoupper($c), Constants::SQL_KEYWORDS) && $c !== '*');
    }
}
