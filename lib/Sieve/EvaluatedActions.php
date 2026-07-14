<?php
declare(strict_types=1);

namespace OCA\SouveraMail\Sieve;

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
