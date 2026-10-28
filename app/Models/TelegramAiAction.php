<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramAiAction extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'telegram_chat_id',
        'requested_by',
        'decided_by',
        'action_type',
        'product_id',
        'payload',
        'status',
        'expires_at',
        'completed_at',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
