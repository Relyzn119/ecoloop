<?php

namespace App\Http\Controllers;

use App\Models\ProductBatch;
use App\Services\AiAgentService;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
        $batches = $this->availableBatches()->get();

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

    public function analyze(Request $request, AiAgentService $aiAgentService, InventoryService $inventoryService): RedirectResponse
    {
        $partner = $request->user()->donationPartner;
        abort_unless($partner?->isReadyForPortal(), 403);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $batches = $this->availableBatches()->limit(100)->get();
        if ($batches->isEmpty()) {
            return back()->withErrors(['photo' => 'Belum ada stok aktif yang dapat dipindai.']);
        }

        $photo = $request->file('photo');
        $decision = $aiAgentService->identifyAndEvaluateDonationPhoto($batches, base64_encode($photo->get()), (string) $photo->getMimeType(), true);
        $batch = $decision['matched_product_id'] === null
            ? null
            : $batches->firstWhere('product_id', $decision['matched_product_id']);
        $donationCompleted = false;
        $donationMessage = null;

        if ($batch && $decision['donation_eligible']) {
            try {
                $donation = $inventoryService->donateReviewedBatch(
                    $batch->id,
                    $partner->id,
                    'Donasi otomatis setelah scan foto oleh mitra. '.$decision['reasoning']
                );

                if ($donation['success']) {
                    $donationCompleted = true;
                    $donationMessage = sprintf(
                        '%d %s %s langsung didonasikan. %s',
                        $donation['quantity'],
                        $donation['unit'],
                        $donation['product_name'],
                        $donation['notifications_queued']
                            ? 'Notifikasi email dan Telegram dimasukkan ke antrean.'
                            : 'Donasi tersimpan, tetapi antrean notifikasi perlu diperiksa.'
                    );
                } else {
                    $donationMessage = $donation['message'];
                }
            } catch (Throwable $exception) {
                Log::warning('Automatic partner photo donation failed.', [
                    'batch_id' => $batch->id,
                    'partner_id' => $partner->id,
                    'user_id' => $request->user()->id,
                    'error' => $exception->getMessage(),
                ]);
                $donationMessage = 'Produk terdeteksi layak, tetapi donasi gagal diproses. Stok belum dikurangi; coba scan ulang atau hubungi manager.';
            }
        }

        return back()->with('visionResult', [
            ...$decision,
            'donation_completed' => $donationCompleted,
            'donation_message' => $donationMessage,
            'batch_id' => $batch?->id,
            'product_name' => $batch?->product->name,
            'batch_code' => $batch?->batch_code,
            'quantity_available' => $batch?->quantity_available,
            'unit' => $batch?->product->unit,
            'review_token' => null,
        ]);
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
