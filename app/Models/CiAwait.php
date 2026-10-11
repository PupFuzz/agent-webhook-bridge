<?php

namespace App\Models;

use App\Bridge\CiAwait\CiAwaitService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * One seat's declared wait for CI on one head (card#11200 / DL-452). Written, claimed and
 * deleted only by {@see CiAwaitService}; the row is data, the model carries no logic.
 *
 * @property int $id
 * @property string $uuid
 * @property string $agent
 * @property string $repo
 * @property string $repo_name
 * @property string $head_sha
 * @property int|null $pr
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon $expires_at
 * @property Carbon|null $last_read_at
 * @property string|null $last_error
 * @property Carbon|null $retry_not_before
 * @property int|null $unconfirmed_status
 * @property Carbon|null $emit_failed_at
 * @property Carbon|null $overdue_at
 * @property string|null $overdue_basis
 * @property Carbon|null $overdue_sent_at
 */
class CiAwait extends Model
{
    protected $table = 'ci_awaits';

    protected $fillable = [
        'agent',
        'repo',
        'repo_name',
        'head_sha',
        'pr',
        'expires_at',
        'last_read_at',
        'last_error',
        'retry_not_before',
        'unconfirmed_status',
        'emit_failed_at',
        'overdue_at',
        'overdue_basis',
        'overdue_sent_at',
    ];

    protected $casts = [
        'pr' => 'integer',
        'expires_at' => 'datetime',
        'last_read_at' => 'datetime',
        'retry_not_before' => 'datetime',
        'unconfirmed_status' => 'integer',
        'emit_failed_at' => 'datetime',
        'overdue_at' => 'datetime',
        'overdue_sent_at' => 'datetime',
    ];

    /** The row's identity across table recreation, minted at insert; `docs/board-tools.md` § `ci_await` defines its use as the inbox line id. */
    protected static function booted(): void
    {
        static::creating(function (self $await): void {
            $await->uuid = (string) Str::uuid();
        });
    }
}
