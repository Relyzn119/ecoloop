<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class EcoLoopAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'rotteyzn007@gmail.com';
        $password = (string) env('ECOLOOP_ADMIN_PASSWORD');

        if ($password !== '') {
            $validator = validator(['password' => $password], [
                'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()],
            ]);

            if ($validator->fails()) {
                throw new RuntimeException('ECOLOOP_ADMIN_PASSWORD must be at least 12 characters and include uppercase, lowercase, and numbers.');
            }
        }

        $user = User::query()
            ->whereIn('email', ['admin2@ecoloop.com', $email])
            ->orderByRaw('email = ? DESC', [$email])
            ->first() ?? new User;
        $isNew = ! $user->exists;
        $user->fill([
            'email' => $email,
            'name' => 'Admin EcoLoop',
            'role' => 'ecoloop_admin',
        ]);
        if ($password !== '') {
            $user->password = Hash::make($password);
        } elseif ($isNew) {
            // Unknown temporary credential; the user sets their own password through the reset flow.
            $user->password = Hash::make(bin2hex(random_bytes(32)));
        }
        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
    }
}
