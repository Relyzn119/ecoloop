<?php

namespace App\Services;

use App\Models\AgentLog;
use App\Models\DonationPartner;
use App\Models\PartnerDonationClaim;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PartnerDonationClaimService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function create(DonationPartner $partner, User $user, int $batchId, int $quantity, string $reasoning): PartnerDonationClaim
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages(['quantity' => 'Jumlah pengambilan minimal 1.']);
        }

        $claim = DB::transaction(function () use ($partner, $user, $batchId, $quantity, $reasoning): PartnerDonationClaim {
            $batchReference = ProductBatch::query()->findOrFail($batchId);
            $product = Product::query()->lockForUpdate()->findOrFail($batchReference->product_id);
            $batch = ProductBatch::query()->lockForUpdate()->findOrFail($batchId);
            $reserved = PartnerDonationClaim::query()
                ->where('product_batch_id', $batch->id)
                ->where('status', 'pending_stock_decision')
                ->sum('quantity');
            $available = max(0, (int) $batch->quantity_available - (int) $reserved);

            if (! $partner->isReadyForPortal()
                || (int) $user->id !== (int) $partner->user_id
                || $batch->quantity_available < 1
                || ! in_array($batch->status, ['active', 'discounted'], true)
                || $batch->expiration_date->lt(now('Asia/Jakarta')->startOfDay())
                || $quantity > $available) {
                throw ValidationException::withMessages([
                    'quantity' => sprintf('Jumlah melebihi stok yang masih dapat diambil. Tersedia saat ini: %d %s.', $available, $product->unit),
                ]);
            }

            $claim = PartnerDonationClaim::query()->create([
                'id' => (string) Str::uuid(),
                'product_batch_id' => $batch->id,
                'donation_partner_id' => $partner->id,
                'requested_by' => $user->id,
                'quantity' => $quantity,
                'unit' => $product->unit,
                'reasoning' => $reasoning,
                'status' => 'pending_stock_decision',
            ]);

            $batch->increment('quantity_donated', $quantity);
            AgentLog::query()->create([
                'product_id' => $product->id,
                'partner_id' => $partner->id,
                'action_type' => 'donation_requested',
                'reasoning' => 'Donation Partner mengambil produk setelah analisis kamera. '.$reasoning,
                'payload' => [
                    'event_type' => 'donation_completed',
                    'source' => 'partner_photo_scan',
                    'partner_claim_id' => $claim->id,
                    'batch_id' => $batch->id,
                    'batch_code' => $batch->batch_code,
                    'quantity_donated' => $quantity,
                    'unit' => $product->unit,
                    'stock_reduction_applied' => null,
                    'stock_decision_pending' => true,
                ],
                'status' => 'success',
            ]);

            return $claim->load(['batch.product', 'partner', 'requester']);
        });

        $this->notifications->queuePartnerDonationClaim($claim);

        return $claim;
    }

    public function decideStock(string $claimId, User $manager, string $chatId, string $decision): string
    {
        if (! in_array($manager->role, ['business_owner', 'manager', 'cashier'], true)) {
            throw new AuthorizationException('Only a business owner, manager, or cashier can confirm stock adjustment.');
        }
        if (! in_array($decision, ['reduce', 'keep'], true)) {
            throw new AuthorizationException('Unknown stock decision.');
        }
        if (! hash_equals((string) $manager->telegram_chat_id, $chatId)) {
            throw new AuthorizationException('This Telegram account is not linked to the manager.');
        }

        return DB::transaction(function () use ($claimId, $manager, $decision): string {
            $claim = PartnerDonationClaim::query()->lockForUpdate()->findOrFail($claimId);
            if ($claim->status !== 'pending_stock_decision') {
                return 'Pengambilan ini sudah diproses dengan status: '.$claim->status.'.';
            }

            $batchReference = ProductBatch::query()->findOrFail($claim->product_batch_id);
            $product = Product::query()->lockForUpdate()->findOrFail($batchReference->product_id);
            $batch = ProductBatch::query()->lockForUpdate()->findOrFail($claim->product_batch_id);
            if ($decision === 'reduce' && $batch->quantity_available < $claim->quantity) {
                return sprintf(
                    'Stok tercatat saat ini hanya %d %s, sedangkan mitra telah mengambil %d %s. Stok belum diubah; pilih “Stok tetap” atau selesaikan selisih stok secara manual.',
                    $batch->quantity_available,
                    $claim->unit,
                    $claim->quantity,
                    $claim->unit
                );
            }

            $stockBefore = (int) $batch->quantity_available;
            $stockAfter = $decision === 'reduce' ? $stockBefore - $claim->quantity : $stockBefore;
            if ($decision === 'reduce') {
                $batch->update([
                    'quantity_available' => $stockAfter,
                    'status' => $stockAfter === 0 ? 'donated' : $batch->status,
                ]);
                app(InventoryService::class)->refreshProductStockSummary($product->id);
            }

            $claim->update([
                'status' => 'completed',
                'decided_by' => $manager->id,
                'stock_reduction_applied' => $decision === 'reduce',
                'completed_at' => now(),
            ]);

            AgentLog::query()
                ->where('payload->partner_claim_id', $claim->id)
                ->get()
                ->each(function (AgentLog $log) use ($decision, $stockBefore, $stockAfter, $manager): void {
                    $payload = $log->payload ?? [];
                    $log->update(['payload' => [
                        ...$payload,
                        'stock_decision_pending' => false,
                        'stock_reduction_applied' => $decision === 'reduce',
                        'stock_before' => $stockBefore,
                        'stock_after' => $stockAfter,
                        'stock_decided_by' => $manager->id,
                    ]]);
                });

            $claim->load(['batch.product', 'partner']);
            $this->notifications->queuePartnerDonationStockResult($claim);

            return sprintf(
                'Dicatat: mitra %s mengambil %d %s %s. Stok %s (%d → %d %s).',
                $claim->partner->name,
                $claim->quantity,
                $claim->unit,
                $claim->batch->product->name,
                $decision === 'reduce' ? 'dikurangi' : 'tetap',
                $stockBefore,
                $stockAfter,
                $claim->unit
            );
        });
    }

    public function availableQuantity(ProductBatch $batch): int
    {
        $reserved = PartnerDonationClaim::query()
            ->where('product_batch_id', $batch->id)
            ->where('status', 'pending_stock_decision')
            ->sum('quantity');

        return max(0, (int) $batch->quantity_available - (int) $reserved);
    }
}
