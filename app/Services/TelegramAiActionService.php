<?php

namespace App\Services;

use App\Models\ProductBatch;
use App\Models\TelegramAiAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TelegramAiActionService
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function decide(string $actionId, User $user, string $chatId, string $decision): string
    {
        if (! in_array($user->role, ['business_owner', 'manager', 'cashier'], true)) {
            throw new AuthorizationException('Only a business owner, manager, or cashier may confirm inventory actions.');
        }

        if (! in_array($decision, ['confirm', 'cancel', 'reduce', 'keep'], true)) {
            throw new AuthorizationException('Unknown Telegram AI action decision.');
        }

        return DB::transaction(function () use ($actionId, $user, $chatId, $decision): string {
            $action = TelegramAiAction::query()->lockForUpdate()->findOrFail($actionId);
            if ((int) $action->requested_by !== (int) $user->id || ! hash_equals($action->telegram_chat_id, $chatId)) {
                throw new AuthorizationException('This pending action belongs to another manager chat.');
            }

            if ($action->status !== 'pending') {
                return 'Permintaan ini sudah diproses dengan status: '.$action->status.'.';
            }

            if ($action->expires_at->isPast()) {
                $action->update(['status' => 'expired', 'completed_at' => now()]);

                return 'Konfirmasi kedaluwarsa. Belum ada perubahan stok atau harga; silakan ajukan ulang jika masih diperlukan.';
            }

            if ($decision === 'cancel') {
                $action->update([
                    'status' => 'cancelled',
                    'decided_by' => $user->id,
                    'completed_at' => now(),
                ]);

                return 'Permintaan dibatalkan. Stok dan harga tidak berubah.';
            }

            $payload = $action->payload ?? [];
            if ($action->action_type === 'donate') {
                $deductStock = $decision !== 'keep';
                $result = app(InventoryService::class)->donateExpiringProductQuantity(
                    (int) $action->product_id,
                    (int) $payload['quantity'],
                    $user->id,
                    $action->id,
                    $deductStock
                );

                if (! $result['success']) {
                    $action->update([
                        'status' => 'failed',
                        'decided_by' => $user->id,
                        'completed_at' => now(),
                        'result' => $result,
                    ]);

                    return sprintf(
                        'Stok berubah sebelum konfirmasi. Donasi tidak dijalankan. Stok %s yang masih tersedia pada batch kedaluwarsa maksimal 7 hari: %d %s.',
                        $result['product_name'],
                        $result['available_quantity'],
                        $result['unit']
                    );
                }

                $action->update([
                    'status' => 'completed',
                    'decided_by' => $user->id,
                    'completed_at' => now(),
                    'result' => [
                        'quantity' => $result['quantity'],
                        'unit' => $result['unit'],
                        'remaining_stock' => $result['remaining_stock'],
                        'allocations' => $result['allocations'],
                        'notifications_queued' => $result['notifications_queued'],
                        'partner_count' => $result['partner_count'],
                        'stock_reduction_applied' => $deductStock,
                    ],
                ]);

                $notificationStatus = $result['notifications_queued']
                    ? "Notifikasi email/Telegram dimasukkan ke antrean untuk {$result['partner_count']} yayasan aktif."
                    : 'Donasi tercatat, tetapi antrean notifikasi perlu diperiksa oleh admin.';

                return sprintf(
                    'Donasi dikonfirmasi: %s %d %s. Stok %s; jumlah tercatat %d %s. Batch dengan tanggal kedaluwarsa terdekat digunakan lebih dahulu. %s',
                    $result['product_name'],
                    $result['quantity'],
                    $result['unit'],
                    $deductStock ? 'dikurangi' : 'tidak dikurangi',
                    $result['remaining_stock'],
                    $result['unit'],
                    $notificationStatus
                );
            }

            if ($decision !== 'confirm' && $decision !== 'cancel') {
                throw new AuthorizationException('Choose the normal confirmation button for non-donation requests.');
            }

            if ($action->action_type === 'discount') {
                $result = app(InventoryService::class)->requestExpiringDiscountApproval(
                    (int) $action->product_id,
                    (int) $payload['discount_percentage'],
                    (string) $payload['reason']
                );

                if ($result['batch_count'] === 0) {
                    $action->update([
                        'status' => 'failed',
                        'decided_by' => $user->id,
                        'completed_at' => now(),
                        'result' => $result,
                    ]);

                    return 'Batch aktif yang kedaluwarsa dalam 7 hari sudah tidak tersedia. Harga tidak berubah.';
                }

                $action->update([
                    'status' => 'completed',
                    'decided_by' => $user->id,
                    'completed_at' => now(),
                    'result' => $result,
                ]);

                return sprintf(
                    'Rekomendasi diskon %d%% untuk %s diajukan pada %d batch mendekati kedaluwarsa. Harga belum berubah; manager/kasir perlu menyetujui melalui notifikasi Telegram atau menu Discount Recommendations.',
                    $payload['discount_percentage'],
                    $result['product_name'],
                    $result['batch_count']
                );
            }

            throw new AuthorizationException('Unsupported EcoLoop AI action.');
        });
    }

    public function decideDonationBatch(string $actionId, User $user, string $chatId, string $decision): string
    {
        if (! in_array($decision, ['reject', 'defer', 'custom', 'reduce', 'keep'], true)) {
            throw new AuthorizationException('Unknown donation decision.');
        }
        if ($decision === 'defer'
            && ($this->authorizedDonationAction($actionId, $user, $chatId)->payload['stage'] ?? null) !== 'prealert') {
            throw new AuthorizationException('Only a next-day donation pre-alert can be deferred.');
        }
        $action = $this->claimDonationAction($actionId, $user, $chatId, ['pending']);
        if (! $action) {
            return 'Permintaan donasi ini sudah diproses atau sedang diproses oleh manager lain.';
        }

        $payload = $action->payload ?? [];
        if ($decision === 'reject') {
            $action->update(['status' => 'cancelled', 'decided_by' => $user->id, 'completed_at' => now()]);
            $this->cancelSiblingDonationActions($action);

            return 'Batch ini ditandai tidak untuk didonasikan. Stok tidak berubah.';
        }

        if ($decision === 'defer') {
            $action->update(['status' => 'deferred', 'decided_by' => $user->id]);

            return 'Baik. EcoLoop akan mengirim ulang konfirmasi saat batch masuk ambang donasi besok. Belum ada stok yang diubah.';
        }

        if ($decision === 'custom') {
            $action->update(['status' => 'awaiting_quantity', 'decided_by' => $user->id]);

            return sprintf(
                'Kirim jumlah dan pilihan stok dengan format: /donasi %s JUMLAH kurangi atau /donasi %s JUMLAH tetap. Stok belum berubah.',
                $action->id,
                $action->id
            );
        }

        if (! in_array($decision, ['reduce', 'keep'], true)) {
            throw new AuthorizationException('Unknown donation decision.');
        }

        $quantity = (int) ($payload['recommended_quantity'] ?? 0);
        $payload['quantity'] = $quantity;
        $payload['stock_mode'] = $decision;
        $payload['preapproved'] = true;
        $futureEligibility = Carbon::parse((string) ($payload['eligible_on'] ?? 'today'), 'Asia/Jakarta')->startOfDay()->isFuture();

        if ($futureEligibility) {
            $action->update([
                'status' => 'scheduled',
                'decided_by' => $user->id,
                'payload' => $payload,
            ]);

            return sprintf(
                'Donasi %s sebanyak %d %s disetujui untuk besok. EcoLoop akan menjalankannya saat batch masuk ambang tanpa meminta konfirmasi lagi; stok %s.',
                $action->product?->name ?? 'produk',
                $quantity,
                $payload['unit'] ?? 'unit',
                $decision === 'reduce' ? 'akan dikurangi' : 'akan tetap'
            );
        }

        return $this->completeDonation($action, $user->id, $quantity, $decision === 'reduce', false);
    }

    public function submitCustomDonation(string $actionId, User $user, string $chatId, int $quantity, string $stockMode): string
    {
        if (! in_array($stockMode, ['kurangi', 'tetap'], true)) {
            throw new AuthorizationException('Pilih mode stok kurangi atau tetap.');
        }

        $action = $this->claimDonationAction($actionId, $user, $chatId, ['awaiting_quantity']);
        if (! $action) {
            return 'Permintaan jumlah ini sudah ditutup atau sedang diproses.';
        }

        $payload = $action->payload ?? [];
        $batch = ProductBatch::query()->find((int) ($payload['batch_id'] ?? 0));
        if (! $batch || $quantity < 1 || $quantity > (int) $batch->quantity_available) {
            $action->update(['status' => 'awaiting_quantity']);

            return sprintf('Jumlah harus antara 1 dan %d %s yang tercatat pada batch ini.', (int) ($batch?->quantity_available ?? 0), $payload['unit'] ?? 'unit');
        }

        $payload['quantity'] = $quantity;
        $payload['stock_mode'] = $stockMode === 'kurangi' ? 'reduce' : 'keep';
        $payload['preapproved'] = true;
        $futureEligibility = Carbon::parse((string) ($payload['eligible_on'] ?? 'today'), 'Asia/Jakarta')->startOfDay()->isFuture();
        if ($futureEligibility) {
            $action->update(['status' => 'scheduled', 'decided_by' => $user->id, 'payload' => $payload]);

            return sprintf(
                'Donasi %s sebanyak %d %s disetujui untuk besok. Akan ada proses otomatis tanpa konfirmasi ulang; stok %s.',
                $action->product?->name ?? 'produk',
                $quantity,
                $payload['unit'] ?? 'unit',
                $payload['stock_mode'] === 'reduce' ? 'akan dikurangi' : 'akan tetap'
            );
        }

        return $this->completeDonation($action, $user->id, $quantity, $payload['stock_mode'] === 'reduce', false);
    }

    /** Execute a pre-approved donation after it enters the eligibility window. */
    public function completeScheduledDonation(string $actionId): void
    {
        $action = DB::transaction(function () use ($actionId): ?TelegramAiAction {
            $action = TelegramAiAction::query()->lockForUpdate()->find($actionId);
            if (! $action || $action->action_type !== 'donation_batch' || $action->status !== 'scheduled') {
                return null;
            }
            $payload = $action->payload ?? [];
            $eligibleOn = Carbon::parse((string) ($payload['eligible_on'] ?? '1970-01-01'), 'Asia/Jakarta')->startOfDay();
            if ($eligibleOn->gt(now('Asia/Jakarta')->startOfDay())) {
                return null;
            }
            $action->update(['status' => 'processing']);

            return $action->fresh();
        });
        if (! $action) {
            return;
        }

        $managerId = (int) ($action->decided_by ?? $action->requested_by);
        $result = $this->completeDonation(
            $action,
            $managerId,
            (int) ($payload['quantity'] ?? $payload['recommended_quantity'] ?? 0),
            ($payload['stock_mode'] ?? 'reduce') === 'reduce',
            true
        );
        $this->notifications->queueTelegramActionResult($action->telegram_chat_id, $result, 'donation-result:'.$action->id);
    }

    private function authorizedDonationAction(string $actionId, User $user, string $chatId): TelegramAiAction
    {
        if (! in_array($user->role, ['business_owner', 'manager', 'cashier'], true)) {
            throw new AuthorizationException('Only a business owner, manager, or cashier may confirm donations.');
        }

        $action = TelegramAiAction::query()->findOrFail($actionId);
        if ($action->action_type !== 'donation_batch'
            || (int) $action->requested_by !== (int) $user->id
            || ! hash_equals($action->telegram_chat_id, $chatId)) {
            throw new AuthorizationException('This donation request belongs to another manager Telegram account.');
        }

        return $action;
    }

    /** Atomically claim a manager action so Telegram retries or double taps cannot execute it twice. */
    private function claimDonationAction(string $actionId, User $user, string $chatId, array $allowedStatuses): ?TelegramAiAction
    {
        return DB::transaction(function () use ($actionId, $user, $chatId, $allowedStatuses): ?TelegramAiAction {
            $action = TelegramAiAction::query()->lockForUpdate()->findOrFail($actionId);
            if ($action->action_type !== 'donation_batch'
                || (int) $action->requested_by !== (int) $user->id
                || ! hash_equals($action->telegram_chat_id, $chatId)) {
                throw new AuthorizationException('This donation request belongs to another manager Telegram account.');
            }
            if (! in_array($action->status, $allowedStatuses, true)) {
                return null;
            }
            if ($action->expires_at->isPast()) {
                $action->update(['status' => 'expired', 'completed_at' => now()]);

                return null;
            }
            $action->update(['status' => 'processing']);

            return $action->fresh();
        });
    }

    private function completeDonation(TelegramAiAction $action, int $managerId, int $quantity, bool $deductStock, bool $scheduled): string
    {
        $payload = $action->payload ?? [];
        $result = app(InventoryService::class)->donateExpiringBatchQuantity(
            (int) ($payload['batch_id'] ?? 0),
            $quantity,
            $managerId,
            $action->id,
            $deductStock
        );

        if (! $result['success']) {
            $action->update([
                'status' => 'failed',
                'decided_by' => $managerId,
                'completed_at' => now(),
                'result' => $result,
            ]);

            return sprintf(
                'Donasi %s tidak dijalankan karena stok atau tanggal batch berubah. Tersedia untuk donasi: %d %s. Tidak ada stok baru yang dikurangi.',
                $result['product_name'] ?? ($action->product?->name ?? 'produk'),
                (int) ($result['available_quantity'] ?? 0),
                $result['unit'] ?? ($payload['unit'] ?? 'unit')
            );
        }

        $action->update([
            'status' => 'completed',
            'decided_by' => $managerId,
            'completed_at' => now(),
            'result' => [
                'quantity' => $result['quantity'],
                'unit' => $result['unit'],
                'remaining_stock' => $result['remaining_stock'],
                'stock_reduction_applied' => $deductStock,
                'notifications_queued' => $result['notifications_queued'],
                'partner_count' => $result['partner_count'],
                'scheduled' => $scheduled,
            ],
        ]);
        $this->cancelSiblingDonationActions($action->fresh());

        return sprintf(
            'Donasi dikonfirmasi: %s %d %s. Stok %s; tersisa %d %s. Pemberitahuan %s kepada %d yayasan aktif.',
            $result['product']['name'],
            $quantity,
            $result['unit'],
            $deductStock ? 'dikurangi' : 'tidak dikurangi',
            $result['remaining_stock'],
            $result['unit'],
            $result['notifications_queued'] ? 'diantrekan' : 'perlu diperiksa',
            $result['partner_count']
        );
    }

    private function cancelSiblingDonationActions(TelegramAiAction $action): void
    {
        $batchId = (int) ($action->payload['batch_id'] ?? 0);
        if ($batchId === 0) {
            return;
        }

        TelegramAiAction::query()
            ->where('action_type', 'donation_batch')
            ->whereKeyNot($action->id)
            ->where('payload->batch_id', $batchId)
            ->whereIn('status', ['pending', 'scheduled', 'deferred', 'awaiting_quantity'])
            ->update(['status' => 'cancelled', 'completed_at' => now()]);
    }
}
