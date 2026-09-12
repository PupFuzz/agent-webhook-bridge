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
 * FINDINGS. But a finding is not the population of relayed exception text: `bridge:reconcile`
 * and `bridge:provision` relay the same `Illuminate\Http\Client\RequestException` — the same
 * kanban response body, baked into `getMessage()` by the same constructor — straight to
 * `error()`/`warn()` with NO finding and NO renderer anywhere in the path. Five arms,
 * invisible to a census that counted findings. A census sentence that reads as closed over a
 * population it never covered is worse than silence: the next reader audits their own join
 * against it and gets confidence.
 *
 * ⛔ WHAT IT COUNTS IS WHAT ITS SCOPE CLAIM MUST SAY, and an earlier revision of this
 * docblock overclaimed exactly the way the census above did. It counts the LITERAL
 * `getMessage()` and the LITERAL `UntrustedText::forOperator(` per file under
 * `app/Console/Commands/`, over comment-stripped source, against the ruling table below.
 * ⚠ **That is a population of RELAYED EXCEPTION TEXT, not of "direct console writes" and
 * not of "foreign bytes reaching an operator terminal".** The sentence it replaces claimed
 * the latter, and `app/Console/Commands/Bridge/InspectCommand.php` falsifies it: that
 * command `table()`s four `webhook_events` envelope fields and a stored
 * `agent_dispatches.error_message`, with no `getMessage()` and no catch block in the file at
 * all, so nothing here can see any of it. ⛔ A kanban response body DOES reach that column:
 * `DispatchService` catches `classify()` into `recordError()`, and
 * `GitHubPrCardMoveClassifier::classify()` calls `KanbanClient::correlateDl()`, which reads
 * through `->throw()`, with no catch in that file. What keeps it off the screen is
 * arithmetic, not a guard — `mb_strimwidth(…, 0, 60, '…')` against a class-and-status prefix
 * measuring 80 characters — and nothing asserts it. That is card#9251, not this pin. ⚠ An
 * earlier revision of this paragraph argued the opposite from a handler taxonomy that was
 * true and did not answer the question; it is named here because enumerating a path and not
 * checking it is the same failure as counting findings and calling it a sink census. Adding
 * a relay — foreign or not — reds this and forces the author to write down which it is. That
 * is the whole point: the defect was never a wrong ruling, it was a site nobody ruled on.
 *
 * ⛔ ONE ROUTE TO THE ONE RULE, and an earlier revision of this docblock described two
 * (card#9200). `App\Bridge\Support\UntrustedText` owns the rule (NAMED, not imported: this
 * class asserts over SOURCE, and an import would put the literal it greps for into its own
 * file). It used to be applied at TWO entry points — a command writing the console called it
 * at the write, while a command yielding a `Finding` declared the span positionally and let
 * the terminal renderer apply it — and the second route existed only to keep
 * `findings[].message` byte-stable for a write contract `docs/check-json-contract.md` §2
 * says does not exist. That premise being false is what made the escape opt-in per call site,
 * so the renderer route is gone: EVERY arm here escapes at the interpolation, `Finding` or
 * not. Which is why `CheckCommand`'s adopting count is no longer 0.
 *
 * WHAT IT DOES NOT DO, stated plainly rather than left to be assumed:
 *  - **It cannot tell anyone a relay is foreign.** The ruling is prose, per entry, below —
 *    beside the count it accounts for, which is the correction card#9121 r4 made: the
 *    rulings used to live in one prose block up here and covered one file fewer than the
 *    table did, so `SignCommand` carried a count nobody had ruled on, which is the very
 *    defect this class exists to prevent.
 *  - **Its accounting covers `relays`, not `escaped`.** The `×N` sum below is checked
 *    against a file's relay count; an escape that is NOT a relay is explained in the same
 *    ruling and is pinned only by the count compare, which is enough because the count and
 *    the ruling are now the same table entry.
 *  - **It is lexical.** A relay that reached the console without the literal `getMessage()`
 *    would be counted nowhere. None exists in `app/Console/Commands/` today as an exception
 *    relay — checked by reading every catch block in the tree, not by this grep — which is
 *    what makes it exhaustive OVER ITS OWN POPULATION now rather than by construction.
 *  - **Foreign text reaching a LOG, a digest, an HTTP response, or a console cell fed from
 *    the DATABASE is a different sink and out of scope here.** `bridge:standup --dry-run` is
 *    the live non-member ruled in DL-366 Decision 11; `bridge:inspect` is the live OPEN
 *    member named above.
 */
class ForeignRelayAdoptionTest extends TestCase
{
    /**
     * Every command that relays an exception message, with its `getMessage()` count, its
     * `UntrustedText::forOperator()` count, and THE RULING ON EVERY ARM, in one entry.
     *
     * ⛔ THE RULING LIVES HERE, BESIDE THE COUNT IT ACCOUNTS FOR. It used to be a prose
     * block above the table, which is a restatement with nothing that reds when the two
     * diverge — and they did: the table held nine files and the prose ruled on eight.
     * `test_every_ruling_accounts_for_every_relay` now reds on exactly that.
     *
     * ⚑ THE `×N` TOKENS ARE THE ACCOUNTING and are read by that test: they must sum to the
     * entry's `relays`. A file whose escapes are not relays says so WITHOUT a `×` token, so
     * the sum stays a statement about relays alone.
     *
     * @var array<string, array{relays: int, escaped: int, ruling: string}>
     */
    private const RULED = [
        'Bridge/BridgeCommand.php' => [
            'relays' => 2, 'escaped' => 0,
            'ruling' => '✔ NOT FOREIGN ×2 — a PDO/driver error from THIS install\'s own database.',
        ],
        'Bridge/CheckCommand.php' => [
            'relays' => 4, 'escaped' => 1,
            'ruling' => '⛔ FOREIGN ×1 — the writeback board-visibility fail-soft envelope, relaying the kanban '
                .'response body, escaped AT THE INTERPOLATION like every other arm in this table (card#9200: this '
                .'entry read `escaped => 0` while the escape was deferred to the terminal renderer). '
                .'✔ NOT FOREIGN ×3 — an agent YAML parse fault, a `writeback.json` parse fault, and the writeback '
                .'client factory\'s own token-file diagnosis.',
        ],
        'Bridge/JobsCommand.php' => [
            'relays' => 1, 'escaped' => 0,
            'ruling' => '✔ NOT FOREIGN ×1 — a `JobSpecException` over options the operator typed.',
        ],
        'Bridge/ProvisionCommand.php' => [
            'relays' => 4, 'escaped' => 1,
            'ruling' => '⛔ FOREIGN ×1 — the `API error` arm, relaying the kanban response body summary from '
                .'`WebhookProvisioner::ensure()`\'s live calls. ✔ NOT FOREIGN ×3 — local secret-file permission '
                .'and read faults on paths this install configured, and the identity_id WRITE arm (DL-369), whose '
                .'own comment rules it: `WritebackIdentityOffer::commit()` throws only a `ConfigException` or a '
                .'file-write fault, so no upstream response body can reach it. ⚑ THE FOURTH RELAY ARRIVED FROM '
                .'`dev` WHILE THIS BRANCH WAS PARKED and this entry read 3 — which is what the pin is for. Ruled '
                .'by reading the arm, not by moving the number: `KanbanIdentityResolver` reads the identity itself '
                .'WITHOUT `->throw()`, takes only `$response->status()` on a non-2xx, scrubs its one '
                .'`ConnectionException` relay, and REFUSES an unrenderable display name at the resolver rather '
                .'than escaping it at a render — so nothing it hands back carries remote bytes either.',
        ],
        'Bridge/ProvisionToolsCommand.php' => [
            'relays' => 1, 'escaped' => 0,
            'ruling' => '✔ NOT FOREIGN ×1 — a local secret-file read fault, on a path this install configured.',
        ],
        'Bridge/ReconcileCommand.php' => [
            'relays' => 6, 'escaped' => 8,
            'ruling' => '⛔ FOREIGN ×4 — the board read, the stage-order read, the GitHub PR read and the card '
                .'move. `KanbanClient` and the GitHub read client both go through `->throw()`, so a non-2xx arrives '
                .'as a `RequestException` whose constructor bakes the RESPONSE BODY SUMMARY into the message. '
                .'Guzzle\'s `bodySummary` gate is `/[^\pL\pM\pN\pP\pS\pZ\n\r\t]/u` — it fails closed on an ESC and '
                .'PASSES `\r`, and a 500 body of "\rboard 8: 0 divergences, nothing to do" returns the cursor to '
                .'column 0 and overwrites the line `bridge:reconcile` just printed, on the run an operator reads to '
                .'decide whether to `--fix`. Witnessed end to end in `ReconcileCommandTest`. '
                .'✔ NOT FOREIGN ×2 — the same two config faults, from its own startup. '
                .'⚑ THE OTHER FOUR ESCAPES ARE NOT RELAYS, and each names a VALUE rather than an arm '
                .'(card#9121 round 5, after a per-ARM ruling here was measured false): a card\'s `pr_url` '
                .'REPO on the out-of-scope arm; the same card\'s raw `pr_url` (`PrUrlRef::$raw`, stored '
                .'verbatim) at BOTH console writes of `evidence`; and a card\'s `dl_number` on the `DlOnly` '
                .'arm. None involves an exception, all four are ordinary-operation lines, and the count is '
                .'8 rather than 6 because the rule escapes AT THE WRITE and `evidence` has two of them.',
        ],
        'Bridge/ReplayCommand.php' => [
            'relays' => 1, 'escaped' => 0,
            'ruling' => '✔ NOT FOREIGN ×1 — a config fault on this install\'s own files.',
        ],
        'Bridge/SignCommand.php' => [
            'relays' => 1, 'escaped' => 0,
            'ruling' => '✔ NOT FOREIGN ×1 — `FileContents::read` on the `--body-file` the OPERATOR named on '
                .'their own command line, so the fault is about this install\'s own filesystem and this operator\'s '
                .'own path. ⚑ This entry is the one card#9121 r4 added: the count was in the table and the ruling '
                .'was in neither list, which is the exact state this class exists to make impossible.',
        ],
        'Bridge/ToolsCallCommand.php' => [
            'relays' => 1, 'escaped' => 0,
            'ruling' => '✔ NOT FOREIGN ×1 — a config fault on this install\'s own files.',
        ],
    ];

    public function test_every_relayed_exception_message_in_a_command_has_a_ruling(): void
    {
        $counts = [];
        foreach (self::RULED as $file => $row) {
            $counts[$file] = ['relays' => $row['relays'], 'escaped' => $row['escaped']];
        }

        $this->assertSame($counts, $this->derive());
    }

    /**
     * ⛔ A COUNT WITH NO RULING IS THE DEFECT THIS CLASS EXISTS TO PREVENT, so the accounting
     * is CHECKED and not merely adjacent.
     *
     * Every entry's ruling carries `×N` tokens that must sum to that entry's `relays`. A file
     * added to the table with a count and a hand-wave reds here; so does a relay added to a
     * file whose ruling is not widened to cover it. `SignCommand` sat in the table with no
     * ruling anywhere for a full review round without a single thing going red.
     */
    public function test_every_ruling_accounts_for_every_relay(): void
    {
        foreach (self::RULED as $file => $row) {
            $this->assertSame(
                $row['relays'],
                $this->accountedIn($row['ruling']),
                "{$file}: the ruling's ×N tokens do not account for its {$row['relays']} relay(s) — ".
                'every arm needs a ruling, which is the whole point of this table.',
            );
        }
    }

    /**
     * ⚑ THE CONTROL FOR THE ACCOUNTING. A sum over a parse that found nothing would be
     * satisfied by every `relays => 0` entry and by an unparseable ruling alike, so the parse
     * is shown to discriminate — it counts, it sums, and it reads a ruling with no token as
     * ZERO rather than as "unknown".
     */
    public function test_the_accounting_discriminates(): void
    {
        $this->assertSame(6, $this->accountedIn('⛔ FOREIGN ×4 — x. ✔ NOT FOREIGN ×2 — y.'));
        $this->assertSame(0, $this->accountedIn('a ruling with no accounting at all'));
        $this->assertSame(1, $this->accountedIn('⛔ FOREIGN ×1 — x.'));
    }

    /** The `×N` tokens in a ruling, summed. */
    private function accountedIn(string $ruling): int
    {
        preg_match_all('/×(\d+)/u', $ruling, $m);

        return array_sum(array_map(intval(...), $m[1]));
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
