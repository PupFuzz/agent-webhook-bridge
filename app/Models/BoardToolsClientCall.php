<?php

namespace App\Models;

use App\Bridge\Tools\CallProvenance;
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
 * ⭐ `call_provenance` NARROWS THAT — IT DOES NOT CLOSE IT (card#7836 / DL-316). It records
 * how the SERVING PROCESS was started, which is the one thing the ssh door can observe.
 * ⭐ WHAT THE STORED `sshd` VALUE ESTABLISHES, AND THE TWO THINGS IT DOES NOT, ARE OWNED BY
 * {@see CallProvenance} — read the rule there rather than here; a copy of it on this model
 * is a copy that can go stale while every test stays green. ⚑ NULL IS A THIRD STATE and is
 * not the enum's business: it is a row written before DL-316, carrying no measurement in
 * either direction, and the check reads it as unproven.
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
        'call_provenance',
        'last_success_at',
    ];

    /**
     * ⚑ THE ENUM CAST IS NULL-PRESERVING, which is the whole point: a pre-DL-316 row
     * hydrates as `null` and reaches the check as "unmeasured" rather than as either
     * verdict.
     *
     * ⛔ THE CAST IS APPLIED LAZILY, ON ATTRIBUTE ACCESS — NOT ON HYDRATION — and a reader
     * that gets that backwards writes a fail-soft envelope around the wrong statement. A
     * backing value this build does not know hydrates CLEANLY out of the query and throws a
     * `ValueError` at the first READ of the attribute (measured, not reasoned). That is why
     * `App\Bridge\Check\Checks\BoardToolsClientHalfCheck` resolves the provenance INSIDE
     * the `try` that wraps its query rather than at the arm that branches on it: read
     * outside, the throw is uncaught and ABORTS `bridge:check`.
     */
    protected $casts = [
        'created_at' => 'datetime',
        'call_provenance' => CallProvenance::class,
        'last_success_at' => 'datetime',
    ];
}
