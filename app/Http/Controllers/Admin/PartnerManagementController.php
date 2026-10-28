<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DonationPartner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PartnerManagementController extends Controller
{
    public function index(Request $request): Response
    {
        $query = DonationPartner::query()->with('user:id,name,email,role');
        $search = trim((string) $request->string('search'));
        $status = $request->string('status')->toString();
        $type = $request->string('type')->toString();

        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($status === 'pending') {
            $query->where('is_active', false);
        } elseif ($status === 'active') {
            $query->where('is_active', true);
        }

        if (in_array($type, ['foundation', 'umkm', 'business_owner'], true)) {
            $query->where('partner_type', $type);
        }

        $partners = $query->orderBy('is_active')->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (DonationPartner $partner): array => [
                'id' => $partner->id,
                'name' => $partner->name,
                'partner_type' => $partner->partner_type,
                'contact_person' => $partner->contact_person,
                'email' => $partner->email,
                'phone' => $partner->phone,
                'telegram_chat_id' => $partner->telegram_chat_id,
                'is_active' => (bool) $partner->is_active,
                'created_at' => $partner->created_at?->toIso8601String(),
                'user' => $partner->user?->only(['id', 'name', 'email', 'role']),
            ]);

        return Inertia::render('Admin/Partners/Index', [
            'partners' => $partners,
            'filters' => ['search' => $search, 'status' => $status, 'type' => $type],
            'stats' => [
                'total' => DonationPartner::count(),
                'pending' => DonationPartner::where('is_active', false)->count(),
                'active' => DonationPartner::where('is_active', true)->count(),
            ],
        ]);
    }

    public function approve(DonationPartner $donationPartner): RedirectResponse
    {
        DB::transaction(function () use ($donationPartner): void {
            $partner = DonationPartner::query()->lockForUpdate()->findOrFail($donationPartner->id);

            if ($partner->user) {
                $partner->user->update(['role' => $this->roleFor($partner->partner_type)]);
            }

            $partner->update(['is_active' => true]);
        });

        return back()->with('success', "{$donationPartner->name} telah disetujui dan diaktifkan.");
    }

    public function toggleStatus(DonationPartner $donationPartner): RedirectResponse
    {
        $donationPartner->update(['is_active' => ! $donationPartner->is_active]);

        return back()->with('success', $donationPartner->is_active
            ? "{$donationPartner->name} telah diaktifkan."
            : "Akses {$donationPartner->name} telah dinonaktifkan.");
    }

    public function destroy(DonationPartner $donationPartner): RedirectResponse
    {
        $name = $donationPartner->name;

        DB::transaction(function () use ($donationPartner): void {
            $partner = DonationPartner::query()->lockForUpdate()->findOrFail($donationPartner->id);
            $user = $partner->user;
            $partner->delete();
            $user?->delete();
        });

        return back()->with('success', "Akun mitra {$name} dan profilnya telah dihapus.");
    }

    private function roleFor(string $partnerType): string
    {
        return match ($partnerType) {
            'umkm' => 'umkm_partner',
            'business_owner' => 'business_owner',
            default => 'donation_partner',
        };
    }
}
