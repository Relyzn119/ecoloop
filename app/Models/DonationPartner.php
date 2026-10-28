<?php

namespace App\Models;

use Database\Factories\DonationPartnerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DonationPartner extends Model
{
    /** @use HasFactory<DonationPartnerFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'partner_type',
        'contact_person',
        'phone',
        'email',
        'telegram_chat_id',
        'address',
        'is_active',
        'user_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope a query to only include active donation partners.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @param Builder<$this> $query */
    public function scopeUmkm(Builder $query): Builder
    {
        return $query->where('partner_type', 'umkm');
    }

    /** Get the account that manages this donation partner profile. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isReadyForPortal(): bool
    {
        return $this->is_active && $this->user_id !== null;
    }
}
