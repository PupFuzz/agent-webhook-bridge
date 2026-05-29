<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentDispatch extends Model
{
    protected $fillable = [
        'webhook_event_id',
        'agent_name',
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
