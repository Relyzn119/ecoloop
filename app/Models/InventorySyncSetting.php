<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventorySyncSetting extends Model
{
    protected $fillable = [
        'auto_sync_enabled',
        'auto_donation_enabled',
        'auto_discount_enabled',
        'auto_donation_prealert_enabled',
        'auto_decision_activated_by',
        'auto_decision_activated_at',
        'last_synced_at',
        'last_sync_summary',
    ];

    protected function casts(): array
    {
        return [
            'auto_sync_enabled' => 'boolean',
            'auto_donation_enabled' => 'boolean',
            'auto_discount_enabled' => 'boolean',
            'auto_donation_prealert_enabled' => 'boolean',
            'auto_decision_activated_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_sync_summary' => 'array',
        ];
    }
}
