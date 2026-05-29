<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebhookEvent extends Model
{
    protected $fillable = [
        'delivery_id',
        'provider',
        'scope_id',
        'event_type',
        'actor_id',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'datetime',
    ];

    /**
     * @return HasMany<AgentDispatch, $this>
     */
    public function dispatches(): HasMany
    {
        return $this->hasMany(AgentDispatch::class);
    }
}
