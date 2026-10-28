<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerDonationClaim extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'product_batch_id',
        'donation_partner_id',
        'requested_by',
        'decided_by',
        'quantity',
        'unit',
        'reasoning',
        'status',
        'stock_reduction_applied',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'stock_reduction_applied' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(DonationPartner::class, 'donation_partner_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
