<?php
declare(strict_types=1);

namespace OCA\SouveraMail\Sieve;

/**
 * Minimalistic Sieve interpreter for the "Apply filters to existing
 * messages" feature.
 *
 * WHAT THIS IS NOT
 * ----------------
 * This is **not** a full RFC-5228 Sieve engine. Sieve was designed for
 * INCOMING mail evaluation at MDA time (Stalwart already does that for
 * new messages). This interpreter's job is only to replay the effect
 * of a Sieve script against messages that are already in a mailbox —
 * so we cover the subset that Snappymail's `sieve.js` actually emits:
 *
 *   • `require [...]`             (ignored — capability metadata)
 *   • `if / elsif / else { … }`   (nested blocks NOT supported —
 *                                  Snappymail's UI only emits flat rules)
 *   • Tests: `header`, `address`, `envelope`, `size`, `exists`,
 *            `not`, `allof(…)`, `anyof(…)`, `true`, `false`
 *   • Match-types: `:contains`, `:matches` (glob → regex),
 *                  `:is`, `:regex`, `:count "gt"` (`:over` for size)
 *   • Actions: `fileinto "Folder";`, `redirect "email@…";`, `keep;`,
 *              `discard;`, `stop;`, `addflag "\\Seen";`,
 *              `removeflag "…";`
 *
 * Anything else (variables, vacation, notify, editheader, include,
 * hasflag, string-set, etc.) is silently ignored — the rule is
 * evaluated as if the unknown test returned `false` (safe default:
 * we'd rather under-apply than accidentally trigger the wrong action
 * on old messages).
 *
 * Parsing strategy
 * ----------------
 * We tokenise line-by-line, respect quoted strings (with `\"` and
 * `\\` escapes), skip comments (`# …` and `/* … *\/`), and build a
 * shallow AST of `Rule[]` where each rule has `test` (a `TestNode`)
 * and `actions` (array of `ActionNode`).
 *
 * Evaluation strategy
 * -------------------
 * Given a `MessageFacts` snapshot (headers + envelope + size — no
 * body unless explicitly fetched) `evaluate()` returns an
 * `EvaluatedActions` list of actions to execute for that message.
 * The caller decides how to apply them (fileinto → JMAP Email/set,
 * redirect → JMAP EmailSubmission/set, etc.).
 *
 * `stop;` short-circuits evaluation of remaining rules for the
 * message. `keep;` is the implicit default when no other terminal
 * action ran — but our caller only tracks explicit actions, so we
 * don't emit a synthetic "keep" for un-matched messages (they stay
 * where they are, which is functionally identical to `keep`).
 */
final class MiniInterpreter
{
    /** @var Rule[] */
    private array $rules = [];

    /**
     * Parse a Sieve script into rule objects. Returns `$this` for
     * chaining. Throws `\RuntimeException` on unrecoverable syntax
     * errors — the caller should surface those to the operator so
     * they know the current active script can't be replayed.
     */
    public function parse(string $script): self
    {
        $this->rules = [];
        // Strip block comments and line comments, but not inside strings.
        $stripped = $this->stripComments($script);
        // Normalise CRLF → LF, collapse tabs → spaces.
        $stripped = \str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $stripped);

        $pos = 0;
        $len = \strlen($stripped);
        while ($pos < $len) {
            $this->skipWhitespace($stripped, $pos);
            if ($pos >= $len) {
                break;
            }
            // require [...] ;
            if (\substr($stripped, $pos, 7) === 'require') {
                $this->skipRequireDirective($stripped, $pos);
                continue;
            }
            // if <test> { <actions> }   (elsif / else are treated as
            // independent rules, matching Snappymail's flat output)
            if (\substr($stripped, $pos, 2) === 'if'
                || \substr($stripped, $pos, 5) === 'elsif'
                || \substr($stripped, $pos, 4) === 'else'
            ) {
                $rule = $this->parseIfBlock($stripped, $pos);
                if ($rule !== null) {
                    $this->rules[] = $rule;
                }
                continue;
            }
            // Top-level bare actions (rare but legal — treat as
            // unconditional rule with `true` test).
            if ($this->looksLikeActionStart($stripped, $pos)) {
                $actions = $this->parseActionList($stripped, $pos, /*terminator*/ ';');
                if ($actions !== []) {
                    $this->rules[] = new Rule(new TestNode('true', []), $actions);
                }
                continue;
            }
            // Anything else — advance by 1 to avoid infinite loop.
            $pos++;
        }
        return $this;
    }

    /**
     * @return Rule[]
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * @return EvaluatedActions
     */
    public function evaluate(MessageFacts $msg): EvaluatedActions
    {
        $actions = [];
        $stopped = false;
        foreach ($this->rules as $rule) {
            if ($stopped) {
                break;
            }
            if (!$this->matches($rule->test, $msg)) {
                continue;
            }
            foreach ($rule->actions as $a) {
                if ($a->kind === 'stop') {
                    $stopped = true;
                    break;
                }
                $actions[] = $a;
            }
        }
        return new EvaluatedActions($actions);
    }

    // -----------------------------------------------------------------
    // Parser helpers
    // -----------------------------------------------------------------

    private function stripComments(string $s): string
    {
        // Remove /* ... */ (non-greedy, multi-line)
        $s = \preg_replace('#/\*.*?\*/#s', '', $s) ?? $s;
        // Remove # comments to end of line — but NOT inside strings.
        $out = '';
        $len = \strlen($s);
        $inString = false;
        $inCommentToNewline = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($inCommentToNewline) {
                if ($c === "\n") {
                    $inCommentToNewline = false;
                    $out .= $c;
                }
                continue;
            }
            if ($c === '"' && ($i === 0 || $s[$i - 1] !== '\\')) {
                $inString = !$inString;
                $out .= $c;
                continue;
            }
            if (!$inString && $c === '#') {
                $inCommentToNewline = true;
                continue;
            }
            $out .= $c;
        }
        return $out;
    }

    private function skipWhitespace(string $s, int &$pos): void
    {
        $len = \strlen($s);
        while ($pos < $len && \ctype_space($s[$pos])) {
            $pos++;
        }
    }

    private function skipRequireDirective(string $s, int &$pos): void
    {
        $semi = \strpos($s, ';', $pos);
        $pos = $semi === false ? \strlen($s) : $semi + 1;
    }

    private function looksLikeActionStart(string $s, int $pos): bool
    {
        static $keywords = ['fileinto', 'redirect', 'keep', 'discard', 'stop', 'addflag', 'removeflag', 'setflag'];
        foreach ($keywords as $kw) {
            if (\substr($s, $pos, \strlen($kw)) === $kw) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse an `if / elsif / else <test> { <actions> }` block.
     * `else` has no test, so we substitute `TestNode('true', [])` and
     * let the caller run it whenever it wants (typically unconditional).
     */
    private function parseIfBlock(string $s, int &$pos): ?Rule
    {
        // Advance past the keyword.
        if (\substr($s, $pos, 5) === 'elsif') {
            $pos += 5;
        } elseif (\substr($s, $pos, 4) === 'else') {
            $pos += 4;
        } elseif (\substr($s, $pos, 2) === 'if') {
            $pos += 2;
        }
        $this->skipWhitespace($s, $pos);

        // Parse the test expression up to the opening `{`.
        $test = new TestNode('true', []);
        if ($pos < \strlen($s) && $s[$pos] !== '{') {
            $braceOpen = $this->findMatchingBrace($s, $pos);
            if ($braceOpen === -1) {
                return null;
            }
            $testExpr = \substr($s, $pos, $braceOpen - $pos);
            $pos = $braceOpen;
            $test = $this->parseTestExpression(\trim($testExpr));
        }
        // Parse the action block between { ... }.
        if ($pos >= \strlen($s) || $s[$pos] !== '{') {
            return null;
        }
        $pos++; // consume {
        $blockEnd = $this->findMatchingBraceEnd($s, $pos);
        if ($blockEnd === -1) {
            return null;
        }
        $blockBody = \substr($s, $pos, $blockEnd - $pos);
        $pos = $blockEnd + 1;

        $inner = 0;
        $actions = $this->parseActionList($blockBody, $inner, /*terminator*/ null);
        return new Rule($test, $actions);
    }

    /** Return the index of the `{` that opens the block starting at $pos. */
    private function findMatchingBrace(string $s, int $pos): int
    {
        $len = \strlen($s);
        $inString = false;
        for ($i = $pos; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '"' && ($i === 0 || $s[$i - 1] !== '\\')) {
                $inString = !$inString;
            } elseif (!$inString && $c === '{') {
                return $i;
            }
        }
        return -1;
    }

    /** Return the index of the matching `}` given `$pos` is inside a block. */
    private function findMatchingBraceEnd(string $s, int $pos): int
    {
        $len = \strlen($s);
        $depth = 1;
        $inString = false;
        for ($i = $pos; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '"' && ($i === 0 || $s[$i - 1] !== '\\')) {
                $inString = !$inString;
                continue;
            }
            if ($inString) {
                continue;
            }
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }
        return -1;
    }

    /**
     * Parse a semicolon-delimited action list. Actions we don't know
     * are silently dropped.
     * @return ActionNode[]
     */
    private function parseActionList(string $s, int &$pos, ?string $terminator): array
    {
        $actions = [];
        $len = \strlen($s);
        while ($pos < $len) {
            $this->skipWhitespace($s, $pos);
            if ($pos >= $len) {
                break;
            }
            if ($terminator !== null && $s[$pos] === $terminator) {
                break;
            }
            // Read up to next ;
            $semi = \strpos($s, ';', $pos);
            $stmt = $semi === false
                ? \substr($s, $pos)
                : \substr($s, $pos, $semi - $pos);
            $pos = $semi === false ? $len : $semi + 1;

            $stmt = \trim($stmt);
            if ($stmt === '') {
                continue;
            }
            $node = $this->parseSingleAction($stmt);
            if ($node !== null) {
                $actions[] = $node;
            }
        }
        return $actions;
    }

    private function parseSingleAction(string $stmt): ?ActionNode
    {
        // Split off leading keyword.
        if (\preg_match('/^(fileinto|redirect|keep|discard|stop|addflag|removeflag|setflag)\b(.*)$/s', $stmt, $m)) {
            $kw = $m[1];
            $rest = \trim($m[2]);
            // Strip :copy / :flags "..." modifiers by ignoring them
            // (fileinto :copy "Folder"; → still fileinto "Folder").
            $rest = \preg_replace('/^:\w+(\s+"[^"]*")?\s*/', '', $rest) ?? $rest;
            $rest = \trim($rest);
            $arg = null;
            if ($rest !== '' && $rest[0] === '"') {
                // Extract quoted argument.
                $i = 0;
                $arg = $this->readQuotedString($rest, $i);
            }
            switch ($kw) {
                case 'fileinto':
                    return new ActionNode('fileinto', $arg ?? 'INBOX');
                case 'redirect':
                    return $arg ? new ActionNode('redirect', $arg) : null;
                case 'keep':
                    return new ActionNode('keep', null);
                case 'discard':
                    return new ActionNode('discard', null);
                case 'stop':
                    return new ActionNode('stop', null);
                case 'addflag':
                case 'setflag':
                    return $arg ? new ActionNode('addflag', $arg) : null;
                case 'removeflag':
                    return $arg ? new ActionNode('removeflag', $arg) : null;
            }
        }
        return null;
    }

    /**
     * Parse an if-test expression. Very tolerant — anything we don't
     * understand becomes `TestNode('false', [])` so the rule never
     * matches (safe default).
     */
    private function parseTestExpression(string $expr): TestNode
    {
        $expr = \trim($expr);
        // not <test>
        if (\preg_match('/^not\b\s*(.+)$/is', $expr, $m)) {
            return new TestNode('not', [$this->parseTestExpression($m[1])]);
        }
        // allof (a, b) / anyof (a, b)
        if (\preg_match('/^(allof|anyof)\b\s*\((.*)\)\s*$/is', $expr, $m)) {
            $kind = $m[1];
            $inner = $this->splitTopLevelCommas($m[2]);
            $children = [];
            foreach ($inner as $t) {
                $children[] = $this->parseTestExpression(\trim($t));
            }
            return new TestNode($kind, $children);
        }
        // header :contains "X" "Y"    /  address :is "from" "..."   /  envelope :matches "from" "*@*"
        if (\preg_match(
            '/^(header|address|envelope)\b\s*((?::\w+\s*(?:"[^"]*"\s*)?)*)"([^"]*)"\s*"([^"]*)"\s*$/is',
            $expr,
            $m
        )) {
            $kind = $m[1];
            $modifiers = $m[2];
            $headerName = $this->unescapeSieveString($m[3]);
            $needle = $this->unescapeSieveString($m[4]);
            $match = 'contains';
            if (\preg_match('/:is\b/', $modifiers)) { $match = 'is'; }
            elseif (\preg_match('/:matches\b/', $modifiers)) { $match = 'matches'; }
            elseif (\preg_match('/:regex\b/', $modifiers)) { $match = 'regex'; }
            return new TestNode($kind, ['header' => $headerName, 'needle' => $needle, 'match' => $match]);
        }
        // header etc. with header-list ["a","b"] and needle-list
        if (\preg_match(
            '/^(header|address|envelope)\b\s*((?::\w+\s*(?:"[^"]*"\s*)?)*)\[([^\]]*)\]\s*\[([^\]]*)\]\s*$/is',
            $expr,
            $m
        )) {
            $kind = $m[1];
            $modifiers = $m[2];
            $headers = $this->parseStringList($m[3]);
            $needles = $this->parseStringList($m[4]);
            $match = 'contains';
            if (\preg_match('/:is\b/', $modifiers)) { $match = 'is'; }
            elseif (\preg_match('/:matches\b/', $modifiers)) { $match = 'matches'; }
            elseif (\preg_match('/:regex\b/', $modifiers)) { $match = 'regex'; }
            return new TestNode($kind, ['headers' => $headers, 'needles' => $needles, 'match' => $match]);
        }
        // size :over N / size :under N (N may have K/M/G suffix)
        if (\preg_match('/^size\b\s*:(over|under)\s+(\d+)\s*([KMG]?)/is', $expr, $m)) {
            $direction = \strtolower($m[1]);
            $mul = ['' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][\strtoupper($m[3])];
            return new TestNode('size', ['direction' => $direction, 'bytes' => (int) $m[2] * $mul]);
        }
        // exists "X" — header exists
        if (\preg_match('/^exists\b\s*"([^"]*)"\s*$/is', $expr, $m)) {
            return new TestNode('exists', ['header' => $m[1]]);
        }
        if (\preg_match('/^exists\b\s*\[([^\]]*)\]\s*$/is', $expr, $m)) {
            return new TestNode('exists', ['headers' => $this->parseStringList($m[1])]);
        }
        // Bare true / false
        if ($expr === 'true') { return new TestNode('true', []); }
        if ($expr === 'false') { return new TestNode('false', []); }

        return new TestNode('false', []);
    }

    /**
     * Undo the Sieve string escapes that our regex-based extractor
     * captured verbatim. Sieve strings escape only `\"` and `\\`
     * (RFC 5228 §2.4.2); everything else is literal.
     */
    private function unescapeSieveString(string $s): string
    {
        return \str_replace(['\\\\', '\\"'], ['\\', '"'], $s);
    }

    /**
     * Read a `"quoted"` string from $s starting at $i (which points at
     * the leading `"`). Advances $i past the closing quote.
     */
    private function readQuotedString(string $s, int &$i): string
    {
        $len = \strlen($s);
        if ($i >= $len || $s[$i] !== '"') {
            return '';
        }
        $i++;
        $out = '';
        while ($i < $len) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $out .= $s[$i + 1];
                $i += 2;
                continue;
            }
            if ($c === '"') {
                $i++;
                return $out;
            }
            $out .= $c;
            $i++;
        }
        return $out;
    }

    /** Parse `"a","b","c"` → ['a','b','c']. */
    private function parseStringList(string $s): array
    {
        $result = [];
        $len = \strlen($s);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && \ctype_space($s[$i])) { $i++; }
            if ($i >= $len) { break; }
            if ($s[$i] === '"') {
                $result[] = $this->readQuotedString($s, $i);
            }
            // Skip comma / whitespace between entries.
            while ($i < $len && ($s[$i] === ',' || \ctype_space($s[$i]))) { $i++; }
        }
        return $result;
    }

    /** Split `a, b(c,d), e` at top-level commas only. */
    private function splitTopLevelCommas(string $s): array
    {
        $parts = [];
        $depth = 0;
        $inString = false;
        $buf = '';
        $len = \strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '"' && ($i === 0 || $s[$i - 1] !== '\\')) {
                $inString = !$inString;
                $buf .= $c;
                continue;
            }
            if (!$inString) {
                if ($c === '(') { $depth++; }
                elseif ($c === ')') { $depth--; }
                elseif ($c === ',' && $depth === 0) {
                    $parts[] = $buf;
                    $buf = '';
                    continue;
                }
            }
            $buf .= $c;
        }
        if (\trim($buf) !== '') { $parts[] = $buf; }
        return $parts;
    }

    // -----------------------------------------------------------------
    // Evaluator
    // -----------------------------------------------------------------

    private function matches(TestNode $test, MessageFacts $msg): bool
    {
        switch ($test->kind) {
            case 'true':  return true;
            case 'false': return false;
            case 'not':
                return !$this->matches($test->args[0], $msg);
            case 'allof':
                foreach ($test->args as $t) {
                    if (!$this->matches($t, $msg)) { return false; }
                }
                return true;
            case 'anyof':
                foreach ($test->args as $t) {
                    if ($this->matches($t, $msg)) { return true; }
                }
                return false;
            case 'header':
                return $this->matchHeader($test->args, $msg);
            case 'address':
                return $this->matchAddress($test->args, $msg);
            case 'envelope':
                return $this->matchEnvelope($test->args, $msg);
            case 'size':
                $bytes = (int) $test->args['bytes'];
                if ($test->args['direction'] === 'over')  { return $msg->size > $bytes; }
                if ($test->args['direction'] === 'under') { return $msg->size < $bytes; }
                return false;
            case 'exists':
                $names = $test->args['headers'] ?? [$test->args['header'] ?? ''];
                foreach ($names as $n) {
                    if ($this->headerValues($msg->headers, $n) !== []) {
                        return true;
                    }
                }
                return false;
        }
        return false;
    }

    /** @param array<string,mixed> $args */
    private function matchHeader(array $args, MessageFacts $msg): bool
    {
        $headers = $args['headers'] ?? [$args['header'] ?? ''];
        $needles = $args['needles'] ?? [$args['needle'] ?? ''];
        $match = $args['match'] ?? 'contains';
        foreach ($headers as $h) {
            foreach ($this->headerValues($msg->headers, $h) as $val) {
                foreach ($needles as $n) {
                    if ($this->cmp($val, $n, $match)) { return true; }
                }
            }
        }
        return false;
    }

    /** @param array<string,mixed> $args */
    private function matchAddress(array $args, MessageFacts $msg): bool
    {
        $headers = $args['headers'] ?? [$args['header'] ?? ''];
        $needles = $args['needles'] ?? [$args['needle'] ?? ''];
        $match = $args['match'] ?? 'contains';
        foreach ($headers as $h) {
            foreach ($this->headerValues($msg->headers, $h) as $val) {
                // Extract email addresses out of "Foo Bar <a@b>, c@d" form.
                foreach ($this->extractAddresses($val) as $addr) {
                    foreach ($needles as $n) {
                        if ($this->cmp($addr, $n, $match)) { return true; }
                    }
                }
            }
        }
        return false;
    }

    /** @param array<string,mixed> $args */
    private function matchEnvelope(array $args, MessageFacts $msg): bool
    {
        $headers = $args['headers'] ?? [$args['header'] ?? ''];
        $needles = $args['needles'] ?? [$args['needle'] ?? ''];
        $match = $args['match'] ?? 'contains';
        foreach ($headers as $h) {
            $h = \strtolower($h);
            $vals = $h === 'from' ? [$msg->envelopeFrom]
                  : ($h === 'to' ? $msg->envelopeTo : []);
            foreach ($vals as $val) {
                if ($val === null || $val === '') { continue; }
                foreach ($needles as $n) {
                    if ($this->cmp($val, $n, $match)) { return true; }
                }
            }
        }
        return false;
    }

    /** Return every value of the named header (case-insensitive), possibly multi-valued. */
    private function headerValues(array $headers, string $name): array
    {
        $key = \strtolower(\trim($name));
        $out = [];
        foreach ($headers as $h => $v) {
            if (\strtolower((string) $h) === $key) {
                if (\is_array($v)) { $out = \array_merge($out, $v); }
                else { $out[] = (string) $v; }
            }
        }
        return $out;
    }

    private function extractAddresses(string $headerValue): array
    {
        $out = [];
        // Very simple RFC 5322 subset — good enough for sieve address tests.
        if (\preg_match_all('/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/', $headerValue, $m)) {
            $out = $m[0];
        }
        return $out;
    }

    private function cmp(string $value, string $needle, string $match): bool
    {
        switch ($match) {
            case 'is':
                return \strcasecmp($value, $needle) === 0;
            case 'contains':
                return \stripos($value, $needle) !== false;
            case 'matches':
                // Sieve glob: `*` = any-run, `?` = single char.
                $regex = '/^' . \str_replace(
                    ['\*', '\?'],
                    ['.*', '.'],
                    \preg_quote($needle, '/')
                ) . '$/i';
                return (bool) \preg_match($regex, $value);
            case 'regex':
                $pattern = '/' . \str_replace('/', '\/', $needle) . '/i';
                return @\preg_match($pattern, $value) === 1;
        }
        return false;
    }
}
