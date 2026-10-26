<?php

namespace App\Models;

use Database\Factories\ProductBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductBatch extends Model
{
    /** @use HasFactory<ProductBatchFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id', 'batch_code', 'quantity_received', 'quantity_available',
        'quantity_donated', 'quantity_sold', 'expiration_date', 'original_price',
        'discount_price', 'status', 'source', 'source_reference', 'store_code', 'source_synced_at',
        'partner_review_ready_at', 'partner_review_requested_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity_received' => 'integer',
            'quantity_available' => 'integer',
            'quantity_donated' => 'integer',
            'quantity_sold' => 'integer',
            'expiration_date' => 'date',
            'source_synced_at' => 'datetime',
            'partner_review_ready_at' => 'datetime',
            'original_price' => 'decimal:2',
            'discount_price' => 'decimal:2',
        ];
    }

    public function partnerReviewRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_review_requested_by');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function discountApprovals(): HasMany
    {
        return $this->hasMany(DiscountApproval::class);
    }
}
