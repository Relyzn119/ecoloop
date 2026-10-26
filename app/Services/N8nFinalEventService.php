<?php

namespace App\Services;

use App\Jobs\DeliverN8nFinalEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

class N8nFinalEventService
{
    /** Queue a read-only event for n8n without affecting the completed business action. */
    public function queue(string $eventType, string $eventId, array $data): void
    {
        $url = config('services.n8n.event_webhook_url');
        $username = config('services.n8n.event_webhook_username');
        $password = config('services.n8n.event_webhook_password');

        if (! is_string($url) || $url === '' || ! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            return;
        }

        try {
            DeliverN8nFinalEvent::dispatch([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'occurred_at' => now()->toIso8601String(),
                'data' => $data,
            ])->afterCommit();
        } catch (Throwable $exception) {
            Log::warning('EcoLoop action succeeded, but its n8n observer event could not be queued.', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
