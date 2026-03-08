<?php

namespace Ikabalzam\LaravelXray\Resolvers;

use Ikabalzam\LaravelXray\Support\AstHelpers;
use Ikabalzam\LaravelXray\Support\CollectionDetector;
use Ikabalzam\LaravelXray\Support\ResolutionResult;
use PhpParser\Node;

/**
 * Main resolution orchestrator — determines which database table a column reference belongs to.
 *
 * ROLE:
 * This is the single entry point for table resolution. When the auditor encounters a
 * method call like `->where('status', 'active')`, it calls TableResolver::resolve()
 * which tries multiple strategies in order of confidence, returning as soon as one succeeds.
 *
 * STRATEGY ORDER (highest confidence first):
 *
 * 1. **Early Collection check** — If the chain clearly originates from a Collection
 *    (property access root, collect() root, Collection-terminal/only methods in chain),
 *    skip immediately. No point running expensive resolution logic.
 *
 * 2. **ChainResolver** — Walk the method chain backward to find the root model/table.
 *    Handles: Model::where(), $this->relation()->where(), $this->hasMany(X::class)->...
 *    This is the highest-signal strategy — if the chain starts from a known model or
 *    static call, we can resolve with near certainty.
 *
 * 3. **ClosureResolver** — Check if we're inside a closure argument to whereHas, with,
 *    where(fn), when(fn), etc. The closure's Builder is scoped to a table determined
 *    by the closure's parent context.
 *
 * 4. **VariableResolver** — Track variable assignments and method parameters to find
 *    the table. Handles scope parameters ($query), Builder type hints, and variables
 *    assigned from relationship calls.
 *
 * 5. **Cross-resolver mediation** — If VariableResolver identifies that the variable is
 *    a closure parameter (but can't resolve it alone), we feed it back to ClosureResolver.
 *    This is the key pattern that breaks the circular dependency: VariableResolver and
 *    ClosureResolver never call each other directly. Instead, TableResolver coordinates
 *    the handoff.
 *
 * ARCHITECTURAL NOTE ON CIRCULAR DEPENDENCY PREVENTION:
 * The original monolithic code had `resolveFromVariable()` calling `resolveClosureParameter()`
 * which called `resolveFromClosureAncestor()` which called `resolveChainVariableOrParameter()`
 * which could call back to `resolveVariableAssignment()`. This worked as recursive method
 * calls on one object, but when split into separate classes it would create:
 *   VariableResolver → ClosureResolver → ChainResolver → VariableResolver (circular!)
 *
 * The solution: VariableResolver exposes `isClosureParameter()` as a diagnostic check,
 * and TableResolver uses it to decide whether to try ClosureResolver as a follow-up.
 * Neither VariableResolver nor ClosureResolver reference each other.
 */
class TableResolver
{
    public function __construct(
        private readonly ChainResolver $chainResolver,
        private readonly ClosureResolver $closureResolver,
        private readonly VariableResolver $variableResolver,
        private readonly CollectionDetector $collectionDetector,
    ) {}

    /**
     * Resolve the database table that a column reference belongs to.
     *
     * Tries strategies in order of confidence, returning as soon as one produces
     * a definitive result (either Resolved or Skip). Returns Unresolved only when
     * all strategies have been exhausted.
     *
     * @param  Node\Expr\MethodCall $call        The method call being audited (e.g., ->where('col', val))
     * @param  array<string, string> $fileContext Map of class short names to table names for the current file.
     *                                            Special key '__self__' holds the table for the class defined in this file.
     * @return ResolutionResult One of:
     *         - Resolved: table name identified, validate the column against it
     *         - Skip: reference is on a Collection, not a query builder — ignore it
     *         - Unresolved: could not determine the table — track as unresolved reference
     */
    public function resolve(Node\Expr\MethodCall $call, array $fileContext): ResolutionResult
    {
        // -----------------------------------------------------------------
        // Early exit: Collection detection
        //
        // Before running any resolution logic, check if the method chain
        // clearly originates from a Collection context. This catches the
        // most common false-positive patterns:
        //   - $this->items->where()      (PropertyFetch root)
        //   - $model->get()->where()     (Collection terminal in chain)
        //   - $list->reject()->where()   (Collection-only method in chain)
        //   - collect($data)->where()    (collect() function root)
        //
        // This is a cheap check that prevents expensive chain/closure/variable
        // resolution for patterns that will always be Collections.
        // -----------------------------------------------------------------
        if ($this->collectionDetector->isCollectionChain($call)) {
            return ResolutionResult::skip();
        }

        // -----------------------------------------------------------------
        // Strategy 1: Chain resolution
        //
        // Walk the method chain backward to find the root model/table.
        // This is the highest-confidence strategy — if the chain starts
        // from Model::where(), $this->relation(), or $this->hasMany(X::class),
        // we can determine the table with near certainty.
        // -----------------------------------------------------------------
        $result = $this->chainResolver->resolve($call, $fileContext);
        if ($result->isResolved() || $result->isSkip()) {
            return $result;
        }

        // -----------------------------------------------------------------
        // Strategy 2: Closure context resolution
        //
        // Check if the audited call is inside a closure argument. Laravel
        // extensively uses closures for query scoping:
        //   - whereHas('posts', fn($q) => $q->where('title', ...))
        //   - ->where(fn($q) => $q->where('status', ...))
        //   - ->with(['posts' => fn($q) => $q->orderBy('date')])
        //
        // The closure's Builder is scoped to a table determined by what
        // method the closure is an argument to.
        // -----------------------------------------------------------------
        $result = $this->closureResolver->resolve($call, $fileContext);
        if ($result->isResolved() || $result->isSkip()) {
            return $result;
        }

        // -----------------------------------------------------------------
        // Strategy 3: Variable tracking
        //
        // If the call is on a variable ($query->where(...)), trace the
        // variable's assignment to find the table. Handles:
        //   - $query = $this->posts();     → relationship assignment
        //   - $query = User::where(...);   → static call assignment
        //   - scopeActive($query)          → scope parameter (self table)
        //   - function foo(Builder $q)     → type-hinted parameter
        // -----------------------------------------------------------------
        $result = $this->variableResolver->resolve($call, $fileContext);
        if ($result->isResolved() || $result->isSkip()) {
            return $result;
        }

        // -----------------------------------------------------------------
        // Strategy 4: Cross-resolver mediation (closure parameter fallback)
        //
        // If VariableResolver couldn't resolve and the variable turns out to
        // be a closure parameter, we should try ClosureResolver. This handles
        // nested closures where the inner closure's variable is a parameter
        // that comes from the outer closure's context:
        //
        //   Model::whereHas('posts', function($q) {
        //       $q->where(function($inner) {  // $inner is a closure param
        //           $inner->where('title', 'Hello');  // should resolve to 'posts' table
        //       });
        //   });
        //
        // VariableResolver can't resolve $inner (no assignment, not a method param).
        // But isClosureParameter() tells us it's a closure param, so we ask
        // ClosureResolver to figure out what that closure is scoped to.
        //
        // This is the mediation pattern that prevents circular dependencies:
        // VariableResolver and ClosureResolver never call each other directly.
        // -----------------------------------------------------------------
        $rootVar = AstHelpers::getRootVariable($call);
        if ($rootVar && is_string($rootVar->name) && $rootVar->name !== 'this') {
            if ($this->variableResolver->isClosureParameter($rootVar)) {
                $result = $this->closureResolver->resolve($call, $fileContext);
                if ($result->isResolved() || $result->isSkip()) {
                    return $result;
                }
            }
        }

        // All strategies exhausted — we cannot determine the table.
        // The auditor will track this as an unresolved reference, which can
        // be surfaced to the user with --show-unresolved for investigation.
        return ResolutionResult::unresolved();
    }
}
