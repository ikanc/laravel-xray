<?php

namespace Ikabalzam\LaravelXray;

use Ikabalzam\LaravelXray\Support\AuditResult;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Artisan command for auditing database column references in PHP code.
 *
 * This is the CLI interface to the Xray engine. It handles:
 * - Command-line options and argument parsing
 * - Output formatting (human-readable and JSON)
 * - Exit codes (SUCCESS for clean audits, FAILURE for confirmed issues)
 *
 * The actual analysis logic lives in SchemaAuditor and its component classes.
 *
 * Usage:
 *   php artisan xray:audit                          # Full audit
 *   php artisan xray:audit --table=users            # Audit only 'users' table
 *   php artisan xray:audit --fix                    # Show suggested column names
 *   php artisan xray:audit --json                   # Machine-readable output
 *   php artisan xray:audit --show-unresolved        # Show dynamic class/table refs
 *   php artisan xray:audit --show-skipped           # Show @audit-skip'd references
 */
class XrayCommand extends Command
{
    protected $signature = 'xray:audit
        {--path= : Path to scan (default: config value or app/)}
        {--table= : Only audit a specific table}
        {--model= : Only audit a specific model}
        {--fix : Show suggested fixes}
        {--json : Output as JSON}
        {--show-skipped : Show references skipped by @audit-skip}
        {--show-unresolved : Show references where the table could not be determined}';

    protected $description = 'Audit PHP code for invalid database column references in Eloquent/Query Builder calls';

    public function handle(): int
    {
        $isJson = $this->option('json');

        if (! $isJson) {
            $this->info('Laravel Xray — Column Reference Audit');
            $this->info('=======================================');
            $this->newLine();
            $this->info('Loading database schema...');
        }

        $auditor = new SchemaAuditor;
        $path = $this->option('path') ?: config('xray.path', base_path('app'));
        $tableFilter = $this->option('table');
        $modelFilter = $this->option('model');

        $result = $auditor->audit($path, $tableFilter, $modelFilter);
        $registry = $auditor->getRegistry();

        if (! $isJson) {
            $this->info("  Found {$registry->tableCount()} tables, {$registry->columnCount()} columns");
            $this->newLine();
            $this->info('  Mapped '.count($registry->getModelMap()).' models');
            $this->newLine();
            $this->info("  Scanned {$result->filesScanned} files, found {$result->queriesFound} column references");
            $this->newLine();
        }

        // JSON output mode
        if ($isJson) {
            $this->line(json_encode($result->toArray(), JSON_PRETTY_PRINT));

            return $result->hasIssues() ? Command::FAILURE : Command::SUCCESS;
        }

        // Display confirmed issues (real bugs)
        if ($result->hasIssues()) {
            $this->displayIssues($result);
        }

        // Display unresolved references (dynamic class/table names)
        if ($result->hasUnresolved()) {
            if ($this->option('show-unresolved')) {
                $this->displayUnresolved($result);
            } else {
                $this->warn("  {$result->unresolvedCount()} unresolved reference(s) where the table could not be determined (dynamic class/table names).");
                $this->info('   Run with --show-unresolved to see details.');
                $this->newLine();
            }
        }

        // Display dynamic method call count
        if ($result->dynamicMethodCalls > 0) {
            $this->warn("  Found {$result->dynamicMethodCalls} dynamic method call(s) that could not be statically analyzed.");
            $this->newLine();
        }

        // Display skipped annotation count
        if ($result->skippedByAnnotation > 0 && $this->option('show-skipped')) {
            $this->info("  Skipped {$result->skippedByAnnotation} reference(s) via @audit-skip annotation.");
            $this->newLine();
        }

        // Final verdict
        if (! $result->hasIssues()) {
            $this->info('No invalid column references found!');

            return Command::SUCCESS;
        }

        return Command::FAILURE;
    }

    /**
     * Display confirmed invalid column references grouped by file.
     */
    private function displayIssues(AuditResult $result): void
    {
        $this->error($result->issueCount().' ISSUE(S) — Invalid column references:');
        $this->newLine();

        $grouped = [];
        foreach ($result->issues as $issue) {
            $grouped[$issue['file']][] = $issue;
        }

        foreach ($grouped as $file => $fileIssues) {
            $this->line("  {$file}");

            foreach ($fileIssues as $issue) {
                $this->line("     Line {$issue['line']}: <fg=red>{$issue['column']}</> not in <fg=cyan>{$issue['table']}</>");
                $this->line('     <fg=gray>'.Str::limit(trim($issue['context']), 120).'</>');

                if (! empty($issue['suggestion'])) {
                    $this->line("     <fg=green>{$issue['suggestion']}</>");
                }

                $this->newLine();
            }
        }

        $this->newLine();
        $this->info('Total: '.$result->issueCount().' issues found');

        if (! $this->option('fix')) {
            $this->info('Run with --fix to see suggested column names.');
        }
    }

    /**
     * Display unresolved references (table could not be determined).
     */
    private function displayUnresolved(AuditResult $result): void
    {
        $this->warn($result->unresolvedCount().' UNRESOLVED — Could not determine table for:');
        $this->newLine();

        $grouped = [];
        foreach ($result->unresolvedReferences as $ref) {
            $grouped[$ref['file']][] = $ref;
        }

        foreach ($grouped as $file => $refs) {
            $this->line("  {$file}");
            foreach ($refs as $ref) {
                $this->line("     Line {$ref['line']}: <fg=yellow>{$ref['column']}</> — table unknown");
                $this->line('     <fg=gray>'.Str::limit(trim($ref['context']), 120).'</>');
                $this->newLine();
            }
        }

        $this->info('Tip: Add @audit-skip in a comment on or above the line to suppress.');
        $this->newLine();
    }
}
