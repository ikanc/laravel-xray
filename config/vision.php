<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Scan Path
    |--------------------------------------------------------------------------
    |
    | The directory to scan for PHP files. Defaults to the app/ directory.
    | You can override this at runtime with the --path option.
    |
    */

    'path' => app_path(),

    /*
    |--------------------------------------------------------------------------
    | Models Directory
    |--------------------------------------------------------------------------
    |
    | Where your Eloquent models live. Vision scans this directory to build
    | the model-to-table mapping and extract relationship definitions.
    |
    */

    'models_path' => app_path('Models'),

    /*
    |--------------------------------------------------------------------------
    | Additional Ignored Column Patterns
    |--------------------------------------------------------------------------
    |
    | Regex patterns for column names that should never be flagged.
    | Vision already ignores common patterns like *_count, *_sum, pivot_*, etc.
    | Add your own project-specific patterns here.
    |
    | Example: '/^cached_/' to ignore all cached_* virtual attributes.
    |
    */

    'ignored_column_patterns' => [
        // '/^cached_/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Additional Non-Relation Methods
    |--------------------------------------------------------------------------
    |
    | Method names that should NOT be treated as potential relationship calls
    | during chain walking. Vision already knows about all standard Laravel
    | query builder methods. Add your custom builder macros or scope-like
    | methods here to prevent false relationship resolution.
    |
    | Example: 'applyFilters' if you have a Builder macro that applies filters.
    |
    */

    'non_relation_methods' => [
        // 'applyFilters',
        // 'scopeCompany',
    ],

    /*
    |--------------------------------------------------------------------------
    | Additional Collection-Only Methods
    |--------------------------------------------------------------------------
    |
    | Methods that exist ONLY on Illuminate\Support\Collection, never on
    | Query\Builder. If any of these appear in a method chain, Vision will
    | treat the entire chain as a Collection context and skip it.
    |
    | WARNING: Do NOT add methods that also exist on Builder — doing so will
    | cause real column references to be silently skipped.
    |
    */

    'collection_only_methods' => [
        // 'customCollectionMethod',
    ],

];
