<?php
declare(strict_types=1);

namespace OCA\SouveraMail\Sieve;

/**
 * A single terminal Sieve action. `arg` is null for
 * `keep`/`discard`/`stop`.
 */
final class ActionNode
{
    public function __construct(
        public readonly string $kind,
        public readonly ?string $arg
    ) {}
}
