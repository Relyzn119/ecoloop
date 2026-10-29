<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UmkmPartnerAccountSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'rotteyzn008@gmail.com';
        $user = User::query()->firstOrNew(['email' => $email]);
        $isNew = ! $user->exists;

        if ($isNew) {
            $user->fill([
                'name' => 'Mitra UMKM Rotteyzn008',
                'email' => $email,
                'phone' => 'Belum diisi',
                'role' => 'umkm_partner',
                'password' => Hash::make(bin2hex(random_bytes(32))),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        if ($isNew || ! $user->donationPartner) {
            $user->donationPartner()->create([
                'name' => 'UMKM Rotteyzn008',
                'partner_type' => 'umkm',
                'contact_person' => $user->name,
                'phone' => $user->phone ?: 'Belum diisi',
                'email' => $email,
                'telegram_chat_id' => null,
                'address' => 'Belum diisi',
                'is_active' => false,
            ]);
        }
    }
}
