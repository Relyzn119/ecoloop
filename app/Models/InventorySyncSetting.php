<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventorySyncSetting extends Model
{
    protected $fillable = [
        'auto_sync_enabled',
        'last_synced_at',
        'last_sync_summary',
    ];

    protected function casts(): array
    {
        return [
            'auto_sync_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_sync_summary' => 'array',
        ];
    }
}
