<?php

namespace App\Models;

use Database\Factories\DiscountApprovalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountApproval extends Model
{
    /** @use HasFactory<DiscountApprovalFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id', 'product_batch_id', 'recommended_percentage', 'recommended_price',
        'approved_percentage', 'approved_price', 'status', 'reasoning', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'recommended_percentage' => 'integer',
            'recommended_price' => 'decimal:2',
            'approved_percentage' => 'integer',
            'approved_price' => 'decimal:2',
            'decided_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }
}
