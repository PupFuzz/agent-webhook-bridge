<?php

namespace App\Models;

use App\Bridge\Writeback\BoardDivergenceLedger;
use App\Bridge\Writeback\MappedBoardGuard;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * One observation of a writeback (card board, mapped board) pair that DIVERGED —
 * the durable half of card#7212, written by {@see BoardDivergenceLedger}.
 *
 * The log line answers the same question for 14 days; this row answers it afterwards.
 * There is no row on the happy path, so the healthy state of this table is EMPTY and
 * `bridge:prune` does not touch it (DL-300) — see the migration for the retention ruling.
 */
class WritebackBoardDivergence extends Model
{
    /** Immutable observations: there is no `updated_at` column, and a row is never rewritten. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'disposition',
        'card_id',
        'card_board',
        'mapped_board',
        'site',
    ];

    protected $casts = [
        'card_id' => 'integer',
        'mapped_board' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * The card's board as OBSERVED, stringified for storage.
     *
     * ⚑ The column is a string and the observed value is `mixed` for one reason: kanban's
     * `board_id` is the thing under suspicion here, so the row must be able to hold what it
     * actually returned — a numeric string, a float, or something that is not a board id at
     * all — rather than what a column type wished it had returned. Null stays null: an absent
     * board is a distinct observation from a foreign one, and the accepted-set predicate
     * refuses both (see {@see MappedBoardGuard}).
     *
     * A string is stored VERBATIM and everything else through `json_encode`, so `12` and
     * `'12'` both store `12` — the reader's query is "which board was it on", and one
     * spelling per board is what makes that query answerable — while a value that is not a
     * board at all cannot masquerade as one (`true` stores `true`, never `1`). The JSON type
     * itself is in the log line the same observation wrote, for as long as that survives.
     */
    protected function cardBoard(): Attribute
    {
        return Attribute::make(set: fn (mixed $value): ?string => match (true) {
            $value === null => null,
            is_string($value) => mb_substr($value, 0, 64),
            default => mb_substr((string) json_encode($value), 0, 64),
        });
    }
}
