<?php

namespace App\Http\Controllers;

use App\Models\ProductBatch;
use App\Services\AiAgentService;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PartnerPortalController extends Controller
{
    public function pending(Request $request): Response
    {
        return Inertia::render('Partner/Pending', [
            'partner' => $request->user()->donationPartner?->only(['name', 'is_active']),
        ]);
    }

    public function scan(Request $request): Response
    {
        $partner = $request->user()->donationPartner;
        abort_unless($partner?->isReadyForPortal(), 403);
        $batches = $this->readyBatches()->get();
        $options = $batches->map(fn (ProductBatch $batch): array => [
            'id' => $batch->id,
            'name' => $batch->product->name,
            'sku' => $batch->product->sku,
            'batch_code' => $batch->batch_code,
            'quantity_available' => $batch->quantity_available,
            'unit' => $batch->product->unit,
            'expiration_date' => $batch->expiration_date->toDateString(),
        ]);

        return Inertia::render('Simulation/Index', [
            'portalMode' => 'partner',
            'batches' => $batches->map(fn (ProductBatch $batch): array => $this->batchPayload($batch)),
            'donationBatches' => $options,
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
        $data = $request->validate([
            'batch_id' => ['required', 'integer', 'exists:product_batches,id'],
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $batch = $this->readyBatches()->with('product.category')->find($data['batch_id']);
        if (! $batch) {
            return back()->withErrors(['batch_id' => 'Batch ini tidak lagi tersedia untuk dipindai.']);
        }

        $photo = $request->file('photo');
        $decision = $aiAgentService->evaluateDonationPhoto($batch->product, $batch, base64_encode($photo->get()), (string) $photo->getMimeType());
        $token = null;
        if ($decision['donation_eligible']) {
            $token = (string) Str::uuid();
            $request->session()->put('partner_vision_reviews.'.$token, [
                'user_id' => $request->user()->id,
                'partner_id' => $request->user()->donationPartner->id,
                'batch_id' => $batch->id,
                'reasoning' => $decision['reasoning'],
                'expires_at' => now()->addMinutes(15)->timestamp,
            ]);
        }

        return back()->with('visionResult', [
            ...$decision,
            'batch_id' => $batch->id,
            'product_name' => $batch->product->name,
            'batch_code' => $batch->batch_code,
            'quantity_available' => $batch->quantity_available,
            'unit' => $batch->product->unit,
            'review_token' => $token,
        ]);
    }

    public function donate(Request $request, InventoryService $inventoryService): RedirectResponse
    {
        $data = $request->validate(['review_token' => ['required', 'uuid']]);
        $key = 'partner_vision_reviews.'.$data['review_token'];
        $review = $request->session()->get($key);
        $partner = $request->user()->donationPartner;
        if (! is_array($review)
            || ($review['expires_at'] ?? 0) < now()->timestamp
            || ($review['user_id'] ?? null) !== $request->user()->id
            || ($review['partner_id'] ?? null) !== $partner?->id) {
            $request->session()->forget($key);

            return back()->with('error', 'Hasil scan sudah kedaluwarsa atau tidak valid. Silakan scan ulang.');
        }

        try {
            $result = $inventoryService->donateReviewedBatch((int) $review['batch_id'], (int) $partner->id, (string) $review['reasoning'], true);
        } catch (Throwable $exception) {
            Log::warning('Donation partner scan donation failed.', ['batch_id' => $review['batch_id'] ?? null, 'user_id' => $request->user()->id, 'error' => $exception->getMessage()]);

            return back()->with('error', 'Donasi belum berhasil. Muat ulang dan coba scan lagi.');
        }

        if (! $result['success']) {
            return back()->with('error', $result['message']);
        }
        $request->session()->forget($key);

        return back()->with('success', sprintf('%d %s %s berhasil didonasikan. Notifikasi email dan Telegram telah dimasukkan ke antrean.', $result['quantity'], $result['unit'], $result['product_name']));
    }

    private function readyBatches()
    {
        return ProductBatch::query()
            ->with(['product:id,sku,name,category_id,unit', 'product.category:id,name'])
            ->whereNotNull('partner_review_ready_at')
            ->where('quantity_available', '>', 0)
            ->whereIn('status', ['active', 'discounted'])
            ->whereDate('expiration_date', '>=', now()->toDateString())
            ->orderBy('expiration_date');
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
