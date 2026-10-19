<?php

namespace App\Models;

use Database\Factories\AgentLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentLog extends Model
{
    /** @use HasFactory<AgentLogFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'partner_id',
        'action_type',
        'reasoning',
        'payload',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * Get the product that this agent action targeted.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the donation partner associated with this action.
     *
     * @return BelongsTo<DonationPartner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(DonationPartner::class);
    }
}
