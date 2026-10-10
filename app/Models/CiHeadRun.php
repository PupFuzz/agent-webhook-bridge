<?php

namespace App\Models;

use App\Bridge\CiAwait\CiHeadRunTracker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One workflow run as the bridge last heard of it from a `workflow_run` delivery (card#11667).
 * Written only by {@see CiHeadRunTracker}; the row is data, the model carries no logic.
 *
 * @property int $id
 * @property int $run_id
 * @property string $repo
 * @property string $repo_name
 * @property string $head_sha
 * @property int|null $workflow_id
 * @property string $workflow
 * @property int|null $run_number
 * @property int|null $run_attempt
 * @property string $event
 * @property string $status
 * @property string|null $conclusion
 * @property string $html_url
 * @property int|null $pr
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 */
class CiHeadRun extends Model
{
    protected $table = 'ci_head_runs';

    protected $fillable = [
        'run_id',
        'repo',
        'repo_name',
        'head_sha',
        'workflow_id',
        'workflow',
        'run_number',
        'run_attempt',
        'event',
        'status',
        'conclusion',
        'html_url',
        'pr',
    ];

    protected $casts = [
        'run_id' => 'integer',
        'workflow_id' => 'integer',
        'run_number' => 'integer',
        'run_attempt' => 'integer',
        'pr' => 'integer',
    ];
}
