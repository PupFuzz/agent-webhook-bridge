<?php

namespace App\Models;

use App\Bridge\CiAwait\CiAwaitService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One seat's declared wait for CI on one head (card#11200 / DL-452). Written, claimed and
 * deleted only by {@see CiAwaitService}; the row is data, the model carries no logic.
 *
 * @property int $id
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
    ];

    protected $casts = [
        'pr' => 'integer',
        'expires_at' => 'datetime',
        'last_read_at' => 'datetime',
        'retry_not_before' => 'datetime',
    ];
}
