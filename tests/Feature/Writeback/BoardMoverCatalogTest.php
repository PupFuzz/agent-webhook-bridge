<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Handlers\ChannelPushHandler;
use App\Bridge\Handlers\KanbanMoveCardHandler;
use App\Bridge\Writeback\WritebackAlertNotifier;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Tests\Support\BoardMoverCatalogCheck;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * The bridge's declaring-end check for `docs/board-mover-catalog.json`: the catalog is true of the
 * code. What counts as a site and why is {@see BoardMoverCatalogCheck}'s docblock; the contract is
 * `docs/writeback.md` § *The board-mover catalog*.
 *
 * The real-tree leg is one assertion over findings; the legs below it run the same checker over
 * fixtures that each break exactly one rule, so every red the real-tree leg can print is shown to
 * be printable.
 */
class BoardMoverCatalogTest extends TestCase
{
    private const FIXTURE_NOTIFIER = 'Fixture\In\Notifier';

    public function test_every_board_mover_log_site_carries_an_id_the_catalog_declares_and_back(): void
    {
        $sites = BoardMoverCatalogCheck::treeSites();

        // A walk that found nothing would report the catalog clean over an empty population.
        $this->assertNotEmpty($sites, 'the board-mover site walk found no site at all — the derivation, not the code, is what changed');

        $this->assertSame([], BoardMoverCatalogCheck::findings($sites, $this->catalog()));
    }

    public function test_the_population_is_derived_from_what_each_class_declares(): void
    {
        $files = array_map(SourceScan::relativeToApp(...), BoardMoverCatalogCheck::populationFiles());

        // Presence witnesses for each of the three membership routes, and one absence.
        $this->assertContains('Bridge/Handlers/KanbanMoveCardHandler.php', $files, 'a DurableReaction handler');
        $this->assertContains('Bridge/Classifiers/GitHubPrCardMoveClassifier.php', $files, 'an EmitsWritebackReactions classifier');
        $this->assertContains('Bridge/Writeback/WritebackAlertNotifier.php', $files, 'the Writeback namespace');
        $this->assertNotContains(
            SourceScan::relativeToApp((string) (new ReflectionClass(ChannelPushHandler::class))->getFileName()),
            $files,
            'a best-effort handler that writes no board state is outside the population',
        );
        $this->assertTrue(BoardMoverCatalogCheck::inPopulation(KanbanMoveCardHandler::class));

        foreach (['warnAndNotify', 'warnAndNotifyCardIdWithheld'] as $helper) {
            $this->assertContains($helper, BoardMoverCatalogCheck::notifierMethods());
        }
    }

    public function test_the_helper_writes_the_callers_id_into_the_log_context(): void
    {
        Log::spy();

        (new WritebackAlertNotifier)->warnAndNotify('fixture.arm', 'subject: refused', ['card_id' => 7], 'o/r', 'opened', 7, 'fixture_reason');
        (new WritebackAlertNotifier)->warnAndNotifyCardIdWithheld('fixture.withheld', 'subject: withheld', ['card_id' => 8], 'o/r', 'opened', 'fixture_reason');

        Log::shouldHaveReceived('warning')->with('subject: refused', ['catalog_id' => 'fixture.arm', 'card_id' => 7])->once();
        Log::shouldHaveReceived('warning')->with('subject: withheld', ['catalog_id' => 'fixture.withheld', 'card_id' => 8])->once();
    }

    public function test_the_walk_finds_every_site_shape_and_skips_what_is_not_a_site(): void
    {
        $source = <<<'PHP'
        <?php
        namespace Fixture\In;

        use Illuminate\Support\Facades\Log;

        final class Notifier
        {
            public function warnAndNotify(string $catalogId, string $message, array $context): void
            {
                Log::warning($message, ['catalog_id' => $catalogId] + $context);
            }

            public function selfReport(): void
            {
                Log::warning('push failed', ['catalog_id' => 'n.push_failed'] + $this->body());
            }
        }

        final class Handler
        {
            public function handle(): void
            {
                // Log::warning('a comment is not a site', ['catalog_id' => 'nope']);
                Log::info('literal', ['catalog_id' => 'h.literal', 'card_id' => 1]);
                Log::info('unioned', $this->context() + ['catalog_id' => 'h.unioned']);
                Log::warning('bare message');
                Log::warning('no key', ['card_id' => 1]);
                Log::warning('computed', ['catalog_id' => self::ID]);
                $this->alerts->warnAndNotify('h.paired', 'refused', ['card_id' => 1]);
                $this->alerts->warnAndNotify($id, 'refused', []);
                $this->alerts->warnAndNotify('h.twice', 'refused', ['catalog_id' => 'h.twice']);
                array_map(fn ($c) => Log::info('in a closure', ['catalog_id' => 'h.closure']), []);
            }
        }
        PHP;
        $source .= "\nnamespace Fixture\\Out;\nfinal class Elsewhere { public function f(): void { \\Illuminate\\Support\\Facades\\Log::warning('outside', []); } }\n";

        $sites = BoardMoverCatalogCheck::sitesIn($source, 'Fixture.php', self::fixturePopulation(...), self::FIXTURE_NOTIFIER, ['warnAndNotify']);

        $this->assertSame([
            ['Notifier::selfReport', 'log', 'n.push_failed', null],
            ['Handler::handle', 'log', 'h.literal', null],
            ['Handler::handle', 'log', 'h.unioned', null],
            ['Handler::handle', 'log', null, 'the call passes no context array'],
            ['Handler::handle', 'log', null, 'no `catalog_id` key in a literal context array'],
            ['Handler::handle', 'log', null, '`catalog_id` is not a string literal'],
            ['Handler::handle', 'alert_channel', 'h.paired', null],
            ['Handler::handle', 'alert_channel', null, 'the first argument to the notifier is not a string-literal catalog id'],
            ['Handler::handle', 'alert_channel', null, 'the log context also carries `catalog_id` — the helper adds it; declare it once'],
            ['Handler::handle', 'log', 'h.closure', null],
        ], array_map(fn (array $s) => [$s['site'], $s['via'], $s['id'], $s['problem']], $sites));
    }

    public function test_red_leg_1_a_site_without_an_id(): void
    {
        $this->assertFindings(
            ['SITE_WITHOUT_ID: Handler::handle (Fixture.php:10) — no `catalog_id` key in a literal context array'],
            "Log::warning('x', ['card_id' => 1]);",
            [],
        );
    }

    public function test_red_leg_2_an_id_the_catalog_does_not_list(): void
    {
        $this->assertFindings(
            ['ID_NOT_IN_CATALOG: `h.unknown` at Handler::handle (Fixture.php:10) is not a catalog entry'],
            "Log::warning('x', ['catalog_id' => 'h.unknown']);",
            [],
        );
    }

    public function test_red_leg_3_an_entry_whose_site_is_gone_unless_retired(): void
    {
        $this->assertFindings(
            ['ENTRY_WITHOUT_SITE: `h.gone` names Handler::handle, and no site in the population emits it — retire it (`retired_since`) or restore the site'],
            '',
            [self::entry('h.gone')],
        );
        $this->assertFindings([], '', [self::entry('h.gone') + ['retired_since' => '0.90.0']]);
        $this->assertFindings(
            ['RETIRED_ID_IN_USE: `h.gone` at Handler::handle (Fixture.php:10) is retired since 0.90.0'],
            "Log::warning('x', ['catalog_id' => 'h.gone']);",
            [self::entry('h.gone') + ['retired_since' => '0.90.0']],
        );
        $this->assertFindings(
            ['ENTRY_SITE_MISMATCH: `h.moved` declares Handler::elsewhere but is emitted at Handler::handle (Fixture.php:10)'],
            "Log::warning('x', ['catalog_id' => 'h.moved']);",
            [['site' => 'Handler::elsewhere'] + self::entry('h.moved')],
        );
    }

    public function test_red_leg_4_a_duplicate_id(): void
    {
        $this->assertFindings(
            ['DUPLICATE_ENTRY_ID: `h.dup` is declared more than once'],
            "Log::warning('x', ['catalog_id' => 'h.dup']);",
            [self::entry('h.dup'), self::entry('h.dup')],
        );
        $this->assertFindings(
            ['ID_USED_AT_MULTIPLE_SITES: `h.dup` is emitted at Handler::handle (Fixture.php:10), Handler::handle (Fixture.php:11)'],
            "Log::warning('x', ['catalog_id' => 'h.dup']);\n        Log::info('y', ['catalog_id' => 'h.dup']);",
            [self::entry('h.dup')],
        );
    }

    public function test_the_surface_and_schema_are_checked_against_the_site(): void
    {
        $this->assertSame([
            'CATALOG_SCHEMA: `schema` is not 1',
            'CATALOG_SCHEMA: `context_key` is not `catalog_id`',
            'CATALOG_EMPTY: the catalog declares no entries — nothing was checked',
        ], BoardMoverCatalogCheck::findings([], ['context_key' => 'reason', 'entries' => []]));

        $this->assertFindings(
            ['SURFACE_MISMATCH: `h.paired` is emitted through the paired log+alert helper, so its surface must include `alert_channel`'],
            "\$this->alerts->warnAndNotify('h.paired', 'x', []);",
            [self::entry('h.paired')],
        );
        $this->assertFindings(
            ['SURFACE_MISMATCH: `h.plain` is a plain log call, so its surface must not include `alert_channel`'],
            "Log::info('x', ['catalog_id' => 'h.plain']);",
            [['surface' => ['log', 'alert_channel']] + self::entry('h.plain')],
        );
        $this->assertFindings(
            [
                'ENTRY_SCHEMA: `h.bad` carries an undeclared field `pattern`',
                "ENTRY_SCHEMA: `h.bad` has a `kind` the catalog's `kinds` does not declare",
                'ENTRY_SCHEMA: `h.bad` `since` must be a release version X.Y.Z',
            ],
            "Log::info('x', ['catalog_id' => 'h.bad']);",
            [['kind' => 'nope', 'since' => 'next', 'pattern' => 'x'] + self::entry('h.bad')],
        );
    }

    /**
     * @param  list<string>  $expected
     * @param  list<array<string, mixed>>  $entries
     */
    private function assertFindings(array $expected, string $body, array $entries): void
    {
        $source = "<?php\nnamespace Fixture\\In;\n\nuse Illuminate\\Support\\Facades\\Log;\n\nfinal class Handler\n{\n    public function handle(): void\n    {\n        {$body}\n    }\n}\n";
        $sites = BoardMoverCatalogCheck::sitesIn($source, 'Fixture.php', self::fixturePopulation(...), self::FIXTURE_NOTIFIER, ['warnAndNotify']);
        // A retired anchor keeps a no-entry fixture from tripping CATALOG_EMPTY; retired and emitted
        // nowhere, it produces no finding of its own.
        $catalog = [
            'schema' => 1,
            'context_key' => 'catalog_id',
            'kinds' => ['declined' => 'x'],
            'entries' => $entries === [] ? [['retired_since' => '0.80.0'] + self::entry('h.anchor')] : $entries,
        ];
        $findings = BoardMoverCatalogCheck::findings($sites, $catalog);

        $this->assertSame($expected, $findings);
    }

    /** @return array<string, mixed> */
    private static function entry(string $id): array
    {
        return ['id' => $id, 'kind' => 'declined', 'surface' => ['log'], 'site' => 'Handler::handle', 'since' => '0.88.0'];
    }

    private static function fixturePopulation(string $class): bool
    {
        return str_starts_with($class, 'Fixture\\In\\');
    }

    /** @return array<string, mixed> */
    private function catalog(): array
    {
        $decoded = json_decode((string) file_get_contents(base_path(BoardMoverCatalogCheck::CATALOG)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
