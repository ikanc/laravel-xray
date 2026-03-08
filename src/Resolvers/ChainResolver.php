<?php

namespace Ikabalzam\LaravelXray\Resolvers;

use Ikabalzam\LaravelXray\Constants;
use Ikabalzam\LaravelXray\Support\CollectionDetector;
use Ikabalzam\LaravelXray\Support\ResolutionResult;
use Ikabalzam\LaravelXray\Support\SchemaRegistry;
use PhpParser\Node;

/**
 * Walks method call chains backward to find the root model or table.
 *
 * METHOD CHAIN ANATOMY:
 * In code like `User::where('active', true)->orderBy('name')->get()`, the AST
 * represents this as nested MethodCall nodes — each one's `->var` property points
 * to the previous call in the chain. This resolver walks that chain backward from
 * the audited call (e.g., ->orderBy()) until it reaches the root expression, which
 * might be:
 *   - A StaticCall:    Model::where(...)   → resolve via SchemaRegistry
 *   - A Variable:      $this->relation()   → resolve relationship or self table
 *   - A PropertyFetch: $model->items       → Collection access (skip)
 *   - A Clone_:        (clone $query)      → unwrap and continue
 *
 * Along the way, the resolver tracks potential relationship calls — method names
 * that are NOT known framework/builder methods. If we find one and the root is
 * $this, that method is likely a relationship definition (e.g., $this->posts()->where()).
 *
 * WHY THIS EXISTS:
 * Chain walking is the highest-signal resolution strategy. When a method call is
 * part of a fluent chain starting from a static Model:: call or $this in a model,
 * we can determine the table with near certainty. This is tried first (Strategy 1)
 * before falling back to closure context or variable tracking.
 *
 * WHAT IT SKIPS (AND WHY):
 * - Collection terminal methods (get(), all(), first(), etc.) — everything after
 *   these operates on an in-memory Collection, not a query builder.
 * - Collection-only methods (reject(), sortBy(), etc.) — definitive Collection
 *   indicators with zero ambiguity.
 * - PropertyFetch roots — property access on models returns loaded Collections
 *   ($model->relation), not query builders ($model->relation()).
 * - Unresolvable relationship candidates — if a non-framework method appears in
 *   the chain but we can't resolve it as a known relationship, we skip rather
 *   than falsely attributing the query to $this's table.
 */
class ChainResolver
{
    public function __construct(
        private readonly SchemaRegistry $registry,
        private readonly CollectionDetector $collectionDetector,
    ) {}

    /**
     * Walk the method chain backward from the audited call to resolve the table.
     *
     * The algorithm:
     * 1. Traverse $call->var (the chain) backward, tracking:
     *    - Relationship definition calls (hasMany, belongsTo, etc.) → resolve immediately
     *    - Collection terminals/only methods → return Skip
     *    - Potential relationship candidates (non-framework method names)
     * 2. When we reach the root node, resolve based on its type:
     *    - StaticCall → resolve via SchemaRegistry (Model::where, DB::table)
     *    - Variable($this) + relationship candidate → resolve relationship
     *    - Variable($this) without relationship → self table
     *    - PropertyFetch → Collection access (Skip)
     *    - Clone_ → unwrap and treat inner expression as root
     *
     * @param  Node\Expr\MethodCall $call        The method call being audited
     * @param  array<string, string> $fileContext Map of class short names to table names for the current file
     * @return ResolutionResult Resolved table, Skip (Collection), or Unresolved
     */
    public function resolve(Node\Expr\MethodCall $call, array $fileContext): ResolutionResult
    {
        $node = $call->var;

        // Track the last non-framework method we saw — this is the potential
        // relationship call. For example, in $this->posts()->where()->orderBy(),
        // as we walk backward past orderBy() and where() (both framework methods),
        // we'll land on posts() as the relationship candidate.
        $lastRelationCandidate = null;

        // Walk the chain backward: each MethodCall's ->var points to the previous call
        while ($node instanceof Node\Expr\MethodCall) {
            $chainMethod = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

            if ($chainMethod) {
                // Relationship DEFINITION in the chain: $this->hasMany(Visit::class)->where(...)
                // This means the chain is building a relationship query — resolve the target
                // model directly from the class argument.
                if (in_array($chainMethod, Constants::RELATION_TYPES)) {
                    $table = $this->registry->resolveModelFromClassArg($node);

                    return $table ? ResolutionResult::resolved($table) : ResolutionResult::unresolved();
                }

                // Collection terminal method in the chain (get(), all(), first(), etc.).
                // Everything after this method operates on the returned Collection/Model,
                // not the query builder. Example:
                //   $this->posts()->get()->where('active', true)
                //   The ->where() is Collection::where(), not Builder::where().
                if (in_array($chainMethod, Constants::COLLECTION_TERMINAL_METHODS)) {
                    return ResolutionResult::skip();
                }

                // Collection-only method (reject(), sortBy(), transform(), etc.).
                // These exist exclusively on Collection — their presence anywhere in the
                // chain is a definitive signal that this is Collection context.
                if (in_array($chainMethod, Constants::collectionOnlyMethods())) {
                    return ResolutionResult::skip();
                }

                // Track potential relationship calls: anything NOT a known framework/builder
                // method might be a relationship method. We save the last one we see because
                // it's closest to the root and most likely to be the actual relationship.
                if (! in_array($chainMethod, Constants::nonRelationMethods())) {
                    $lastRelationCandidate = $chainMethod;
                }
            }

            $node = $node->var;
        }

        // We've reached the root of the chain. Resolve based on what type it is.

        // Unwrap clone expressions: (clone $query)->where() → treat $query as root.
        // Cloning a query builder is common when you want to branch a query without
        // mutating the original. The clone produces the same builder type.
        if ($node instanceof Node\Expr\Clone_) {
            $node = $node->expr;
        }

        // Root is a static call: Model::where()->orderBy()->...
        // Delegate to SchemaRegistry which handles DB::table(), self::, static::,
        // and Model:: patterns.
        if ($node instanceof Node\Expr\StaticCall) {
            $table = $this->registry->resolveStaticCallTable($node, $fileContext);

            return $table ? ResolutionResult::resolved($table) : ResolutionResult::unresolved();
        }

        // Root is a variable (most commonly $this, but could be $query, $model, etc.)
        if ($node instanceof Node\Expr\Variable && is_string($node->name)) {
            $varName = $node->name;

            // If we found a potential relationship method in the chain, try to resolve it
            // via SchemaRegistry's relationship map.
            if ($lastRelationCandidate) {
                $table = $this->registry->resolveRelationMethod($lastRelationCandidate, $fileContext);
                if ($table) {
                    return ResolutionResult::resolved($table);
                }

                // We had a relationship candidate but couldn't resolve it. This means
                // the chain goes through an unknown method (e.g., getAllPermissions(),
                // a custom scope return, etc.). We SKIP rather than falling through to
                // the $this resolution — attributing the query to $this's table would
                // be incorrect if the unknown method returns a different model's builder.
                //
                // Example: $this->getAllPermissions()->pluck('name')
                // getAllPermissions() returns Permission models, not this model's builder.
                // If we resolved to $this's table, 'name' would be falsely validated
                // against the wrong table.
                return ResolutionResult::skip();
            }

            // $this in a model file with no relationship in the chain:
            // $this->where(...), $this->newQuery()->where(...), etc.
            // The query operates on this model's own table.
            if ($varName === 'this') {
                $selfTable = $fileContext['__self__'] ?? null;

                return $selfTable ? ResolutionResult::resolved($selfTable) : ResolutionResult::unresolved();
            }
        }

        // Root is a property fetch: $this->items->where(), $model->relation->where()
        // Property access on Eloquent models returns a loaded Collection (the relation
        // was eagerly loaded), NOT a query builder. Contrast with method call syntax
        // ($model->relation()->where()) which returns a Builder.
        if ($node instanceof Node\Expr\PropertyFetch) {
            // Exception: if we found a relationship method AFTER the property access,
            // the property might just be accessing another model, and the relationship
            // call produces a real query. Example: $this->client->invoices()->where()
            if ($lastRelationCandidate) {
                $table = $this->registry->resolveRelationMethod($lastRelationCandidate, $fileContext);
                if ($table) {
                    return ResolutionResult::resolved($table);
                }
            }

            // No relationship method — pure property access chain is Collection context.
            // Examples: $this->items->where(), $checklist->items->where(), $job->visits->pluck()
            return ResolutionResult::skip();
        }

        return ResolutionResult::unresolved();
    }
}
