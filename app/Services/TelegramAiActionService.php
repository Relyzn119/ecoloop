<?php

namespace App\Services;

use App\Models\AgentLog;
use App\Models\InventorySyncSetting;
use App\Models\Product;
use App\Models\ProductAutomationPolicy;
use App\Models\ProductBatch;
use App\Models\TelegramAiAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TelegramAiActionService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** @return array{text: string, reply_markup: array<string, mixed>|null} */
    public function decideAutomationPolicy(string $actionId, User $user, string $chatId, string $decision): array
    {
        if (! in_array($user->role, ['business_owner', 'manager'], true)) {
            throw new AuthorizationException('Only a business owner or manager may change automatic inventory policies.');
        }
        if (! in_array($decision, ['arm', 'activate', 'cancel'], true)) {
            throw new AuthorizationException('Unknown automation policy confirmation.');
        }

        return DB::transaction(function () use ($actionId, $user, $chatId, $decision): array {
            $action = TelegramAiAction::query()->lockForUpdate()->findOrFail($actionId);
            if ($action->action_type !== 'automation_policy'
                || (int) $action->requested_by !== (int) $user->id
                || ! hash_equals($action->telegram_chat_id, $chatId)) {
                throw new AuthorizationException('This automatic policy request belongs to another manager chat.');
            }

            if ($decision === 'cancel') {
                if (! in_array($action->status, ['pending', 'cooldown'], true)) {
                    return ['text' => 'Permintaan otomatisasi ini sudah diproses.', 'reply_markup' => null];
                }
                $action->update(['status' => 'cancelled', 'decided_by' => $user->id, 'completed_at' => now()]);

                return ['text' => 'Aktivasi otomatisasi dibatalkan. Tidak ada kebijakan yang berubah.', 'reply_markup' => null];
            }

            if ($action->expires_at?->isPast()) {
                $action->update(['status' => 'expired', 'completed_at' => now()]);

                return ['text' => 'Permintaan aktivasi kedaluwarsa. Tidak ada kebijakan yang berubah; ajukan permintaan baru bila masih diperlukan.', 'reply_markup' => null];
            }

            $payload = $action->payload ?? [];
            // `arm` is retained for buttons sent by older bot messages. Both the
            // old and new activation buttons now perform one explicit confirmation.
            if (! in_array($action->status, ['pending', 'cooldown'], true)) {
                return ['text' => 'Permintaan otomatisasi ini sudah diproses atau tidak lagi aktif. Ajukan permintaan baru bila masih diperlukan.', 'reply_markup' => null];
            }

            $settings = InventorySyncSetting::query()->lockForUpdate()->findOrFail(1);
            $fields = [
                'donation' => 'auto_donation_enabled',
                'discount' => 'auto_discount_enabled',
                'donation_prealert' => 'auto_donation_prealert_enabled',
            ];
            $enabled = (bool) ($payload['enabled'] ?? false);
            $scopes = array_values(array_intersect((array) ($payload['scopes'] ?? []), array_keys($fields)));
            $productChanges = [];
            foreach ((array) ($payload['product_changes'] ?? []) as $change) {
                $productId = (int) ($change['product_id'] ?? 0);
                $productScopes = array_values(array_intersect((array) ($change['scopes'] ?? []), array_keys($fields)));
                if ($productId < 1 || ! is_bool($change['enabled'] ?? null) || $productScopes === []) {
                    throw new AuthorizationException('A product automation change is incomplete. No strategy was applied.');
                }
                $product = Product::query()->findOrFail($productId);
                $policy = ProductAutomationPolicy::query()->lockForUpdate()->firstOrNew(['product_id' => $productId]);
                foreach ($productScopes as $scope) {
                    $policy->{$fields[$scope]} = $change['enabled'];
                }
                $policy->updated_by = $user->id;
                $policy->save();
                $productChanges[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'enabled' => $change['enabled'],
                    'scopes' => $productScopes,
                ];
            }

            if ($productChanges === []) {
                if ($scopes === []) {
                    throw new AuthorizationException('No valid automatic policy scope was requested.');
                }
                foreach ($scopes as $scope) {
                    $settings->{$fields[$scope]} = $enabled;
                }
                $anyAutomationRemainsEnabled = $settings->auto_donation_enabled
                    || $settings->auto_discount_enabled
                    || $settings->auto_donation_prealert_enabled;
                if ($enabled) {
                    $settings->auto_decision_activated_by = $user->id;
                    $settings->auto_decision_activated_at = now();
                } elseif (! $anyAutomationRemainsEnabled) {
                    $settings->auto_decision_activated_by = null;
                    $settings->auto_decision_activated_at = null;
                }
                $settings->save();
            }

            $labelForScope = fn (string $scope): string => match ($scope) {
                'donation' => 'donasi otomatis 0–2 hari',
                'discount' => 'diskon AI',
                'donation_prealert' => 'pre-alert donasi besok',
            };
            $scopeDescription = $productChanges !== []
                ? collect($productChanges)->map(fn (array $change): string => sprintf(
                    '%s [%s] %s: %s',
                    $change['product_name'],
                    $change['sku'],
                    $change['enabled'] ? 'otomatis tanpa konfirmasi item' : 'kembali perlu konfirmasi',
                    collect($change['scopes'])->map($labelForScope)->implode(', ')
                ))->implode('; ')
                : collect($scopes)->map($labelForScope)->implode(', ');

            AgentLog::query()->create([
                // Keep action_type within the existing database enum. The specific
                // event and affected scopes are recorded in the JSON payload.
                'action_type' => 'manual_override',
                'reasoning' => sprintf('Inventory automation strategy updated by %s through Telegram confirmation: %s.', $user->name, $scopeDescription),
                'payload' => [
                    'event' => $productChanges !== [] ? 'product_automation_policy_updated' : ($enabled ? 'automation_policy_enabled' : 'automation_policy_disabled'),
                    'scopes' => $scopes,
                    'enabled' => $enabled,
                    'product_changes' => $productChanges,
                    'actor_id' => $user->id,
                    'telegram_chat_id' => $chatId,
                ],
                'status' => 'success',
            ]);

            $action->update([
                'status' => 'completed',
                'decided_by' => $user->id,
                'completed_at' => now(),
                'result' => ['enabled' => $enabled, 'scopes' => $scopes, 'product_changes' => $productChanges],
            ]);

            return [
                'text' => sprintf('Strategi otomatis berhasil diperbarui: %s. Dikonfirmasi oleh %s.', $scopeDescription, $user->name),
                'reply_markup' => null,
            ];
        });
    }

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
            $settings = InventorySyncSetting::query()->findOrFail(1);
            $productPolicy = app(ProductAutomationPolicyService::class)->forProduct((int) $action->product_id, $settings);
            $automaticScopeEnabled = ($payload['prealert_auto'] ?? false)
                ? ($productPolicy['auto_donation_prealert_enabled'] || $productPolicy['auto_donation_enabled'])
                : $productPolicy['auto_donation_enabled'];
            if (($payload['automation_policy'] ?? false) === true && ! $automaticScopeEnabled) {
                $payload['stage'] = 'eligible';
                $payload['automation_policy'] = false;
                $payload['preapproved'] = false;
                $payload['prealert_auto'] = false;
                $action->update(['status' => 'pending', 'payload' => $payload]);

                return $action->fresh();
            }
            $action->update(['status' => 'processing']);

            return $action->fresh();
        });
        if (! $action) {
            return;
        }
        if ($action->status === 'pending') {
            $this->notifications->queueDonationApprovalBatch([$action]);

            return;
        }

        // Re-read the payload from the claimed action. The transaction closure
        // above owns its local `$payload`; referring to it here yielded quantity 0.
        $payload = $action->payload ?? [];
        $quantity = (int) ($payload['quantity'] ?? $payload['recommended_quantity'] ?? 0);
        if ($quantity < 1) {
            $message = 'Jadwal donasi otomatis tidak memiliki jumlah yang valid; stok tidak diubah. Buat ulang jadwal setelah memeriksa batch.';
            $action->update([
                'status' => 'failed',
                'completed_at' => now(),
                'result' => ['success' => false, 'reason' => 'scheduled_donation_quantity_missing'],
            ]);
            Log::error('Scheduled EcoLoop donation has no valid quantity.', [
                'action_id' => $action->id,
                'product_id' => $action->product_id,
            ]);
            $this->notifications->queueTelegramActionResult($action->telegram_chat_id, $message, 'donation-result:'.$action->id);

            return;
        }

        $managerId = (int) ($action->decided_by ?? $action->requested_by);
        $result = $this->completeDonation(
            $action,
            $managerId,
            $quantity,
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
            $deductStock,
            ($payload['automation_policy'] ?? false) === true
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
