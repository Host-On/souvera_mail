<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

use Psr\Log\LoggerInterface;

/**
 * Out-of-office / vacation auto-responder ("Abwesenheitsnotiz").
 *
 * Why we live inside the active filter script
 * -------------------------------------------
 * Stalwart enforces AT MOST ONE active Sieve script per account, and the
 * webmail Filters UI owns exactly one script — `smail.user`
 * (one active script per account).
 * Creating a *separate* `vacation` script and activating it would silently
 * DEACTIVATE the user's filter rules. So we instead merge a managed
 * `vacation` block into whichever script is currently active (falling back
 * to `smail.user` when none is active yet), preserving the user's existing
 * filters.
 *
 * Round-trip strategy
 * -------------------
 * We never parse the generated Sieve back out. Instead the managed block
 * carries a `# SOUVERA-VACATION-META:` comment holding a base64-encoded JSON
 * blob with the exact form state (enabled, subject, message, from, to). On
 * read we decode that; on write we regenerate the whole block. This keeps
 * parsing trivial and robust against quoting/escaping edge cases.
 *
 * The block is delimited so it can be stripped cleanly on every save:
 *
 *   require ["vacation"]; # SOUVERA-VACATION-REQUIRE
 *   …user's own requires + rules…
 *   # BEGIN SOUVERA VACATION
 *   # SOUVERA-VACATION-META: <base64 json>
 *   if allof(currentdate :value "ge" "date" "2026-07-01",
 *            currentdate :value "le" "date" "2026-07-31") {
 *       vacation :days 1 :subject "…" "…";
 *   }
 *   # END SOUVERA VACATION
 *
 * The managed `require` sits at the very top so it precedes every command
 * (RFC 5228 requires all `require` statements before any other command);
 * the `vacation` command itself sits at the end, after the user's rules.
 */
class VacationService
{
    private const SCRIPT_NAME_FALLBACK = 'smail.user';
    private const REQUIRE_MARKER = '# SOUVERA-VACATION-REQUIRE';
    private const BLOCK_BEGIN = '# BEGIN SOUVERA VACATION';
    private const BLOCK_END = '# END SOUVERA VACATION';
    private const META_PREFIX = '# SOUVERA-VACATION-META: ';

    public function __construct(
        private SieveScriptService $sieveScripts,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Extract the managed vacation parts (marker require line + BEGIN/END
     * block) from an arbitrary script body. Used by
     * SieveScriptService::rebuildActiveScript() so a filter rebuild can
     * carry the auto-responder over into the newly merged main script.
     *
     * Returns '' when the body contains no managed vacation parts.
     */
    public static function extractManagedBlock(string $body): string
    {
        return self::extractManagedRequire($body) . self::extractManagedBody($body);
    }

    /**
     * Only the marker require line (without trailing newline semantics
     * guaranteed) — '' when absent.
     */
    public static function extractManagedRequire(string $body): string
    {
        if (\preg_match('/^.*' . \preg_quote(self::REQUIRE_MARKER, '/') . '.*$/m', $body, $m) === 1) {
            return \rtrim((string) $m[0]) . "\n";
        }
        return '';
    }

    /**
     * Only the BEGIN/END vacation block — '' when absent.
     */
    public static function extractManagedBody(string $body): string
    {
        $begin = \strpos($body, self::BLOCK_BEGIN);
        if ($begin !== false) {
            $end = \strpos($body, self::BLOCK_END, $begin);
            if ($end !== false) {
                return \substr($body, $begin, $end + \strlen(self::BLOCK_END) - $begin) . "\n";
            }
        }
        return '';
    }

    public function isAvailable(): bool
    {
        return $this->sieveScripts->isAvailable();
    }

    /**
     * Current vacation state for the user.
     *
     * @return array{enabled: bool, subject: string, message: string, from: string, to: string}
     */
    public function get(string $userId): array
    {
        $default = ['enabled' => false, 'subject' => '', 'message' => '', 'from' => '', 'to' => ''];

        $activeBody = $this->activeScript($userId)['body'];
        if ($activeBody === '') {
            return $default;
        }

        $meta = $this->extractMeta($activeBody);
        if ($meta === null) {
            return $default;
        }

        return [
            'enabled' => (bool) ($meta['enabled'] ?? true),
            'subject' => (string) ($meta['subject'] ?? ''),
            'message' => (string) ($meta['message'] ?? ''),
            'from' => (string) ($meta['from'] ?? ''),
            'to' => (string) ($meta['to'] ?? ''),
        ];
    }

    /**
     * Enable/disable the auto-responder, persisting into the active script.
     *
     * @param string $from empty or ISO date (YYYY-MM-DD)
     * @param string $to   empty or ISO date (YYYY-MM-DD)
     */
    public function set(
        string $userId,
        bool $enabled,
        string $subject,
        string $message,
        string $from = '',
        string $to = '',
    ): void {
        $subject = \trim($subject);
        $message = \trim($message);
        $from = $this->normaliseDate($from);
        $to = $this->normaliseDate($to);

        if ($enabled) {
            if ($message === '') {
                throw new \InvalidArgumentException('Vacation message must not be empty');
            }
            if ($subject === '') {
                $subject = 'Abwesenheitsnotiz';
            }
            if ($from !== '' && $to !== '' && $from > $to) {
                throw new \InvalidArgumentException('Vacation start date must not be after the end date');
            }
        }

        $active = $this->activeScript($userId);
        $scriptName = $active['name'] !== '' ? $active['name'] : self::SCRIPT_NAME_FALLBACK;

        $base = $this->stripManaged($active['body']);

        if ($enabled) {
            $meta = [
                'enabled' => true,
                'subject' => $subject,
                'message' => $message,
                'from' => $from,
                'to' => $to,
            ];
            $caps = ['vacation'];
            if ($from !== '' || $to !== '') {
                // currentdate :value "ge"/"le" needs the date + relational extensions.
                $caps[] = 'date';
                $caps[] = 'relational';
            }
            $requireLine = 'require ["' . \implode('", "', $caps) . '"]; ' . self::REQUIRE_MARKER;
            $block = $this->buildBlock($meta, $subject, $message, $from, $to);
            $newBody = $requireLine . "\n"
                . ($base !== '' ? $base . "\n" : '')
                . $block . "\n";
        } else {
            // Disabled: managed parts already stripped — persist the bare
            // user script so the responder stops firing.
            $newBody = $base !== '' ? $base . "\n" : '';
        }

        $this->sieveScripts->saveScript($userId, $scriptName, $newBody);
        // Keep this script the active one (saveScript does not activate).
        $this->sieveScripts->activateScript($userId, $scriptName);

        $this->logger->info(
            'Souvera Mail: vacation responder ' . ($enabled ? 'enabled' : 'disabled')
                . ' for user in script "' . $scriptName . '"',
            ['app' => 'souvera_mail']
        );
    }

    // ---------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------

    /**
     * @return array{name: string, body: string}
     */
    private function activeScript(string $userId): array
    {
        $scripts = $this->sieveScripts->listScriptsWithBodies($userId)['scripts'];
        foreach ($scripts as $s) {
            if (($s['isActive'] ?? false) === true) {
                return ['name' => (string) ($s['name'] ?? ''), 'body' => (string) ($s['body'] ?? '')];
            }
        }
        // No active script yet — reuse the Filters default so we don't create
        // an orphan and later fight the Filters UI over which one is active.
        foreach ($scripts as $s) {
            if (($s['name'] ?? '') === self::SCRIPT_NAME_FALLBACK) {
                return ['name' => self::SCRIPT_NAME_FALLBACK, 'body' => (string) ($s['body'] ?? '')];
            }
        }
        return ['name' => '', 'body' => ''];
    }

    /**
     * Remove our managed require line and BEGIN…END block from a script body.
     */
    private function stripManaged(string $body): string
    {
        $lines = \preg_split('/\r?\n/', $body) ?: [];
        $out = [];
        $inBlock = false;
        foreach ($lines as $line) {
            if (\str_contains($line, self::BLOCK_BEGIN)) {
                $inBlock = true;
                continue;
            }
            if (\str_contains($line, self::BLOCK_END)) {
                $inBlock = false;
                continue;
            }
            if ($inBlock) {
                continue;
            }
            if (\str_contains($line, self::REQUIRE_MARKER)) {
                continue;
            }
            $out[] = $line;
        }
        return \trim(\implode("\n", $out));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function extractMeta(string $body): ?array
    {
        $lines = \preg_split('/\r?\n/', $body) ?: [];
        foreach ($lines as $line) {
            $pos = \strpos($line, self::META_PREFIX);
            if ($pos === false) {
                continue;
            }
            $encoded = \trim(\substr($line, $pos + \strlen(self::META_PREFIX)));
            $json = \base64_decode($encoded, true);
            if ($json === false) {
                return null;
            }
            $decoded = \json_decode($json, true);
            return \is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function buildBlock(array $meta, string $subject, string $message, string $from, string $to): string
    {
        $encodedMeta = \base64_encode((string) \json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $vacationCmd = 'vacation :days 1 :subject "' . $this->escape($subject) . '" "'
            . $this->escape($message) . '";';

        $conditions = [];
        if ($from !== '') {
            $conditions[] = 'currentdate :value "ge" "date" "' . $from . '"';
        }
        if ($to !== '') {
            $conditions[] = 'currentdate :value "le" "date" "' . $to . '"';
        }

        if (\count($conditions) === 1) {
            $inner = 'if ' . $conditions[0] . " {\n    " . $vacationCmd . "\n}";
        } elseif (\count($conditions) === 2) {
            $inner = "if allof(\n    " . $conditions[0] . ",\n    " . $conditions[1]
                . ") {\n    " . $vacationCmd . "\n}";
        } else {
            $inner = $vacationCmd;
        }

        return self::BLOCK_BEGIN . "\n"
            . self::META_PREFIX . $encodedMeta . "\n"
            . $inner . "\n"
            . self::BLOCK_END;
    }

    /**
     * Escape a Sieve quoted-string per RFC 5228 §2.4.2 (backslash + dquote).
     */
    private function escape(string $s): string
    {
        return \str_replace(['\\', '"'], ['\\\\', '\\"'], $s);
    }

    private function normaliseDate(string $date): string
    {
        $date = \trim($date);
        if ($date === '') {
            return '';
        }
        if (\preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new \InvalidArgumentException('Date must be in YYYY-MM-DD format');
        }
        return $date;
    }
}
