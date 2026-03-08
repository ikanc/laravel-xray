<?php

namespace Ikabalzam\LaravelXray\Resolvers;

use Ikabalzam\LaravelXray\Constants;
use Ikabalzam\LaravelXray\Support\AstHelpers;
use Ikabalzam\LaravelXray\Support\CollectionDetector;
use Ikabalzam\LaravelXray\Support\ResolutionResult;
use Ikabalzam\LaravelXray\Support\SchemaRegistry;
use PhpParser\Node;

/**
 * Resolves the target table when a column reference is inside a closure argument.
 *
 * CLOSURE CONTEXT IN LARAVEL:
 * Many Eloquent/Builder methods accept closures that receive a Builder instance
 * scoped to a specific table. The table depends on WHAT the closure is an argument to:
 *
 *   - whereHas('posts', fn($q) => ...)       → $q targets the 'posts' table
 *   - ->where(fn($q) => $q->where(...))      → $q targets the SAME table as the parent chain
 *   - ->when($cond, fn($q) => ...)            → $q passes through the parent's builder
 *   - ->with(['posts' => fn($q) => ...])      → $q targets the 'posts' table (eager loading)
 *   - ->each(fn($item) => ...)                → $item is a model instance (Collection), skip
 *
 * WHY THIS EXISTS:
 * Without closure resolution, any column reference inside a closure would be unresolved
 * because the variable ($q, $query, etc.) is a closure parameter — it has no assignment
 * in the enclosing scope. This resolver bridges that gap by analyzing the closure's
 * parent context to determine what Builder the closure parameter represents.
 *
 * ARCHITECTURAL NOTE ON CIRCULAR DEPENDENCIES:
 * This resolver uses ChainResolver for resolving parent method call chains (Case 2),
 * and VariableResolver as a fallback when ChainResolver can't resolve a variable root.
 *
 * The dependency flows ONE WAY: ClosureResolver → VariableResolver (never the reverse).
 * VariableResolver does NOT call ClosureResolver — when VariableResolver encounters a
 * closure parameter it can't resolve, it returns Unresolved and the TableResolver
 * mediator handles the cross-resolution via isClosureParameter() + ClosureResolver.
 * This prevents circular dependency: the dangerous cycle would be
 * VariableResolver → ClosureResolver → VariableResolver, which we avoid by having
 * VariableResolver never call ClosureResolver directly.
 */
class ClosureResolver
{
    public function __construct(
        private readonly SchemaRegistry $registry,
        private readonly CollectionDetector $collectionDetector,
        private readonly ChainResolver $chainResolver,
        private readonly VariableResolver $variableResolver,
    ) {}

    /**
     * Check if the audited call is inside a closure and resolve the table from the closure's context.
     *
     * The algorithm:
     * 1. Walk parent nodes upward to find the enclosing Closure/ArrowFunction
     * 2. Find what that closure is an argument TO (the "closure parent")
     * 3. Based on the closure parent's method name, determine the table:
     *    - Case 1a: Relation constraint methods (whereHas, etc.) → resolve the relation
     *    - Case 1b: Collection iterator methods on Collection chains → Skip
     *    - Case 2:  Builder closure methods (where, orWhere, etc.) → same table as parent chain
     *    - Case 2b: Passthrough methods (when, unless, tap) → same table as parent chain
     *    - Case 3:  Array eager loading (['relation' => fn($q) => ...]) → resolve the relation
     *
     * @param  Node\Expr\MethodCall $call        The method call being audited (inside the closure)
     * @param  array<string, string> $fileContext Map of class short names to table names for the current file
     * @return ResolutionResult Resolved table, Skip (Collection), or Unresolved
     */
    public function resolve(Node\Expr\MethodCall $call, array $fileContext): ResolutionResult
    {
        // Walk up through potentially nested closure levels.
        // In code like:
        //   Comment::where(function ($q) {
        //       $q->where(function ($q2) {
        //           $q2->where('commentable_type', 'Lead');
        //       });
        //   });
        // The inner $q2 is inside TWO nested closures. We try to resolve from
        // the innermost closure first, then walk outward if that fails.
        $node = $call;

        // Safety limit to prevent infinite loops in pathological ASTs
        $maxDepth = 5;

        while ($maxDepth-- > 0) {
            // Find the next enclosing Closure or ArrowFunction above the current node
            $closure = null;
            while ($node = $node->getAttribute('parent')) {
                if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
                    $closure = $node;
                    break;
                }
            }

            if (! $closure) {
                return ResolutionResult::unresolved();
            }

            $result = $this->resolveFromClosure($closure, $fileContext);
            if ($result->isResolved() || $result->isSkip()) {
                return $result;
            }

            // This closure level couldn't resolve. Walk up past it to look for outer closures.
            // This handles nested closures where the inner closure's Builder comes from
            // the outer closure's context.
            $node = $closure;
        }

        return ResolutionResult::unresolved();
    }

    /**
     * Attempt to resolve the table from a single closure level.
     *
     * Given a closure node, determines what the closure is an argument TO and uses
     * that context to resolve the table.
     *
     * @param  Node\Expr\Closure|Node\Expr\ArrowFunction $closure     The closure node
     * @param  array<string, string>                     $fileContext  Map of class short names to table names
     * @return ResolutionResult Resolved table, Skip (Collection), or Unresolved
     */
    private function resolveFromClosure(
        Node\Expr\Closure|Node\Expr\ArrowFunction $closure,
        array $fileContext,
    ): ResolutionResult {
        // Find what this closure is an argument to.
        $closureParent = $closure->getAttribute('parent');

        // Unwrap the Arg wrapper if present
        if ($closureParent instanceof Node\Arg) {
            $closureParent = $closureParent->getAttribute('parent');
        }

        if (! $closureParent) {
            return ResolutionResult::unresolved();
        }

        // Determine the parent method name (works for both MethodCall and StaticCall)
        $parentMethod = null;
        if ($closureParent instanceof Node\Expr\MethodCall || $closureParent instanceof Node\Expr\StaticCall) {
            $parentMethod = $closureParent->name instanceof Node\Identifier
                ? $closureParent->name->toString()
                : null;
        }

        if ($parentMethod) {
            // -----------------------------------------------------------------
            // Case 1a: Relation constraint methods
            // whereHas('relation', fn($q) => ...), orWhereHas, whereDoesntHave, etc.
            //
            // The first string argument is the relationship name. We resolve it
            // to the related model's table. Handles dot notation ('job.visits' → 'visits')
            // and both MethodCall and StaticCall parents.
            // -----------------------------------------------------------------
            if (in_array($parentMethod, Constants::RELATION_CONSTRAINT_METHODS)) {
                $relationName = AstHelpers::extractFirstStringArg($closureParent);
                if ($relationName) {
                    // Handle dot notation: 'job.visits' → resolve only the final segment.
                    // In whereHas('job.visits', fn($q) => ...), the closure's $q targets
                    // the 'visits' table (the deepest relation in the dot chain).
                    if (str_contains($relationName, '.')) {
                        $parts = explode('.', $relationName);
                        $relationName = end($parts);
                    }

                    // For static calls like Model::whereHas('posts', fn($q) => ...),
                    // we need to resolve the Model first, then look up its relationships.
                    // This is different from instance calls where we use fileContext.
                    if ($closureParent instanceof Node\Expr\StaticCall) {
                        $staticTable = $this->registry->resolveStaticCallTable($closureParent, $fileContext);
                        if ($staticTable) {
                            $modelMap = $this->registry->getModelMap();
                            $modelClass = array_search($staticTable, $modelMap);
                            if ($modelClass) {
                                $relationshipMap = $this->registry->getRelationshipMap();
                                if (isset($relationshipMap[$modelClass][$relationName])) {
                                    $relatedClass = $relationshipMap[$modelClass][$relationName];
                                    $relatedTable = $modelMap[$relatedClass] ?? null;

                                    return $relatedTable
                                        ? ResolutionResult::resolved($relatedTable)
                                        : ResolutionResult::unresolved();
                                }
                            }
                        }
                    }

                    // For instance calls, resolve through the file context's relationship map
                    $table = $this->registry->resolveRelationMethod($relationName, $fileContext);

                    return $table ? ResolutionResult::resolved($table) : ResolutionResult::unresolved();
                }
            }

            // -----------------------------------------------------------------
            // Case 1b: Collection iterator methods on Collection chains
            // ->each(fn($item) => ...), ->map(fn($item) => ...), ->filter(fn($item) => ...)
            //
            // When these methods are called on a Collection chain, the closure
            // parameter is an individual model instance, not a Builder. Column
            // references inside are attribute access, not SQL column references.
            //
            // We only skip when the parent call is actually on a Collection chain
            // (verified by CollectionDetector). These methods also exist in non-Collection
            // contexts (e.g., Builder::each() for chunking), so we can't blindly skip.
            // -----------------------------------------------------------------
            if (in_array($parentMethod, Constants::COLLECTION_ITERATOR_METHODS)) {
                if ($closureParent instanceof Node\Expr\MethodCall
                    && $this->collectionDetector->isCollectionChain($closureParent)) {
                    return ResolutionResult::skip();
                }
            }

            // -----------------------------------------------------------------
            // Case 2: Builder closure methods (where, orWhere, having, orHaving)
            // ->where(function($q) { $q->where('col', val); })
            //
            // The closure receives the SAME Builder as the parent call. So to resolve
            // the table, we need to resolve what table the parent call itself operates on.
            //
            // For StaticCall parents: Model::where(fn($q) => ...) → resolve Model directly
            // For MethodCall parents: ->where(fn($q) => ...) → walk the parent's chain
            // -----------------------------------------------------------------
            if (in_array($parentMethod, Constants::BUILDER_CLOSURE_METHODS)) {
                return $this->resolveParentCallTable($closureParent, $fileContext);
            }

            // -----------------------------------------------------------------
            // Case 2b: Builder passthrough methods (when, unless, tap)
            // ->when($condition, function($q) { $q->where('col', val); })
            //
            // These conditionally execute the closure with the same Builder.
            // Resolution logic is identical to Case 2 for MethodCall parents.
            // -----------------------------------------------------------------
            if (in_array($parentMethod, Constants::BUILDER_PASSTHROUGH_METHODS)
                && $closureParent instanceof Node\Expr\MethodCall) {
                return $this->resolveParentCallTable($closureParent, $fileContext);
            }
        }

        // -----------------------------------------------------------------
        // Case 3: Array eager loading pattern
        // ->with(['relation' => function($q) { $q->where('col', val); }])
        // ->withCount(['relation' => function($q) { ... }])
        //
        // The closure is a value in an array, where the key is the relationship name.
        // The closure's $q parameter is a Builder scoped to that relationship's table.
        //
        // Also handles 'as' aliases: 'payments as total' → 'payments'
        // And dot notation: 'job.visits' → 'visits'
        // -----------------------------------------------------------------
        if ($closureParent instanceof Node\Expr\ArrayItem) {
            $key = $closureParent->key;
            if ($key instanceof Node\Scalar\String_) {
                $relationName = $key->value;

                // Strip 'as alias' suffix: 'payments as total' → 'payments'
                if (str_contains($relationName, ' as ')) {
                    $relationName = trim(explode(' as ', $relationName)[0]);
                }

                // Handle dot notation: 'job.visits' → 'visits'
                if (str_contains($relationName, '.')) {
                    $parts = explode('.', $relationName);
                    $relationName = end($parts);
                }

                $table = $this->registry->resolveRelationMethod($relationName, $fileContext);

                return $table ? ResolutionResult::resolved($table) : ResolutionResult::unresolved();
            }
        }

        return ResolutionResult::unresolved();
    }

    /**
     * Resolve the table that a parent call (containing a closure argument) operates on.
     *
     * For StaticCall parents (e.g., Model::where(fn($q) => ...)):
     *   → resolve directly via SchemaRegistry
     *
     * For MethodCall parents (e.g., $query->where(fn($q) => ...)):
     *   → use ChainResolver to walk the parent's own method chain
     *   → if ChainResolver can't resolve it, return Unresolved (TableResolver will
     *     try VariableResolver as a fallback — we don't call it here to avoid circular deps)
     *
     * @param  Node\Expr\MethodCall|Node\Expr\StaticCall $parentCall The call that the closure is an argument to
     * @param  array<string, string>                     $fileContext
     * @return ResolutionResult
     */
    private function resolveParentCallTable(
        Node\Expr\MethodCall|Node\Expr\StaticCall $parentCall,
        array $fileContext,
    ): ResolutionResult {
        if ($parentCall instanceof Node\Expr\StaticCall) {
            $table = $this->registry->resolveStaticCallTable($parentCall, $fileContext);

            return $table ? ResolutionResult::resolved($table) : ResolutionResult::unresolved();
        }

        // MethodCall parent — walk its chain via ChainResolver.
        // This handles cases like: $this->posts()->where(fn($q) => $q->where('title', ...))
        // ChainResolver will walk $this->posts()->where() and resolve to the 'posts' table.
        $result = $this->chainResolver->resolve($parentCall, $fileContext);
        if ($result->isResolved() || $result->isSkip()) {
            return $result;
        }

        // ChainResolver couldn't resolve — try VariableResolver as a fallback.
        // This handles the common pattern where a closure is inside a method call
        // on a variable that was assigned from a static call:
        //   $query = Lead::where('company_id', $id);
        //   $query->where(function($q) { $q->where('status', 'active'); });
        //
        // ChainResolver walks to $query but can't resolve variables. VariableResolver
        // traces $query's assignment back to Lead::where() → 'leads' table.
        //
        // NOTE: This does NOT create a circular dependency. VariableResolver→resolve()
        // does not call ClosureResolver. It calls resolveVariableAssignment() and
        // resolveFromMethodParameter(), neither of which invoke ClosureResolver.
        // The circular dep only exists in the other direction (which is why
        // VariableResolver does NOT depend on ClosureResolver — the mediation
        // for that direction goes through TableResolver).
        $result = $this->variableResolver->resolve($parentCall, $fileContext);
        if ($result->isResolved() || $result->isSkip()) {
            return $result;
        }

        return ResolutionResult::unresolved();
    }
}
