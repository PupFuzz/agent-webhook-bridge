<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\RetentionPostureCheck;
use App\Bridge\Retention\RetentionFootprint;
use App\Bridge\Retention\RetentionStoreProbe;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * WHAT IS HERE IS WHAT THE GOLDEN SUITE CANNOT SEE, and that is one criterion, not two
 * lists: a golden capture is plain text, so it witnesses a MESSAGE perfectly and a
 * SEVERITY nowhere, and it is captured against a working install, so it reaches no
 * fail-soft `catch`. Everything else
 * this check reports is measured byte-for-byte by that suite (`CheckGoldenTest` — named,
 * not `{@see}`-linked, because pint's docblock fixer turns a fully-qualified `{@see}` into
 * a real `use`, and an import minted by a comment is one an unused-import gate can never
 * retire), which pins the operator-visible line rather than just the branch. Adding a
 * golden-covered leg here for its own sake would duplicate that suite, not strengthen it.
 *
 *  1. THE FAIL-SOFT `catch` around the last-failure marker read. The golden suite cannot
 *     reach it: doing so needs an unreachable cache backend, and a golden fixture is
 *     captured against a working install. It is also invisible to
 *     `bin/check-golden-mutate.php`, which enumerates predicates in
 *     `CheckCommand::handle()` and does not walk `catch` blocks — so without this test the
 *     arm is justified by reading alone.
 *  2. THE EARLY-FINISH LEG'S SEVERITY (card#5698 sub-shape (3) / DL-261). The golden
 *     suite DOES pin this leg's line, on 34 fixtures — but a golden capture is plain
 *     text, so it witnesses a severity nowhere. `warn` and `unvalidated` differ only in
 *     the renderer's colour and the closing tally, which means this leg could be flipped
 *     back to convicting the receiver on a PATH read and every golden file would stay
 *     byte-identical. That is the same gap the stage-10 sweep hit, and it is why a swept
 *     site needs a severity assertion of its own before the sweep can be trusted.
 *  3. THE THREE COST LEGS' SEVERITIES (card#8374). Same gap, three more sites: the
 *     corpus pins every byte of the store line, the row-delete-OFF line and the
 *     payload-nulling-OFF line, and would stay byte-identical if all three were demoted
 *     to `ok` — which would take the two OFF legs from a yellow line an operator acts on
 *     to a green one confirming the posture they were warned about. The store line's
 *     `ok`/`warn` split is the age verdict and nothing else, so it is the one arm where
 *     the severity IS the finding.
 *  4. THE INVERTED-SHARE ARM'S MESSAGE — the one leg here whose text the corpus COULD
 *     have carried, kept out of it on purpose. Every golden fixture is an operator-
 *     distinguishable INSTALL shape, and this arm is not one: it is reached when the
 *     ENGINE's size figure disagrees with a live payload scan, a property of the storage
 *     accounting rather than of anything an operator configured. A fixture for it would
 *     pin numbers chosen only to disagree, which asserts the pin. Its control is pinned
 *     on the boundary here for the same reason.
 */
class RetentionPostureCheckTest extends TestCase
{
    use MaterializesChecks;

    /** @var list<string> */
    private array $tmpDirs = [];

    private string|false $savedPath = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedPath = getenv('PATH');

        // A healthy, usable posture — the only route that reaches the marker read.
        config([
            'bridge.retention.enabled' => true,
            'bridge.retention.older_than' => '30d',
            'bridge.retention.null_payloads_older_than' => '',
            'bridge.retention.interval' => 86400,
            'bridge.retention.batch' => 500,
        ]);
    }

    protected function tearDown(): void
    {
        // PATH is process-global: leaving a fixture bin dir behind would silently decide
        // this leg for every later test in the process.
        $this->savedPath === false ? putenv('PATH') : putenv('PATH='.$this->savedPath);

        foreach ($this->tmpDirs as $dir) {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
        }
        $this->tmpDirs = [];

        parent::tearDown();
    }

    public function test_an_unreachable_cache_backend_is_reported_rather_than_aborting_the_check(): void
    {
        Cache::swap(new Repository(new class extends ArrayStore
        {
            public function get($key)
            {
                throw new RuntimeException('no connection to localhost:6379');
            }
        }));

        $messages = $this->messagesFrom($this->drainedStore());

        // The witness that the throw was reached through the HEALTHY path and the check
        // ran to completion: without it, a check that aborted on its first line would
        // satisfy an absence-only assertion just as well.
        $this->assertContains(Severity::Ok, array_column($messages, 'severity'));
        $this->assertStringContainsString('retention: on (delete >30d', $messages[0]['message']);

        $this->assertStringContainsString(
            'retention: could not read the last-failure marker (no connection to localhost:6379)',
            end($messages)['message'],
        );
        $this->assertSame(Severity::Unvalidated, end($messages)['severity']);
    }

    /**
     * The discriminating control for the assertion above: on a reachable backend with no
     * marker, that message is ABSENT. Without this, the test would still pass against a
     * check that emitted the failure line unconditionally.
     */
    public function test_a_reachable_backend_with_no_marker_reports_nothing_about_it(): void
    {
        Cache::swap(new Repository(new ArrayStore));

        $messages = $this->messagesFrom($this->drainedStore());

        // The absence assertion below is only evidence if the check reached the marker
        // read at all: a check that yielded NOTHING would satisfy it just as well. This
        // witnesses the same healthy route the throwing-store test takes.
        $this->assertStringContainsString('retention: on (delete >30d', $messages[0]['message']);

        foreach ($messages as $finding) {
            $this->assertStringNotContainsString('could not read the last-failure marker', $finding['message']);
        }
    }

    /**
     * The leg used to assert `this PHP install has no fastcgi_finish_request()` from an
     * `ExecutableFinder` miss. That is a claim about the RECEIVER's SAPI drawn from the
     * PATH of a console process, and it is false on a healthy install whose php-fpm sits
     * in `/usr/sbin` — measured on the reference host, which serves the receiver under FPM
     * and still produced the claim from a shell whose PATH omitted it.
     */
    public function test_no_php_fpm_on_path_discloses_that_it_could_not_measure_rather_than_asserting(): void
    {
        Cache::swap(new Repository(new ArrayStore));
        putenv('PATH='.$this->binDir());

        $findings = $this->messagesFrom($this->drainedStore());

        // The healthy posture line, the two card#8374 cost legs this setUp's config
        // reaches (an empty store, and payload nulling being off), then this one —
        // asserted by COUNT so the leg is located structurally. Keying on message text
        // would let a reword silently turn the control below into a test of nothing.
        $this->assertCount(4, $findings);
        $this->assertStringContainsString('retention: on (delete >30d', $findings[0]['message']);

        $this->assertSame(Severity::Unvalidated, $findings[3]['severity']);
        $this->assertStringContainsString('could NOT determine whether the receiver ends the request early', $findings[3]['message']);
        $this->assertStringNotContainsString('this PHP install has no fastcgi_finish_request()', $findings[3]['message']);
    }

    /**
     * The discriminating control: with a findable binary the leg says nothing. Without
     * this, the assertion above would pass just as well against a check that emitted the
     * disclosure unconditionally — which would make the line useless rather than wrong.
     */
    public function test_a_findable_php_fpm_binary_leaves_the_leg_silent(): void
    {
        Cache::swap(new Repository(new ArrayStore));
        $bin = $this->binDir();
        // The name the check looks for FIRST, built from the same constants it uses.
        $stub = $bin.'/php-fpm'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        file_put_contents($stub, "#!/bin/sh\nexit 0\n");
        chmod($stub, 0o755);
        putenv('PATH='.$bin);

        $findings = $this->messagesFrom($this->drainedStore());

        // Three, not one: the posture line plus the two cost legs. The early-finish leg is
        // the one that must be absent, and the count is what says so.
        $this->assertCount(3, $findings);
        $this->assertStringContainsString('retention: on (delete >30d', $findings[0]['message']);
        foreach ($findings as $finding) {
            $this->assertStringNotContainsString('could NOT determine whether the receiver ends', $finding['message']);
        }
    }

    // ---- the cost legs (card#8374) ----
    //
    // Each pair is an arm and the control that discriminates it. The MESSAGES are pinned
    // byte-for-byte by the golden corpus, so what these add is the severity — the one
    // property a text capture witnesses nowhere — plus the arms the corpus has no fixture
    // for (a driver that reports no byte length; a store measurement that threw).

    public function test_the_store_line_reports_the_size_the_share_and_the_age_it_measured(): void
    {
        Cache::swap(new Repository(new ArrayStore));

        $line = $this->costLineFrom($this->messagesFrom($this->storeHolding()));

        $this->assertSame(Severity::Ok, $line['severity']);
        $this->assertStringContainsString('database 1.2 GiB', $line['message']);
        $this->assertStringContainsString('webhook_events 12345 rows, 11987 still carry a payload holding 894.0 MiB (~73% of the database)', $line['message']);
        $this->assertStringContainsString('oldest row 12.4d old, inside the 30d delete window', $line['message']);
    }

    /**
     * The `warn` arm — rows the window should already have taken are still here. This is
     * the only severity split in the cost legs that is decided by a MEASUREMENT rather
     * than by a config key, which is why it needs the control below.
     */
    public function test_an_oldest_row_past_the_delete_window_warns_and_names_the_window(): void
    {
        Cache::swap(new Repository(new ArrayStore));

        $line = $this->costLineFrom($this->messagesFrom($this->storeHolding(oldestRowAgeDays: 47.2)));

        $this->assertSame(Severity::Warn, $line['severity']);
        $this->assertStringContainsString('oldest row 47.2d old, PAST the 30d delete window', $line['message']);
        // The remedy names the batch bound, because a backlog draining 500 rows per
        // delivery is the benign reading of this line and the operator has to be able to
        // tell it from the delete leg not running at all.
        $this->assertStringContainsString('at most 500 rows', $line['message']);
    }

    /**
     * The discriminating control: the same store, one day inside the window, is `ok`. Only
     * the age moves, so a check that warned on the SIZE would fail here.
     */
    public function test_an_oldest_row_inside_the_delete_window_is_ok(): void
    {
        Cache::swap(new Repository(new ArrayStore));

        $line = $this->costLineFrom($this->messagesFrom($this->storeHolding(oldestRowAgeDays: 29.9)));

        $this->assertSame(Severity::Ok, $line['severity']);
        $this->assertStringNotContainsString('PAST the', $line['message']);
    }

    /**
     * THE LINE THE 894 MB INSTALL NEVER GOT. `retention: on (delete >30d …)` is what it
     * printed, and nothing in it could distinguish that install from one whose payloads
     * are being nulled.
     */
    public function test_payload_nulling_off_warns_with_the_bytes_it_is_holding(): void
    {
        Cache::swap(new Repository(new ArrayStore));

        $found = $this->lineContaining($this->messagesFrom($this->storeHolding()), 'payload NULLING is OFF');

        $this->assertSame(Severity::Warn, $found['severity']);
        $this->assertStringContainsString('11987 of 12345 retained rows still carry a full webhook payload, holding 894.0 MiB', $found['message']);
        $this->assertStringContainsString('kept until the 30d row-delete window removes the row', $found['message']);
    }

    /**
     * The discriminating control: with the leg ON, the check says nothing about it. Without
     * this the assertion above would pass against a check that warned unconditionally,
     * which would make the warning useless rather than wrong.
     */
    public function test_payload_nulling_on_says_nothing_about_it(): void
    {
        Cache::swap(new Repository(new ArrayStore));
        config(['bridge.retention.null_payloads_older_than' => '7d']);

        $messages = $this->messagesFrom($this->storeHolding());

        // The witness that the healthy route was reached at all — an absence assertion
        // over a check that yielded nothing would pass just as well.
        $this->assertStringContainsString('retention: on (delete >30d + null payloads >7d', $messages[0]['message']);
        foreach ($messages as $finding) {
            $this->assertStringNotContainsString('NULLING is OFF', $finding['message']);
            $this->assertStringNotContainsString('ROW-DELETE leg is OFF', $finding['message']);
        }
    }

    /**
     * The mirror leg: a payload-only install nulls payloads for ever and deletes nothing,
     * so its row count is unbounded. It is reported as its own line rather than folded
     * into the store line, because the remedy is a different config key.
     */
    public function test_the_row_delete_leg_off_warns_that_rows_are_never_deleted(): void
    {
        Cache::swap(new Repository(new ArrayStore));
        config(['bridge.retention.older_than' => '', 'bridge.retention.null_payloads_older_than' => '7d']);

        $messages = $this->messagesFrom($this->storeHolding());
        $found = $this->lineContaining($messages, 'ROW-DELETE leg is OFF');

        $this->assertSame(Severity::Warn, $found['severity']);
        $this->assertStringContainsString('12345 row(s) retained now', $found['message']);
        // With no window there is nothing to compare the age against, so the store line
        // must not manufacture one.
        $this->assertStringContainsString('oldest row 12.4d old (no delete window', $this->costLineFrom($messages)['message']);
    }

    /**
     * THE CONTROL THE CARD ASKS FOR: an empty store must report itself as empty without
     * inventing a row or an age. The database still HAS a size, which is what makes this
     * a real arm rather than an all-zeroes read.
     */
    public function test_an_empty_store_is_reported_without_inventing_a_row_or_an_age(): void
    {
        Cache::swap(new Repository(new ArrayStore));

        $line = $this->costLineFrom($this->messagesFrom($this->drainedStore()));

        $this->assertSame(Severity::Ok, $line['severity']);
        $this->assertStringContainsString('database 4.0 MiB · webhook_events is EMPTY (0 rows)', $line['message']);
        $this->assertStringNotContainsString('oldest row', $line['message']);
        $this->assertStringNotContainsString('% of the database', $line['message']);
    }

    /**
     * A measurement that threw is `unvalidated` and says so — never an empty store. The
     * two are one line apart in the output and opposite in meaning: one says retention is
     * holding nothing, the other says this run cannot tell.
     */
    public function test_an_unmeasurable_store_is_unvalidated_and_never_reads_as_an_empty_one(): void
    {
        Cache::swap(new Repository(new ArrayStore));

        $messages = $this->messagesFrom($this->unreadableStore('SQLSTATE[HY000]: no such table: webhook_events'));

        // Reached through the HEALTHY posture path and the check ran on afterwards.
        $this->assertStringContainsString('retention: on (delete >30d', $messages[0]['message']);
        $this->assertSame(Severity::Unvalidated, $messages[1]['severity']);
        $this->assertStringContainsString('could NOT measure what the store is holding (SQLSTATE[HY000]: no such table: webhook_events)', $messages[1]['message']);

        foreach ($messages as $finding) {
            $this->assertStringNotContainsString('is EMPTY', $finding['message']);
            // Nothing downstream of the measurement may speak either: an OFF leg with no
            // denominator is the bare setting again.
            $this->assertStringNotContainsString('NULLING is OFF', $finding['message']);
        }
    }

    /**
     * A figure the driver cannot source is ABSENT, not zero (DL-306's ruling, and the
     * card's). The golden corpus has no fixture for this arm because no driver the suite
     * runs on reaches it — which is exactly why it is asserted here.
     */
    public function test_a_figure_the_probe_could_not_source_is_absent_rather_than_zero(): void
    {
        Cache::swap(new Repository(new ArrayStore));

        $line = $this->costLineFrom($this->messagesFrom($this->storeHolding(payloadBytes: null, storeBytes: null)));

        $this->assertSame(Severity::Ok, $line['severity']);
        $this->assertStringContainsString('database size not reported by this driver', $line['message']);
        $this->assertStringContainsString('11987 still carry a payload (byte size not measurable on this database driver)', $line['message']);
        // The two shapes an inference would take: a zero, or a share computed from one.
        $this->assertStringNotContainsString('0 B', $line['message']);
        $this->assertStringNotContainsString('% of the database', $line['message']);
    }

    /**
     * ⛔ NOTHING BOUNDS THE SHARE AT 100%, because it is a quotient across two accounting
     * bases: a live `sum(length(payload))` over this table's rows, divided by whatever the
     * ENGINE reports for the whole database. Only SQLite makes the numerator a subset of
     * the denominator by construction; MariaDB's is `information_schema`'s allocation
     * accounting, and nothing in either source guarantees one contains the other
     * (`RetentionFootprint`, DL-331). Before this arm the renderer printed the quotient
     * unconditionally — a footprint of 937426944 inside a reported 400000000 rendered
     * `~234% of the database`, a capacity figure an operator cannot act on and cannot
     * tell from a real one.
     */
    public function test_a_payload_sum_larger_than_the_reported_database_prints_no_share(): void
    {
        Cache::swap(new Repository(new ArrayStore));

        $line = $this->costLineFrom($this->messagesFrom($this->storeHolding(payloadBytes: 937426944, storeBytes: 400000000)));

        // Both MEASUREMENTS still print — it is only their ratio that is dropped.
        $this->assertStringContainsString('database 381.5 MiB', $line['message']);
        $this->assertStringContainsString('11987 still carry a payload holding 894.0 MiB', $line['message']);
        $this->assertStringContainsString('MORE than the database size above, so no share is shown', $line['message']);
        $this->assertStringNotContainsString('% of the database', $line['message']);
        // The disagreement is a measurement-quality note, not a posture: the store's
        // severity is the age verdict and nothing else.
        $this->assertSame(Severity::Ok, $line['severity']);
    }

    /**
     * The discriminating control, pinned ON THE BOUNDARY rather than comfortably inside
     * it: a payload sum exactly EQUAL to the reported database still prints its share, so
     * the arm above is `>` and not a `>=` that would silently swallow the one case where
     * the two figures agree perfectly. Without it, a renderer that had simply stopped
     * printing shares would satisfy the assertions above.
     */
    public function test_a_payload_sum_equal_to_the_reported_database_still_prints_its_share(): void
    {
        Cache::swap(new Repository(new ArrayStore));

        $line = $this->costLineFrom($this->messagesFrom($this->storeHolding(payloadBytes: 1288490188, storeBytes: 1288490188)));

        $this->assertStringContainsString('(~100% of the database)', $line['message']);
        $this->assertStringNotContainsString('MORE than the database size', $line['message']);
    }

    /**
     * The store line, located by the one stem every one of its arms shares. Locating it by
     * INDEX would make each assertion above depend on how many legs precede it.
     *
     * @param  list<array{severity: Severity, message: string}>  $messages
     * @return array{severity: Severity, message: string}
     */
    private function costLineFrom(array $messages): array
    {
        return $this->lineContaining($messages, 'retention: database');
    }

    /**
     * @param  list<array{severity: Severity, message: string}>  $messages
     * @return array{severity: Severity, message: string}
     */
    private function lineContaining(array $messages, string $needle): array
    {
        foreach ($messages as $finding) {
            if (str_contains($finding['message'], $needle)) {
                return $finding;
            }
        }

        $this->fail("no finding contains '{$needle}'; got: ".json_encode(array_column($messages, 'message')));
    }

    /**
     * A fresh directory per call, and PATH is pointed at it and NOTHING else: any real
     * php-fpm on the host must be unreachable, or the miss case would be a lie on a box
     * that has one. `ExecutableFinder` resolves from `getenv('PATH')` plus a
     * `command -v` fallback inheriting the same PATH, so this controls the answer.
     */
    private function binDir(): string
    {
        $dir = sys_get_temp_dir().'/bridge-retention-posture-'.bin2hex(random_bytes(8));
        mkdir($dir, 0o755, true);
        $this->tmpDirs[] = $dir;

        return $dir;
    }

    /**
     * Run the check against a pinned store.
     *
     * THE DEFAULT PIN IS A DRAINED STORE, so a test that is about something else adds
     * exactly ONE line (the empty-store cost line) rather than a loaded store's cost line
     * plus whichever OFF leg the config reaches. The pin is required, never defaulted
     * inside the check: a check that could build its own probe would query the live
     * database from a unit test, which is the coupling the seam exists to remove.
     *
     * @return list<array{severity: Severity, message: string}>
     */
    private function messagesFrom(RetentionStoreProbe $store): array
    {
        return array_map(
            fn (Finding $f) => ['severity' => $f->severity, 'message' => $f->message],
            $this->findingsOf(new RetentionPostureCheck($store), new CheckContext),
        );
    }

    /** A probe that answers with the footprint it was given. */
    private function storeHolding(
        int $rows = 12345,
        int $rowsWithPayload = 11987,
        ?int $payloadBytes = 937426944,
        ?int $storeBytes = 1288490188,
        ?float $oldestRowAgeDays = 12.4,
    ): RetentionStoreProbe {
        $footprint = new RetentionFootprint($rows, $rowsWithPayload, $payloadBytes, $storeBytes, $oldestRowAgeDays);

        return new class($footprint) implements RetentionStoreProbe
        {
            public function __construct(private readonly RetentionFootprint $footprint) {}

            public function measure(): RetentionFootprint
            {
                return $this->footprint;
            }
        };
    }

    /** The drained store every unrelated test runs against. */
    private function drainedStore(): RetentionStoreProbe
    {
        return $this->storeHolding(rows: 0, rowsWithPayload: 0, payloadBytes: 0, storeBytes: 4194304, oldestRowAgeDays: null);
    }

    /** A probe whose measurement throws — the fail-soft arm's only route. */
    private function unreadableStore(string $message): RetentionStoreProbe
    {
        return new class($message) implements RetentionStoreProbe
        {
            public function __construct(private readonly string $message) {}

            public function measure(): RetentionFootprint
            {
                throw new RuntimeException($this->message);
            }
        };
    }
}
