<?php
declare(strict_types=1);

/*
 * DEPRECATED (v0.14.44 — 2026-02-19)
 * ----------------------------------
 * Historically this file bundled five Sieve value-object classes
 * (Rule, TestNode, ActionNode, MessageFacts, EvaluatedActions) in
 * a single file. That layout broke PSR-4 autoloading: composer's
 * `OCA\\SouveraMail\\` PSR-4 mapping resolves each class to its own
 * file, so `use OCA\SouveraMail\Sieve\Rule` triggered a lookup for
 * `lib/Sieve/Rule.php` that did not exist. `Error: Class not found`
 * bubbled up through the controller's Throwable-catch — but on some
 * NC middleware chains it landed in the global exception handler
 * FIRST and rendered as HTML, which broke the frontend `JSON.parse`
 * (operator report 2026-02-19).
 *
 * Split into PSR-4-conformant per-class files:
 *   - lib/Sieve/Rule.php
 *   - lib/Sieve/TestNode.php
 *   - lib/Sieve/ActionNode.php
 *   - lib/Sieve/MessageFacts.php
 *   - lib/Sieve/EvaluatedActions.php
 *
 * This shim remains only so that any lingering `require Types.php`
 * (e.g. in an older test file, or a stale opcache load path) keeps
 * working. It requires the split files idempotently — no fatal if
 * they're already loaded via the PSR-4 autoloader.
 */

require_once __DIR__ . '/Rule.php';
require_once __DIR__ . '/TestNode.php';
require_once __DIR__ . '/ActionNode.php';
require_once __DIR__ . '/MessageFacts.php';
require_once __DIR__ . '/EvaluatedActions.php';
