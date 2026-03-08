<?php

namespace Ikabalzam\LaravelVision\Support;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/**
 * Static utility class for common AST (Abstract Syntax Tree) operations
 * used throughout the schema audit tool.
 *
 * These helpers are stateless and operate purely on PhpParser node structures.
 * They are extracted from the monolithic AuditDatabaseColumns command to enable
 * reuse across multiple audit passes and sub-analyzers.
 */
final class AstHelpers
{
    /**
     * Walk down a method chain to find the root variable at the bottom.
     *
     * Given an expression like `$this->relation()->where()->orderBy()`, this
     * traverses the `->var` property of each MethodCall/PropertyFetch until it
     * reaches a Variable node. Also unwraps Clone_ expressions, since patterns
     * like `(clone $query)->where(...)` are common in query builder usage.
     *
     * Examples:
     *   - `$this->relation()->where()` → Variable node for `$this`
     *   - `$items->where()`            → Variable node for `$items`
     *   - `(clone $query)->where()`    → Variable node for `$query`
     *   - `SomeClass::query()->where()`→ null (root is a StaticCall, not a Variable)
     *
     * @param  Node\Expr  $node  Any expression node, typically a MethodCall at the end of a chain.
     * @return Node\Expr\Variable|null The root variable, or null if the chain doesn't originate from a variable
     *                                  (e.g., it starts from a static call or function return).
     */
    public static function getRootVariable(Node\Expr $node): ?Node\Expr\Variable
    {
        while ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\PropertyFetch) {
            $node = $node->var;
        }

        // Unwrap clone expressions: (clone $query)->where() → $query
        if ($node instanceof Node\Expr\Clone_) {
            $node = $node->expr;
        }

        return $node instanceof Node\Expr\Variable ? $node : null;
    }

    /**
     * Extract the first string literal argument from a method or static call.
     *
     * Many Query Builder methods accept a column name as their first argument
     * (e.g., `->where('status', 'active')`, `->orderBy('created_at')`). This
     * helper safely extracts that first argument when it is a plain string literal.
     *
     * Returns null if:
     *   - The call has no arguments
     *   - The first argument is not a Node\Arg (e.g., variadic unpacking)
     *   - The first argument is not a string literal (variable, concatenation, etc.)
     *
     * @param  Node\Expr\MethodCall|Node\Expr\StaticCall  $call  The call node to inspect.
     * @return string|null The string value of the first argument, or null if not extractable.
     */
    public static function extractFirstStringArg(Node\Expr\MethodCall|Node\Expr\StaticCall $call): ?string
    {
        if (empty($call->args) || ! ($call->args[0] instanceof Node\Arg)) {
            return null;
        }

        $firstArg = $call->args[0]->value;

        if ($firstArg instanceof Node\Scalar\String_) {
            return $firstArg->value;
        }

        return null;
    }

    /**
     * Traverse the entire AST and set a 'parent' attribute on every node.
     *
     * PhpParser nodes do not natively track their parent. This traversal adds
     * a 'parent' attribute to each node, enabling upward tree walks — which are
     * essential for determining context (e.g., finding the enclosing ClassMethod
     * to check for @audit-skip PHPDoc annotations, or determining if a closure
     * is inside a Collection iterator).
     *
     * This MUST be called before any analysis pass that relies on
     * `$node->getAttribute('parent')`. Typically run as "Pass 1" before the
     * main method-call analysis.
     *
     * Note: This modifies the AST nodes in-place by adding attributes. The
     * parent references create circular structures, so the AST should not be
     * serialized after this step.
     *
     * @param  array  $ast  The parsed AST array (from PhpParser\Parser::parse()).
     */
    public static function setupParentReferences(array $ast): void
    {
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new class extends NodeVisitorAbstract
        {
            public function enterNode(Node $node): void
            {
                foreach ($node->getSubNodeNames() as $name) {
                    $subNode = $node->$name;
                    if ($subNode instanceof Node) {
                        $subNode->setAttribute('parent', $node);
                    } elseif (is_array($subNode)) {
                        foreach ($subNode as $child) {
                            if ($child instanceof Node) {
                                $child->setAttribute('parent', $node);
                            }
                        }
                    }
                }
            }
        });
        $traverser->traverse($ast);
    }
}
