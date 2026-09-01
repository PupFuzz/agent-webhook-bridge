<?php

namespace App\Models;

use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Scheduling\JobScheduler;
use App\Bridge\Scheduling\JobSpec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One INSTANCE in the periodic-job registry (card#8425 / DL-325) — written by
 * {@see JobRegistry} and executed by {@see JobScheduler}.
 *
 * ⭐ THE ROW IS DATA; THE HANDLER IS CODE. That split is the governance model, not an
 * implementation detail: `handler` is a key into a registry of classes that exist in this
 * repository, so what any job CAN DO was fixed at code-review time, while inserting and
 * removing instances of an already-reviewed handler is ungated and happens at runtime. A
 * `handler` naming nothing is refused loudly at both ends rather than skipped.
 *
 * ⭐ `justification` IS REQUIRED AND IS PART OF THE PUBLIC ENUMERATION. A periodic job is
 * the last resort in this design; the field is the one sentence the inserter owes saying
 * why the event-gated path could not do it. It is friction by intent — see
 * {@see JobSpec} for what that friction does and does not buy.
 *
 * ⛔ NO SECRET, TOKEN OR CONFIG VALUE MAY BE STORED ON THIS MODEL. Every column is printed
 * by `bridge:jobs` and several are summarised by `bridge:check`; `payload` is handler input
 * and is operator-visible for the same reason.
 *
 * ⚑ `last_status` HAS THREE VALUES AND A NULL, and collapsing them loses the remedy.
 * `ok` / `failed` / `refused` / never-run: a refusal means the scheduler declined to invoke
 * anything (the handler name resolves to nothing, or names a state-mutating handler this
 * install has not armed), which is a different fact from a handler that ran and threw.
 *
 * The `@property` block below is not decoration: `app/Models` is outside the analysed paths
 * (see `phpstan-laravel.neon`), so a reader in `app/Bridge` — which IS analysed — otherwise
 * sees every column as the raw `string|null` the schema hands back and the casts below are
 * invisible to it.
 *
 * @property string $name
 * @property string $handler
 * @property int $interval_s
 * @property string $owner
 * @property string $docs_ref
 * @property string $justification
 * @property bool $enabled
 * @property array<mixed>|null $payload
 * @property Carbon|null $last_run_at
 * @property Carbon|null $next_due_at
 * @property string|null $last_status
 * @property string|null $last_summary
 * @property string|null $last_error
 * @property int|null $last_duration_ms
 * @property int $consecutive_failures
 */
class ScheduledJob extends Model
{
    /** A pass completed and the handler returned an outcome. */
    public const STATUS_OK = 'ok';

    /** The handler ran and threw. `last_error` carries the exception. */
    public const STATUS_FAILED = 'failed';

    /**
     * The scheduler declined to invoke anything. NOT a failing handler — nothing ran.
     * `last_error` carries the refusal reason in operator vocabulary.
     */
    public const STATUS_REFUSED = 'refused';

    protected $fillable = [
        'name',
        'handler',
        'interval_s',
        'owner',
        'docs_ref',
        'justification',
        'enabled',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'payload' => 'array',
            'interval_s' => 'integer',
            'last_duration_ms' => 'integer',
            'consecutive_failures' => 'integer',
            'last_run_at' => 'datetime',
            'next_due_at' => 'datetime',
        ];
    }
}
