<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAutomationPolicy extends Model
{
    protected $fillable = [
        'product_id',
        'auto_donation_enabled',
        'auto_discount_enabled',
        'auto_donation_prealert_enabled',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'auto_donation_enabled' => 'boolean',
            'auto_discount_enabled' => 'boolean',
            'auto_donation_prealert_enabled' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
