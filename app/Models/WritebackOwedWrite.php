<?php

namespace App\Models;

use App\Bridge\Writeback\OwedWriteQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One durable writeback the bridge still OWES (card#10849 / DL-440). Written, drained and
 * given up only by {@see OwedWriteQueue}; the row is data, the model carries no logic.
 *
 * The `@property` block is not decoration: `app/Models` is outside the analysed paths, so a
 * reader in `app/Bridge` otherwise sees every column as the raw value the schema hands back.
 *
 * @property int $id
 * @property string $subject_key
 * @property string $provider
 * @property string $scope_id
 * @property string $handler
 * @property string $debounce_key
 * @property string $target_id
 * @property string $agent_name
 * @property array<mixed> $payload
 * @property int $webhook_event_id
 * @property Carbon $queued_at
 * @property int $attempts
 * @property Carbon|null $not_before
 * @property int|null $last_status
 * @property string|null $last_error
 */
class WritebackOwedWrite extends Model
{
    protected $table = 'writeback_owed_writes';

    protected $fillable = [
        'subject_key',
        'provider',
        'scope_id',
        'handler',
        'debounce_key',
        'target_id',
        'agent_name',
        'payload',
        'webhook_event_id',
        'queued_at',
        'attempts',
        'not_before',
        'last_status',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'webhook_event_id' => 'integer',
        'queued_at' => 'datetime',
        'attempts' => 'integer',
        'not_before' => 'datetime',
        'last_status' => 'integer',
    ];
}
