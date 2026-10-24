<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DiscountApproval;
use App\Models\User;
use App\Services\DiscountApprovalService;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, DiscountApprovalService $approvals, TelegramBotService $telegram): JsonResponse
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
            'message.text' => ['required_with:message', 'string', 'max:200'],
        ]);

        $chatId = null;
        try {
            if (isset($update['callback_query'])) {
                $callback = $update['callback_query'];
                // Telegram identifies the user separately from the chat. In private chats
                // they usually match; in groups the chat ID must not be used for authorization.
                $user = $this->authorizedUser((string) $callback['from']['id']);
                $chatId = (string) $callback['message']['chat']['id'];
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
                        "EcoLoop bot connected. Your Telegram chat ID is {$chatId}. Copy this ID into the Telegram Chat ID field for this partner under Donation Partners in EcoLoop. Each partner needs the chat ID from its own Telegram account or group."
                    );

                    return response()->json(['ok' => true]);
                }

                if (preg_match('/^\/discount\s+(\d+)\s+(\d{1,2})$/', $text, $matches) === 1) {
                    $user = $this->authorizedUser($chatId);
                    $approval = $approvals->decide((int) $matches[1], $user, 'approve', (int) $matches[2]);
                    $telegram->sendMessage($chatId, sprintf('Approved at %d%%. New batch price: %s.', $approval->approved_percentage, number_format((float) $approval->approved_price, 2)));

                    return response()->json(['ok' => true, 'approval_id' => $approval->id]);
                }
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
                    $telegram->sendMessage($chatId, 'EcoLoop could not process that action. Check that your Telegram chat ID is linked to a manager or cashier account and that the request is still pending.');
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
