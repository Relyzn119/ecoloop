<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliverN8nFinalEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly array $event) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 60, 180];
    }

    public function handle(): void
    {
        $response = Http::withBasicAuth(
            (string) config('services.n8n.event_webhook_username'),
            (string) config('services.n8n.event_webhook_password')
        )
            ->acceptJson()
            ->asJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->post((string) config('services.n8n.event_webhook_url'), $this->event);

        $response->throw();

        if ($response->json('accepted') !== true || $response->json('event_id') !== $this->event['event_id']) {
            throw new \RuntimeException('n8n did not acknowledge the EcoLoop event.');
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('EcoLoop final event was not acknowledged by the n8n observer after retries.', [
            'event_id' => $this->event['event_id'] ?? null,
            'event_type' => $this->event['event_type'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
