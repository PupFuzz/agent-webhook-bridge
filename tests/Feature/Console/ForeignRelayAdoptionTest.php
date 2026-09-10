<?php

namespace Tests\Feature\Console;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * A TRIPWIRE ON EVERY RELAYED EXCEPTION MESSAGE A BRIDGE COMMAND PRINTS (card#9121, DL-366).
 *
 * ⭐ WHY IT EXISTS, and what it corrects. DL-366 Decision 11 declared kanban-relayed
 * exception text a foreign span "at all seven arms" — a census taken over `bridge:check`'s
 * FINDINGS. But a finding is not the population: the population is FOREIGN BYTES REACHING AN
 * OPERATOR'S TERMINAL, and `bridge:reconcile` and `bridge:provision` relay the same
 * `Illuminate\Http\Client\RequestException` — the same kanban response body, baked into
 * `getMessage()` by the same constructor — straight to `error()`/`warn()` with NO finding and
 * NO renderer anywhere in the path. Five arms, invisible to a census that counted findings.
 * A census sentence that reads as closed over a population it never covered is worse than
 * silence: the next reader audits their own join against it and gets confidence.
 *
 * WHAT IT DOES: counts `getMessage()` per file under `app/Console/Commands/`, over
 * COMMENT-STRIPPED source, against the ruling table below. Adding a relay — foreign or not —
 * reds this and forces the author to write down which it is. That is the whole point: the
 * defect was never a wrong ruling, it was a site nobody ruled on.
 *
 * ⛔ THE TWO ROUTES TO THE ONE RULE, because a reader will otherwise take the second for a
 * second implementation. `App\Bridge\Support\UntrustedText` owns the rule (NAMED, not
 * imported: this class asserts over SOURCE, and an import would put the literal it greps for
 * into its own file). A command that yields a `Finding` declares the span positionally and
 * the TERMINAL renderer applies the rule; a command that writes the console directly IS the
 * renderer, so it calls `UntrustedText::forOperator()` at the write. One rule, one owner, two
 * entry points — not two rules.
 *
 * WHAT IT DOES NOT DO, stated plainly rather than left to be assumed:
 *  - **It cannot tell anyone a relay is foreign.** The ruling is prose, per arm, below.
 *  - **It bounds `app/Console/Commands/` only** — the population of DIRECT console writes.
 *    Foreign text reaching an operator through a `Finding` is bounded by the renderer and by
 *    the per-producer coverage pins; foreign text reaching a LOG, a digest or an HTTP
 *    response is a different sink with a different encoding and is out of scope here
 *    (`bridge:standup --dry-run` is the live example, ruled in DL-366 Decision 11).
 *  - **It is lexical.** A relay that reached the console without the literal `getMessage()`
 *    would be counted nowhere. None exists in `app/Console/Commands/` today — checked by
 *    reading every catch block in the tree, not by this grep — which is what makes this
 *    exhaustive NOW rather than by construction.
 */
class ForeignRelayAdoptionTest extends TestCase
{
    /**
     * Every command that relays an exception message, with its `getMessage()` count and the
     * ruling on each arm.
     *
     * ⛔ FOREIGN — a remote chose these bytes, so they are escaped before the console:
     *  - `ReconcileCommand` ×4: the board read, the stage-order read, the GitHub PR read and
     *    the card move. `KanbanClient` and the GitHub read client both go through `->throw()`,
     *    so a non-2xx arrives as a `RequestException` whose constructor bakes the RESPONSE
     *    BODY SUMMARY into the message. ⚑ Guzzle's `bodySummary` gate is
     *    `/[^\pL\pM\pN\pP\pS\pZ\n\r\t]/u` — it fails closed on an ESC and PASSES `\r`, and a
     *    500 body of `"\rboard 8: 0 divergences, nothing to do"` returns the cursor to
     *    column 0 and overwrites the line `bridge:reconcile` just printed, on the run an
     *    operator reads to decide whether to `--fix`. Witnessed end to end in
     *    `ReconcileCommandTest`.
     *  - `ProvisionCommand` ×1: the `API error` arm, relaying the same kanban body from
     *    `WebhookProvisioner::ensure()`'s live calls.
     *  - `CheckCommand` ×1 (line ~482): the writeback board-visibility fail-soft envelope.
     *    It takes the OTHER route — a `Finding` with an `Untrusted` segment — so it does NOT
     *    call `forOperator()` here, which is why the adopting count below is not the foreign
     *    count.
     *
     * ✔ NOT FOREIGN — every byte is this install's own or the operator's own, and declaring
     * them foreign would say this install does not vouch for what this install wrote:
     *  - `BridgeCommand` ×2 — a PDO/driver error from THIS install's own database.
     *  - `CheckCommand` ×3 — an agent YAML parse fault, a `writeback.json` parse fault, and
     *    the writeback client factory's own token-file diagnosis.
     *  - `ReconcileCommand` ×2 — the same two config faults, from its own startup.
     *  - `ProvisionCommand` ×2 and `ProvisionToolsCommand` ×1 — local secret-file permission
     *    and read faults, on paths this install configured.
     *  - `JobsCommand` ×1 — a `JobSpecException` over options the operator typed.
     *  - `ReplayCommand` ×1 and `ToolsCallCommand` ×1 — config faults on this install's files.
     *
     * @var array<string, array{relays: int, escaped: int}>
     */
    private const RULED = [
        'Bridge/BridgeCommand.php' => ['relays' => 2, 'escaped' => 0],
        'Bridge/CheckCommand.php' => ['relays' => 4, 'escaped' => 0],
        'Bridge/JobsCommand.php' => ['relays' => 1, 'escaped' => 0],
        'Bridge/ProvisionCommand.php' => ['relays' => 3, 'escaped' => 1],
        'Bridge/ProvisionToolsCommand.php' => ['relays' => 1, 'escaped' => 0],
        'Bridge/ReconcileCommand.php' => ['relays' => 6, 'escaped' => 4],
        'Bridge/ReplayCommand.php' => ['relays' => 1, 'escaped' => 0],
        'Bridge/SignCommand.php' => ['relays' => 1, 'escaped' => 0],
        'Bridge/ToolsCallCommand.php' => ['relays' => 1, 'escaped' => 0],
    ];

    public function test_every_relayed_exception_message_in_a_command_has_a_ruling(): void
    {
        $this->assertSame(self::RULED, $this->derive());
    }

    /**
     * ⚑ THE CONTROL. A pin over a derivation is worth nothing until the derivation has been
     * seen to MOVE — a `token_get_all()` walk that returned an empty map would satisfy the
     * assertion above the moment the table were emptied to match it. So the same walk is run
     * over a synthetic command carrying one relay and one escape, and must find them.
     */
    public function test_the_derivation_discriminates(): void
    {
        $source = <<<'PHP'
        <?php
        // a comment mentioning getMessage() and UntrustedText::forOperator( must NOT count
        class Fake {
            public function h(Throwable $e): void {
                $this->error('own: '.$e->getMessage());
                $this->warn('foreign: '.UntrustedText::forOperator($e->getMessage()));
            }
        }
        PHP;

        $this->assertSame(['relays' => 2, 'escaped' => 1], $this->countIn($source));
        $this->assertSame(['relays' => 0, 'escaped' => 0], $this->countIn("<?php\nclass Fake {}\n"));
    }

    /** @return array<string, array{relays: int, escaped: int}> */
    private function derive(): array
    {
        $root = base_path('app/Console/Commands');
        $found = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $counts = $this->countIn((string) file_get_contents($file->getPathname()));
            if ($counts['relays'] > 0 || $counts['escaped'] > 0) {
                $found[str_replace($root.'/', '', $file->getPathname())] = $counts;
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * COMMENT-STRIPPED, because these arms carry their reasoning in comments that name both
     * literals — counting prose would red this pin on a doc reword and green it on a deleted
     * call, i.e. exactly backwards.
     *
     * @return array{relays: int, escaped: int}
     */
    private function countIn(string $source): array
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            $code .= is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1])
                : $token;
        }

        return [
            'relays' => substr_count($code, 'getMessage()'),
            'escaped' => substr_count($code, 'UntrustedText::forOperator('),
        ];
    }
}
