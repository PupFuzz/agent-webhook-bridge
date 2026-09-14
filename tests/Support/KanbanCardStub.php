<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A `/tasks/{id}.json` endpoint that REMEMBERS what was written to it: a GET answers the card's
 * current row, a PATCH applies its fields to that row the way kanban does for the fields the
 * writeback sends (`workflow_stage_id` and `tags` replace), and every request is answered from
 * the state the previous one left.
 *
 * ⭐ WHY STATE, NOT A FIXED BODY. The terminal owner-tag clear reads the card AFTER the move and
 * writes a tag list built from that read, so a subject that turns on "which read did the write
 * come from" cannot be expressed with one canned answer: a fixed body makes the scan read and
 * the fresh read identical, which is exactly the case in which the defect is invisible.
 *
 * ⚑ `moveOnlyToken` models kanban's DL-204 split (`TaskMutator::update()` authorizes `move` only
 * when `workflow_stage_id` is the PATCH's SOLE key, `update` otherwise): a PATCH carrying any
 * other key answers 403 and changes nothing, which is what a custom board role holding
 * `task.move` but not `task.update` sees.
 *
 * ⚠ It models the two fields the writeback's moves and clears send and nothing else — no
 * `payload` per-key merge, no validation. A leg whose subject is another field stubs its own.
 */
final class KanbanCardStub
{
    /** @var list<array{method: string, id: int, data: array<string, mixed>, status: int}> */
    public array $log = [];

    /**
     * Another writer, run after every PATCH this endpoint APPLIED — the seam a test uses to
     * change the card between the bridge's write and its next read.
     *
     * @var (\Closure(self, int, array<string, mixed>): void)|null
     */
    public ?\Closure $afterWrite = null;

    /** @param  array<int, array<string, mixed>>  $cards  card id => the row kanban would answer */
    public function __construct(public array $cards, private readonly bool $moveOnlyToken = false) {}

    /** @return array<string, mixed> an `Http::fake()` stub set answering every `/tasks/{id}.json` */
    public function stub(): array
    {
        return ['*/tasks/*.json' => fn (Request $request): PromiseInterface => $this->answer($request)];
    }

    /** @return list<array<string, mixed>> the PATCH bodies sent to $id, in order, whatever they were answered */
    public function patchesTo(int $id): array
    {
        return array_values(array_map(
            static fn (array $entry): array => $entry['data'],
            array_filter($this->log, static fn (array $entry): bool => $entry['method'] === 'PATCH' && $entry['id'] === $id),
        ));
    }

    private function answer(Request $request): PromiseInterface
    {
        if (preg_match('#/tasks/(\d+)\.json#', $request->url(), $m) !== 1) {
            return Http::response(['message' => 'not a card url'], 404);
        }
        $id = (int) $m[1];
        $data = $request->method() === 'PATCH' ? $request->data() : [];

        [$status, $body] = match (true) {
            ! isset($this->cards[$id]) => [404, ['message' => 'No query results for model [App\\Models\\Task].']],
            $request->method() === 'GET' => [200, ['data' => $this->cards[$id]]],
            $request->method() !== 'PATCH' => [405, ['message' => 'method not stubbed']],
            $this->moveOnlyToken && array_keys($data) !== ['workflow_stage_id'] => [403, ['message' => 'This action is unauthorized.']],
            default => [200, ['data' => $this->cards[$id] = array_replace($this->cards[$id], $data)]],
        };
        $this->log[] = ['method' => $request->method(), 'id' => $id, 'data' => $data, 'status' => $status];
        if ($request->method() === 'PATCH' && $status === 200 && $this->afterWrite !== null) {
            ($this->afterWrite)($this, $id, $data);
        }

        return Http::response($body, $status);
    }
}
