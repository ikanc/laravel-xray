<?php

namespace Ikabalzam\LaravelVision;

use Ikabalzam\LaravelVision\Analyzers\FileAnalyzer;
use Ikabalzam\LaravelVision\Analyzers\MethodCallAnalyzer;
use Ikabalzam\LaravelVision\Analyzers\StaticCallAnalyzer;
use Ikabalzam\LaravelVision\Resolvers\ChainResolver;
use Ikabalzam\LaravelVision\Resolvers\ClosureResolver;
use Ikabalzam\LaravelVision\Resolvers\TableResolver;
use Ikabalzam\LaravelVision\Resolvers\VariableResolver;
use Ikabalzam\LaravelVision\Support\AuditResult;
use Ikabalzam\LaravelVision\Support\CollectionDetector;
use Ikabalzam\LaravelVision\Support\ColumnExtractor;
use Ikabalzam\LaravelVision\Support\ColumnValidator;
use Ikabalzam\LaravelVision\Support\SchemaRegistry;
use Illuminate\Support\Facades\File;

/**
 * Main orchestrator for the schema audit tool.
 *
 * Wires together all the components (loaders, analyzers, resolvers, validators)
 * and runs the full audit pipeline:
 *
 * 1. Load database schema and model metadata (SchemaLoader → SchemaRegistry)
 * 2. Scan PHP files in the target directory
 * 3. For each file: parse AST → find column references → resolve tables → validate
 * 4. Collect results into AuditResult (issues + unresolved + stats)
 *
 * This class is framework-agnostic in its core logic — the artisan command
 * handles CLI output and user interaction.
 *
 * Usage:
 *   $auditor = new SchemaAuditor();
 *   $result = $auditor->audit('/path/to/app', tableFilter: 'users');
 */
class SchemaAuditor
{
    private SchemaRegistry $registry;

    private FileAnalyzer $fileAnalyzer;

    /**
     * Run the full audit pipeline.
     *
     * @param  string       $path        Directory to scan (default: app/)
     * @param  string|null  $tableFilter Only audit columns for this specific table
     * @param  string|null  $modelFilter Only audit files related to this model
     * @return AuditResult  Complete audit results with issues, unresolved refs, and stats
     */
    public function audit(string $path, ?string $tableFilter = null, ?string $modelFilter = null): AuditResult
    {
        // Step 1: Load schema and model metadata
        $this->registry = (new SchemaLoader)->load();

        // Step 2: Build the analysis pipeline (dependency injection chain)
        $this->buildPipeline();

        // Step 3: Scan all PHP files
        $result = new AuditResult;
        $this->scanDirectory($path, $tableFilter, $modelFilter, $result);

        return $result;
    }

    /**
     * Get the schema registry (available after audit() is called).
     * Used by the artisan command for schema stats display.
     */
    public function getRegistry(): SchemaRegistry
    {
        return $this->registry;
    }

    /**
     * Build the full dependency chain for the analysis pipeline.
     *
     * The dependency graph flows strictly downward — no circular dependencies:
     *
     *   SchemaRegistry (data container)
     *       ↓
     *   CollectionDetector, ColumnExtractor, ColumnValidator (support utilities)
     *       ↓
     *   ChainResolver → ClosureResolver → VariableResolver (resolution strategies)
     *       ↓
     *   TableResolver (resolution orchestrator)
     *       ↓
     *   MethodCallAnalyzer, StaticCallAnalyzer (call analyzers)
     *       ↓
     *   FileAnalyzer (file-level orchestrator)
     */
    private function buildPipeline(): void
    {
        // Support utilities
        $collectionDetector = new CollectionDetector;
        $columnExtractor = new ColumnExtractor;
        $columnValidator = new ColumnValidator($this->registry);

        // Resolvers (order matters — ChainResolver and VariableResolver are dependencies of ClosureResolver)
        $chainResolver = new ChainResolver($this->registry, $collectionDetector);
        $variableResolver = new VariableResolver($this->registry);
        $closureResolver = new ClosureResolver($this->registry, $collectionDetector, $chainResolver, $variableResolver);
        $tableResolver = new TableResolver($chainResolver, $closureResolver, $variableResolver, $collectionDetector);

        // Analyzers
        $methodCallAnalyzer = new MethodCallAnalyzer($tableResolver, $collectionDetector, $columnExtractor, $columnValidator, $this->registry);
        $staticCallAnalyzer = new StaticCallAnalyzer($this->registry, $columnValidator);

        $this->fileAnalyzer = new FileAnalyzer($this->registry, $methodCallAnalyzer, $staticCallAnalyzer);
    }

    /**
     * Recursively scan all PHP files in the given directory.
     */
    private function scanDirectory(string $path, ?string $tableFilter, ?string $modelFilter, AuditResult $result): void
    {
        $files = File::allFiles($path);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = str_replace(base_path() . '/', '', $file->getPathname());

            $this->fileAnalyzer->analyze(
                $file->getPathname(),
                $relativePath,
                $tableFilter,
                $modelFilter,
                $result,
            );
        }
    }
}
