<?php

namespace App\Bridge\Standup;

/**
 * The fleet snapshot the standup push carries (DL-306) — every field of it derived from
 * a store the bridge itself writes, and nothing else in it.
 *
 * WHAT IS DELIBERATELY NOT HERE, because the bridge cannot source it and an inferred
 * field is worse than a missing one:
 *  - a per-seat context-% — it has no producer the bridge can read. The coordination
 *    layer does emit one, and it is omitted on cross-project posts by design and only
 *    refreshes when a seat POSTS, so it is a lower bound on activity rather than a
 *    heartbeat: it corroborates, it cannot exonerate.
 *  - `last_activity` / idle / "quiet for N" — no per-seat liveness signal exists here
 *    ({@see SeatSnapshot}); `last_delivery_at` is a delivery time and says so.
 *  - an open `to:<agent>` THREAD count — the bridge sees coordination events, not
 *    thread lifecycle, and keeps no thread-state store, so it cannot say which threads
 *    are still open. `unseen_inbox_intents` is the neighbouring fact it CAN state
 *    (intents staged for that seat that its `bridge:inbox` cursor has not consumed) and
 *    it ships under that name, not under the one it is not.
 *
 * An EMPTY fleet renders as empty lists — `seats: []` / `boards: []` — never as
 * invented rows and never as an omitted section, so "nothing is configured" stays
 * distinguishable from "the section was not built".
 */
final class StandupDigest
{
    /**
     * @param  list<SeatSnapshot>  $seats
     * @param  list<BoardSnapshot>  $boards
     */
    public function __construct(
        public readonly string $generatedAt,
        public readonly array $seats,
        public readonly array $boards,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt,
            'seats' => array_map(fn (SeatSnapshot $s): array => $s->toArray(), $this->seats),
            'boards' => array_map(fn (BoardSnapshot $b): array => $b->toArray(), $this->boards),
        ];
    }

    /**
     * The one-line human summary. It counts the seats and boards the snapshot COVERS —
     * never a "N stalled" / "N idle" reading, which is the sentence the missing liveness
     * signal cannot support.
     */
    public function summary(): string
    {
        return sprintf(
            'standup: %d seat(s), %d board(s) with a Now lane, as of %s (delivery times, not activity)',
            count($this->seats),
            count($this->boards),
            $this->generatedAt,
        );
    }
}
