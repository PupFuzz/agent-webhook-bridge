<?php

namespace App\Bridge\Scheduling;

use App\Models\ScheduledJob;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The runtime insert / remove / enumerate API over the periodic-job registry
 * (card#8425 / DL-325). The directive's third floor term — *any function can insert or
 * remove jobs at runtime; the main crontab never changes* — is this class.
 *
 * ⭐ INSERT IS AN UPSERT BY NAME. A caller that re-declares its job on every boot (the
 * common shape: a subsystem that wants "my cleanup, every 30 minutes") must converge on one
 * row rather than minting a schedule per boot. The name is the identity.
 *
 * ⛔ WITH ONE FIELD CARVED OUT: `enabled` is written at CREATE ONLY. A declaration says what
 * a job IS; whether this install currently wants it running is an OPERATOR fact, owned by
 * {@see self::setEnabled()}, and a re-declare that reverted it would undo `bridge:jobs
 * disable` at the next boot with nothing said anywhere.
 *
 * ⭐ IT REFUSES AT INSERT AS WELL AS AT RUN, and the two refusals share ONE predicate
 * ({@see JobHandlerRegistry::refusalFor()}). Insert-time refusal is what stops an install
 * accumulating rows that can never execute — a registry full of jobs nobody can run is a
 * worse audit surface than no registry, because every row reads as a thing that happens.
 * Run-time refusal is still needed: arming is operator config and can be withdrawn after a
 * row exists.
 *
 * ⚠ NO ORDERING GUARANTEE ACROSS CALLERS. Two processes inserting the same name race, and
 * the last writer wins the non-identity fields. That is the correct outcome for an upsert
 * keyed on a handle and is stated rather than defended against: an instance is a
 * declaration, not an accumulator.
 */
final class JobRegistry
{
    public function __construct(private readonly JobHandlerRegistry $handlers) {}

    /**
     * Declare an instance. Returns the stored row.
     *
     * @throws JobSpecException when the handler may not be invoked on this install — see
     *                          {@see JobRefusal} for the two reasons and their remedies
     */
    public function insert(JobSpec $spec): ScheduledJob
    {
        $refusal = $this->handlers->refusalFor($spec->handler);
        if ($refusal !== null) {
            throw new JobSpecException("job '{$spec->name}' refused: ".$refusal->message);
        }

        $job = ScheduledJob::query()->firstOrNew(['name' => $spec->name]);

        // ⛔ `enabled` IS WRITTEN ON CREATE ONLY, and that is why this is not a plain
        // `updateOrCreate`. Insert is an upsert a caller RE-RUNS — the common shape is a
        // subsystem re-declaring its job on every boot — so writing the spec's `enabled` on
        // the update path would let the next boot silently undo `bridge:jobs disable`, an
        // operator act, with nothing said anywhere. {@see self::setEnabled()} owns the
        // switch; a declaration says what a job IS, not whether this install currently wants
        // it running. Every other field still converges on the re-declare, which is what
        // makes the upsert worth having.
        if (! $job->exists) {
            $job->enabled = $spec->enabled;
        }

        $job->fill([
            'handler' => $spec->handler,
            'interval_s' => $spec->intervalS,
            'owner' => $spec->owner,
            'docs_ref' => $spec->docsRef,
            'justification' => trim($spec->justification),
            'payload' => $spec->payload === [] ? null : $spec->payload,
        ])->save();

        // A periodic population that changed shape is a fact about the install, and the one
        // place it is cheap to notice is the moment it changed. The line carries the
        // justification for the same reason the enumeration does.
        Log::info('scheduled job declared', [
            'name' => $spec->name,
            'handler' => $spec->handler,
            'interval_s' => $spec->intervalS,
            'owner' => $spec->owner,
            'justification' => trim($spec->justification),
        ]);

        return $job;
    }

    /** Remove an instance. Returns whether there was one to remove. */
    public function remove(string $name): bool
    {
        $removed = ScheduledJob::query()->where('name', $name)->delete() > 0;

        if ($removed) {
            Log::info('scheduled job removed', ['name' => $name]);
        }

        return $removed;
    }

    /**
     * Turn an instance on or off WITHOUT losing it. A disabled row stays enumerable: a job
     * somebody switched off is a fact about this install, and deleting it to silence it
     * destroys the record of the decision.
     *
     * Returns whether an instance by that name exists.
     */
    public function setEnabled(string $name, bool $enabled): bool
    {
        $job = ScheduledJob::query()->where('name', $name)->first();
        if ($job === null) {
            return false;
        }

        $job->enabled = $enabled;
        $job->save();
        Log::info('scheduled job '.($enabled ? 'enabled' : 'disabled'), ['name' => $name]);

        return true;
    }

    /**
     * THE AUDIT SURFACE — every instance this install would run, enabled or not, in name
     * order. *"GET the job list and you have named the entire periodic population"* is only
     * true of a method that filters nothing.
     *
     * @return Collection<int, ScheduledJob>
     */
    public function all()
    {
        return ScheduledJob::query()->orderBy('name')->get();
    }

    public function find(string $name): ?ScheduledJob
    {
        return ScheduledJob::query()->where('name', $name)->first();
    }
}
