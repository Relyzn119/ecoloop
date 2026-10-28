<?php

namespace Database\Seeders;

use App\Models\DonationPartner;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ManagerAccountSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $owner = User::firstOrCreate(
            ['email' => 'admin@ecoloop.local'],
            [
                'name' => 'Pemberi Donasi EcoLoop',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        if ($owner->name === 'EcoLoop Admin') {
            $owner->name = 'Pemberi Donasi EcoLoop';
        }

        $owner->forceFill([
            'role' => 'business_owner',
            'phone' => $owner->phone ?: '+6281234567890',
            'email_verified_at' => $owner->email_verified_at ?: now(),
        ])->save();

        $partner = DonationPartner::query()->firstOrNew(['user_id' => $owner->id]);
        $partner->fill([
            'name' => $partner->name ?: 'Supermarket EcoLoop',
            'partner_type' => 'business_owner',
            'contact_person' => $partner->contact_person ?: $owner->name,
            'phone' => $owner->phone,
            'email' => $owner->email,
            'telegram_chat_id' => $owner->telegram_chat_id ?: $partner->telegram_chat_id,
            'address' => $partner->address ?: 'Alamat toko belum diisi',
            'is_active' => true,
            'user_id' => $owner->id,
        ])->save();
    }
}
