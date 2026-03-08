<?php

namespace Ikabalzam\LaravelVision\Resolvers;

use Ikabalzam\LaravelVision\Constants;
use Ikabalzam\LaravelVision\Support\AstHelpers;
use Ikabalzam\LaravelVision\Support\ResolutionResult;
use Ikabalzam\LaravelVision\Support\SchemaRegistry;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Tracks variable assignments and method parameters to determine table context.
 *
 * VARIABLE TRACKING IN PHP:
 * When the audited call is on a variable ($query->where('col', ...)), we need to
 * determine what that variable holds. PHP is dynamically typed, so the only way
 * to know is by analyzing the variable's assignment(s) in the enclosing scope.
 *
 * This resolver handles several patterns:
 *
 * 1. **Direct assignment from relationship**: `$query = $this->posts()` → 'posts' table
 * 2. **Assignment from static call**: `$query = User::where(...)` → 'users' table
 * 3. **Assignment from Collection**: `$items = $this->posts` → Collection (Skip)
 * 4. **Self-referencing reassignment**: `$query = $query->where(...)` → trace back
 * 5. **Transitive variable**: `$q = $otherVar->where(...)` → resolve $otherVar
 * 6. **Method parameters**: `scopeActive($query)` → self table for scope params
 * 7. **Type-hinted parameters**: `function foo(Builder $query)` → self table
 * 8. **Collection parameters**: `function foo(Collection $items)` → Skip
 *
 * WHY THIS EXISTS:
 * Variables break the direct chain between a model/static call and the audited method.
 * Without variable tracking, code like:
 *   $query = Lead::where('active', true);
 *   $query->orderBy('created_at');  // Can't resolve without knowing what $query is
 * would produce unresolved references. This resolver bridges that gap.
 *
 * ARCHITECTURAL NOTE:
 * This resolver does NOT call ClosureResolver directly. When a variable turns out
 * to be a closure parameter, this resolver signals that fact (via isClosureParameter())
 * and the TableResolver mediator handles the cross-resolution. This prevents circular
 * dependency: VariableResolver → ClosureResolver → ChainResolver → (potentially back).
 */
class VariableResolver
{
    public function __construct(
        private readonly SchemaRegistry $registry,
    ) {}

    /**
     * Main entry point: resolve the table from the variable a method is called on.
     *
     * Extracts the root variable from the call chain, then tries:
     * 1. $this → self table from fileContext
     * 2. Variable assignment tracking → trace what the variable was assigned from
     * 3. Method parameter checking → scope params, Builder type hints, Collection type hints
     *
     * NOTE: This method does NOT check closure parameters. That check is deferred
     * to the TableResolver mediator via isClosureParameter() to avoid circular deps.
     *
     * @param  Node\Expr\MethodCall $call        The method call being audited
     * @param  array<string, string> $fileContext Map of class short names to table names for the current file
     * @return ResolutionResult Resolved table, Skip (Collection), or Unresolved
     */
    public function resolve(Node\Expr\MethodCall $call, array $fileContext): ResolutionResult
    {
        $varNode = AstHelpers::getRootVariable($call);
        if (! $varNode || ! is_string($varNode->name)) {
            return ResolutionResult::unresolved();
        }

        $varName = $varNode->name;

        // $this in a model file — the query operates on this model's own table.
        if ($varName === 'this') {
            $selfTable = $fileContext['__self__'] ?? null;

            return $selfTable ? ResolutionResult::resolved($selfTable) : ResolutionResult::unresolved();
        }

        // Try to resolve from variable assignment in the enclosing scope.
        // This handles patterns like:
        //   $query = $this->posts();           → 'posts' table
        //   $items = $model->collection;       → Collection (Skip)
        //   $q = User::where('active', true);  → 'users' table
        $assignResult = $this->resolveVariableAssignment($varNode, $fileContext);
        if ($assignResult->isSkip()) {
            return ResolutionResult::skip();
        }
        if ($assignResult->isResolved()) {
            return $assignResult;
        }

        // Try to resolve from method parameter type.
        // Handles scope parameters, Builder type hints, Collection type hints.
        $paramResult = $this->resolveFromMethodParameter($varNode, $fileContext);
        if ($paramResult->isResolved() || $paramResult->isSkip()) {
            return $paramResult;
        }

        return ResolutionResult::unresolved();
    }

    /**
     * Check if a variable is a parameter of a Closure or ArrowFunction.
     *
     * This is used by TableResolver to decide whether to try ClosureResolver
     * as a follow-up when VariableResolver returns Unresolved. We can't call
     * ClosureResolver directly from here (circular dependency), so we expose
     * this check for the mediator to use.
     *
     * @param  Node\Expr\Variable $var The variable to check
     * @return bool True if the variable is a closure/arrow function parameter
     */
    public function isClosureParameter(Node\Expr\Variable $var): bool
    {
        $varName = $var->name;
        if (! is_string($varName)) {
            return false;
        }

        // Walk up parent nodes to find enclosing Closure/ArrowFunction
        $node = $var;
        $closure = null;
        while ($node = $node->getAttribute('parent')) {
            if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
                $closure = $node;
                break;
            }
        }

        if (! $closure) {
            return false;
        }

        // Check if this variable matches one of the closure's parameters
        foreach ($closure->params as $param) {
            if ($param->var instanceof Node\Expr\Variable && $param->var->name === $varName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scan the enclosing scope for assignments to this variable and resolve the table.
     *
     * Finds the LAST assignment to the variable BEFORE the usage line (to handle
     * reassignments correctly), then analyzes the right-hand side (RHS) of the
     * assignment to determine the table:
     *
     * - PropertyFetch RHS ($var = $this->relation) → Collection (Skip)
     * - MethodCall RHS → walk the chain to find static call root, $this root, etc.
     * - StaticCall RHS ($var = Model::where()) → resolve via SchemaRegistry
     * - Self-referencing ($var = $var->method()) → trace back to previous assignment
     * - Transitive ($var = $otherVar->method()) → recursively resolve $otherVar
     * - collect() root → Collection (Skip)
     *
     * @param  Node\Expr\Variable   $var         The variable to trace
     * @param  array<string, string> $fileContext Map of class short names to table names
     * @return ResolutionResult Resolved table, Skip (Collection), or Unresolved
     */
    public function resolveVariableAssignment(Node\Expr\Variable $var, array $fileContext): ResolutionResult
    {
        $varName = $var->name;
        if (! is_string($varName)) {
            return ResolutionResult::unresolved();
        }

        // Walk up to find the enclosing function/method/closure body that defines
        // the variable's scope. In PHP, variables are scoped to their enclosing
        // function — a variable assigned in a method isn't visible in a nested closure
        // unless it's explicitly passed via `use`.
        $node = $var;
        $scope = null;
        while ($node = $node->getAttribute('parent')) {
            if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_
                || $node instanceof Node\Expr\Closure) {
                $scope = $node;
                break;
            }
        }

        if (! $scope) {
            return ResolutionResult::unresolved();
        }

        // Search the scope for assignments to this variable.
        // Use the LAST assignment BEFORE the usage line to handle reassignments correctly.
        // Example:
        //   $query = Lead::query();          // line 10 — initial assignment
        //   $query = $query->where(...);     // line 11 — reassignment
        //   $query->orderBy('name');         // line 12 — usage
        // At line 12, we want the assignment from line 11 (the most recent before usage).
        $finder = new NodeFinder;
        $assigns = $finder->findInstanceOf([$scope], Node\Expr\Assign::class);

        $usageLine = $var->getStartLine();
        $bestAssign = null;
        foreach ($assigns as $assign) {
            if (! ($assign->var instanceof Node\Expr\Variable) || $assign->var->name !== $varName) {
                continue;
            }
            $assignLine = $assign->getStartLine();
            if ($assignLine <= $usageLine) {
                // Skip match expression assignments: `$query = match($type) { ... $query->where() ... }`
                // The code inside match arms references the PREVIOUS $query value, not the match result.
                // If we pick the match as the best assignment, we'd try to resolve Match_ which we can't handle.
                if ($assign->expr instanceof Node\Expr\Match_) {
                    continue;
                }

                $bestAssign = $assign;
            }
        }

        if (! $bestAssign) {
            return ResolutionResult::unresolved();
        }

        $rhs = $bestAssign->expr;

        // $var = $this->relation (property access → loaded Collection, not Builder)
        if ($rhs instanceof Node\Expr\PropertyFetch) {
            return ResolutionResult::skip();
        }

        // $var = $something->method() or $var = $something->method1()->method2()
        if ($rhs instanceof Node\Expr\MethodCall) {
            return $this->resolveMethodCallAssignment($rhs, $var, $bestAssign, $assigns, $fileContext);
        }

        // $var = Model::where(...) or $var = Model::query()
        if ($rhs instanceof Node\Expr\StaticCall) {
            $table = $this->registry->resolveStaticCallTable($rhs, $fileContext);

            return $table ? ResolutionResult::resolved($table) : ResolutionResult::unresolved();
        }

        return ResolutionResult::unresolved();
    }

    /**
     * Resolve the table from a method parameter's type or position.
     *
     * Checks whether a variable is a parameter of the enclosing ClassMethod, and
     * if so, determines the table based on:
     *
     * 1. **Scope method first parameter**: In `scopeActive($query)`, the first param
     *    is always a Builder for the model's own table. This is an Eloquent convention.
     *
     * 2. **Builder type hint**: `function foo(Builder $query)` in a model file →
     *    likely this model's builder, resolve to self table.
     *
     * 3. **Collection type hint**: `function foo(Collection $items)` → Skip.
     *    The variable is an in-memory Collection, not a query builder.
     *
     * NOTE: If the variable is inside a Closure/ArrowFunction, we bail out early.
     * Closure parameters are handled separately by ClosureResolver (via TableResolver
     * mediation). This prevents confusing a closure param with a method param.
     *
     * @param  Node\Expr\Variable   $var         The variable to check
     * @param  array<string, string> $fileContext Map of class short names to table names
     * @return ResolutionResult Resolved table, Skip (Collection), or Unresolved
     */
    public function resolveFromMethodParameter(Node\Expr\Variable $var, array $fileContext): ResolutionResult
    {
        $varName = $var->name;
        if (! is_string($varName)) {
            return ResolutionResult::unresolved();
        }

        // Walk up to find the enclosing ClassMethod
        $node = $var;
        $method = null;
        while ($node = $node->getAttribute('parent')) {
            if ($node instanceof Node\Stmt\ClassMethod) {
                $method = $node;
                break;
            }
            // If we hit a Closure/ArrowFunction before a ClassMethod, this variable
            // is a closure parameter, not a method parameter. Bail out — closure
            // parameter resolution is handled by ClosureResolver via the mediator.
            if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
                return ResolutionResult::unresolved();
            }
        }

        if (! $method) {
            return ResolutionResult::unresolved();
        }

        // Check if this variable is one of the method's parameters
        $matchedParam = null;
        foreach ($method->params as $param) {
            if ($param->var instanceof Node\Expr\Variable && $param->var->name === $varName) {
                $matchedParam = $param;
                break;
            }
        }

        if (! $matchedParam) {
            return ResolutionResult::unresolved();
        }

        // If we're in a model file, check for Eloquent scope convention and Builder type hints
        $selfTable = $fileContext['__self__'] ?? null;
        if ($selfTable) {
            $methodName = $method->name->toString();

            // Eloquent scopes: scopeActive($query), scopeForCompany($query, $companyId)
            // The first parameter of any scope method is always a Builder for this model.
            if (str_starts_with($methodName, 'scope')) {
                $firstParam = $method->params[0] ?? null;
                if ($firstParam && $firstParam->var instanceof Node\Expr\Variable
                    && $firstParam->var->name === $varName) {
                    return ResolutionResult::resolved($selfTable);
                }
            }

            // Type-hinted Builder parameter in a model method → likely this model's builder.
            // Example: public function applyFilters(Builder $query) { $query->where(...); }
            if ($matchedParam->type instanceof Node\Name) {
                $typeName = $matchedParam->type->toString();
                if (str_contains($typeName, 'Builder') || str_contains($typeName, 'QueryBuilder')) {
                    return ResolutionResult::resolved($selfTable);
                }
            }
        }

        // Type-hinted Collection parameter → this variable is an in-memory Collection.
        // Column references on it are attribute access, not SQL columns.
        if ($matchedParam->type instanceof Node\Name) {
            $typeName = $matchedParam->type->toString();
            if (str_contains($typeName, 'Collection') || str_contains($typeName, 'EloquentCollection')) {
                return ResolutionResult::skip();
            }
        }

        return ResolutionResult::unresolved();
    }

    /**
     * Resolve the table from a MethodCall on the RHS of a variable assignment.
     *
     * Handles the complex case where $var is assigned from a method call chain:
     *   $var = $this->posts()->where('active', true);
     *   $var = User::where('status', 'active')->orderBy('name');
     *   $var = $otherVar->where('col', val);
     *   $var = $var->where('additional_filter', val);  // self-referencing
     *
     * Walks the chain backward to find the root, then resolves based on root type.
     *
     * @param  Node\Expr\MethodCall  $rhs         The RHS method call expression
     * @param  Node\Expr\Variable    $var         The variable being assigned to
     * @param  Node\Expr\Assign      $bestAssign  The assignment node (for line number tracking)
     * @param  array                 $assigns     All assignments in scope (for self-reference tracing)
     * @param  array<string, string> $fileContext  Map of class short names to table names
     * @return ResolutionResult
     */
    private function resolveMethodCallAssignment(
        Node\Expr\MethodCall $rhs,
        Node\Expr\Variable $var,
        Node\Expr\Assign $bestAssign,
        array $assigns,
        array $fileContext,
    ): ResolutionResult {
        $varName = $var->name;
        $method = $rhs->name instanceof Node\Identifier ? $rhs->name->toString() : null;

        // If the terminal method is a Collection terminal (get(), all(), first(), etc.),
        // the result is a Collection/Model, not a Builder.
        if ($method && in_array($method, Constants::COLLECTION_TERMINAL_METHODS)) {
            return ResolutionResult::skip();
        }

        // Walk the method call chain to find its root expression and track
        // potential relationship candidates along the way.
        $chainNode = $rhs;
        $lastCandidate = null;
        while ($chainNode instanceof Node\Expr\MethodCall) {
            $cm = $chainNode->name instanceof Node\Identifier ? $chainNode->name->toString() : null;
            if ($cm && ! in_array($cm, Constants::nonRelationMethods())
                && ! in_array($cm, Constants::COLLECTION_TERMINAL_METHODS)) {
                $lastCandidate = $cm;
            }
            $chainNode = $chainNode->var;
        }

        // Root is a static call: $var = Model::where()->orderBy()
        if ($chainNode instanceof Node\Expr\StaticCall) {
            $table = $this->registry->resolveStaticCallTable($chainNode, $fileContext);

            return $table ? ResolutionResult::resolved($table) : ResolutionResult::unresolved();
        }

        // Root is $this with a relationship: $var = $this->posts()->where()
        if ($chainNode instanceof Node\Expr\Variable && $chainNode->name === 'this' && $lastCandidate) {
            $table = $this->registry->resolveRelationMethod($lastCandidate, $fileContext);
            if ($table) {
                return ResolutionResult::resolved($table);
            }
        }

        // Root is $this without relationship: $var = $this->newQuery()
        if ($chainNode instanceof Node\Expr\Variable && $chainNode->name === 'this') {
            $selfTable = $fileContext['__self__'] ?? null;

            return $selfTable ? ResolutionResult::resolved($selfTable) : ResolutionResult::unresolved();
        }

        // Root is property access: $var = $this->items->filter()
        // Property access returns a Collection.
        if ($chainNode instanceof Node\Expr\PropertyFetch) {
            return ResolutionResult::skip();
        }

        // Relationship call on another variable: $var = $company->leads()
        if ($lastCandidate) {
            $table = $this->registry->resolveRelationMethod($lastCandidate, $fileContext);
            if ($table) {
                return ResolutionResult::resolved($table);
            }
        }

        // Self-referencing reassignment: $var = $var->whereIn('status', [...])
        // The chain root is the same variable being assigned. We need to look at
        // the PREVIOUS assignment to find the original source.
        if ($chainNode instanceof Node\Expr\Variable && is_string($chainNode->name)
            && $chainNode->name === $varName) {
            return $this->resolveSelfReferencingAssignment($bestAssign, $assigns, $varName, $fileContext);
        }

        // Root is another variable: $var = $otherVar->where()
        // Recursively resolve $otherVar to find its table.
        if ($chainNode instanceof Node\Expr\Variable && is_string($chainNode->name)
            && $chainNode->name !== $varName && $chainNode->name !== 'this') {
            return $this->resolveTransitiveVariable($chainNode, $fileContext);
        }

        // Root is collect() function call: $var = collect($data)->where()
        if ($chainNode instanceof Node\Expr\FuncCall) {
            $funcName = $chainNode->name instanceof Node\Name ? $chainNode->name->toString() : null;
            if ($funcName === 'collect') {
                return ResolutionResult::skip();
            }
        }

        return ResolutionResult::unresolved();
    }

    /**
     * Handle self-referencing reassignment: $var = $var->method()
     *
     * When a variable is reassigned from itself ($query = $query->where(...)),
     * we trace back to the PREVIOUS assignment to find the original source.
     * The previous assignment is fully resolved — not just checked for Collection
     * indicators — because the original assignment could be a Model::query(),
     * $this->relation(), or any other resolvable pattern.
     *
     * Example chain:
     *   $query = Lead::query();             // line 10 — original (StaticCall)
     *   $query = $query->where('active');   // line 11 — self-referencing
     *   $query = $query->orderBy('name');   // line 12 — self-referencing again
     *   $query->select('email');            // line 13 — audited call
     *
     * At line 13, bestAssign is line 12 (self-ref). We trace back to line 11 (self-ref),
     * then to line 10 (Lead::query()), which resolves to 'leads' table.
     *
     * @param  Node\Expr\Assign      $currentAssign The current (self-referencing) assignment
     * @param  array                  $allAssigns    All assignments in scope
     * @param  string                 $varName       The variable name
     * @param  array<string, string>  $fileContext
     * @return ResolutionResult
     */
    private function resolveSelfReferencingAssignment(
        Node\Expr\Assign $currentAssign,
        array $allAssigns,
        string $varName,
        array $fileContext,
    ): ResolutionResult {
        $assignLine = $currentAssign->getStartLine();
        $prevAssign = null;

        foreach ($allAssigns as $a) {
            if (! ($a->var instanceof Node\Expr\Variable) || $a->var->name !== $varName) {
                continue;
            }
            $aLine = $a->getStartLine();
            if ($aLine < $assignLine) {
                $prevAssign = $a;
            }
        }

        if (! $prevAssign) {
            return ResolutionResult::unresolved();
        }

        $rhs = $prevAssign->expr;

        // PropertyFetch → Collection (e.g., $items = $this->relation)
        if ($rhs instanceof Node\Expr\PropertyFetch) {
            return ResolutionResult::skip();
        }

        // MethodCall → full chain resolution (handles nested self-refs recursively)
        if ($rhs instanceof Node\Expr\MethodCall) {
            return $this->resolveMethodCallAssignment($rhs, $prevAssign->var, $prevAssign, $allAssigns, $fileContext);
        }

        // StaticCall → resolve directly (e.g., $query = Model::query())
        if ($rhs instanceof Node\Expr\StaticCall) {
            $table = $this->registry->resolveStaticCallTable($rhs, $fileContext);

            return $table ? ResolutionResult::resolved($table) : ResolutionResult::unresolved();
        }

        // collect() → Collection
        if ($rhs instanceof Node\Expr\FuncCall) {
            $fn = $rhs->name instanceof Node\Name ? $rhs->name->toString() : null;
            if ($fn === 'collect') {
                return ResolutionResult::skip();
            }
        }

        return ResolutionResult::unresolved();
    }

    /**
     * Resolve a transitive variable: $var = $otherVar->method()
     *
     * When the chain root is another variable, recursively resolve that variable's
     * assignment to find the original table. Also checks if the root variable is
     * a method parameter with a type hint.
     *
     * @param  Node\Expr\Variable    $rootVar     The root variable to resolve
     * @param  array<string, string> $fileContext
     * @return ResolutionResult
     */
    private function resolveTransitiveVariable(
        Node\Expr\Variable $rootVar,
        array $fileContext,
    ): ResolutionResult {
        // Recursively resolve the other variable's assignment
        $innerResult = $this->resolveVariableAssignment($rootVar, $fileContext);
        if ($innerResult->isResolved() || $innerResult->isSkip()) {
            return $innerResult;
        }

        // If no assignment found, the root var might be a method parameter.
        // Example: function foo(Collection $leads) { $claimed = $leads->whereNotNull(...); }
        $paramResult = $this->resolveFromMethodParameter($rootVar, $fileContext);
        if ($paramResult->isResolved() || $paramResult->isSkip()) {
            return $paramResult;
        }

        return ResolutionResult::unresolved();
    }
}
