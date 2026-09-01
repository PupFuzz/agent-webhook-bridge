<?php

namespace Tests\Feature\Writeback;

use App\Bridge\Writeback\KanbanClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use SplFileInfo;
use Tests\TestCase;

/**
 * A TRIPWIRE ON THE CALL CONSTRUCTION OF EVERY BOARD-SCOPED READ (card#7211, rt#327).
 *
 * THE MECHANISM IT GUARDS, measured live on this install and not inherited:
 *  - `q=board_id=<id>` IS enforced server-side. Proven with the control that makes the
 *    zero mean something: a token planted only on board B returned `total:1` scoped to B
 *    and `total:0` scoped to A — the scope suppressed a card the endpoint had just proven
 *    it could find. It holds past page 1 (B's rows excluded from the COUNT, not merely
 *    from the page), and `tags:` inside `q` is enforced the same way.
 *  - ⛔ BUT AN UNRECOGNISED **TOP-LEVEL** QUERY PARAMETER IS SILENTLY DROPPED, and the
 *    endpoint answers HTTP 200 with an UNFILTERED result set — every card the token can
 *    reach, across every board on a multi-tenant instance.
 *  - ⛔⛔ AND THE TRAP: `board_id` ALSO works as a bare top-level parameter and filters
 *    correctly there. So a developer who hoists `q=board_id=N` to `?board_id=N` tests it,
 *    sees it filter, and concludes top-level parameters are honoured. They are not — that
 *    one happens to be recognised. Hoist `tags` the same way and the filter evaporates
 *    with no change in the response shape.
 *
 * ⇒ THE DANGEROUS EDIT IS ENTIRELY ON OUR SIDE OF THE WIRE, and it is the more idiomatic
 * refactor of the two:
 *   `['q' => "board_id={$b} tags:\"{$t}\""]`  ← safe; the terms are inside `q`
 *   `['board_id' => $b, 'tags' => $t]`        ← unfiltered for any term the server does
 *                                                not recognise, 200 either way
 * NOTHING IN A 200 RESPONSE DISTINGUISHES A DROPPED FILTER FROM AN HONOURED ONE, so no
 * assertion on a search RESULT can see this change; a regression test that pins the
 * server's behaviour pins the wrong side of the wire. This class pins the CALL.
 *
 * ⚠ FILTER vs SWITCH — the invariant is about FILTERS, and the distinction is load-bearing.
 * A **filter** narrows the result set by data the caller supplies (board, tags, swimlane,
 * custom fields, free text); every one of those rides inside `q=`, and one hoisted to the
 * top level is the silent-drop defect above. A **switch** selects a MODE the endpoint is
 * measured to recognise as a top-level parameter — `limit`/`page` (the DL-146 pagination
 * contract) and `archived` (DL-296: `TasksController::search` applies
 * `whereNull('archived_at')` unless `?archived` is passed, `whereNotNull` when it is, with
 * no both-sides mode, so the archive side is reachable ONLY as a top-level switch). The
 * recognised set is therefore `q` + {@see SEARCH_SWITCHES}, and it is an ALLOWLIST rather
 * than a `board_id` denylist because a key this class has never heard of is indistinguishable
 * from a dropped one at the response. See `docs/kanban-integration-contract.md` §3.
 *
 * TWO DERIVATIONS — COMPLEMENTARY, BUT NOT INDEPENDENT, and the difference is stated here
 * because card#7211's own review found them blind in the SAME place:
 *  1. RUNTIME ({@see test_every_board_scoped_read_carries_its_board_term_inside_the_q_string}).
 *     Reflect the board-scoped read methods off `KanbanClient`, invoke every one under a
 *     capturing `Http::fake` — over a small ARGUMENT MATRIX, not one value per parameter —
 *     and assert on the URL that actually went out. It sees a query built anywhere: a
 *     helper, a merge, a base-URL default, an optional-parameter branch.
 *  2. SOURCE ({@see test_no_search_call_site_in_app_hoists_a_filter_to_a_top_level_parameter}).
 *     Tokenize every `app/` file that names `/tasks/search.json` and read the top-level keys
 *     of each call's query — including one assembled into a local `$query` variable — and
 *     reconcile the parsed call sites against the file's own count of endpoint mentions, so
 *     a call site it cannot follow reds instead of being skipped.
 *
 * WHAT EACH LEG IS BLIND TO, and which one covers it:
 *  - Runtime is blind to a board-scoped read whose board parameter is not NAMED like a board
 *    (it never enters the reflected population) → SOURCE covers it: the source scan keys on
 *    the endpoint, not on the method's signature.
 *  - Runtime is blind to a branch keyed on a value its matrix does not supply (the matrix is
 *    each parameter's default plus one sample; a third distinct value is unexercised)
 *    → SOURCE covers it, because that branch's `$query['key'] = …` write is in the source.
 *  - Source is blind to a query key contributed by the HTTP layer rather than the call site
 *    (a base-URL default, a `PendingRequest` global) → RUNTIME covers it: it reads the URL.
 *  - Source no longer goes blind on a query it cannot parse — it REDS. That is the point of
 *    the denominator reconciliation, and it is why "blind" above never means "silent".
 *  - RESIDUE, named rather than claimed away: the source scan reconciles PER FILE, so a call
 *    site whose path comes from a constant defined in ANOTHER file reds where the constant is
 *    DECLARED (that file names the endpoint and yields no call site) — but only while the
 *    declaration is under `app/`. One placed in config or a package would leave the calling
 *    file naming nothing, hence unselected. And the source scan reads a conditional
 *    `$query['k'] = …` as unconditionally present: it over-reports keys, which can only cause
 *    a red, never a silent pass.
 *
 * ⛔ THE ONE THING BOTH WERE BLIND TO, now closed, kept here because a reviewer trusted the
 * complementary-blindness claim and it did not hold: `cardRowsByTag($archivedOnly: true)`
 * (DL-296) adds a top-level key on an OPTIONAL-parameter branch. The runtime leg invoked
 * every method with a single argument set, so the branch never ran; the source leg could not
 * parse the conditionally-built `$query` at all. A shared blind spot is not two derivations.
 * Both legs were fixed rather than the claim narrowed — but the claim is now stated as
 * complementary-with-named-residue, not as independence.
 *
 * WHAT IT DOES NOT DO, stated plainly because a guard trusted for more than it does is
 * worse than no guard:
 *  - **It does not verify the server honours anything.** It asserts we ASK correctly. The
 *    server-side half is the establishing measurement above, which needs two boards and a
 *    planted token and does not belong in CI. Adding a key to {@see SEARCH_SWITCHES} is a
 *    claim about the SERVER: measure it with a control, and cite the decision.
 *  - **It does not re-check the RESULT.** A row whose own `board_id` is foreign still gets
 *    written to — that is the client-side board re-check, card#7211's second fix, which is
 *    a fail-closed tightening on a write path and is gated separately. This catches the bad
 *    CALL; that catches the bad RESULT; they are not redundant.
 *  - **It does not constrain WRITES.** `createCard` POSTs `board_id` in the BODY, which is
 *    the documented create contract and not a filter at all — non-GET requests are
 *    dispositioned out below, at the one place that decision is made.
 */
class BoardScopedReadConstructionTest extends TestCase
{
    /** The board under test. Deliberately not a real board id, and distinct from every other sample below. */
    private const BOARD_ID = 907011;

    /** Sample values for the non-board parameters, kept distinct so a term cannot be mistaken for the board scope. */
    private const SWIMLANE_ID = 771;

    private const STAGE_ID = 49;

    private const OTHER_INT = 31337;

    /** The endpoint whose call construction this class owns. */
    private const SEARCH_ENDPOINT = '/tasks/search.json';

    /**
     * The FILTER GRAMMAR's own parameter. Everything that narrows the result set by data —
     * board, tags, swimlane, custom fields, free text — rides inside this string, where the
     * server parses it. Hoisting any of those terms out of it is the defect this class exists
     * to catch.
     */
    private const SEARCH_FILTER_PARAM = 'q';

    /**
     * The top-level parameters kanban's `TasksController::search` is MEASURED to recognise.
     *
     * These are mode SWITCHES, not filters: `limit`/`page` are the DL-146 pagination contract,
     * and `archived` selects the other side of the archive axis (DL-296 — `whereNull` on
     * `archived_at` unless it is passed, `whereNotNull` when it is; there is no both-sides
     * mode, so the archived side is reachable ONLY here). ⛔ ADDING A KEY TO THIS LIST IS A
     * CLAIM ABOUT WHAT THE SERVER RECOGNISES, not a way to make this test go green: measure it
     * against a live instance with a control, record the measurement, and cite the decision
     * here and on the `docs/kanban-integration-contract.md` §3 row. A key that is really a
     * FILTER belongs inside `q=` no matter how the server treats it, because the grammar is
     * where a filter is guaranteed to be parsed.
     *
     * @var list<string>
     */
    private const SEARCH_SWITCHES = ['limit', 'page', 'archived'];

    /**
     * At most this many argument sets per method in the runtime matrix.
     *
     * A bound, not a convergence claim: the matrix is the cartesian product of each
     * parameter's candidate values, and a method that blows past this needs a NARROWED
     * candidate set (or a hand-written case), not a silently truncated matrix.
     */
    private const MAX_ARGUMENT_SETS = 32;

    /**
     * Board-scoped reads that MUST be reachable by the runtime derivation — the presence
     * witness for it. A scanner that matches nothing satisfies an absence assertion just as
     * well as a clean codebase does.
     *
     * This is a floor, NOT the population: a method added later is covered by the loop
     * without being listed here. It reds when the derivation stops SEEING a known reader —
     * a renamed board parameter, a method gone private, a client split out — which is the
     * failure mode that would quietly empty the guard.
     *
     * @var list<string>
     */
    private const KNOWN_BOARD_SCOPED_READS = [
        'cardRowsOnBoard',   // the card#8375 tenant check: (board, card id) → the row, or nothing
        'cardsByTag',        // the 4 writeback sites of card#7211: tag → ids
        'cardRowsByTag',     //   … tag → full rows
        'swimlaneCards',     //   … swimlane rows, paged
        'readBoardCards',    //   … full board read, paged
        'visibility',        // the bridge:check board probe
        'correlateDl',       // scan mode routes these three through the board read
        'correlatePr',
        'correlateIssue',
        'findCardsByRef',    // board in the PATH, not in `q` — the other legal construction
    ];

    /**
     * The subset of the above observed to actually emit a `/tasks/search.json` GET.
     *
     * Separate from the list above because reaching a method is not exercising its search:
     * `findCardsByRef` is board-scoped and emits no search at all, and the `correlate*`
     * trio only reaches the search in `scan` mode. Without this, a method that returned
     * early on the sample arguments would be "covered" by a loop that never ran.
     *
     * @var list<string>
     */
    private const KNOWN_SEARCH_READERS = [
        'cardRowsOnBoard',
        'cardsByTag',
        'cardRowsByTag',
        'swimlaneCards',
        'readBoardCards',
        'visibility',
        'correlateDl',
        'correlatePr',
        'correlateIssue',
    ];

    /**
     * `q` plus the measured switches — the complete set of top-level keys a search GET may
     * carry. Derived rather than declared so the two halves cannot drift apart.
     *
     * @return list<string>
     */
    private static function recognisedTopLevel(): array
    {
        return array_merge([self::SEARCH_FILTER_PARAM], self::SEARCH_SWITCHES);
    }

    /** @param list<string> $stray */
    private static function strayKeyMessage(string $where, array $stray, string $detail): string
    {
        return "{$where}: ".self::SEARCH_ENDPOINT.' carries top-level parameter(s) ['.implode(', ', $stray).'] '
            ."— {$detail}. The recognised top-level set is `".self::SEARCH_FILTER_PARAM.'` (the filter grammar) '
            .'plus the MEASURED switches ['.implode(', ', self::SEARCH_SWITCHES).']. A key outside it is either a '
            .'FILTER, which must ride inside `q=`, or one the server does not recognise — and an unrecognised '
            .'top-level parameter is dropped WITHOUT ERROR, so the search returns UNFILTERED across every board '
            .'the token can reach. Widening the switch set is a claim about what kanban recognises: measure it '
            .'against a live instance with a control and cite the decision (card#7211; `archived` = DL-296).';
    }

    public function test_every_board_scoped_read_carries_its_board_term_inside_the_q_string(): void
    {
        $searchReads = 0;

        foreach ($this->exercise() as $site => $requests) {
            foreach ($requests as $request) {
                if ($request->method() !== 'GET') {
                    // DISPOSITIONED, not skipped: a POST/PATCH body is not a query filter.
                    // `createCard` legitimately sends `board_id` as a top-level body field
                    // (the documented create contract) and nothing is dropped from a body.
                    continue;
                }

                $url = $request->url();
                $query = self::queryOf($url);

                if (! str_contains($url, self::SEARCH_ENDPOINT)) {
                    // The other legal construction: the board rides in the PATH
                    // (`/boards/{id}/…`), where it is a route segment and cannot be dropped.
                    $this->assertStringContainsString('/boards/'.self::BOARD_ID.'/', $url,
                        "{$site}: a board-scoped GET carries the board neither in the path nor as a search — {$url}");
                    $this->assertArrayNotHasKey('board_id', $query,
                        "{$site}: the board scope is a top-level query parameter — silently droppable — on {$url}");

                    continue;
                }

                $searchReads++;

                $this->assertMatchesRegularExpression(
                    '/(?<![a-z_])board_id='.self::BOARD_ID.'(?![0-9])/',
                    (string) ($query[self::SEARCH_FILTER_PARAM] ?? ''),
                    "{$site}: the board scope is NOT inside the q= term of {$url}. An unrecognised top-level "
                    .'parameter is silently dropped and the endpoint returns 200 with an UNFILTERED, '
                    .'fleet-wide result set — put the board back inside q= (card#7211).'
                );

                $stray = array_values(array_diff(array_keys($query), self::recognisedTopLevel()));
                $this->assertSame([], $stray, self::strayKeyMessage((string) $site, $stray, "on {$url}"));
            }
        }

        // Presence witness: the rule above is only evidence if it ran. A guard that
        // inspected zero requests passes every assertion in the loop.
        $this->assertGreaterThanOrEqual(count(self::KNOWN_SEARCH_READERS), $searchReads,
            'the construction rule inspected fewer search reads than there are known search readers — the '
            .'derivation, not the code, is what changed');
    }

    public function test_the_derivation_reaches_every_known_board_scoped_read(): void
    {
        $derived = array_map(static fn (ReflectionMethod $m): string => $m->getName(), self::boardScopedMethods());
        sort($derived);

        foreach (self::KNOWN_BOARD_SCOPED_READS as $known) {
            $this->assertContains($known, $derived,
                "the board-scoped read `{$known}` is no longer reachable by the derivation, so nothing checks its "
                .'call construction. Either its board parameter was renamed (teach `namesABoard`) or it moved off '
                .'KanbanClient (point the derivation at its new home) — do not delete the expectation.');
        }

        $captured = $this->exercise();
        $searchReaders = [];
        foreach ($captured as $site => $requests) {
            $this->assertNotSame([], $requests,
                "{$site} emitted no HTTP request at all, so its call construction is UNCHECKED. It most likely "
                .'returned early on the sample arguments — add a sample for its parameter in `sampleValues()` (or, '
                .'if it genuinely performs no read, disposition it here on the record).');

            [, $method] = explode('::', (string) $site, 2);
            foreach ($requests as $request) {
                if ($request->method() === 'GET' && str_contains($request->url(), self::SEARCH_ENDPOINT)) {
                    $searchReaders[$method] = true;
                }
            }
        }

        foreach (self::KNOWN_SEARCH_READERS as $reader) {
            $this->assertArrayHasKey($reader, $searchReaders,
                "`{$reader}` no longer reaches ".self::SEARCH_ENDPOINT.' under either correlation mode — its '
                .'construction was asserted against nothing.');
        }
        $this->assertGreaterThanOrEqual(count(self::KNOWN_SEARCH_READERS), count($searchReaders));
    }

    /**
     * The runtime matrix must EXERCISE the optional-parameter branches, not merely reach the
     * methods that own them.
     *
     * Its own presence witness, and the one card#7211's review showed was missing: with a
     * single argument set per method, `cardRowsByTag`'s `$archivedOnly` branch — which adds a
     * top-level key (DL-296) — never ran, so the assertion above was green about a call it had
     * never made. This asserts the matrix actually emits BOTH sides of a boolean branch, so
     * that coverage cannot quietly collapse back to one sample.
     */
    public function test_the_runtime_matrix_exercises_both_sides_of_an_optional_parameter_branch(): void
    {
        $urls = [];
        foreach ($this->exercise() as $site => $requests) {
            if (! str_ends_with((string) $site, '::cardRowsByTag')) {
                continue;
            }
            foreach ($requests as $request) {
                $urls[] = $request->url();
            }
        }

        $withSwitch = array_values(array_filter($urls, static fn (string $u): bool => array_key_exists('archived', self::queryOf($u))));
        $without = array_values(array_filter($urls, static fn (string $u): bool => ! array_key_exists('archived', self::queryOf($u))));

        $this->assertNotSame([], $without,
            'the matrix never exercised cardRowsByTag on its DEFAULT (live) side — the read every caller but the '
            .'DL-296 archived-twin pre-check makes.');
        $this->assertNotSame([], $withSwitch,
            'the matrix never exercised cardRowsByTag($archivedOnly: true), so the branch that adds a top-level '
            .'parameter went unasserted — which is exactly the shared blind spot card#7211 closed. `sampleValues()` '
            .'must offer both sides of a boolean/optional parameter; a single value per parameter is a coverage '
            .'hole wearing a green tick.');
    }

    public function test_no_search_call_site_in_app_hoists_a_filter_to_a_top_level_parameter(): void
    {
        $callSites = 0;

        foreach (self::appPhpFiles() as $path) {
            $src = (string) file_get_contents($path);
            if (! str_contains($src, self::SEARCH_ENDPOINT)) {
                continue;
            }

            $tokens = self::tokensOf($src);
            $mentions = self::endpointMentions($tokens);
            try {
                $sites = self::searchCallSites($tokens);
            } catch (\RuntimeException $e) {
                $this->fail("{$path}: {$e->getMessage()}");
            }

            // DENOMINATOR RECONCILIATION. A file that names the endpoint in CODE must yield
            // exactly that many parsed call sites. A mention with no call site is a shape this
            // scan cannot follow — a path constant, an interpolated path, a call built through
            // a helper — and it REDS here rather than being `continue`d into invisibility,
            // which is the failure mode that would let the next call site go unchecked while
            // the floor below stayed satisfied by the existing ones. (A mention that appears
            // only in a comment or docblock is not code and is not counted, on either side.)
            $this->assertCount($mentions, $sites,
                "{$path}: names ".self::SEARCH_ENDPOINT." in code {$mentions}× but this scan resolved "
                .count($sites).' call site(s). A call site it cannot follow is NOT exempt — it is unchecked. '
                .'Either reshape the call so the path is a string literal and the query an array literal (or a '
                .'local `$query` variable built from one), or teach `searchCallSites()` the new shape.');

            foreach ($sites as $site) {
                $callSites++;
                $where = $path.':'.$site['line'];

                $this->assertNotNull($site['q'],
                    "{$where}: a ".self::SEARCH_ENDPOINT.' call with no `q=` term at all — the board scope has '
                    .'nowhere to ride, so this search is fleet-wide (card#7211).');

                $this->assertMatchesRegularExpression('~^"board_id=\{\$~', (string) $site['q'],
                    "{$where}: a ".self::SEARCH_ENDPOINT.' call whose q= term does not open with the board scope '
                    .'— '.(string) $site['q']);

                $stray = array_values(array_diff($site['keys'], self::recognisedTopLevel()));
                $this->assertSame([], $stray, self::strayKeyMessage($where, $stray, 'in the source query'));
            }
        }

        // Presence witness for the source derivation, with a count.
        //
        // The DERIVATION, not the figure, is what is pinned: `$callSites` is recomputed from
        // the tree on every run by the loop above, and the loop reconciles its own denominator
        // per file, so a call site the scan stops finding cannot hide behind this number. The
        // floor is only the floor — RE-DERIVE it (run this test; the count is the loop's) rather
        // than carrying it forward, because a number carried across the passes that falsified it
        // stops living in the loop. It reds when the scan stops FINDING the call sites; a NEW
        // one does not red it, being checked by the loop above.
        $this->assertGreaterThanOrEqual(6, $callSites,
            'the source scan found fewer '.self::SEARCH_ENDPOINT.' call sites than exist — it is reporting where '
            .'the searcher stopped, not the state of the code');
    }

    /**
     * Invoke every board-scoped read under a capturing fake, in BOTH correlation modes
     * (`ref` routes the `correlate*` trio at the by-ref path, `scan` routes them at the
     * board search — one mode alone leaves the other construction unexercised), and over
     * the ARGUMENT MATRIX (see {@see sampleValues()}) rather than one value per parameter.
     *
     * @return array<string, list<Request>> "mode::method" → the requests it emitted
     */
    private function exercise(): array
    {
        $captured = [];
        $site = '';

        Http::fake(function (Request $request) use (&$captured, &$site) {
            $captured[$site][] = $request;

            // One body that satisfies every reader's shape: no rows, an exact zero total,
            // and an explicit terminal `links.next` so the page walks stop at page 1.
            return Http::response(['data' => [], 'meta' => ['total' => 0], 'links' => ['next' => null]]);
        });

        foreach (['ref', 'scan'] as $mode) {
            $client = new KanbanClient('https://kanban.example.com/api/v3', 'wb-token', $mode);
            foreach (self::boardScopedMethods() as $method) {
                $site = $mode.'::'.$method->getName();
                $captured[$site] ??= [];
                foreach ($this->argumentSets($method) as $args) {
                    try {
                        $method->invokeArgs($client, $args);
                    } catch (\Throwable) {
                        // The request was captured before the throw — an empty `data` envelope
                        // makes some readers (createCard) fail on the RESPONSE, which is not what
                        // is under test. A method that emitted nothing is caught by the coverage
                        // assertion, not swallowed here.
                    }
                }
            }
        }

        return $captured;
    }

    /**
     * The ARGUMENT MATRIX for one method: the cartesian product of each parameter's candidate
     * values.
     *
     * One value per parameter is not coverage of a method with an optional parameter — the
     * branch that parameter gates is precisely where a top-level key gets added (DL-296's
     * `archived`), and it is invisible to a single invocation. Bounded by
     * {@see MAX_ARGUMENT_SETS}, and the bound FAILS rather than truncating: a silently
     * shortened matrix is the same coverage hole one level up.
     *
     * @return list<list<mixed>>
     */
    private function argumentSets(ReflectionMethod $method): array
    {
        $sets = [[]];
        foreach ($method->getParameters() as $parameter) {
            $next = [];
            foreach ($sets as $set) {
                foreach ($this->sampleValues($method, $parameter) as $value) {
                    $next[] = [...$set, $value];
                }
            }
            $sets = $next;

            $this->assertLessThanOrEqual(self::MAX_ARGUMENT_SETS, count($sets),
                "the argument matrix for {$method->getName()}() exceeds ".self::MAX_ARGUMENT_SETS.' sets. Narrow '
                .'the candidate values in `sampleValues()` for the parameters that do not gate a query-shape '
                .'branch — do not truncate the matrix silently.');
        }

        return $sets;
    }

    /**
     * The population: every public instance method of the kanban client that takes a board.
     *
     * DERIVED, NEVER LISTED. A board-scoped read added later is covered without editing an
     * enumeration — which is the point, since the edit this guards against is exactly the
     * kind a reviewer approves.
     *
     * @return list<ReflectionMethod>
     */
    private static function boardScopedMethods(): array
    {
        $methods = [];
        foreach ((new ReflectionClass(KanbanClient::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }
            foreach ($method->getParameters() as $parameter) {
                if (self::namesABoard($parameter->getName())) {
                    $methods[] = $method;
                    break;
                }
            }
        }

        return $methods;
    }

    private static function namesABoard(string $parameter): bool
    {
        return preg_match('/^board(_?id)?$/i', $parameter) === 1;
    }

    /**
     * The candidate values for one parameter: a value its method ACCEPTS, plus the parameter's
     * own default when it has one, plus both sides of a boolean.
     *
     * A default is a distinct branch, not a fallback — `findCardsByRef`'s `$source` omits a
     * query key when null and adds one when set, and `cardRowsByTag`'s `$archivedOnly` is the
     * DL-296 switch. Exercising only the non-default side leaves the shipped behaviour of
     * every ordinary caller unasserted, and exercising only the default side leaves the branch
     * that adds a key unasserted; the matrix runs both.
     *
     * @return list<mixed>
     */
    private function sampleValues(ReflectionMethod $method, ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

        $values = $typeName === 'bool' ? [false, true] : [$this->sampleValue($method, $parameter)];

        if ($parameter->isDefaultValueAvailable()) {
            $default = $parameter->getDefaultValue();
            if (! in_array($default, $values, true)) {
                $values[] = $default;
            }
        }

        return $values;
    }

    /**
     * A sample argument for one parameter.
     *
     * Named parameters get a value their method will ACCEPT — `correlateDl` returns early on
     * a `$dl` with no digits and would emit no request at all, which is a coverage hole
     * wearing a green tick. An unrecognised type fails loudly rather than defaulting: a
     * silently-skipped method is the failure this whole class exists to prevent.
     */
    private function sampleValue(ReflectionMethod $method, ReflectionParameter $parameter): mixed
    {
        $name = $parameter->getName();
        if (self::namesABoard($name)) {
            return self::BOARD_ID;
        }

        $byName = match ($name) {
            'dl' => 'DL-1',
            'system' => 'dl',
            'ref' => '1',
            'source', 'repo' => 'owner/repo',
            'tag' => 'id:sentinel',
            'swimlaneId' => self::SWIMLANE_ID,
            'stageId' => self::STAGE_ID,
            default => null,
        };
        if ($byName !== null) {
            return $byName;
        }

        $type = $parameter->getType();

        return match ($type instanceof ReflectionNamedType ? $type->getName() : null) {
            'int' => self::OTHER_INT,
            'string' => 'sentinel',
            'bool' => false,
            'array' => [],
            default => $this->fail(
                "no sample value for {$method->getName()}(\${$name}) — teach `sampleValue()` a value its method "
                .'accepts, or the method goes unexercised and its call construction unchecked'
            ),
        };
    }

    /** @return array<string, string> the top-level query parameters of a URL */
    private static function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    /** @return list<string> */
    private static function appPhpFiles(): array
    {
        $paths = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        return $paths;
    }

    // ── The source derivation, on PHP's own tokenizer ──────────────────────────────────
    //
    // A regex over the raw text finds a call site only in the exact spelling it was written
    // to match: a double-quoted path, a path constant, or a query assembled into a variable
    // all read as "no call site here", which is a SKIP wearing the same green as a clean
    // file. The tokenizer gives the two things the regex could not: the real string literals
    // (quoting-independent, comments excluded) and balanced nesting, so a nested array in a
    // query is parsed rather than truncated into a false "unparseable".

    /**
     * @return list<array{id: int|null, text: string, line: int}>
     */
    private static function tokensOf(string $src): array
    {
        $out = [];
        $line = 1;
        foreach (token_get_all($src) as $token) {
            if (is_array($token)) {
                $out[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];
                $line = $token[2] + substr_count($token[1], "\n");
            } else {
                $out[] = ['id' => null, 'text' => $token, 'line' => $line];
            }
        }

        return $out;
    }

    /**
     * How many times the file names the endpoint in CODE — the source leg's denominator.
     *
     * Comments and docblocks are excluded on purpose: this class's own prose names the
     * endpoint, and so does `KanbanClient`'s. What must reconcile is CODE mentions against
     * parsed call sites.
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function endpointMentions(array $tokens): int
    {
        $n = 0;
        foreach ($tokens as $token) {
            if (in_array($token['id'], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (str_contains($token['text'], self::SEARCH_ENDPOINT)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Every `->get(<path naming the search endpoint>, <query>)` in one file, with the query's
     * TOP-LEVEL keys resolved.
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return list<array{keys: list<string>, q: string|null, line: int}>
     */
    private static function searchCallSites(array $tokens): array
    {
        $sites = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_OBJECT_OPERATOR) {
                continue;
            }
            $name = self::significant($tokens, $i + 1);
            if ($name === null || $tokens[$name]['text'] !== 'get') {
                continue;
            }
            $paren = self::significant($tokens, $name + 1);
            if ($paren === null || $tokens[$paren]['text'] !== '(') {
                continue;
            }

            $args = self::balancedGroup($tokens, $paren);
            if ($args === null || $args === []) {
                continue;
            }
            if (! str_contains(self::textOf($tokens, $args[0]), self::SEARCH_ENDPOINT)) {
                continue;
            }

            $sites[] = self::queryShape($tokens, $args, $i) + ['line' => $tokens[$i]['line']];
        }

        return $sites;
    }

    /**
     * The top-level keys (and the `q` value's source text) of a search call's query argument.
     *
     * Resolves BOTH shapes in the tree: an inline array literal, and a query assembled into a
     * local variable — `$query = [...]` followed by `$query['key'] = …` (DL-296's conditional
     * `archived`). Anything else returns a `q` of null and no keys ONLY by way of a hard
     * failure, never silently: an unresolvable shape throws so the call site cannot pass
     * unchecked.
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  list<list<int>>  $args
     * @return array{keys: list<string>, q: string|null}
     */
    private static function queryShape(array $tokens, array $args, int $callIndex): array
    {
        $line = $tokens[$callIndex]['line'];
        if (! isset($args[1])) {
            return ['keys' => [], 'q' => null];
        }

        $first = self::firstSignificant($tokens, $args[1]);
        if ($first === null) {
            return ['keys' => [], 'q' => null];
        }

        if ($tokens[$first]['text'] === '[') {
            return self::arrayLiteralShape($tokens, $first, $line);
        }

        if ($tokens[$first]['id'] === T_VARIABLE && self::significant($tokens, $first + 1, $args[1]) === null) {
            return self::variableShape($tokens, $tokens[$first]['text'], $callIndex, $line);
        }

        throw new \RuntimeException(
            "line {$line}: the query argument of a ".self::SEARCH_ENDPOINT.' call is neither an array literal nor '
            .'a local variable this scan can resolve. It is NOT exempt — an unresolved query is an unchecked one. '
            .'Reshape the call, or teach `queryShape()` the new form.'
        );
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return array{keys: list<string>, q: string|null}
     */
    private static function arrayLiteralShape(array $tokens, int $open, int $line): array
    {
        $elements = self::balancedGroup($tokens, $open);
        if ($elements === null) {
            throw new \RuntimeException("line {$line}: an unterminated query array literal.");
        }

        $keys = [];
        $q = null;
        foreach ($elements as $element) {
            $k = self::firstSignificant($tokens, $element);
            if ($k === null) {
                continue;
            }
            $arrow = self::significant($tokens, $k + 1, $element);
            if ($tokens[$k]['id'] !== T_CONSTANT_ENCAPSED_STRING || $arrow === null || $tokens[$arrow]['id'] !== T_DOUBLE_ARROW) {
                throw new \RuntimeException(
                    "line {$line}: a query element that is not a `'key' => value` pair (a spread, a variable key, "
                    .'or a positional element). Its top-level key cannot be read, so the call is unchecked.'
                );
            }
            $key = trim($tokens[$k]['text'], "'\"");
            $keys[] = $key;
            if ($key === self::SEARCH_FILTER_PARAM) {
                $value = array_values(array_filter($element, static fn (int $x): bool => $x > $arrow));
                $q = trim(self::textOf($tokens, $value));
            }
        }

        return ['keys' => $keys, 'q' => $q];
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return array{keys: list<string>, q: string|null}
     */
    private static function variableShape(array $tokens, string $variable, int $callIndex, int $line): array
    {
        // PHP variable scope is the innermost enclosing function, so that is the window the
        // assignments are read from — `$query` is a common local name and another method's
        // `$query` must not leak into this one's shape.
        $start = 0;
        for ($i = $callIndex; $i >= 0; $i--) {
            if (in_array($tokens[$i]['id'], [T_FUNCTION, T_FN], true)) {
                $start = $i;
                break;
            }
        }

        $keys = null;
        $q = null;
        for ($i = $start; $i < $callIndex; $i++) {
            if ($tokens[$i]['id'] !== T_VARIABLE || $tokens[$i]['text'] !== $variable) {
                continue;
            }
            $next = self::significant($tokens, $i + 1);
            if ($next === null) {
                continue;
            }

            if ($tokens[$next]['text'] === '=') {
                $open = self::significant($tokens, $next + 1);
                if ($open === null || $tokens[$open]['text'] !== '[') {
                    throw new \RuntimeException(
                        "line {$tokens[$i]['line']}: `{$variable}` is assigned from a shape this scan cannot "
                        .'resolve (a merge, a helper call, a spread). Its top-level keys are unknown, so the '
                        .self::SEARCH_ENDPOINT.' call it feeds is unchecked.'
                    );
                }
                $shape = self::arrayLiteralShape($tokens, $open, $tokens[$i]['line']);
                $keys = $shape['keys'];
                $q = $shape['q'];

                continue;
            }

            if ($tokens[$next]['text'] !== '[') {
                continue;   // a read of the variable, not a write
            }

            $key = self::significant($tokens, $next + 1);
            $close = $key === null ? null : self::significant($tokens, $key + 1);
            $assign = $close === null ? null : self::significant($tokens, $close + 1);
            if ($assign === null || $tokens[$assign]['text'] !== '=') {
                continue;   // `$query['k']` read, or `$query[]` — not a keyed write
            }
            if ($key === null || $tokens[$key]['id'] !== T_CONSTANT_ENCAPSED_STRING || $tokens[$close]['text'] !== ']') {
                throw new \RuntimeException(
                    "line {$tokens[$i]['line']}: `{$variable}` is written under a key this scan cannot read "
                    .'(a computed or variable key). The top-level parameter it adds is unknown, so the '
                    .self::SEARCH_ENDPOINT.' call it feeds is unchecked.'
                );
            }
            if ($keys === null) {
                throw new \RuntimeException(
                    "line {$tokens[$i]['line']}: `{$variable}` is written before this scan saw it assigned an "
                    .'array literal — the base shape is unknown.'
                );
            }
            $keys[] = trim($tokens[$key]['text'], "'\"");
        }

        if ($keys === null) {
            throw new \RuntimeException(
                "line {$line}: the query variable `{$variable}` has no array-literal assignment in its enclosing "
                .'function, so its top-level keys are unknown and the '.self::SEARCH_ENDPOINT.' call is unchecked.'
            );
        }

        return ['keys' => $keys, 'q' => $q];
    }

    /**
     * The comma-separated groups inside the bracket that OPENS at `$open`, as token indices —
     * nesting-aware, so a nested array or a call in a value is carried whole instead of being
     * cut at the first `]` (which read as "unparseable" and reported the wrong cause).
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return list<list<int>>|null null when the bracket is never closed
     */
    private static function balancedGroup(array $tokens, int $open): ?array
    {
        $groups = [];
        $current = [];
        $depth = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            $id = $tokens[$i]['id'];
            $text = $tokens[$i]['text'];

            // T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES open an interpolation whose `}` is a
            // plain token — count them, or a `"…{$x}…"` value unbalances the walk.
            if (in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;
            } elseif ($id === null && in_array($text, ['(', '[', '{'], true)) {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($id === null && in_array($text, [')', ']', '}'], true)) {
                $depth--;
                if ($depth === 0) {
                    if ($current !== []) {
                        $groups[] = $current;
                    }

                    return $groups;
                }
            } elseif ($id === null && $text === ',' && $depth === 1) {
                $groups[] = $current;
                $current = [];

                continue;
            }

            $current[] = $i;
        }

        return null;
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  list<int>|null  $within  restrict the search to these indices
     */
    private static function significant(array $tokens, int $from, ?array $within = null): ?int
    {
        for ($i = $from; $i < count($tokens); $i++) {
            if ($within !== null && ! in_array($i, $within, true)) {
                return null;
            }
            if (! in_array($tokens[$i]['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  list<int>  $indices
     */
    private static function firstSignificant(array $tokens, array $indices): ?int
    {
        foreach ($indices as $i) {
            if (! in_array($tokens[$i]['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  list<int>  $indices
     */
    private static function textOf(array $tokens, array $indices): string
    {
        $text = '';
        foreach ($indices as $i) {
            $text .= $tokens[$i]['text'];
        }

        return $text;
    }
}
