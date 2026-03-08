<?php

namespace Ikabalzam\LaravelXray\Support;

/**
 * Data transfer object holding the complete results of a schema audit run.
 *
 * Separates the audit results into three categories:
 *
 * - **Issues**: Confirmed invalid column references. The table IS known and the
 *   column does NOT exist in it. These are real bugs that will cause SQL errors.
 *
 * - **Unresolved**: Column references where we couldn't determine the target table.
 *   Typically caused by dynamic class names ($modelClass::where()) or dynamic table
 *   names (DB::table($var)). These are NOT necessarily bugs — they're the tool's
 *   honest admission that it can't statically analyze dynamic code.
 *
 * - **Stats**: Scan metadata (files scanned, references found, skipped counts).
 */
final class AuditResult
{
    /** @var array<array{file: string, line: int, column: string, table: string, context: string, suggestion: string|null, severity: string}> */
    public array $issues = [];

    /** @var array<array{file: string, line: int, column: string, context: string}> */
    public array $unresolvedReferences = [];

    public int $filesScanned = 0;

    public int $queriesFound = 0;

    public int $skippedByAnnotation = 0;

    public int $dynamicMethodCalls = 0;

    public function addIssue(
        string $file,
        int $line,
        string $column,
        string $table,
        string $context,
        ?string $suggestion = null,
    ): void {
        $this->issues[] = [
            'file' => $file,
            'line' => $line,
            'column' => $column,
            'table' => $table,
            'context' => $context,
            'suggestion' => $suggestion,
            'severity' => 'error',
        ];
    }

    public function addUnresolved(string $file, int $line, string $column, string $context): void
    {
        $this->unresolvedReferences[] = [
            'file' => $file,
            'line' => $line,
            'column' => $column,
            'context' => $context,
        ];
    }

    public function hasIssues(): bool
    {
        return ! empty($this->issues);
    }

    public function hasUnresolved(): bool
    {
        return ! empty($this->unresolvedReferences);
    }

    public function issueCount(): int
    {
        return count($this->issues);
    }

    public function unresolvedCount(): int
    {
        return count($this->unresolvedReferences);
    }

    public function toArray(): array
    {
        return [
            'issues' => $this->issues,
            'unresolved' => $this->unresolvedReferences,
            'stats' => [
                'files_scanned' => $this->filesScanned,
                'column_references' => $this->queriesFound,
                'skipped_by_annotation' => $this->skippedByAnnotation,
                'dynamic_method_calls' => $this->dynamicMethodCalls,
            ],
        ];
    }
}
