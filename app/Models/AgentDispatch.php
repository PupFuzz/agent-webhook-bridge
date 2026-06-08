<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentDispatch extends Model
{
    /** Terminal outcome vocabulary (DL-036). NULL on pre-DL-036 rows. */
    public const OUTCOME_DELIVERED = 'delivered'; // classifier emitted reactions; intents/handlers ran

    public const OUTCOME_DROPPED = 'dropped';     // a gate-drop (echo / not-a-signal / no reactions) — no work

    public const OUTCOME_ERRORED = 'errored';     // classifier/handler threw (processed_at null → replayable)

    protected $fillable = [
        'webhook_event_id',
        'agent_name',
        'outcome',
        'reason',
        'processed_at',
        'error_message',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<WebhookEvent, $this>
     */
    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class);
    }
}
