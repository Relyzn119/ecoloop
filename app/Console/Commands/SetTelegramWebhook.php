<?php

namespace App\Console\Commands;

use App\Services\TelegramBotService;
use Illuminate\Console\Command;
use Throwable;

class SetTelegramWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:set-webhook {--url= : Public HTTPS base URL for this EcoLoop app}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register EcoLoop Telegram webhook without displaying the bot token.';

    /**
     * Execute the console command.
     */
    public function handle(TelegramBotService $telegram): int
    {
        $baseUrl = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $secret = (string) config('services.telegram.webhook_secret');

        if (! str_starts_with($baseUrl, 'https://') || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            $this->error('Provide a public HTTPS URL with --url or APP_URL. Local .test URLs cannot receive Telegram webhooks.');

            return self::FAILURE;
        }

        if ($secret === '') {
            $this->error('TELEGRAM_WEBHOOK_SECRET is missing from the application environment.');

            return self::FAILURE;
        }

        try {
            $telegram->registerWebhook($baseUrl.'/api/v1/telegram/webhook', $secret);
        } catch (Throwable) {
            $this->error('Telegram webhook registration failed. Check the bot token, public URL, and Telegram API reachability.');

            return self::FAILURE;
        }

        $this->info('Telegram webhook registered for '.$baseUrl.'/api/v1/telegram/webhook.');

        return self::SUCCESS;
    }
}
