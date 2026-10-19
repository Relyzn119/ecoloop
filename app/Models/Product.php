<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'sku',
        'name',
        'description',
        'quantity',
        'unit',
        'original_price',
        'discount_price',
        'expiration_date',
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
            'quantity' => 'integer',
            'original_price' => 'decimal:2',
            'discount_price' => 'decimal:2',
            'expiration_date' => 'date',
        ];
    }

    /**
     * Get the category that owns the product.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the agent audit logs associated with this product.
     *
     * @return HasMany<AgentLog, $this>
     */
    public function agentLogs(): HasMany
    {
        return $this->hasMany(AgentLog::class);
    }

    /**
     * Scope a query to only include active or discounted products.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['active', 'discounted']);
    }

    /**
     * Scope a query to include products expiring within a given number of days.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeExpiringSoon(Builder $query, int $days = 3): Builder
    {
        return $query->where('expiration_date', '<=', now()->addDays($days)->toDateString())
            ->where('expiration_date', '>=', now()->toDateString());
    }
}
