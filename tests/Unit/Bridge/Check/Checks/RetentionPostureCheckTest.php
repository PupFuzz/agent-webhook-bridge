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
use Tests\TestCase;

/**
 * ONE leg only, deliberately: the fail-soft `catch` around the last-failure marker read.
 *
 * Every other posture this check can report is measured byte-for-byte by the golden suite
 * (`CheckGoldenTest` — named, not `{@see}`-linked, because pint's docblock fixer turns a
 * fully-qualified `{@see}` into a real `use`, and an import minted by a comment is one an
 * unused-import gate can never retire). That suite is the stronger measurement — it pins
 * the operator-visible line, not just the branch. The catch arm is
 * the one leg that suite cannot reach, because reaching it needs an unreachable cache
 * backend and a golden fixture is captured against a working install. It is also invisible
 * to `bin/check-golden-mutate.php`, which enumerates predicates in `CheckCommand::handle()`
 * and does not walk `catch` blocks — so without this test the arm is justified by reading
 * alone. Adding golden-covered legs here would duplicate the suite, not strengthen it.
 */
class RetentionPostureCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A healthy, usable posture — the only route that reaches the marker read.
        config([
            'bridge.retention.enabled' => true,
            'bridge.retention.older_than' => '30d',
            'bridge.retention.null_payloads_older_than' => '',
            'bridge.retention.interval' => 86400,
            'bridge.retention.batch' => 500,
        ]);
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
        $this->assertSame(Severity::Warn, end($messages)['severity']);
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

    /** @return list<array{severity: Severity, message: string}> */
    private function messagesFrom(RetentionPostureCheck $check): array
    {
        return array_map(
            fn (Finding $f) => ['severity' => $f->severity, 'message' => $f->message],
            iterator_to_array($check->run(new CheckContext), false),
        );
    }
}
