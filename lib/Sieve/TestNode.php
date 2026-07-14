<?php
declare(strict_types=1);

namespace OCA\SouveraMail\Sieve;

/**
 * Parsed test expression. `kind` is one of:
 *   'true', 'false', 'not', 'allof', 'anyof',
 *   'header', 'address', 'envelope', 'size', 'exists'.
 *
 * For `not` / `allof` / `anyof` the `args` array is `TestNode[]`.
 * For `header` / `address` / `envelope` it's a shape:
 *   ['header'|'headers' => string|string[],
 *    'needle'|'needles' => string|string[],
 *    'match' => 'is'|'contains'|'matches'|'regex']
 * For `size`: ['direction' => 'over'|'under', 'bytes' => int].
 * For `exists`: ['header'|'headers' => string|string[]].
 */
final class TestNode
{
    /**
     * @param string $kind
     * @param array<int|string, mixed> $args
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $args
    ) {}
}
