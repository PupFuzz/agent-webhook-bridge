<?php

namespace Tests\Unit\Tools;

use App\Bridge\Support\ChannelSnapshotManifest;
use App\Bridge\Tools\BoardToolsRegistry;
use App\Bridge\Tools\ClientCapabilities;
use App\Bridge\Tools\ClientVersion;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The COMMITTED `resources/client-capabilities.json`, held against the three things it
 * restates (card#10566 / DL-425): the channel server this checkout bundles, the tools the
 * bridge registers, and `ClientVersion::FIRST_REPORTING_SNAPSHOT`.
 *
 * ⚠ WHAT THIS CANNOT SEE: whether a `since` is the RIGHT historical version. That needs the
 * git history, which a depth-1 CI checkout does not have; `bin/gen-client-capabilities.mjs
 * --check` owns it, as a step of the required SQLite job with the full history fetched. A
 * `since` (a feature's included) re-dated to another version that already existed stays green
 * here and reds there.
 */
class ClientCapabilityTableTest extends TestCase
{
    /**
     * The working tree's tool → argument set, read by the generator itself (`--current`), so the
     * set compared here is extracted exactly as the table's own history was. No git is involved.
     *
     * @return array{client_version: string, tools: array<string, list<string>>}
     */
    private static function currentSurface(): array
    {
        $process = new Process(['node', base_path('bin/gen-client-capabilities.mjs'), '--current']);
        $process->run();
        self::assertSame(0, $process->getExitCode(), 'gen-client-capabilities --current did not answer: '.$process->getErrorOutput());

        /** @var array{client_version: string, tools: array<string, list<string>>} $surface */
        $surface = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $surface;
    }

    public function test_the_table_is_dated_at_the_client_this_checkout_bundles(): void
    {
        $bundled = ChannelSnapshotManifest::readManifest(base_path('examples/channel-servers/package.json'));

        $this->assertSame(
            $bundled['version'],
            ClientCapabilities::bundled()->currentClientVersion,
            'resources/client-capabilities.json is dated at a different client than examples/channel-servers/package.json — regenerate it with `node bin/gen-client-capabilities.mjs`',
        );
    }

    public function test_what_the_table_says_the_current_client_declares_is_what_the_server_advertises(): void
    {
        $surface = self::currentSurface();
        $this->assertNotEmpty($surface['tools'], 'the generator extracted no tools from the working tree — the extraction, not the table, is what changed');

        $this->assertSame(
            $surface['tools'],
            ClientCapabilities::bundled()->declaredAt($surface['client_version']),
            'the capability table does not say the current client declares exactly what its TOOL_DEFINITIONS advertises — regenerate it with `node bin/gen-client-capabilities.mjs`',
        );
    }

    /**
     * Every tool the bridge registers and every argument it accepts is tabled, and tabled as
     * declared by the current client — set EQUALITY, so a tabled argument the bridge no longer
     * accepts reds as well. The population is the registry, never a list here.
     */
    public function test_every_registered_tool_and_accepted_argument_is_tabled_as_current(): void
    {
        $registry = new BoardToolsRegistry;
        $accepted = [];
        foreach ($registry->known() as $tool) {
            $arguments = $registry->resolve($tool)?->acceptedArguments() ?? [];
            sort($arguments);
            $accepted[$tool] = $arguments;
        }
        $this->assertNotEmpty($accepted, 'the registry came back empty — the derivation, not the table, is what changed');

        $caps = ClientCapabilities::bundled();

        $this->assertSame($accepted, $caps->declaredAt($caps->currentClientVersion));
    }

    /**
     * `features` is generated too — each dated by the markers `FEATURE_MARKERS` in the generator
     * names, and held there by `--check`. `client_version_report` restates a fact PHP already
     * carries, so the two copies are held equal here.
     */
    public function test_the_client_version_report_feature_is_the_first_reporting_snapshot(): void
    {
        $this->assertSame(
            ClientVersion::FIRST_REPORTING_SNAPSHOT,
            ClientCapabilities::bundled()->feature('client_version_report'),
            'features.client_version_report and ClientVersion::FIRST_REPORTING_SNAPSHOT name different first-reporting clients',
        );
    }
}
