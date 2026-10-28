<?php

namespace App\Models;

use Database\Factories\InventorySyncRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventorySyncRun extends Model
{
    /** @use HasFactory<InventorySyncRunFactory> */
    use HasFactory;

    protected $fillable = [
        'trigger_type',
        'status',
        'started_at',
        'completed_at',
        'summary',
        'details',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'summary' => 'array',
            'details' => 'array',
        ];
    }
}
