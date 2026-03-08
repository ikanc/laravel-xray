<?php

namespace Ikabalzam\LaravelXray\Support;

use Ikabalzam\LaravelXray\Constants;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;

/**
 * Determines whether a method call chain is operating on an Eloquent Collection
 * (in-memory) rather than a Query Builder (database query).
 *
 * THE PROBLEM:
 * Laravel's Collection and Query Builder share many method names — where(), pluck(),
 * sum(), orderBy(), groupBy(), and others. When the schema auditor encounters a call
 * like `->where('status', 'active')`, it needs to know whether that 'status' argument
 * is a database column (Builder context) or an in-memory attribute key (Collection
 * context). Treating Collection operations as database queries produces massive false
 * positives, since attribute access on hydrated models doesn't need to match any
 * specific database column.
 *
 * THE APPROACH:
 * We walk backwards through the method call chain (from the audited call toward the
 * root expression) and apply four detection strategies:
 *
 * 1. **Collection terminal methods** — If the chain passes through a method like
 *    get(), all(), or first(), everything after it operates on the returned Collection
 *    (or Model), not the Builder. Example: `User::where(...)->get()->where(...)` —
 *    the second where() is Collection::where().
 *
 * 2. **Collection-only methods** — Methods like reject(), sortBy(), transform() exist
 *    exclusively on Collection, never on Builder. If any of these appear anywhere in
 *    the chain, the entire chain is a Collection context. These are definitive signals
 *    with zero ambiguity.
 *
 * 3. **PropertyFetch root** — If the chain starts from a property access like
 *    `$model->relation->where(...)`, the property access eagerly loads the relationship
 *    as a Collection. Contrast with `$model->relation()->where(...)` (MethodCall),
 *    which returns a Builder. This distinction is fundamental to how Eloquent works:
 *    property access = Collection, method call = Builder.
 *
 * 4. **collect() function root** — If the chain starts from `collect($array)->where(...)`,
 *    the root is explicitly constructing a Collection. Everything chained after it is
 *    Collection context by definition.
 */
class CollectionDetector
{
    /**
     * Check if a method call chain originates from or passes through a Collection context.
     *
     * Walks backward through the chain from the given method call to the root expression,
     * checking each node against the four detection strategies described above.
     *
     * @param  MethodCall  $call  The method call being audited (e.g., the ->where() node)
     * @return bool True if the chain is operating on a Collection, false if it's a Builder
     */
    public function isCollectionChain(MethodCall $call): bool
    {
        $node = $call->var;

        while ($node instanceof MethodCall) {
            $method = $node->name instanceof Identifier ? $node->name->toString() : null;

            if ($method) {
                // Strategy 1: Chain passes through a Collection terminal method —
                // everything after it operates on the returned Collection/Model.
                if (in_array($method, Constants::COLLECTION_TERMINAL_METHODS)) {
                    return true;
                }

                // Strategy 2: Chain includes a Collection-only method —
                // definitively a Collection context with no Builder ambiguity.
                if (in_array($method, Constants::collectionOnlyMethods())) {
                    return true;
                }
            }

            $node = $node->var;
        }

        // Strategy 3: Root is a property access ($model->relation->where()).
        // Property access on an Eloquent model eagerly loads the relationship
        // as a Collection, unlike method call which returns a Builder.
        if ($node instanceof PropertyFetch) {
            return true;
        }

        // Strategy 4: Root is a collect() function call (collect($array)->where()).
        // Explicitly constructing a Collection from an array.
        if ($node instanceof FuncCall) {
            $funcName = $node->name instanceof Name ? $node->name->toString() : null;
            if ($funcName === 'collect') {
                return true;
            }
        }

        return false;
    }
}
