<?php

namespace App\Bridge\Tools;

/**
 * One parsed `authorized_keys` line, for the `bridge:check` SSH-transport pinned-line
 * probe (Finding D, card 4952). The board-tools forced-command line must DENY a shell
 * (pty), agent-forwarding, X11-forwarding, and port-forwarding — asserted by the
 * effective OUTCOME, never by a literal `restrict` keyword (FIPS-2): a FIPS sshd's
 * `restrict` disables forwarding without honoring `permitlisten`, so a valid FIPS line
 * uses the enumerated `no-*` form and carries no `restrict`.
 *
 * Parsing respects the real `authorized_keys` grammar (F5): the option list is NOT
 * naively comma-splittable — `command="a,b"`, `from="1.2.3.4,5.6.7.8"`,
 * `environment="A=B"` carry commas and `\`-escapes INSIDE quoted values. A quoted-field
 * tokenizer is used, and the capability set is computed from parsed option KEYS only,
 * matched CASE-INSENSITIVELY — never a substring scan (DR2-4: a substring scan would
 * read `environment="X=no-port-forwarding"` as a deny token and certify a
 * tunnel-PERMITTING line).
 *
 * Capability model (DR3 must-fix — last-writer-wins, left-to-right): each of the four
 * capabilities starts GRANTED (a bare sshd grants everything), a deny token
 * (`restrict` denies all four; `no-pty` / `no-agent-forwarding` / `no-X11-forwarding`
 * / `no-port-forwarding` deny one) turns it off, and a LATER positive override token
 * (`pty` / `agent-forwarding` / `x11-forwarding` / `port-forwarding`) turns it back on
 * — sshd(8) states each "permit … previously disabled by the restrict option". Scoped
 * forwarding tokens `permitopen=` / `permitlisten=` / `tunnel=` are treated as
 * port-forwarding PERMITS (the fail-closed reading — no legitimate board-tools recipe
 * line carries them). So `restrict,pty` is NOT certified (sshd grants the pty) and
 * `restrict,permitopen="x:22"` is NOT certified. The grant/deny token inventory is
 * pinned to OpenSSH 9.x's four override tokens.
 */
final class AuthorizedKeysLine
{
    /**
     * @param  list<string>  $optionTokens  raw option tokens (quotes/escapes intact)
     */
    private function __construct(
        public readonly array $optionTokens,
        public readonly ?string $keyAlgorithm,
    ) {}

    /**
     * Parse an authorized_keys file's text into its non-comment, non-blank lines.
     *
     * @return list<self>
     */
    public static function parseFile(string $content): array
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $rawLine) {
            $line = self::parseLine($rawLine);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private static function parseLine(string $raw): ?self
    {
        $raw = trim($raw);
        if ($raw === '' || $raw[0] === '#') {
            return null;
        }

        // The first field runs to the first UNQUOTED whitespace. It is the options
        // list UNLESS it is itself a key-type token (a line with no options).
        [$field1, $rest] = self::splitFirstField($raw);
        if (self::isKeyType($field1)) {
            return new self([], strtolower($field1));
        }

        $optionTokens = self::tokenizeOptions($field1);
        // The next field after the options is the key algorithm.
        [$keyAlgo] = self::splitFirstField($rest);

        return new self($optionTokens, $keyAlgo !== '' ? strtolower($keyAlgo) : null);
    }

    /**
     * Split off the first whitespace-delimited field, respecting `"`-quoting and
     * `\`-escapes (so quoted whitespace inside an option value does not split).
     *
     * @return array{0: string, 1: string} [field, remainder]
     */
    private static function splitFirstField(string $s): array
    {
        $len = strlen($s);
        $inQuote = false;
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $i++;

                continue;
            }
            if ($c === '"') {
                $inQuote = ! $inQuote;

                continue;
            }
            if (! $inQuote && ($c === ' ' || $c === "\t")) {
                return [substr($s, 0, $i), ltrim(substr($s, $i))];
            }
        }

        return [$s, ''];
    }

    /**
     * Split an options string into tokens on UNQUOTED commas (quotes + escapes
     * preserved so a later value-dequote is exact).
     *
     * @return list<string>
     */
    private static function tokenizeOptions(string $opts): array
    {
        $tokens = [];
        $cur = '';
        $inQuote = false;
        $len = strlen($opts);
        for ($i = 0; $i < $len; $i++) {
            $c = $opts[$i];
            if ($c === '\\' && $i + 1 < $len) {
                $cur .= $c.$opts[$i + 1];
                $i++;

                continue;
            }
            if ($c === '"') {
                $inQuote = ! $inQuote;
                $cur .= $c;

                continue;
            }
            if ($c === ',' && ! $inQuote) {
                if ($cur !== '') {
                    $tokens[] = $cur;
                }
                $cur = '';

                continue;
            }
            $cur .= $c;
        }
        if ($cur !== '') {
            $tokens[] = $cur;
        }

        return $tokens;
    }

    private static function isKeyType(string $field): bool
    {
        return preg_match('/^(ssh-(rsa|dss|ed25519)|ecdsa-sha2-nistp\d+|sk-|rsa-sha2-)/i', $field) === 1;
    }

    /** The lower-cased option KEY of a token (`command="…"` → `command`). */
    private static function optionKey(string $token): string
    {
        $eq = strpos($token, '=');
        $key = $eq === false ? $token : substr($token, 0, $eq);

        return strtolower(trim($key));
    }

    /** The dequoted VALUE of a token, or null when the token has no `=value`. */
    private static function optionValue(string $token): ?string
    {
        $eq = strpos($token, '=');
        if ($eq === false) {
            return null;
        }
        $value = substr($token, $eq + 1);
        if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
            $value = substr($value, 1, -1);
        }

        return str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
    }

    /** The forced-command value (`command="…"`), or null when unforced. */
    public function forcedCommand(): ?string
    {
        foreach ($this->optionTokens as $token) {
            if (self::optionKey($token) === 'command') {
                return self::optionValue($token);
            }
        }

        return null;
    }

    /**
     * Whether this line's forced command invokes `bridge:tools-call --agent=<agent>`
     * for exactly the named agent (bounded so `--agent=me` does not match `meta`).
     */
    public function forcesToolsCallFor(string $agent): bool
    {
        $command = $this->forcedCommand();
        if ($command === null || ! str_contains($command, 'bridge:tools-call')) {
            return false;
        }

        return preg_match('/--agent=(["\']?)'.preg_quote($agent, '/').'\1(\s|$)/', $command) === 1;
    }

    /**
     * The capabilities STILL GRANTED after evaluating the option tokens left-to-right
     * (last-writer-wins). Empty ⇒ the line denies all four (certified). Any of
     * {pty, agent-forwarding, x11-forwarding, port-forwarding} present ⇒ NOT certified.
     *
     * @return list<string>
     */
    public function grantedCapabilities(): array
    {
        $cap = ['pty' => true, 'agent-forwarding' => true, 'x11-forwarding' => true, 'port-forwarding' => true];
        foreach ($this->optionTokens as $token) {
            switch (self::optionKey($token)) {
                case 'restrict':
                    $cap = ['pty' => false, 'agent-forwarding' => false, 'x11-forwarding' => false, 'port-forwarding' => false];
                    break;
                case 'no-pty':
                    $cap['pty'] = false;
                    break;
                case 'no-agent-forwarding':
                    $cap['agent-forwarding'] = false;
                    break;
                case 'no-x11-forwarding':
                    $cap['x11-forwarding'] = false;
                    break;
                case 'no-port-forwarding':
                    $cap['port-forwarding'] = false;
                    break;
                case 'pty':
                    $cap['pty'] = true;
                    break;
                case 'agent-forwarding':
                    $cap['agent-forwarding'] = true;
                    break;
                case 'x11-forwarding':
                    $cap['x11-forwarding'] = true;
                    break;
                case 'port-forwarding':
                case 'permitopen':
                case 'permitlisten':
                case 'tunnel':
                    // permitopen/permitlisten/tunnel are scoped port-forwarding permits —
                    // treated as GRANTING port-forwarding (fail-closed).
                    $cap['port-forwarding'] = true;
                    break;
            }
        }

        return array_keys(array_filter($cap));
    }

    /** OUTCOME assertion: the line denies shell/pty + all three forwardings. */
    public function deniesShellAndForwarding(): bool
    {
        return $this->grantedCapabilities() === [];
    }

    /**
     * Whether the key algorithm would authenticate on a FIPS sshd. ed25519 is
     * FIPS-REJECTED (the FAIL trigger on a FIPS seat); ECDSA-nistp* and RSA families
     * are FIPS-approved. An unknown/absent algorithm is treated as not-approved.
     */
    public function keyAlgorithmIsFipsApproved(): bool
    {
        $algo = $this->keyAlgorithm;
        if ($algo === null) {
            return false;
        }
        if (str_contains($algo, 'ed25519')) {
            return false;
        }

        return preg_match('/^(ecdsa-sha2-nistp\d+|ssh-rsa|rsa-sha2-)/', $algo) === 1;
    }
}
