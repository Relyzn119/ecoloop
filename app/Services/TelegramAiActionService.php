<?php

namespace App\Services;

use App\Models\TelegramAiAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class TelegramAiActionService
{
    public function decide(string $actionId, User $user, string $chatId, string $decision): string
    {
        if (! in_array($user->role, ['manager', 'cashier'], true)) {
            throw new AuthorizationException('Only managers and cashiers may confirm inventory actions.');
        }

        if (! in_array($decision, ['confirm', 'cancel'], true)) {
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
                $result = app(InventoryService::class)->donateExpiringProductQuantity(
                    (int) $action->product_id,
                    (int) $payload['quantity'],
                    $user->id,
                    $action->id
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
                    ],
                ]);

                $notificationStatus = $result['notifications_queued']
                    ? "Notifikasi email/Telegram dimasukkan ke antrean untuk {$result['partner_count']} yayasan aktif."
                    : 'Donasi tercatat, tetapi antrean notifikasi perlu diperiksa oleh admin.';

                return sprintf(
                    'Donasi dikonfirmasi: %s %d %s. Stok tersisa: %d %s. Batch dengan tanggal kedaluwarsa terdekat digunakan lebih dahulu. %s',
                    $result['product_name'],
                    $result['quantity'],
                    $result['unit'],
                    $result['remaining_stock'],
                    $result['unit'],
                    $notificationStatus
                );
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
}
