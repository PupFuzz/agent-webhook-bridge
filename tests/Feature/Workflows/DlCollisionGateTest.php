<?php

namespace Tests\Feature\Workflows;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Wiring checks for `.github/workflows/dl-collision-gate.yml`, which runs
 * agent-board-toolkit's `dl-collision-gate` composite action (card#12338).
 *
 * The gate's behaviour — the grammar, the three assertions, the base snapshot,
 * the exit mapping — is the toolkit's, and is exercised by that repository's
 * selftests at the pinned SHA. What can go wrong HERE is the wiring: a mutable
 * pin, a dropped input, a shallow or re-pointed checkout, a `paths:` filter that
 * makes the gate absent, or the toolkit checkout action's `ref:` falling behind
 * this pin — dependabot bumps a `uses:` and never a `ref:`. Each is a case below.
 */
class DlCollisionGateTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/dl-collision-gate.yml';

    private const TOOLKIT_CHECKOUT = '.github/actions/agent-board-toolkit/action.yml';

    /** `uses: <owner>/<repo>/<path>@<40-hex>  # vX.Y.Z` — CLAUDE.md standing rule 8. */
    private const PINNED_GATE = '/^\s*-\s*uses:\s*PupFuzz\/agent-board-toolkit\/dl-collision-gate@([0-9a-f]{40})\s+#\s*(v\d+\.\d+\.\d+)\s*$/m';

    private const PINNED_REF = '/^\s*ref:\s*([0-9a-f]{40})\s+#\s*(v\d+\.\d+\.\d+)\s*$/m';

    /** @return array<string,mixed> */
    private function workflow(): array
    {
        return Yaml::parseFile(base_path(self::WORKFLOW));
    }

    /** @return list<array<string,mixed>> */
    private function steps(): array
    {
        return $this->workflow()['jobs']['dl-collision-gate']['steps'];
    }

    /** @return array<string,mixed> */
    private function gateStep(): array
    {
        $gate = array_values(array_filter(
            $this->steps(),
            fn (array $s): bool => str_starts_with((string) ($s['uses'] ?? ''), 'PupFuzz/agent-board-toolkit/dl-collision-gate@'),
        ));
        $this->assertCount(1, $gate, 'exactly one step runs the toolkit gate');

        return $gate[0];
    }

    /** @return array{0:string,1:string} [sha, version comment] */
    private function match(string $pattern, string $text, string $what): array
    {
        $this->assertSame(1, preg_match_all($pattern, $text, $m), "exactly one {$what}, pinned by full SHA with its # vX.Y.Z comment");

        return [$m[1][0], $m[2][0]];
    }

    public function test_the_gate_is_the_toolkit_action_pinned_by_full_sha_with_its_version_comment(): void
    {
        [$sha] = $this->match(self::PINNED_GATE, (string) file_get_contents(base_path(self::WORKFLOW)), 'toolkit gate step');

        $this->assertSame('PupFuzz/agent-board-toolkit/dl-collision-gate@'.$sha, $this->gateStep()['uses']);
    }

    public function test_the_gate_is_handed_the_pr_head_sha_and_the_base_ref_name(): void
    {
        // The action's required inputs (dl-collision-gate/action.yml at the pin).
        // The base SNAPSHOT is not an input: the action derives it from the merge.
        $this->assertSame([
            'head-sha' => '${{ github.event.pull_request.head.sha }}',
            'base-ref' => '${{ github.event.pull_request.base.ref }}',
        ], $this->gateStep()['with'] ?? null);
    }

    public function test_the_checkout_is_full_depth_on_the_default_ref_and_runs_first(): void
    {
        $steps = $this->steps();
        $checkout = $steps[0];

        $this->assertStringStartsWith('actions/checkout@', (string) ($checkout['uses'] ?? ''));
        $this->assertSame(0, $checkout['with']['fetch-depth'] ?? null, 'the gate reads the base snapshot and the target tip out of history');
        // The default ref on a pull_request event is refs/pull/N/merge — the only
        // work tree the gate accepts. Any `ref:` here re-points it.
        $this->assertArrayNotHasKey('ref', $checkout['with']);
        $this->assertGreaterThan(0, array_search($this->gateStep(), $steps, true), 'the checkout must come before the gate');
    }

    public function test_it_runs_on_every_pull_request_with_no_paths_filter(): void
    {
        $on = $this->workflow()['on'] ?? null;
        $this->assertIsArray($on);
        $this->assertSame(['opened', 'reopened', 'synchronize', 'edited'], $on['pull_request']['types'] ?? null, 'a base-branch retarget fires only `edited`');
        $this->assertArrayNotHasKey('paths', $on['pull_request'], 'a paths: filter that stops matching makes the gate ABSENT, not red');
        $this->assertArrayNotHasKey('paths-ignore', $on['pull_request']);
    }

    public function test_the_toolkit_checkout_ref_is_the_gate_pin(): void
    {
        // dependabot bumps the gate's `uses:` and never the checkout action's
        // `ref:`, so a bump PR reds HERE until the second follows the first —
        // one toolkit release on both paths, never two.
        $gate = $this->match(self::PINNED_GATE, (string) file_get_contents(base_path(self::WORKFLOW)), 'toolkit gate step');
        $ref = $this->match(self::PINNED_REF, (string) file_get_contents(base_path(self::TOOLKIT_CHECKOUT)), 'toolkit checkout ref');

        $this->assertSame($gate, $ref, self::TOOLKIT_CHECKOUT.' must check out the SHA (and name the version) the gate is pinned to');

        $action = Yaml::parseFile(base_path(self::TOOLKIT_CHECKOUT));
        $this->assertSame('PupFuzz/agent-board-toolkit', $action['runs']['steps'][0]['with']['repository'] ?? null);
    }
}
