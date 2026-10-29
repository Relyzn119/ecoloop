<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class CreateEcoLoopAdmin extends Command
{
    protected $signature = 'ecoloop:make-admin';

    protected $description = 'Create a separate EcoLoop administrator account';

    public function handle(): int
    {
        $name = trim((string) $this->ask('Admin name'));
        $email = mb_strtolower(trim((string) $this->ask('Admin email')));

        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error('Name and a valid email address are required.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->components->error('That email already belongs to an account. Use a new email; existing supermarket roles are never promoted automatically.');

            return self::FAILURE;
        }

        $password = $this->secret('Admin password (minimum 12 characters)');
        $confirmation = $this->secret('Confirm admin password');
        $validator = validator([
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'password' => ['required', 'string', 'confirmed', Password::min(12)->mixedCase()->numbers()],
        ]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        User::query()->create([
            'name' => $name,
            'email' => $email,
            'role' => 'ecoloop_admin',
            'email_verified_at' => now(),
            'password' => Hash::make($password),
        ]);

        $this->components->info("EcoLoop admin account created for {$email}.");

        return self::SUCCESS;
    }
}
