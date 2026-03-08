# Laravel Xray

**Static analysis for Eloquent column references.** Finds invalid database column names in your Laravel code before they hit production.

Xray uses PHP-Parser to build an AST of your codebase, then traces every `->where('column')`, `->orderBy('column')`, `->pluck('column')` (and 30+ other methods) back to its database table and validates the column actually exists.

## The Problem

```php
// This will blow up at runtime — 'stauts' doesn't exist
User::where('stauts', 'active')->get();

// This passes all tests until a specific code path runs
$query->orderBy('craeted_at');

// Renamed a column in a migration but missed a reference
Invoice::where('total', '>', 1000); // was renamed to 'total_in_cents'
```

These bugs survive code review, pass your test suite, and explode in production. Xray catches them statically — no HTTP requests, no test data, no runtime needed.

## Installation

```bash
composer require ikabalzam/laravel-xray --dev
```

Laravel auto-discovers the service provider. No manual registration needed.

## Usage

```bash
# Full audit
php artisan xray:audit

# Audit a specific table
php artisan xray:audit --table=users

# Show suggested fixes for typos
php artisan xray:audit --fix

# JSON output (for CI pipelines)
php artisan xray:audit --json

# Show unresolved references (dynamic class/table names)
php artisan xray:audit --show-unresolved

# Scan a specific directory
php artisan xray:audit --path=app/Services
```

## What It Catches

- **Typos**: `'stauts'` instead of `'status'` (with Levenshtein suggestions)
- **Renamed columns**: References to columns that no longer exist after a migration
- **Wrong table**: Column exists but on a different table than the one being queried
- **Copy-paste errors**: Column from one model accidentally used in another's query

## What It Understands

Xray isn't a dumb regex grep. It performs deep AST analysis:

- **Method chains**: `User::where()->orderBy()->get()` — traces the chain back to `User`
- **Relationships**: `$this->posts()->where('title', ...)` — resolves `posts()` to the `posts` table
- **Closures**: `->whereHas('posts', fn($q) => $q->where('title', ...))` — resolves through closure context
- **Nested closures**: Multiple closure levels deep
- **Variable tracking**: `$query = User::query(); $query->where('name', ...)` — traces variable assignments
- **Self-referencing**: `$q = $q->where(...)` — traces back to the original assignment
- **Static calls**: `User::where()`, `self::where()`, `static::where()`, `DB::table('users')`
- **Collection detection**: Won't flag `$users->where('name', ...)` — it knows that's `Collection::where()`, not `Builder::where()`
- **Resources**: `$this->status` in a Resource file resolves to the underlying model
- **Scopes**: `scopeActive($query)` — knows `$query` is a Builder for this model
- **Raw SQL**: Extracts columns from `selectRaw('COUNT(id) as total')`
- **SQL aliases**: Won't flag columns defined with `AS alias` elsewhere in the file
- **Type hints**: `function foo(Collection $items)` — knows to skip Collection parameters
- **`@audit-skip`**: Opt out of specific lines, methods, or entire classes

## Suppressing False Positives

```php
// Inline
->where('dynamic_column', $value) // @audit-skip

// Line above
// @audit-skip — this column exists in a dynamic view
->where('computed_field', $value)

// Entire method
/** @audit-skip This method uses a legacy table not in the main schema */
public function legacyQuery() {
    // All column references in this method are skipped
}
```

## Configuration

```bash
php artisan vendor:publish --tag=xray-config
```

```php
// config/xray.php
return [
    // Default scan path
    'path' => app_path(),

    // Where your models live
    'models_path' => app_path('Models'),

    // Extra column patterns to ignore (regex)
    'ignored_column_patterns' => [
        '/^cached_/',  // your custom virtual attributes
    ],

    // Extra methods to treat as non-relationship during chain walking
    'non_relation_methods' => [
        'applyFilters',  // your custom Builder macros
    ],

    // Extra Collection-only methods (NEVER add Builder methods here!)
    'collection_only_methods' => [],
];
```

## CI Integration

Xray returns exit code 1 when issues are found, making it perfect for CI:

```yaml
# GitHub Actions
- name: Audit column references
  run: php artisan xray:audit --json
```

```yaml
# GitLab CI
xray:
  script: php artisan xray:audit
  allow_failure: false
```

## How It Works

Xray is built on [nikic/php-parser](https://github.com/nikic/PHP-Parser) and performs multi-pass analysis:

1. **Schema loading** — Reads your live database schema (tables + columns) and scans `app/Models` to build a model-to-table map with relationship metadata
2. **AST parsing** — Parses each PHP file into an Abstract Syntax Tree
3. **Context detection** — Identifies model imports, class declarations, `@mixin` annotations
4. **Column extraction** — Finds all method calls that accept column arguments
5. **Table resolution** — Traces each column reference back to its database table using four strategies:
   - **Chain resolution**: Walk method chains backward (`User::where()->orderBy()`)
   - **Closure resolution**: Analyze closure parent context (`whereHas`, `where(fn)`, `with`)
   - **Variable resolution**: Track variable assignments and type hints
   - **Cross-resolver mediation**: Coordinate between strategies for complex patterns
6. **Validation** — Check each column against the resolved table's actual schema

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12
- A running database connection (Xray reads schema metadata via `Schema::getColumnListing()`)

## License

MIT License. See [LICENSE](LICENSE) for details.
