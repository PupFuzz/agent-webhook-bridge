<?php

namespace Tests\Feature\Console\Check;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Support\CheckGolden\BootsGoldenInstall;
use Tests\Support\CheckGolden\GoldenInstall;
use Tests\TestCase;

/**
 * The two properties of `bridge:check`'s shared-identities derivation that its OUTPUT
 * cannot express (card#5546).
 *
 * Both are behavior the golden corpus is structurally unable to witness — one is a LOG
 * count and the other is a predicate whose branches print the same bytes — so a green
 * golden suite says nothing about either, and this file is what makes them measured.
 * It runs the real command over a real install shape rather than a hand-built context:
 * what is under test is the derivation in `handle()`, not any one check.
 */
class SharedIdentitiesDerivationTest extends TestCase
{
    use BootsGoldenInstall;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->tearDownGoldenInstall();
        parent::tearDown();
    }

    /**
     * THE CARD'S VERIFY CRITERION. A file that parses as an object but carries a
     * wrongly-shaped ENTRY logs one warning per parse, and the run used to parse it
     * twice — once for the registry build and once for the report — so an operator
     * tailing the log, or an alert counting the line, saw two faults where the install
     * has one. Nothing on stdout differs between the two states, which is exactly why
     * this is asserted on the log and not on a golden file.
     */
    public function test_a_wrongly_shaped_entry_warns_exactly_once_per_run(): void
    {
        $this->bootGoldenInstall('shared-identities-entry-warning', fn (GoldenInstall $i) => $i
            ->boot()
            ->agent('prod-agent', $this->kanbanAgentYaml())
            ->json('shared-identities.json', ['shared_identities' => [
                ['github_login' => 'no-id', 'agents' => ['prod-agent']],
            ]]));

        Log::spy();
        Artisan::call('bridge:check');

        // Argument-scoped: the run legitimately logs other things, and a bare count would
        // be answering about all of them.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => str_contains($m, 'entry without a numeric github_user_id'))
            ->once();
    }

    /**
     * The registry is built IFF there is a config dir AND at least one config parsed —
     * unchanged by card#5546, which split the guard's text without moving the build.
     *
     * THE GOLDEN SUITE IS BLIND TO THIS GATE, which is why the split owes a test of its
     * own: `docs/check-golden-coverage.md` is generated from `CheckCommand::handle()` and
     * disclosed this very predicate as one whose flip reds no golden file, so a green
     * corpus is not evidence that the build still happens under the same condition.
     *
     * The instrument is the registry's OWN construction-time warning: an entry naming an
     * agent with no YAML warns once per build, so the warning's presence is a witness that
     * a registry was constructed and its absence that none was.
     */
    public function test_no_agent_config_parses_and_the_registry_is_not_built(): void
    {
        $this->bootGoldenInstall('shared-identities-gate-negative', fn (GoldenInstall $i) => $i
            ->boot()
            // The ONLY .yml in the dir, and it does not parse — so `$configs` is empty
            // while the config dir is perfectly usable.
            ->agent('prod-agent', "identity:\n  kanban_user_id: 137\nsubscriptions: [\n")
            ->json('shared-identities.json', ['shared_identities' => [
                ['github_user_id' => 12000042, 'agents' => ['ghost']],
            ]]));

        Log::spy();
        Artisan::call('bridge:check');

        Log::shouldNotHaveReceived('warning', [Mockery::on(
            fn (string $m) => str_contains($m, 'references unknown agent')
        )]);
    }

    /** The positive control: the same file, one agent that DOES parse, one build. */
    public function test_a_parsed_agent_config_builds_the_registry_exactly_once(): void
    {
        $this->bootGoldenInstall('shared-identities-gate-positive', fn (GoldenInstall $i) => $i
            ->boot()
            ->agent('prod-agent', $this->kanbanAgentYaml())
            ->json('shared-identities.json', ['shared_identities' => [
                ['github_user_id' => 12000042, 'agents' => ['ghost']],
            ]]));

        Log::spy();
        Artisan::call('bridge:check');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $m) => str_contains($m, 'references unknown agent') && str_contains($m, 'ghost'))
            ->once();
    }

    private function kanbanAgentYaml(): string
    {
        return "identity:\n  kanban_user_id: 137\nsubscriptions:\n  - provider: kanban\n    scopes: [5]\n";
    }
}
