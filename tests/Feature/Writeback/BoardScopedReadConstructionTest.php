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
 * TWO INDEPENDENT DERIVATIONS, because each is blind where the other sees:
 *  1. RUNTIME ({@see test_every_board_scoped_read_carries_its_board_term_inside_the_q_string}).
 *     Reflect the board-scoped read methods off `KanbanClient`, invoke every one under a
 *     capturing `Http::fake`, and assert on the URL that actually went out. It sees a
 *     query built anywhere — a helper, a merge, a base-URL default — not just a literal at
 *     the call site. It is blind to a board-scoped read whose board parameter is named
 *     something this class does not recognise as a board.
 *  2. SOURCE ({@see test_no_search_call_site_in_app_hoists_a_filter_to_a_top_level_parameter}).
 *     Parse every `/tasks/search.json` GET literal in `app/`, whatever encloses it. It is
 *     blind to a query assembled out of literals, and it reconciles its own denominator so
 *     a call site it cannot parse reds instead of passing silently.
 *
 * WHAT IT DOES NOT DO, stated plainly because a guard trusted for more than it does is
 * worse than no guard:
 *  - **It does not verify the server honours anything.** It asserts we ASK correctly. The
 *    server-side half is the establishing measurement above, which needs two boards and a
 *    planted token and does not belong in CI.
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

    /**
     * The ONLY top-level parameters a `/tasks/search.json` GET may carry.
     *
     * `q` is the filter grammar; `limit` and `page` are the pagination contract and are
     * recognised. EVERY OTHER KEY IS EITHER SILENTLY DROPPED (⇒ unfiltered) OR A FILTER
     * THAT BELONGS INSIDE `q`, and the two are indistinguishable from the response — which
     * is why this is an allowlist and not a `board_id` denylist. Widening it is a decision
     * about what the SERVER recognises: measure it against a live instance with a control
     * before adding a key here.
     *
     * @var list<string>
     */
    private const SEARCH_PARAMS = ['q', 'limit', 'page'];

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
        'cardsByTag',
        'cardRowsByTag',
        'swimlaneCards',
        'readBoardCards',
        'visibility',
        'correlateDl',
        'correlatePr',
        'correlateIssue',
    ];

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

                if (! str_contains($url, '/tasks/search.json')) {
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
                    (string) ($query['q'] ?? ''),
                    "{$site}: the board scope is NOT inside the q= term of {$url}. An unrecognised top-level "
                    .'parameter is silently dropped and the endpoint returns 200 with an UNFILTERED, '
                    .'fleet-wide result set — put the board back inside q= (card#7211).'
                );

                $stray = array_values(array_diff(array_keys($query), self::SEARCH_PARAMS));
                $this->assertSame([], $stray,
                    "{$site}: /tasks/search.json carries top-level parameter(s) [".implode(', ', $stray).'] — '
                    .'only ['.implode(', ', self::SEARCH_PARAMS).'] are recognised; a filter hoisted out of q= '
                    .'is dropped WITHOUT ERROR and the search returns unfiltered (card#7211).');
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
                .'returned early on the sample arguments — add a sample for its parameter in `sampleValue()` (or, '
                .'if it genuinely performs no read, disposition it here on the record).');

            [, $method] = explode('::', (string) $site, 2);
            foreach ($requests as $request) {
                if ($request->method() === 'GET' && str_contains($request->url(), '/tasks/search.json')) {
                    $searchReaders[$method] = true;
                }
            }
        }

        foreach (self::KNOWN_SEARCH_READERS as $reader) {
            $this->assertArrayHasKey($reader, $searchReaders,
                "`{$reader}` no longer reaches /tasks/search.json under either correlation mode — its construction "
                .'was asserted against nothing.');
        }
        $this->assertGreaterThanOrEqual(count(self::KNOWN_SEARCH_READERS), count($searchReaders));
    }

    public function test_no_search_call_site_in_app_hoists_a_filter_to_a_top_level_parameter(): void
    {
        $callSites = 0;

        foreach (self::appPhpFiles() as $path) {
            $src = (string) file_get_contents($path);
            $literals = preg_match_all("~'/tasks/search\.json'~", $src);
            if ($literals === 0) {
                continue;
            }

            preg_match_all("~->get\(\s*'/tasks/search\.json'\s*,\s*\[(?P<query>[^]]*)\]\s*\)~", $src, $calls);
            $this->assertCount($literals, $calls['query'],
                "{$path}: a /tasks/search.json call site this guard cannot parse. It is NOT exempt — reshape the "
                .'call to a literal query array, or teach this test to read the new shape; an unparsed call site is '
                .'an unchecked one.');

            foreach ($calls['query'] as $query) {
                $callSites++;

                $this->assertMatchesRegularExpression("~'q'\s*=>\s*\"board_id=\{\\\$~", $query,
                    "{$path}: a /tasks/search.json call whose q= term does not open with the board scope — {$query}");

                preg_match_all("~'(?P<key>[a-z_]+)'\s*=>~", $query, $keys);
                $stray = array_values(array_diff($keys['key'], self::SEARCH_PARAMS));
                $this->assertSame([], $stray,
                    "{$path}: /tasks/search.json is passed top-level parameter(s) [".implode(', ', $stray).'] — '
                    .'an unrecognised one is dropped silently and the search returns UNFILTERED (card#7211). '
                    .'Filters belong inside the q= string.');
            }
        }

        // Presence witness for the source derivation, with a count: the five literal search
        // call sites in `app/` today. A floor, so a new one does not red this — it is checked
        // by the loop above; it reds when the scan stops FINDING them.
        $this->assertGreaterThanOrEqual(5, $callSites,
            'the source scan found fewer /tasks/search.json call sites than exist — it is reporting where the '
            .'searcher stopped, not the state of the code');
    }

    /**
     * Invoke every board-scoped read under a capturing fake, in BOTH correlation modes
     * (`ref` routes the `correlate*` trio at the by-ref path, `scan` routes them at the
     * board search — one mode alone leaves the other construction unexercised).
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
                try {
                    $method->invokeArgs($client, array_map(
                        fn (ReflectionParameter $p): mixed => $this->sampleValue($method, $p),
                        $method->getParameters(),
                    ));
                } catch (\Throwable) {
                    // The request was captured before the throw — an empty `data` envelope
                    // makes some readers (createCard) fail on the RESPONSE, which is not what
                    // is under test. A method that emitted nothing is caught by the coverage
                    // assertion, not swallowed here.
                }
            }
        }

        return $captured;
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
}
