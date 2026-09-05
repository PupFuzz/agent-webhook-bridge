<?php

namespace App\Bridge\Scheduling;

/**
 * What a periodic {@see JobHandler} is allowed to DO — the governance split, encoded
 * structurally rather than left to a comment (card#8425 / DL-325).
 *
 * The single tick makes adding a periodic job nearly free, which is the point and also the
 * hazard: periodic state-mutators drifting in silently is exactly what the fleet's
 * no-standing-daemons posture existed to prevent. The line drawn — and ratified by the
 * operator — is that HANDLERS ARE THE GOVERNED SURFACE and INSTANCES ARE FREE. This enum
 * is the handler half of it.
 *
 * ⭐ WHY AN ENUM ON THE HANDLER AND NOT A REVIEW CONVENTION. A convention that
 * state-mutating jobs "need approval" is enforced by whoever happens to review the PR, and
 * is silently satisfied by a handler that grows a write six months later. Declaring the
 * capability puts the claim in the type system, where {@see JobHandlerRegistry} can act on
 * it at runtime: a {@see self::MutatesState} handler is INERT unless this install's
 * operator armed it by name (`BRIDGE_JOBS_ARMED_MUTATORS`), and the scheduler records a
 * loud refusal rather than running it.
 *
 * ⛔ WHAT THE DECLARATION DOES NOT ESTABLISH, stated because an unstated bound reads as a
 * guarantee: it records what the AUTHOR CLAIMS, not what the code does. A handler that
 * writes to a board while declaring {@see self::ReadAndAlert} is mis-declared, and nothing
 * here detects that — the declaration's job is to make the claim reviewable and to make
 * arming an explicit operator act, not to sandbox the handler.
 */
enum JobCapability: string
{
    /**
     * Reads state and tells somebody about it — staleness checks, wakes, domain watches,
     * cleanups of the job's own bookkeeping. Exists under normal code review.
     */
    case ReadAndAlert = 'read_and_alert';

    /**
     * Mutates board or install state. REQUIRES OPERATOR APPROVAL TO EXIST AT ALL, and the
     * approval is mechanised: the handler is registered but stays unarmed until this
     * install names it in `bridge.jobs.armed_mutators`. An unarmed instance is refused at
     * insert and, if it was armed and later disarmed, refused again at run.
     */
    case MutatesState = 'mutates_state';
}
