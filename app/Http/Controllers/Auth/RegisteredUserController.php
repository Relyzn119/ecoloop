<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'partner_type' => ['required', 'string', 'in:foundation,umkm,business_owner'],
            'organization_name' => ['required', 'string', 'max:200'],
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:150', 'unique:'.User::class],
            'phone' => ['required', 'string', 'max:50'],
            'telegram_chat_id' => ['required', 'string', 'max:64', 'unique:donation_partners,telegram_chat_id'],
            'address' => ['required', 'string', 'max:2000'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'],
                'role' => match ($validated['partner_type']) {
                    'umkm' => 'umkm_partner',
                    'business_owner' => 'business_owner',
                    default => 'donation_partner',
                },
                'password' => Hash::make($validated['password']),
            ]);

            $user->donationPartner()->create([
                'name' => $validated['organization_name'],
                'partner_type' => $validated['partner_type'],
                'contact_person' => $validated['name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'],
                'telegram_chat_id' => $validated['telegram_chat_id'],
                'address' => $validated['address'],
                'is_active' => false,
            ]);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        return to_route('partner.pending');
    }
}
