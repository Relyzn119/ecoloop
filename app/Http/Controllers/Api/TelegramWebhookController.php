<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiscountApproval;
use App\Models\User;
use App\Services\DiscountApprovalService;
use App\Services\PartnerDonationClaimService;
use App\Services\TelegramAiActionService;
use App\Services\TelegramAiChatService;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, DiscountApprovalService $approvals, TelegramBotService $telegram, TelegramAiChatService $aiChat, TelegramAiActionService $aiActions, PartnerDonationClaimService $partnerClaims): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        abort_unless($secret !== '' && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        $update = $request->validate([
            'callback_query' => ['sometimes', 'array'],
            'callback_query.id' => ['required_with:callback_query', 'string'],
            'callback_query.data' => ['required_with:callback_query', 'string', 'max:100'],
            'callback_query.from.id' => ['required_with:callback_query', 'numeric'],
            'callback_query.message.chat.id' => ['required_with:callback_query', 'numeric'],
            'message' => ['sometimes', 'array'],
            'message.chat.id' => ['required_with:message', 'numeric'],
            'message.text' => ['required_with:message', 'string', 'max:2000'],
        ]);

        $chatId = null;
        try {
            if (isset($update['callback_query'])) {
                $callback = $update['callback_query'];
                // Telegram identifies the user separately from the chat. In private chats
                // they usually match; in groups the chat ID must not be used for authorization.
                $user = $this->authorizedUser((string) $callback['from']['id']);
                $chatId = (string) $callback['message']['chat']['id'];
                if (preg_match('/^eco:partner-stock:(reduce|keep):([0-9a-f-]{36})$/i', $callback['data'], $partnerStockMatch) === 1) {
                    $resultMessage = $partnerClaims->decideStock(
                        $partnerStockMatch[2],
                        $user,
                        $chatId,
                        strtolower($partnerStockMatch[1])
                    );
                    $telegram->answerCallback((string) $callback['id'], 'EcoLoop mencatat pilihan stok.');
                    $telegram->sendMessage($chatId, $resultMessage);

                    return response()->json(['ok' => true, 'partner_donation_claim_id' => $partnerStockMatch[2]]);
                }

                if (preg_match('/^eco:donation:(reduce|keep|custom|defer|reject):([0-9a-f-]{36})$/i', $callback['data'], $donationMatch) === 1) {
                    $resultMessage = $aiActions->decideDonationBatch($donationMatch[2], $user, $chatId, strtolower($donationMatch[1]));
                    $telegram->answerCallback((string) $callback['id'], 'EcoLoop memproses pilihan donasi.');
                    $telegram->sendMessage($chatId, $resultMessage);

                    return response()->json(['ok' => true, 'donation_action_id' => $donationMatch[2]]);
                }

                if (preg_match('/^eco:donate_(reduce|keep):([0-9a-f-]{36})$/i', $callback['data'], $donationMatch) === 1) {
                    $resultMessage = $aiActions->decide($donationMatch[2], $user, $chatId, strtolower($donationMatch[1]));
                    $telegram->answerCallback((string) $callback['id'], 'EcoLoop memproses donasi.');
                    $telegram->sendMessage($chatId, $resultMessage);

                    return response()->json(['ok' => true, 'donation_action_id' => $donationMatch[2]]);
                }

                if (preg_match('/^eco:(confirm|cancel):([0-9a-f-]{36})$/i', $callback['data'], $actionMatch) === 1) {
                    $actionDecision = $actionMatch[1] === 'confirm' ? 'confirm' : 'cancel';
                    $resultMessage = $aiActions->decide($actionMatch[2], $user, $chatId, $actionDecision);
                    $telegram->answerCallback((string) $callback['id'], $actionDecision === 'confirm' ? 'EcoLoop sedang memproses konfirmasi.' : 'Permintaan dibatalkan.');
                    $telegram->sendMessage($chatId, $resultMessage);

                    return response()->json(['ok' => true, 'action_id' => $actionMatch[2]]);
                }

                [$decision, $approvalId] = array_pad(explode(':', $callback['data'], 2), 2, '');
                abort_unless(in_array($decision, ['approve', 'reject'], true) && ctype_digit($approvalId), 422);

                $existingApproval = DiscountApproval::query()->findOrFail((int) $approvalId);
                if ($existingApproval->status !== 'pending') {
                    $message = match ($existingApproval->status) {
                        'approved' => 'Already approved at '.$existingApproval->approved_percentage.'%.',
                        'rejected' => 'This discount request was already rejected.',
                        default => 'This discount request has already been processed.',
                    };
                    $telegram->answerCallback((string) $callback['id'], $message);

                    return response()->json([
                        'ok' => true,
                        'approval_id' => $existingApproval->id,
                        'already_processed' => true,
                    ]);
                }

                $approval = $approvals->decide((int) $approvalId, $user, $decision);
                $telegram->answerCallback((string) $callback['id'], $decision === 'approve'
                    ? 'Approved at '.$approval->approved_percentage.'%.'
                    : 'Discount rejected.');

                return response()->json(['ok' => true, 'approval_id' => $approval->id]);
            }

            if (isset($update['message'])) {
                $message = $update['message'];
                $chatId = (string) $message['chat']['id'];
                $text = trim($message['text']);

                if (preg_match('/^\/(?:start|id)(?:@[A-Za-z0-9_]+)?(?:\s|$)/i', $text) === 1) {
                    $telegram->sendMessage(
                        $chatId,
                        "Halo! Saya EcoLoop AI, asisten generatif EcoLoop. Chat ID Anda: {$chatId}. Hubungkan ID ini ke akun EcoLoop: pemilik bisnis/manager/kasir melalui Settings → Profile, atau mitra melalui pendaftaran/data mitra. Setelah terhubung, kirim pertanyaan langsung di sini. Gunakan /help untuk panduan role dan /reset untuk memulai ulang percakapan."
                    );

                    return response()->json(['ok' => true]);
                }

                if (preg_match('/^\/help(?:@[A-Za-z0-9_]+)?(?:\s|$)/i', $text) === 1) {
                    $telegram->sendMessage($chatId, $aiChat->help($chatId));

                    return response()->json(['ok' => true]);
                }

                if (preg_match('/^\/reset(?:@[A-Za-z0-9_]+)?(?:\s|$)/i', $text) === 1) {
                    $telegram->sendMessage($chatId, $aiChat->reset($chatId));

                    return response()->json(['ok' => true]);
                }

                if (preg_match('/^\/discount\s+(\d+)\s+(\d{1,2})$/', $text, $matches) === 1) {
                    $user = $this->authorizedUser($chatId);
                    $approval = $approvals->decide((int) $matches[1], $user, 'approve', (int) $matches[2]);
                    $telegram->sendMessage($chatId, sprintf('Approved at %d%%. New batch price: %s.', $approval->approved_percentage, number_format((float) $approval->approved_price, 2)));

                    return response()->json(['ok' => true, 'approval_id' => $approval->id]);
                }

                if (preg_match('/^\/(?:donasi|donate)(?:@[A-Za-z0-9_]+)?\s+([0-9a-f-]{36})\s+(\d+)\s+(kurangi|tetap)$/i', $text, $matches) === 1) {
                    $user = $this->authorizedUser($chatId);
                    $resultMessage = $aiActions->submitCustomDonation(
                        $matches[1],
                        $user,
                        $chatId,
                        (int) $matches[2],
                        strtolower($matches[3])
                    );
                    $telegram->sendMessage($chatId, $resultMessage);

                    return response()->json(['ok' => true, 'donation_action_id' => $matches[1]]);
                }

                if (str_starts_with($text, '/')) {
                    $telegram->sendMessage($chatId, $aiChat->help($chatId));

                    return response()->json(['ok' => true]);
                }

                $aiResponse = $aiChat->reply($chatId, $text);
                $telegram->sendMessage($chatId, $aiResponse['text'], $aiResponse['reply_markup']);
            }
        } catch (Throwable $exception) {
            Log::warning('Telegram update could not be processed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'update_type' => isset($update['callback_query']) ? 'callback_query' : 'message',
            ]);
            if (isset($update['callback_query']['id'])) {
                $telegram->answerCallback((string) $update['callback_query']['id'], 'Could not process. Check role and pending status.');
            } elseif ($chatId !== null && isset($update['message'])) {
                try {
                    $telegram->sendMessage($chatId, 'EcoLoop tidak dapat memproses pesan ini. Pastikan Chat ID terhubung ke akun EcoLoop dan coba lagi. Untuk persetujuan diskon, pemilik bisnis/manager/kasir dapat memutuskan permintaan yang masih pending.');
                } catch (Throwable) {
                    // Telegram delivery will be retried through the notification queue for outgoing alerts.
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    private function authorizedUser(string $chatId): User
    {
        return User::query()->notificationRecipients()->where('telegram_chat_id', $chatId)->firstOrFail();
    }
}
