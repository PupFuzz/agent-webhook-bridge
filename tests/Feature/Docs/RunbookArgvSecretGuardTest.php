<?php

namespace Tests\Feature\Docs;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A drift guard on the class card#8336 opened: a documented procedure that resolves a
 * secret into a COMMAND'S ARGUMENTS. `/proc/<pid>/cmdline` is world-readable on a host
 * without `hidepid` (measured on the deployment host, not assumed), so for the lifetime
 * of that process every local account can read the value — which is exactly what the
 * `chmod 600` on the secret file exists to prevent.
 *
 * ⛔ WHY A GUARD AND NOT A SENTENCE. The rule is a HANDLING rule, and prose cannot be
 * checked: the runbook that carried the defect also carried the correct reasoning about
 * secrets elsewhere in the same file. A copy of the recipe is one paste away, and nothing
 * would have reddened. `bridge:sign` moves the handling into code; this keeps the
 * DOCUMENTS from re-minting it around the code.
 *
 * ⛔ WHAT THIS GUARD CAN AND CANNOT SEE — stated so its green is not read as more than it
 * is (the population and the predicate, both):
 *  - POPULATION: every `.md` file in the working tree except `vendor/`, `node_modules/`,
 *    `storage/`, `.git/` and `bootstrap/cache/` — the tree, not the index, so an
 *    untracked draft is scanned too (it is about to be committed). Within each file,
 *    only fenced code blocks tagged as shell (bash/sh/shell/console/zsh) or left
 *    untagged: blocks an operator would paste into a terminal. Prose that DESCRIBES the
 *    defect (this repo's decision log and changelog quote the leaking command by design)
 *    is deliberately out of scope — a description is not a recipe.
 *  - PREDICATE: a SPELLING LIST, not a semantic analysis. It matches a shell EXPANSION
 *    (`$var`, `$(…)`, a backtick) landing in one of the argument positions the audit
 *    found or considered — it cannot see a spelling nobody has thought of yet, and it
 *    says nothing about a literal secret typed inline. Add the spelling when the next one
 *    is found; do not weaken an entry to make a red go away.
 *  - NOT COVERED, deliberately: a secret in the ENVIRONMENT. `/proc/<pid>/environ` is
 *    readable only by the process's own user and root, so it is a materially weaker
 *    exposure than argv and a different decision. This guard would be lying if its name
 *    implied it.
 */
class RunbookArgvSecretGuardTest extends TestCase
{
    /**
     * Argument positions that carry a credential, each mapped to the remedy. The value is
     * what the failure prints, so it has to say what to do instead, not just what is wrong.
     *
     * @var array<string, string>
     */
    private const FORBIDDEN = [
        '/-hmac\s+\S*[$`]/' => 'openssl -hmac <expansion> — sign with `php artisan bridge:sign`, which reads the secret file itself',
        '/-macopt\s+\S*key:\S*[$`]/' => 'openssl -macopt …key:<expansion> — sign with `php artisan bridge:sign`',
        '/-H\s+["\']?[^"\']*[Aa]uthorization\s*:[^"\']*[$`]/' => 'curl -H "Authorization: …<expansion>" — feed the header out-of-band: printf \'Authorization: Bearer %s\' "$(cat <file>)" | curl -H @- …',
        '/(?:--password|--token|--secret|--api-key)[= ]\S*[$`]/' => 'a credential as a flag value — pass a PATH and let the program read the file',
        '/curl[^|\n]*\s-u\s+\S*[$`]/' => 'curl -u <expansion> — use --netrc-file, or a config file fed on stdin with -K -',
        '/mysql[^|\n]*\s-p\S*[$`]/' => 'mysql -p<expansion> — use a client config file (~/.my.cnf, chmod 600)',
    ];

    /**
     * Fence info-strings treated as "a shell block". The empty string is included on
     * purpose: an untagged fence is the other way a paste-ready recipe is written, and no
     * non-shell fence in this repo matches the list above, so including it costs nothing
     * and closes the obvious evasion.
     *
     * @var list<string>
     */
    private const SHELL_FENCES = ['', 'bash', 'sh', 'shell', 'console', 'zsh'];

    public function test_no_documented_shell_recipe_puts_a_resolved_secret_in_argv(): void
    {
        $files = $this->markdownFiles();

        // A guard over an empty population reports where the search stopped, not the state
        // of the docs.
        $this->assertGreaterThan(10, count($files), 'the markdown census found almost nothing — the scan, not the docs, is what this run measured');

        $findings = [];
        foreach ($files as $path => $contents) {
            foreach ($this->findings($contents) as $finding) {
                $findings[] = $path.':'.$finding;
            }
        }

        $this->assertSame([], $findings, "a doc tells an operator to put a secret on a command line:\n".implode("\n", $findings));
    }

    public function test_the_guard_fires_on_the_recipe_it_was_written_for(): void
    {
        // POSITIVE CONTROL — the smoke-test block as CLAUDE_DEPLOYMENT.md carried it at
        // v0.79.0. Without this, a scan that silently matched nothing at all would pass
        // the test above forever.
        $doc = "# Runbook\n\n```bash\nSECRET=\$(cat \"<dir>/webhook-secret-scope-\${SCOPE}\")\n"
            ."SIG=\$(printf '%s' \"\$BODY\" | openssl dgst -sha256 -hmac \"\$SECRET\" -hex | awk '{print \$NF}')\n```\n";

        $findings = $this->findings($doc);

        $this->assertCount(1, $findings, 'the guard did not see the very command it exists to refuse');
        $this->assertStringContainsString('bridge:sign', $findings[0]);
    }

    public function test_the_guard_does_not_fire_on_the_replacement_or_on_prose(): void
    {
        // The two accepted forms, so a fix cannot be "reworded until the check went quiet":
        // the signature comes from a command that reads the file, and a bearer header is
        // fed to curl on stdin. Plus the same leaking command in PROSE, which the decision
        // log has to be able to quote.
        $doc = "Never write `openssl dgst -sha256 -hmac \"\$SECRET\"` — the secret lands in argv.\n\n"
            ."```bash\nSIG=\$(printf '%s' \"\$BODY\" | php artisan bridge:sign --provider=github --scope=\"\$SCOPE\")\n"
            ."printf 'Authorization: Bearer %s' \"\$(cat \"\$TOKEN_FILE\")\" | curl -H @- -X POST \"\$URL\"\n```\n";

        $this->assertSame([], $this->findings($doc));
    }

    /**
     * @return list<string> "<line number>: <remedy> — <the offending line>"
     */
    private function findings(string $markdown): array
    {
        $findings = [];
        $inFence = false;
        $isShell = false;

        foreach (explode("\n", $markdown) as $index => $line) {
            if (preg_match('/^\s*```([A-Za-z0-9_+-]*)\s*$/', $line, $m) === 1) {
                $inFence = ! $inFence;
                $isShell = $inFence && in_array(strtolower($m[1]), self::SHELL_FENCES, true);

                continue;
            }
            if (! $isShell) {
                continue;
            }
            foreach (self::FORBIDDEN as $pattern => $remedy) {
                if (preg_match($pattern, $line) === 1) {
                    $findings[] = ($index + 1).': '.$remedy.' — '.trim($line);
                }
            }
        }

        return $findings;
    }

    /**
     * The census. Reads the WORKING TREE rather than `git ls-files`, so a draft that has
     * not been added yet is still scanned — the moment the check is worth something.
     *
     * @return array<string, string> relative path => contents
     */
    private function markdownFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $skip = ['vendor', 'node_modules', 'storage', '.git', 'bootstrap/cache'];

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = str_replace($root.'/', '', (string) $file);
            if (! str_ends_with($path, '.md')) {
                continue;
            }
            foreach ($skip as $prefix) {
                if (str_starts_with($path, $prefix.'/')) {
                    continue 2;
                }
            }
            $files[$path] = (string) file_get_contents((string) $file);
        }

        return $files;
    }
}
