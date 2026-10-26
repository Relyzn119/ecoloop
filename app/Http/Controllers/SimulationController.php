<?php

namespace App\Http\Controllers;

use App\Http\Resources\AgentLogResource;
use App\Models\AgentLog;
use App\Models\DiscountApproval;
use App\Models\DonationPartner;
use App\Models\ProductBatch;
use App\Services\AiAgentService;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class SimulationController extends Controller
{
    /**
     * Display the latest inventory batches, pending discount approvals, and agent activity.
     */
    public function index(Request $request): Response
    {
        $batches = ProductBatch::query()
            ->with(['product:id,sku,name,category_id,unit', 'product.category:id,name'])
            ->orderBy('expiration_date')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(function (ProductBatch $batch): array {
                $daysRemaining = (int) now()->startOfDay()->diffInDays($batch->expiration_date->startOfDay(), false);

                return [
                    'id' => $batch->id,
                    'product_id' => $batch->product_id,
                    'sku' => $batch->product->sku,
                    'name' => $batch->product->name,
                    'category' => $batch->product->category?->name ?? 'General',
                    'batch_code' => $batch->batch_code,
                    'quantity_received' => $batch->quantity_received,
                    'quantity_available' => $batch->quantity_available,
                    'quantity_donated' => $batch->quantity_donated,
                    'quantity_sold' => $batch->quantity_sold,
                    'unit' => $batch->product->unit,
                    'original_price' => (float) $batch->original_price,
                    'discount_price' => $batch->discount_price !== null ? (float) $batch->discount_price : null,
                    'status' => $batch->status,
                    'source' => $batch->source,
                    'store_code' => $batch->store_code,
                    'source_synced_at' => $batch->source_synced_at?->toIso8601String(),
                    'expiration_date' => $batch->expiration_date->toDateString(),
                    'days_remaining' => $daysRemaining,
                ];
            });

        $pendingApprovals = DiscountApproval::query()
            ->with(['product:id,sku,name', 'batch:id,batch_code,quantity_available,expiration_date'])
            ->where('status', 'pending')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (DiscountApproval $approval): array => [
                'id' => $approval->id,
                'product_name' => $approval->product->name,
                'sku' => $approval->product->sku,
                'batch_code' => $approval->batch->batch_code,
                'quantity' => $approval->batch->quantity_available,
                'expiration_date' => $approval->batch->expiration_date->toDateString(),
                'recommended_percentage' => $approval->recommended_percentage,
                'recommended_price' => (float) $approval->recommended_price,
                'reasoning' => $approval->reasoning,
                'created_at' => $approval->created_at?->toIso8601String(),
            ]);

        $recentLogs = AgentLog::query()
            ->with(['product:id,name,sku', 'partner:id,name'])
            ->latest('id')
            ->limit(10)
            ->get();

        $partners = DonationPartner::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'telegram_chat_id'])
            ->map(fn (DonationPartner $partner): array => [
                'id' => $partner->id,
                'name' => $partner->name,
                'telegram_ready' => filled($partner->telegram_chat_id),
            ]);

        return Inertia::render('Simulation/Index', [
            'batches' => $batches,
            'donationPartners' => $partners,
            'pendingApprovals' => $pendingApprovals,
            'recentLogs' => AgentLogResource::collection($recentLogs),
            'lastUpdatedAt' => now()->toIso8601String(),
            'visionResult' => $request->session()->get('visionResult'),
        ]);
    }

    /** Analyze a single photo without changing inventory. */
    public function analyzeDonationPhoto(Request $request, AiAgentService $aiAgentService): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $batches = ProductBatch::query()
            ->with('product.category')
            ->where('quantity_available', '>', 0)
            ->whereIn('status', ['active', 'discounted'])
            ->whereDate('expiration_date', '>=', now()->toDateString())
            ->orderBy('expiration_date')
            ->limit(100)
            ->get();

        if ($batches->isEmpty()) {
            return back()->withErrors(['photo' => 'Tidak ada stok aktif yang dapat dicocokkan.']);
        }

        $photo = $request->file('photo');
        $decision = $aiAgentService->identifyAndEvaluateDonationPhoto(
            $batches,
            base64_encode($photo->get()),
            (string) $photo->getMimeType()
        );
        $batch = $decision['matched_product_id'] === null
            ? null
            : $batches->firstWhere('product_id', $decision['matched_product_id']);

        $reviewToken = null;
        if ($batch && $decision['donation_eligible']) {
            $reviewToken = (string) Str::uuid();
            $request->session()->put('simulation_vision_reviews.'.$reviewToken, [
                'batch_id' => $batch->id,
                'reasoning' => $decision['reasoning'],
                'expires_at' => now()->addMinutes(15)->timestamp,
            ]);
        }

        return back()->with('visionResult', [
            ...$decision,
            'batch_id' => $batch?->id,
            'product_name' => $batch?->product->name,
            'batch_code' => $batch?->batch_code,
            'quantity_available' => $batch?->quantity_available,
            'unit' => $batch?->product->unit,
            'review_token' => $reviewToken,
        ]);
    }

    /** Complete donation only after an eligible photo result and an explicit user click. */
    public function donateReviewedBatch(Request $request, InventoryService $inventoryService): RedirectResponse
    {
        $validated = $request->validate([
            'review_token' => ['required', 'uuid'],
            'partner_id' => [
                'required',
                'integer',
                Rule::exists('donation_partners', 'id')
                    ->where('is_active', true)
                    ->whereNotNull('telegram_chat_id'),
            ],
        ]);

        $sessionKey = 'simulation_vision_reviews.'.$validated['review_token'];
        $review = $request->session()->get($sessionKey);
        if (! is_array($review) || ($review['expires_at'] ?? 0) < now()->timestamp) {
            $request->session()->forget($sessionKey);

            return back()->with('error', 'Hasil pemeriksaan foto sudah kedaluwarsa. Silakan lakukan analisis foto lagi.');
        }

        try {
            $result = $inventoryService->donateReviewedBatch(
                (int) $review['batch_id'],
                (int) $validated['partner_id'],
                (string) $review['reasoning']
            );
        } catch (Throwable $exception) {
            Log::warning('Photo-reviewed donation could not be completed.', [
                'batch_id' => $review['batch_id'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            return back()->with('error', 'Donasi belum berhasil. Periksa stok dan mitra, lalu muat ulang halaman.');
        }

        if (! $result['success']) {
            $request->session()->forget($sessionKey);

            return back()->with('error', $result['message']);
        }

        $request->session()->forget($sessionKey);

        $message = sprintf(
            '%d %s %s berhasil didonasikan ke %s.',
            $result['quantity'],
            $result['unit'],
            $result['product_name'],
            $result['partner_name']
        );
        $message .= $result['notifications_queued']
            ? ' Pemberitahuan email dan Telegram telah dimasukkan ke antrean.'
            : ' Namun antrean notifikasi gagal dibuat; periksa log aplikasi sebelum menyampaikan kepada mitra.';

        return back()->with('success', $message);
    }
}
