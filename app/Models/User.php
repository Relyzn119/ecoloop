<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'role',
        'telegram_chat_id',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @param Builder<User> $query */
    public function scopeNotificationRecipients(Builder $query): Builder
    {
        return $query->whereIn('role', ['manager', 'cashier']);
    }

    /** Get the donation partner profile owned by this account. */
    public function donationPartner(): HasOne
    {
        return $this->hasOne(DonationPartner::class);
    }

    /** Resolve the post-authentication destination for this account role. */
    public function landingRoute(): string
    {
        if ($this->role === 'ecoloop_admin') {
            return 'admin.partners.index';
        }

        if (in_array($this->role, ['donation_partner', 'umkm_partner', 'business_owner'], true)) {
            return $this->donationPartner?->is_active
                ? match ($this->role) {
                    'umkm_partner' => 'umkm.offers',
                    'donation_partner' => 'partner.scan',
                    'business_owner' => 'business-owner.inventory',
                    default => 'partner.pending',
                }
            : 'partner.pending';
        }

        return 'dashboard';
    }
}
