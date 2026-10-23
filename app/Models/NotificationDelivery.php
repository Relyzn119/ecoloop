<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationDelivery extends Model
{
    protected $fillable = [
        'event_type', 'channel', 'recipient', 'dedupe_key', 'subject', 'payload',
        'status', 'attempts', 'last_error', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }
}
