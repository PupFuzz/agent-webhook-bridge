<?php

namespace Tests\Feature\Console;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * THE EXPOSURE PREDICATE, AS A RUNNABLE THING RATHER THAN A NUMBER (card#9850 / DL-404).
 *
 * The card asked WHICH INSTALLS are exposed to the one-repo-one-board defect. That is not
 * derivable from any one seat — an install's `writeback.json` lives on its own machine and
 * there is no cross-install read path — so the deliverable is a command each seat runs
 * against its OWN config, and what this class pins is the three things that make its output
 * worth quoting:
 *
 *  - it EVALUATES, rather than flagging a config shape: a repo is exposed because a card its
 *    own merged pull requests cite resolves on none of its declared boards;
 *  - it says so in a four-part line that names its POPULATION and never implies a fleet;
 *  - ⛔ it establishes exposure WITHOUT ever learning, or printing, the board the off-set card
 *    is really on. That would take an unscoped read of an author-supplied id against a GLOBAL
 *    kanban id space (card#8375), and stdout is a durable surface. The negative assertion in
 *    {@see test_an_exposed_mapping_is_named_without_the_output_ever_learning_the_true_board}
 *    is what reds if somebody "improves" the report by adding one.
 */
class WritebackExposureCommandTest extends TestCase
{
    private const COORD_BOARD = 2;

    private const SPRINT_BOARD = 13;

    /** The card the coordination repo's merged PR cites. It is on neither declared board here. */
    private const CITED_CARD = 7908;

    /**
     * Where that card really lives. NOTHING this command prints may contain it.
     *
     * ⚑ Deliberately a four-digit id that appears in no other literal in this class. An
     * earlier draft used `9`, and the assertion could not fail for the right reason: the
     * digit is inside `card#7908` and inside the temp path, so it matched by coincidence and
     * would have gone on matching after the leak it exists to catch was introduced.
     */
    private const TRUE_BOARD = 9002;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/wbexp-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/kanban');
        File::ensureDirectoryExists($this->dir.'/github');
        config([
            'bridge.config_dir' => $this->dir,
            'bridge.secret_dir' => $this->dir,
            'bridge.providers.kanban.api_base_url' => 'https://kanban.example.com/api/v3',
            'bridge.providers.github.token_path' => null,
        ]);
        foreach (['kanban/writeback-token', 'github/token'] as $rel) {
            File::put($this->dir.'/'.$rel, 'a-token');
            chmod($this->dir.'/'.$rel, 0o600);
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /**
     * Run the command and hand back its WHOLE output.
     *
     * ⚑ Not `$this->artisan(…)->expectsOutputToContain(…)`: that helper CONSUMES each output
     * line it matches, so two expectations about one line make the second fail on a line that
     * is right there — which is exactly the shape of these findings, where the verdict, the
     * count and the card id share a line. Asserting over the whole buffer also lets the
     * security leg below assert a NEGATIVE, which no per-line expectation can express.
     */
    private function runExposure(int $expectedExit = 0): string
    {
        $this->assertSame($expectedExit, Artisan::call('bridge:writeback-exposure'));

        return Artisan::output();
    }

    /** @param  array<string, mixed>  $mapping */
    private function writeMapping(array $mapping): void
    {
        File::put($this->dir.'/writeback.json', (string) json_encode([
            'identity_id' => 4242,
            'mappings' => ['owner/coord-repo' => $mapping],
        ]));
    }

    /** @param  list<array<string, mixed>>  $pulls */
    private function githubAnswers(array $pulls): array
    {
        return ['https://api.github.com/repos/owner/coord-repo/pulls*' => Http::response($pulls)];
    }

    /**
     * ⭐ THE HEADLINE. A coordination repo mapped to board 2, whose merged PR cites a card that
     * is on neither declared board: EXPOSED, named, with the off-set card id given so the
     * operator knows which cards to look at when deciding what to add to `boards`.
     *
     * ⛔ AND THE OUTPUT NEVER NAMES BOARD 9. The kanban fake would answer an unscoped read of
     * this card HAPPILY — board and all — so "the true board never appears" is a measurement
     * of this command and not of what the credential could reach. The negative on the
     * unscoped-read URL is the mechanism half of the same assertion: the value cannot be in
     * the output because it was never fetched.
     */
    public function test_an_exposed_mapping_is_named_without_the_output_ever_learning_the_true_board(): void
    {
        $this->writeMapping(['board_id' => self::COORD_BOARD, 'create_coord_cards' => true, 'coord_card_stage_id' => 21]);
        Http::fake($this->githubAnswers([[
            'number' => 727, 'merged_at' => '2026-09-03T00:00:00Z',
            'title' => '[TASK] bridge reconcile cron wrapper (closes card#'.self::CITED_CARD.')',
            'head' => ['ref' => 'fix/card-'.self::CITED_CARD.'-bridge-reconcile-cron-wrapper'],
        ]]) + [
            // The declared board does not hold it…
            '*/tasks/search.json*' => Http::response(['data' => []]),
            // …and the unscoped read that would say where it IS answers happily, and must
            // never be made.
            '*/tasks/'.self::CITED_CARD.'.json' => Http::response(['data' => [
                'id' => self::CITED_CARD, 'board_id' => self::TRUE_BOARD,
            ]]),
        ]);

        $output = $this->runExposure();

        // FIRST, and deliberately: the security property. Asserted before the shape
        // assertions below because a leak introduced into the finding line would otherwise
        // trip one of those first, and a red at the wrong assertion is a red that does not
        // say what went wrong.
        $this->assertStringNotContainsString(
            (string) self::TRUE_BOARD,
            $output,
            'the report must never name the board an off-set card is really on: it was not measured, and the '
            .'only way to learn it is the unscoped read of an author-supplied id card#8375 exists to prevent. '
            .'Logging IS the leak, and stdout is a durable surface',
        );
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/tasks/'.self::CITED_CARD.'.json'));

        $this->assertStringContainsString('card#'.self::CITED_CARD.') -> EXPOSED', $output);
        $this->assertStringContainsString(
            '1 mappings reachable on this box, 1 evaluated, 1 exposed, 0 unreachable. Fleet-wide: not derivable.',
            $output,
        );
    }

    /**
     * The same repo once it has ADOPTED the fix: the cited card resolves on a declared board,
     * so the mapping is evaluated and clean. Without this leg the command could report every
     * mapping exposed and still satisfy the leg above — a verdict that cannot come back
     * negative is not a measurement.
     */
    public function test_a_mapping_that_declares_the_board_its_cards_are_on_is_evaluated_and_not_exposed(): void
    {
        $this->writeMapping([
            'board_id' => self::COORD_BOARD, 'create_coord_cards' => true, 'coord_card_stage_id' => 21,
            'boards' => [(string) self::SPRINT_BOARD => ['merged' => 97]],
        ]);
        Http::fake($this->githubAnswers([[
            'number' => 727, 'merged_at' => '2026-09-03T00:00:00Z',
            'title' => '[TASK] something (closes card#'.self::CITED_CARD.')',
            'head' => ['ref' => 'fix/card-'.self::CITED_CARD.'-slug'],
        ]]) + [
            '*/tasks/search.json?q=board_id%3D'.self::COORD_BOARD.'*' => Http::response(['data' => []]),
            '*/tasks/search.json?q=board_id%3D'.self::SPRINT_BOARD.'*' => Http::response(['data' => [
                ['id' => self::CITED_CARD, 'board_id' => self::SPRINT_BOARD],
            ]]),
        ]);

        $output = $this->runExposure();

        $this->assertStringContainsString('declares boards 2+13', $output);
        $this->assertStringContainsString('every one on a declared board -> not exposed', $output);
        $this->assertStringContainsString(
            '1 mappings reachable on this box, 1 evaluated, 0 exposed, 0 unreachable. Fleet-wide: not derivable.',
            $output,
        );
    }

    /**
     * A mapping this box could not ASK is `unreachable` and is NEVER folded into the clean
     * count — the whole reason the closing line carries four numbers rather than two. The
     * non-zero exit says the measurement is incomplete, so a caller that only reads the exit
     * code still cannot mistake it for a clean bill.
     */
    public function test_a_repo_whose_pull_requests_cannot_be_read_is_unreachable_and_never_clean(): void
    {
        $this->writeMapping(['board_id' => self::COORD_BOARD, 'create_coord_cards' => true, 'coord_card_stage_id' => 21]);
        Http::fake([
            'https://api.github.com/repos/owner/coord-repo/pulls*' => Http::response(['message' => 'Not Found'], 404),
            '*/tasks/search.json*' => Http::response(['data' => []]),
        ]);

        $output = $this->runExposure(expectedExit: 1);

        $this->assertStringContainsString('-> unreachable', $output);
        $this->assertStringContainsString(
            '1 mappings reachable on this box, 0 evaluated, 0 exposed, 1 unreachable. Fleet-wide: not derivable.',
            $output,
        );
    }

    /**
     * An install with the writeback OFF has no population, and that is reported as such rather
     * than as "0 exposed" — which would be a clean bill over a measurement nobody made. The
     * distinction is the whole difference between a zero and an empty.
     */
    public function test_an_install_with_no_writeback_json_reports_no_population_rather_than_a_clean_bill(): void
    {
        $output = $this->runExposure(expectedExit: 1);

        $this->assertStringContainsString('the writeback is OFF on this install', $output);
        $this->assertStringContainsString(
            '0 mappings reachable on this box, 0 evaluated, 0 exposed, 0 unreachable. Fleet-wide: not derivable.',
            $output,
        );
    }

    /**
     * A PR that cites cards on TWO boards must have BOTH looked at. `CardTokenGrammar::parse()`
     * answers the leftmost token only, so a command built on it would clear this repo on the
     * strength of the citation that happens to come first — the exact blindness the audit
     * exists to remove.
     */
    public function test_a_pull_request_citing_two_cards_has_both_probed(): void
    {
        $this->writeMapping([
            'board_id' => self::COORD_BOARD, 'stages' => ['merged' => 22],
            'boards' => [(string) self::SPRINT_BOARD => ['merged' => 97]],
        ]);
        Http::fake($this->githubAnswers([[
            'number' => 900, 'merged_at' => '2026-09-03T00:00:00Z',
            'title' => 'chore: card#111 and card#222 together',
            'head' => ['ref' => 'chore/two'],
        ]]) + [
            '*/tasks/search.json?q=board_id%3D'.self::COORD_BOARD.'%20id%3D111*' => Http::response(['data' => [['id' => 111, 'board_id' => self::COORD_BOARD]]]),
            '*/tasks/search.json*' => Http::response(['data' => []]),
        ]);

        $output = $this->runExposure();

        $this->assertStringContainsString('2 cited card(s), 1 on NO declared board (card#222) -> EXPOSED', $output,
            'card#111 resolves and card#222 does not, so a command reading only the LEFTMOST token would have '
            .'cleared this repo — the id named here is the proof both were probed');
    }
}
