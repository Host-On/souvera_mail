<?php
declare(strict_types=1);

// PSR-4 shim — declares `OCA\SouveraMail\Sieve\Rule` by loading the
// bundled Types.php file where it (together with TestNode/ActionNode/
// MessageFacts/EvaluatedActions) actually lives. See Types.php
// docblock for the rationale. `require_once` is idempotent — if
// another shim already loaded Types.php this is a no-op.
require_once __DIR__ . '/Types.php';
