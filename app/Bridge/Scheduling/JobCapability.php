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
 * it at runtime: a {@see self::MutatesState} handler can be DISARMED by this install's
 * operator by name (`BRIDGE_JOBS_DISARMED_MUTATORS`), and the scheduler then records a loud
 * refusal rather than running it. ⚠ Since card#10918 / DL-441 it is ARMED BY DEFAULT: DL-325
 * shipped it inert until armed, and the operator reversed that (new functionality ships on;
 * a per-handler kill switch stays).
 *
 * ⛔ WHAT THE DECLARATION DOES NOT ESTABLISH, stated because an unstated bound reads as a
 * guarantee: it records what the AUTHOR CLAIMS, not what the code does. A handler that
 * writes to a board while declaring {@see self::ReadAndAlert} is mis-declared, and nothing
 * here detects that — the declaration's job is to make the claim reviewable and to give the
 * operator a per-handler kill switch, not to sandbox the handler.
 */
enum JobCapability: string
{
    /**
     * Reads state and tells somebody about it — staleness checks, wakes, domain watches,
     * cleanups of the job's own bookkeeping. Exists under normal code review.
     */
    case ReadAndAlert = 'read_and_alert';

    /**
     * Mutates board or install state. Armed by default (DL-441); an operator switches one off
     * by naming it in `bridge.jobs.disarmed_mutators`. A disarmed handler's instance is
     * refused at insert and, if it was armed at insert and disarmed since, refused again at run.
     */
    case MutatesState = 'mutates_state';
}
