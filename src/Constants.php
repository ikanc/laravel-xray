<?php

namespace Ikabalzam\LaravelXray;

/**
 * Central registry of method name constants used throughout the Xray engine.
 *
 * These lists define how the auditor classifies method calls:
 * - Which methods accept column name arguments (and thus need validation)
 * - Which methods indicate Collection vs Query Builder context
 * - Which methods define or constrain Eloquent relationships
 *
 * MAINTENANCE NOTES:
 * - COLUMN_METHODS and RAW_SQL_METHODS define what we audit. Adding a method here means
 *   its first string argument will be validated as a column name.
 * - COLLECTION_ONLY_METHODS must NEVER include methods that also exist on Query\Builder.
 *   Adding a Builder method here would cause real column references to be silently skipped.
 * - NON_RELATION_METHODS is used during chain walking to distinguish framework methods
 *   from relationship method calls. If a method is NOT in this list, the walker treats it
 *   as a potential relationship method.
 */
final class Constants
{
    /**
     * Query Builder methods whose first string argument is a column name.
     *
     * When the auditor encounters `->where('some_column', $value)`, it extracts
     * 'some_column' and validates it exists in the resolved table's schema.
     */
    public const COLUMN_METHODS = [
        'where', 'orWhere', 'andWhere',
        'whereIn', 'whereNotIn', 'orWhereIn', 'orWhereNotIn',
        'whereNull', 'whereNotNull', 'orWhereNull', 'orWhereNotNull',
        'whereBetween', 'whereNotBetween', 'orWhereBetween',
        'whereDate', 'whereMonth', 'whereDay', 'whereYear', 'whereTime',
        'orderBy', 'orderByDesc',
        'groupBy',
        'having', 'orHaving',
        'pluck', 'value',
        'sum', 'avg', 'average', 'min', 'max', 'count',
        'increment', 'decrement',
        'select', 'addSelect',
    ];

    /**
     * Methods whose first string argument contains raw SQL with embedded column references.
     *
     * These are parsed differently — we extract column names from the SQL string itself
     * using regex patterns (e.g., aggregate functions, bare column names).
     */
    public const RAW_SQL_METHODS = [
        'selectRaw', 'whereRaw', 'orWhereRaw', 'orderByRaw',
        'havingRaw', 'groupByRaw',
    ];

    /**
     * Methods that can accept multiple column arguments or arrays of columns.
     *
     * e.g., `->select('col1', 'col2')` or `->select(['col1', 'col2'])`
     * Each column argument is individually validated.
     */
    public const MULTI_COLUMN_METHODS = ['select', 'addSelect', 'groupBy'];

    /**
     * Methods that return a Collection (not a Query Builder).
     *
     * When these appear in a method chain, everything AFTER them operates on
     * an in-memory Collection, not a database query. Column references after
     * these methods are attribute access, not SQL column references.
     *
     * Example: `$model->relation()->get()->where('status', 'active')`
     *          The ->where() after ->get() is Collection::where(), not Builder::where()
     */
    public const COLLECTION_TERMINAL_METHODS = [
        'get', 'all', 'values', 'collect', 'toArray', 'pluck',
        'keyBy', 'groupBy', 'filter', 'map', 'mapWithKeys',
        'first', 'find', 'findOrFail', 'sole',
    ];

    /**
     * Methods that ONLY exist on Collection, never on Query\Builder or Eloquent\Builder.
     *
     * These are definitive Collection indicators — if ANY of these appear in a chain,
     * the entire chain is operating on a Collection.
     *
     * CRITICAL: Do NOT add methods that also exist on Builder! Specifically excluded:
     * - union (Query\Builder::union)
     * - join (Query\Builder::join)
     * - dump (Builder::dump)
     * - dd (Builder::dd)
     * - each (Builder::each via chunking)
     * - chunk (Builder::chunk)
     * - tap (Macroable::tap)
     * - when/unless (Builder::when/unless)
     *
     * Adding a Builder method here would silently skip real database queries.
     */
    public const COLLECTION_ONLY_METHODS = [
        'transform', 'reject', 'partition',
        'sortBy', 'sortByDesc', 'sortKeys', 'sortKeysDesc', 'sortKeysUsing',
        'flatMap', 'mapInto', 'mapSpread', 'mapToGroups',
        'reduce', 'reduceSpread',
        'contains', 'containsStrict', 'doesntContain',
        'every', 'some',
        'push', 'prepend', 'put', 'pull', 'forget',
        'merge', 'mergeRecursive', 'concat', 'combine',
        'diff', 'diffKeys', 'diffAssoc', 'intersect', 'intersectByKeys',
        'flip', 'flatten', 'collapse',
        'countBy', 'median', 'mode',
        'pad', 'zip',
        'random', 'shuffle',
        'pop', 'shift', 'splice',
        'toJson', 'implode',
        'nth', 'only', 'except',
    ];

    /**
     * Collection iteration methods that pass items to closures.
     *
     * When a closure is an argument to one of these methods AND the parent chain
     * is a Collection, the closure parameter is a model instance, not a Builder.
     * Column references inside should be skipped.
     */
    public const COLLECTION_ITERATOR_METHODS = [
        'each', 'map', 'filter', 'reject', 'every', 'some',
        'flatMap', 'mapWithKeys', 'transform', 'reduce', 'partition',
        'sortBy', 'sortByDesc', 'contains', 'first', 'firstWhere',
        'mapInto', 'mapSpread', 'mapToGroups', 'eachSpread',
    ];

    /**
     * Methods that constrain a relationship query via closure.
     *
     * In `->whereHas('posts', function($q) { ... })`, the closure's $q parameter
     * is a Builder scoped to the 'posts' relationship's table.
     */
    public const RELATION_CONSTRAINT_METHODS = [
        'whereHas', 'orWhereHas', 'whereDoesntHave', 'orWhereDoesntHave',
        'withWhereHas', 'has', 'doesntHave',
    ];

    /**
     * Builder methods that pass the same Builder to a closure parameter.
     *
     * In `->where(function($q) { ... })`, $q is the same Builder as the caller.
     * The closure adds grouped conditions to the same query.
     */
    public const BUILDER_CLOSURE_METHODS = ['where', 'orWhere', 'having', 'orHaving'];

    /**
     * Builder methods that pass the Builder through to a closure conditionally.
     *
     * In `->when($condition, function($q) { ... })`, $q is the same Builder.
     */
    public const BUILDER_PASSTHROUGH_METHODS = ['when', 'unless', 'tap'];

    /**
     * Non-relationship methods that can appear in Eloquent chains.
     *
     * Used during chain walking to distinguish framework/query methods from
     * relationship method calls. If a method is NOT in this list, the chain
     * walker treats it as a potential relationship call and tries to resolve it.
     *
     * Example: `$user->posts()->where()->orderBy()->get()`
     *          posts() is NOT in this list -> treated as relationship
     *          where(), orderBy(), get() ARE in this list -> skipped during chain walk
     */
    public const NON_RELATION_METHODS = [
        'query', 'newQuery', 'newModelQuery', 'select', 'addSelect', 'where', 'orWhere',
        'join', 'leftJoin', 'rightJoin', 'crossJoin', 'with', 'withTrashed',
        'withoutTrashed', 'orderBy', 'orderByDesc', 'groupBy', 'having',
        'limit', 'offset', 'skip', 'take', 'latest', 'oldest',
        'first', 'get', 'all', 'find', 'findOrFail', 'count', 'sum',
        'map', 'each', 'filter', 'pluck', 'toArray', 'load', 'fresh',
        'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull',
        'whereBetween', 'whereDate', 'clone', 'when', 'unless',
        'selectRaw', 'whereRaw', 'orWhereRaw', 'orderByRaw', 'havingRaw', 'groupByRaw',
        // Auth/framework calls that appear in chains but aren't relationships
        'user', 'company', 'auth', 'input', 'request', 'response',
        'format', 'copy', 'create', 'make', 'build', 'resolve',
    ];

    /**
     * Eloquent relationship definition methods.
     *
     * Used when scanning model files to build the relationship map.
     * When we see `$this->hasMany(Post::class)`, we record that the method
     * returns a relationship to the Post model's table.
     */
    public const RELATION_TYPES = [
        'hasMany', 'hasOne', 'belongsTo', 'belongsToMany',
        'morphMany', 'morphOne', 'morphToMany',
        'hasManyThrough', 'hasOneThrough',
    ];

    /**
     * Regex patterns for columns that should be ignored during validation.
     *
     * These patterns match computed columns, aliases, and framework-generated
     * virtual attributes that don't correspond to real database columns.
     *
     * Examples:
     * - jobs_count    -> generated by withCount('jobs')
     * - pivot_role    -> pivot table accessor
     * - amount_sum    -> generated by withSum('payments', 'amount')
     */
    public const IGNORED_COLUMN_PATTERNS = [
        '/^pivot_/',           // Pivot table aliases
        '/^preloaded_/',       // withSum/withCount aliases
        '/_count$/',           // withCount aliases (e.g., jobs_count)
        '/_sum$/',             // withSum aliases
        '/_avg$/',             // withAvg aliases
        '/_min$/',             // withMin aliases
        '/_max$/',             // withMax aliases
        '/^laravel_/',         // Laravel internal aliases
    ];

    /**
     * SQL keywords/functions that should not be treated as column names
     * when extracted from raw SQL expressions.
     */
    public const SQL_KEYWORDS = [
        'COUNT', 'SUM', 'AVG', 'MIN', 'MAX',
        'DISTINCT', 'NULL', 'TRUE', 'FALSE',
        'ASC', 'DESC',
    ];

    /**
     * Merge user-configured extras with the built-in constants.
     *
     * Called during initialization to incorporate config('xray.*') values.
     */
    public static function nonRelationMethods(): array
    {
        return array_merge(self::NON_RELATION_METHODS, config('xray.non_relation_methods', []));
    }

    public static function collectionOnlyMethods(): array
    {
        return array_merge(self::COLLECTION_ONLY_METHODS, config('xray.collection_only_methods', []));
    }

    public static function ignoredColumnPatterns(): array
    {
        return array_merge(self::IGNORED_COLUMN_PATTERNS, config('xray.ignored_column_patterns', []));
    }
}
