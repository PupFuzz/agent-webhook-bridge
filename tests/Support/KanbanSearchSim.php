<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A kanban `GET /tasks/search.json` that a test can WRITE TO between requests, and between the
 * COUNT and the SELECT of one request — the shape of the server the page walk has to survive
 * (card#10653). It answers the way `TasksController::search` does: `orderByDesc('id')` then
 * `paginate()`, which is a COUNT query followed by a separate `LIMIT`/`OFFSET` SELECT with no
 * transaction around them, and an `id<N` q-token applied as a `where` (`QueryParser`'s
 * `^id(<=|>=|<|>|=)(\d+)$` arm). Every other q-token is ignored: the rows a test gives ARE the
 * match set.
 *
 * Writes are callables keyed by the 1-based number of the request they precede (`before`) or
 * land inside (`race`, applied after that request's COUNT and before its SELECT).
 *
 * `$order` / `$idFilter` model a server that breaks one of the two properties the walk keys on:
 * `asc` answers in ascending id order; `ignore` drops the `id<` token; `free_text` treats it as a
 * word no card carries, so a keyed request matches nothing.
 */
final class KanbanSearchSim
{
    /** @var array<int, array<string, mixed>> live rows by id */
    private array $rows = [];

    /** @var array<int, callable(self): void> */
    private array $before = [];

    /** @var array<int, callable(self): void> */
    private array $race = [];

    public int $requests = 0;

    /** @var list<array<string, string>> the query of every request, in order */
    public array $queries = [];

    /** @param iterable<int> $ids */
    public function __construct(iterable $ids, private readonly string $order = 'desc', private readonly string $idFilter = 'apply')
    {
        foreach ($ids as $id) {
            $this->rows[$id] = ['id' => $id];
        }
    }

    /**
     * Give one card a full row (it is `['id' => …]` otherwise), placing it on the board if it is not.
     * `$id` is the card's id as the SERVER orders and filters it; it defaults to the row's own `id`,
     * and is given separately only for a row whose `id` field a test has made unreadable.
     *
     * @param  array<string, mixed>  $row
     */
    public function put(array $row, ?int $id = null): self
    {
        $this->rows[$id ?? (int) $row['id']] = $row;

        return $this;
    }

    /** @param callable(self): void $write */
    public function before(int $request, callable $write): self
    {
        $this->before[$request] = $write;

        return $this;
    }

    /** @param callable(self): void $write */
    public function race(int $request, callable $write): self
    {
        $this->race[$request] = $write;

        return $this;
    }

    public function archive(int $id): void
    {
        unset($this->rows[$id]);
    }

    public function restore(int $id): void
    {
        $this->rows[$id] = ['id' => $id];
    }

    public function install(): self
    {
        Http::fake(['*/tasks/search.json*' => $this->responder()]);

        return $this;
    }

    /** The `Http::fake` stub for the search endpoint, for a test that fakes other endpoints beside it. */
    public function responder(): \Closure
    {
        return fn (Request $request) => $this->answer($request);
    }

    /** @return list<int> */
    public function ids(): array
    {
        return array_keys($this->rows);
    }

    private function answer(Request $request): mixed
    {
        $this->requests++;
        ($this->before[$this->requests] ?? static fn () => null)($this);

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */
        $this->queries[] = $query;
        $below = preg_match('/(?:^| )id<(\d+)(?: |$)/', (string) ($query['q'] ?? ''), $m) === 1 ? (int) $m[1] : null;
        $perPage = min(200, max(1, (int) ($query['limit'] ?? 50)));
        $page = max(1, (int) ($query['page'] ?? 1));

        $total = count($this->matching($below));
        ($this->race[$this->requests] ?? static fn () => null)($this);
        $data = array_slice($this->matching($below), ($page - 1) * $perPage, $perPage);

        $lastPage = max(1, (int) ceil($total / $perPage));

        return Http::response([
            'data' => $data,
            'links' => ['next' => $page < $lastPage ? 'https://kanban.example.com/api/v3/tasks/search.json?page='.($page + 1) : null],
            'meta' => ['total' => $total, 'current_page' => $page, 'last_page' => $lastPage, 'per_page' => $perPage],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function matching(?int $below): array
    {
        if ($below !== null && $this->idFilter === 'free_text') {
            return [];
        }
        $ids = array_values(array_filter(
            array_keys($this->rows),
            fn (int $id) => $below === null || $this->idFilter === 'ignore' || $id < $below,
        ));
        $this->order === 'asc' ? sort($ids) : rsort($ids);

        return array_map(fn (int $id) => $this->rows[$id], $ids);
    }
}
