<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDonationPartnerRequest;
use App\Http\Requests\UpdateDonationPartnerRequest;
use App\Models\AgentLog;
use App\Models\DonationPartner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DonationPartnerController extends Controller
{
    /**
     * Display a listing of donation partners with search, filtering, and summary statistics.
     */
    public function index(Request $request): Response
    {
        $query = DonationPartner::query();

        // Search by name, contact person, email, phone, or address
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        // Filter by active status
        if ($status = $request->input('status')) {
            if ($status === 'active') {
                $query->where('is_active', true);
            } elseif ($status === 'inactive') {
                $query->where('is_active', false);
            }
        }

        $partners = $query->orderBy('name', 'asc')
            ->paginate(10)
            ->withQueryString()
            ->through(fn (DonationPartner $partner) => [
                'id' => $partner->id,
                'name' => $partner->name,
                'contact_person' => $partner->contact_person,
                'phone' => $partner->phone,
                'email' => $partner->email,
                'address' => $partner->address,
                'is_active' => (bool) $partner->is_active,
                'created_at' => $partner->created_at?->format('M d, Y'),
            ]);

        $totalCount = DonationPartner::count();
        $activeCount = DonationPartner::where('is_active', true)->count();
        $inactiveCount = DonationPartner::where('is_active', false)->count();
        $totalDonationsDispatched = AgentLog::where('action_type', 'donation_requested')->count();

        return Inertia::render('Partners/Index', [
            'partners' => $partners,
            'filters' => [
                'search' => $request->input('search', ''),
                'status' => $request->input('status', ''),
            ],
            'stats' => [
                'total_partners' => $totalCount,
                'active_partners' => $activeCount,
                'inactive_partners' => $inactiveCount,
                'donations_dispatched' => $totalDonationsDispatched,
            ],
        ]);
    }

    /**
     * Store a newly created donation partner in storage.
     */
    public function store(StoreDonationPartnerRequest $request): RedirectResponse
    {
        $partner = DonationPartner::create($request->validated());

        return redirect()->back()
            ->with('success', "Donation partner '{$partner->name}' added successfully.");
    }

    /**
     * Update the specified donation partner in storage.
     */
    public function update(UpdateDonationPartnerRequest $request, DonationPartner $donationPartner): RedirectResponse
    {
        $donationPartner->update($request->validated());

        return redirect()->back()
            ->with('success', "Donation partner '{$donationPartner->name}' updated successfully.");
    }

    /**
     * Remove the specified donation partner from storage.
     */
    public function destroy(DonationPartner $donationPartner): RedirectResponse
    {
        $partnerName = $donationPartner->name;
        $donationPartner->delete();

        return redirect()->back()
            ->with('success', "Donation partner '{$partnerName}' deleted successfully.");
    }

    /**
     * Toggle the active status of the specified donation partner.
     */
    public function toggleStatus(DonationPartner $donationPartner): RedirectResponse
    {
        $donationPartner->update([
            'is_active' => ! $donationPartner->is_active,
        ]);

        $statusText = $donationPartner->is_active ? 'activated' : 'deactivated';

        return redirect()->back()
            ->with('success', "Partner '{$donationPartner->name}' has been {$statusText}.");
    }
}
