<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\RetentionPostureCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * TWO legs, and each is here for a reason the golden suite cannot cover. Everything else
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

        $messages = $this->messagesFrom(new RetentionPostureCheck);

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

        $messages = $this->messagesFrom(new RetentionPostureCheck);

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

        $findings = $this->messagesFrom(new RetentionPostureCheck);

        // Exactly the healthy posture line plus this leg — asserted by COUNT so the leg is
        // located structurally. Keying on message text would let a reword silently turn
        // the control below into a test of nothing.
        $this->assertCount(2, $findings);
        $this->assertStringContainsString('retention: on (delete >30d', $findings[0]['message']);

        $this->assertSame(Severity::Unvalidated, $findings[1]['severity']);
        $this->assertStringContainsString('could NOT determine whether the receiver ends the request early', $findings[1]['message']);
        $this->assertStringNotContainsString('this PHP install has no fastcgi_finish_request()', $findings[1]['message']);
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

        $findings = $this->messagesFrom(new RetentionPostureCheck);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('retention: on (delete >30d', $findings[0]['message']);
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

    /** @return list<array{severity: Severity, message: string}> */
    private function messagesFrom(RetentionPostureCheck $check): array
    {
        return array_map(
            fn (Finding $f) => ['severity' => $f->severity, 'message' => $f->message],
            $this->findingsOf($check, new CheckContext),
        );
    }
}
