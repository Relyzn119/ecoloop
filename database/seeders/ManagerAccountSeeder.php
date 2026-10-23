<?php

namespace Database\Seeders;

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
        $admin = User::firstOrCreate(
            ['email' => 'admin@ecoloop.local'],
            [
                'name' => 'EcoLoop Admin',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        $admin->forceFill([
            'role' => 'manager',
            'phone' => $admin->phone ?: '+6281234567890',
        ])->save();
    }
}
