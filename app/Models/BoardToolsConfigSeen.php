<?php

namespace App\Models;

use App\Bridge\Tools\ConfigSeenLedger;
use Illuminate\Database\Eloquent\Model;

/**
 * What this install has ever SEEN in an agent's `board_tools` block — the durable witness
 * card#8973 / DL-360 exists for, written by {@see ConfigSeenLedger} and read by
 * `App\Bridge\Check\Checks\BoardToolsLostCheck`.
 *
 * ONE ROW PER AGENT, REWRITTEN IN PLACE. The question it answers is "did a run of this
 * install ever parse an enabled block for this seat, and has the operator retired it" — one
 * answer per agent, so a row per sighting would record the check CADENCE in a table nothing
 * prunes, to hold a fact every later row replaces.
 *
 * ⛔ IT IS NOT `board_tools_client_calls` AND MUST NOT BE COLLAPSED INTO IT. That row says
 * the board-tools DOOR OPENED for an agent, which `bridge:check --probe-tools`,
 * `--self-cert` and a hand-run `bridge:tools-call` stamp indistinguishably — so an agent
 * probed once and later removed carries one forever, and reading it as "this seat had a
 * block" would FAIL installs nobody touched. This row is the narrower fact: a run PARSED an
 * enabled block. The two are joined only in the LOST line's EVIDENCE clause, never in its
 * trigger.
 *
 * ⚑ THE THREE NULLABLE STAMPS ARE THREE DIFFERENT STATEMENTS, and none of them is a
 * default. `first_seen_at`/`last_seen_at` NULL means this row was born by a RETIREMENT of a
 * seat never seen enabled; `retired_seen_at`/`retired_reason` NULL means no retirement
 * stands — and an enabled sighting CLEARS them, because re-adding the block re-opens the
 * question the retirement closed.
 *
 * ⛔ NAMES AND TIMESTAMPS ONLY, plus the operator's own retirement sentence. Nothing on this
 * model may grow a field carrying a secret, a token or a config VALUE — its whole content is
 * printed verbatim by `bridge:check`.
 */
class BoardToolsConfigSeen extends Model
{
    /**
     * ⛔ PINNED, BECAUSE THE CONVENTION DOES NOT PRODUCE IT. Eloquent's default is the
     * snake_case STUDLY-PLURAL of the class name, and pluralizing `…ConfigSeen` yields
     * `board_tools_config_seens` — a table this install does not have. Measured, not
     * assumed: `getTable()` returned that spelling, and larastan's migration scan is what
     * surfaced it (the model resolved to a table no migration creates, so every property
     * read was "undefined"). The table is named for the FACT it records, singular, and the
     * model states it rather than being renamed to suit an inflector.
     */
    protected $table = 'board_tools_config_seen';

    /**
     * `created_at` is the first time this install recorded anything for the agent and is
     * never rewritten; there is no `updated_at` column, because `last_seen_at` and
     * `retired_seen_at` ARE the updates and each says which kind — a third stamp standing
     * for "one of those two happened" could only disagree with them.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'agent',
        'transport',
        'board_id',
        'swimlane_id',
        'first_seen_at',
        'last_seen_at',
        'retired_seen_at',
        'retired_reason',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'retired_seen_at' => 'datetime',
    ];
}
