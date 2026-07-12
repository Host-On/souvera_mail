<?php
declare(strict_types=1);

namespace OCA\SouveraMail\Sieve;

/**
 * Value-object types for the Sieve mini-interpreter. Kept as plain
 * final classes (no getters/setters — public readonly props) because
 * these are per-request throw-aways: we build them, evaluate them,
 * throw them away. Adding an ORM-style wrapper would be over-
 * engineering for a feature that runs a handful of times per user
 * per day at most.
 */

/** A single parsed `if <test> { <actions> }` block. */
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

/** A single terminal action. `arg` is null for `keep`/`discard`/`stop`. */
final class ActionNode
{
    public function __construct(
        public readonly string $kind,
        public readonly ?string $arg
    ) {}
}

/**
 * Snapshot of the message facts we need to evaluate rules.
 *
 * `headers` is a shape `array<string, string|string[]>` where the key
 * is the header name (case preserved) and the value is either a single
 * decoded UTF-8 string OR an array of strings if the header appears
 * multiple times.
 *
 * `envelopeFrom` and `envelopeTo` come from the SMTP envelope (JMAP
 * `Email.envelope.mailFrom` / `rcptTo`). They're optional — if we
 * don't have them, `envelope :is "from" "..."` rules never match
 * (safe under-apply).
 *
 * `size` is the raw octets count of the RFC 5322 message (JMAP
 * `Email.size`).
 */
final class MessageFacts
{
    /**
     * @param string $emailId JMAP Email id (used later for Email/set etc.)
     * @param array<string, string|string[]> $headers
     * @param string|null $envelopeFrom
     * @param string[]    $envelopeTo
     * @param int         $size
     */
    public function __construct(
        public readonly string $emailId,
        public readonly array $headers,
        public readonly ?string $envelopeFrom,
        public readonly array $envelopeTo,
        public readonly int $size
    ) {}
}

/** Result of evaluating one message against a full rule set. */
final class EvaluatedActions
{
    /**
     * @param ActionNode[] $actions
     */
    public function __construct(
        public readonly array $actions
    ) {}

    /** Does this message need to be moved to a specific folder? */
    public function fileintoTarget(): ?string
    {
        foreach ($this->actions as $a) {
            if ($a->kind === 'fileinto') {
                return $a->arg;
            }
        }
        return null;
    }

    /**
     * All redirect targets (may be zero, one, or many if multiple
     * redirect actions ran). Each entry is an email address.
     * @return string[]
     */
    public function redirectTargets(): array
    {
        $out = [];
        foreach ($this->actions as $a) {
            if ($a->kind === 'redirect' && $a->arg !== null && $a->arg !== '') {
                $out[] = $a->arg;
            }
        }
        return $out;
    }

    public function shouldDiscard(): bool
    {
        foreach ($this->actions as $a) {
            if ($a->kind === 'discard') { return true; }
        }
        return false;
    }

    /** @return string[] */
    public function addedFlags(): array
    {
        $out = [];
        foreach ($this->actions as $a) {
            if ($a->kind === 'addflag' && $a->arg !== null) {
                $out[] = $a->arg;
            }
        }
        return $out;
    }

    /** True if any action other than a bare `keep` ran. */
    public function isEmpty(): bool
    {
        foreach ($this->actions as $a) {
            if ($a->kind !== 'keep') { return false; }
        }
        return true;
    }
}
