<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramBotService
{
    /** @param array<string, mixed>|null $replyMarkup */
    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null): Response
    {
        $token = config('services.telegram.bot_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->timeout(10)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", array_filter([
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => $replyMarkup,
            ], fn (mixed $value): bool => $value !== null));

        if ($response->failed() || ! $response->json('ok')) {
            throw new RuntimeException('Telegram sendMessage request failed with status '.$response->status().'.');
        }

        return $response;
    }

    public function answerCallback(string $callbackQueryId, string $text): void
    {
        $token = config('services.telegram.bot_token');
        if (! is_string($token) || $token === '') {
            return;
        }

        Http::acceptJson()->asJson()->timeout(10)
            ->post("https://api.telegram.org/bot{$token}/answerCallbackQuery", [
                'callback_query_id' => $callbackQueryId,
                'text' => $text,
            ]);
    }

    public function registerWebhook(string $url, string $secret): bool
    {
        $token = config('services.telegram.bot_token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $response = Http::acceptJson()->asJson()->timeout(15)
            ->post("https://api.telegram.org/bot{$token}/setWebhook", [
                'url' => $url,
                'secret_token' => $secret,
                'allowed_updates' => ['message', 'callback_query'],
            ]);

        if ($response->failed() || ! $response->json('ok')) {
            throw new RuntimeException('Telegram rejected the webhook registration (HTTP '.$response->status().').');
        }

        return true;
    }
}
