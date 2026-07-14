<?php
declare(strict_types=1);

namespace OCA\SouveraMail\Sieve;

/**
 * A single parsed `if <test> { <actions> }` block.
 * Public-readonly value object — see {@see MiniInterpreter} docblock
 * for the wider Sieve subset semantics.
 */
final class Rule
{
    /**
     * @param TestNode     $test
     * @param ActionNode[] $actions
     */
    public function __construct(
        public readonly TestNode $test,
        public readonly array $actions
    ) {}
}
