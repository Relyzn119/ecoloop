<?php

namespace App\Http\Controllers;

use App\Models\PartnerDonationClaim;
use App\Models\ProductBatch;
use App\Services\AiAgentService;
use App\Services\PartnerDonationClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PartnerPortalController extends Controller
{
    public function pending(Request $request): Response
    {
        return Inertia::render('Partner/Pending', [
            'partner' => $request->user()->donationPartner?->only(['name', 'partner_type', 'is_active']),
        ]);
    }

    public function offers(Request $request): Response
    {
        $partner = $request->user()->donationPartner;
        abort_unless($partner?->isReadyForPortal() && $partner->partner_type === 'umkm', 403);

        $offers = ProductBatch::query()
            ->with('product:id,sku,name,unit')
            ->where('status', 'discounted')
            ->where('quantity_available', '>', 0)
            ->whereDate('expiration_date', '>=', now()->toDateString())
            ->orderBy('expiration_date')
            ->paginate(20)
            ->through(fn (ProductBatch $batch): array => [
                'id' => $batch->id,
                'product_name' => $batch->product->name,
                'sku' => $batch->product->sku,
                'unit' => $batch->product->unit,
                'batch_code' => $batch->batch_code,
                'quantity_available' => $batch->quantity_available,
                'original_price' => (float) $batch->original_price,
                'discount_price' => (float) $batch->discount_price,
                'discount_percentage' => $batch->original_price > 0
                    ? (int) round((1 - ((float) $batch->discount_price / (float) $batch->original_price)) * 100)
                    : 0,
                'expiration_date' => $batch->expiration_date->toDateString(),
            ]);

        return Inertia::render('Umkm/Offers', ['offers' => $offers]);
    }

    public function scan(Request $request): Response
    {
        $partner = $request->user()->donationPartner;
        abort_unless($partner?->isReadyForPortal(), 403);
        $batches = $this->availableBatchesForPartner();

        return Inertia::render('Simulation/Index', [
            'portalMode' => 'partner',
            'batches' => $batches->map(fn (ProductBatch $batch): array => $this->batchPayload($batch)),
            'donationPartners' => [[
                'id' => $partner->id,
                'name' => $partner->name,
                'telegram_ready' => filled($partner->telegram_chat_id),
            ]],
            'pendingApprovals' => [],
            'recentLogs' => [],
            'lastUpdatedAt' => now()->toIso8601String(),
            'visionResult' => $request->session()->get('visionResult'),
        ]);
    }

    public function analyze(Request $request, AiAgentService $aiAgentService): RedirectResponse
    {
        $partner = $request->user()->donationPartner;
        abort_unless($partner?->isReadyForPortal(), 403);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $batches = $this->availableBatchesForPartner();
        if ($batches->isEmpty()) {
            return back()->withErrors(['photo' => 'Belum ada stok aktif yang dapat dipindai.']);
        }

        $photo = $request->file('photo');
        $decision = $aiAgentService->identifyAndEvaluateDonationPhoto($batches, base64_encode($photo->get()), (string) $photo->getMimeType(), true);
        $batch = $decision['matched_product_id'] === null
            ? null
            : $batches->firstWhere('product_id', $decision['matched_product_id']);
        $reviewToken = null;
        if ($batch && $decision['donation_eligible']) {
            $reviewToken = (string) Str::uuid();
            $request->session()->put('partner_vision_reviews.'.$reviewToken, [
                'batch_id' => $batch->id,
                'partner_id' => $partner->id,
                'reasoning' => $decision['reasoning'],
                'expires_at' => now()->addMinutes(15)->timestamp,
            ]);
        }

        return back()->with('visionResult', [
            ...$decision,
            'donation_completed' => false,
            'donation_message' => null,
            'batch_id' => $batch?->id,
            'product_name' => $batch?->product->name,
            'batch_code' => $batch?->batch_code,
            'quantity_available' => $batch?->quantity_available,
            'unit' => $batch?->product->unit,
            'review_token' => $reviewToken,
        ]);
    }

    public function claim(Request $request, PartnerDonationClaimService $claims): RedirectResponse
    {
        $partner = $request->user()->donationPartner;
        abort_unless($partner?->isReadyForPortal(), 403);
        $validated = $request->validate([
            'review_token' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);
        $sessionKey = 'partner_vision_reviews.'.$validated['review_token'];
        $review = $request->session()->get($sessionKey);
        if (! is_array($review)
            || (int) ($review['partner_id'] ?? 0) !== (int) $partner->id
            || (int) ($review['expires_at'] ?? 0) < now()->timestamp) {
            $request->session()->forget($sessionKey);

            return back()->withErrors(['review_token' => 'Hasil scan kedaluwarsa. Silakan foto dan analisis produk lagi.']);
        }

        try {
            $claim = $claims->create(
                $partner,
                $request->user(),
                (int) $review['batch_id'],
                (int) $validated['quantity'],
                (string) $review['reasoning']
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('Partner photo donation claim could not be recorded.', [
                'partner_id' => $partner->id,
                'user_id' => $request->user()->id,
                'error' => $exception->getMessage(),
            ]);

            return back()->withErrors(['quantity' => 'Pengambilan belum berhasil dicatat. Periksa stok lalu coba lagi.']);
        }

        $request->session()->forget($sessionKey);

        return back()->with('success', sprintf(
            '%d %s %s tercatat diambil oleh %s. Donasi sudah masuk ke riwayat; manager/kasir sudah diberi notifikasi untuk memilih apakah stok supermarket ikut dikurangi.',
            $claim->quantity,
            $claim->unit,
            $claim->batch->product->name,
            $partner->name
        ));
    }

    private function availableBatches()
    {
        return ProductBatch::query()
            ->with(['product:id,sku,name,category_id,unit', 'product.category:id,name'])
            ->where('quantity_available', '>', 0)
            ->whereIn('status', ['active', 'discounted'])
            ->whereDate('expiration_date', '>=', now()->toDateString())
            ->orderBy('expiration_date')
            ->limit(100);
    }

    /** @return Collection<int, ProductBatch> */
    private function availableBatchesForPartner(): Collection
    {
        $batches = $this->availableBatches()->get();
        if ($batches->isEmpty()) {
            return $batches;
        }

        $reserved = PartnerDonationClaim::query()
            ->whereIn('product_batch_id', $batches->modelKeys())
            ->where('status', 'pending_stock_decision')
            ->selectRaw('product_batch_id, SUM(quantity) AS reserved_quantity')
            ->groupBy('product_batch_id')
            ->pluck('reserved_quantity', 'product_batch_id');

        return $batches->map(function (ProductBatch $batch) use ($reserved): ProductBatch {
            $batch->quantity_available = max(0, (int) $batch->quantity_available - (int) ($reserved[$batch->id] ?? 0));

            return $batch;
        })->filter(fn (ProductBatch $batch): bool => $batch->quantity_available > 0)->values();
    }

    private function batchPayload(ProductBatch $batch): array
    {
        return [
            'id' => $batch->id, 'product_id' => $batch->product_id,
            'sku' => $batch->product->sku, 'name' => $batch->product->name,
            'category' => $batch->product->category?->name ?? 'General',
            'batch_code' => $batch->batch_code, 'quantity_received' => $batch->quantity_received,
            'quantity_available' => $batch->quantity_available, 'quantity_donated' => $batch->quantity_donated,
            'quantity_sold' => $batch->quantity_sold, 'unit' => $batch->product->unit,
            'original_price' => (float) $batch->original_price,
            'discount_price' => $batch->discount_price !== null ? (float) $batch->discount_price : null,
            'status' => $batch->status, 'source' => $batch->source, 'store_code' => $batch->store_code,
            'source_synced_at' => $batch->source_synced_at?->toIso8601String(),
            'expiration_date' => $batch->expiration_date->toDateString(),
            'days_remaining' => (int) now()->startOfDay()->diffInDays($batch->expiration_date->startOfDay(), false),
        ];
    }
}
