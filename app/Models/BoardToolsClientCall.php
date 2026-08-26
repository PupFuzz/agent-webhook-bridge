<?php

namespace App\Models;

use App\Bridge\Tools\ClientHalfLedger;
use Illuminate\Database\Eloquent\Model;

/**
 * The last SUCCESSFUL board-tools call recorded for one agent — the durable half of
 * card#7756, written by {@see ClientHalfLedger} and read by
 * `App\Bridge\Check\Checks\BoardToolsClientHalfCheck`.
 *
 * ONE ROW PER AGENT, REWRITTEN IN PLACE. What the row answers is "when did the board-tools
 * door last open for this agent, and over which transport" — one answer per agent, so a row per
 * call would record the call RATE in a table nothing prunes, to hold a fact every later
 * row replaces.
 *
 * ⛔ IT IS EVIDENCE IN ONE DIRECTION ONLY, AND ABOUT THE CALL RATHER THAN THE CALLER. A row
 * says the board-tools door opened for that agent at `last_success_at`; a seat opening it
 * has exercised its whole client chain, but `bridge:check --probe-tools`, `--self-cert` and
 * a hand-run `bridge:tools-call` stamp the row identically. NO row proves nothing at all: a
 * seat that was never wired and a seat that simply has not called are the same absence here,
 * and the bridge cannot tell them apart without reading files that are not its to read. The
 * check's own text carries both bounds to the operator.
 *
 * ⛔ NAMES AND TIMESTAMPS ONLY. Nothing on this model may grow a field carrying a secret,
 * a token, or a config VALUE — its whole content is printed by `bridge:check`.
 */
class BoardToolsClientCall extends Model
{
    /**
     * `created_at` is the first successful call ever recorded for the agent and is never
     * rewritten; there is no `updated_at` column, because `last_success_at` IS the update
     * and naming it twice would invite the two to disagree.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'agent',
        'transport',
        'last_success_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'last_success_at' => 'datetime',
    ];
}
