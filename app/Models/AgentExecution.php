<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentExecution extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'execution_id',
        'event_type',
        'execution_time',
        'total_processed',
        'total_discounted',
        'total_donated',
        'total_skipped',
        'total_failed',
        'status',
        'error_message',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'execution_time' => 'datetime',
            'total_processed' => 'integer',
            'total_discounted' => 'integer',
            'total_donated' => 'integer',
            'total_skipped' => 'integer',
            'total_failed' => 'integer',
            'payload' => 'array',
        ];
    }
}
