<?php

namespace App\Jobs;

use App\Models\AgentLog;
use App\Models\NotificationDelivery;
use App\Services\TelegramBotService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendDiscountApprovalNotifications implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $deliveryId) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(TelegramBotService $telegram): void
    {
        $delivery = NotificationDelivery::query()->findOrFail($this->deliveryId);
        if ($delivery->status === 'sent') {
            return;
        }

        $delivery->increment('attempts');
        $delivery->update(['status' => 'sending', 'last_error' => null]);

        if ($delivery->channel === 'email') {
            Mail::raw((string) $delivery->payload['body'], function ($message) use ($delivery): void {
                $message->to($delivery->recipient)->subject($delivery->subject ?? 'EcoLoop discount approval');
            });
        } else {
            $telegram->sendMessage(
                $delivery->recipient,
                (string) $delivery->payload['text'],
                $delivery->payload['reply_markup'] ?? null
            );
        }

        $delivery->update(['status' => 'sent', 'sent_at' => now()]);
        AgentLog::query()->create([
            'product_id' => $delivery->payload['product_id'] ?? null,
            'action_type' => 'alert_sent',
            'reasoning' => 'Discount approval notification delivered through '.$delivery->channel.'.',
            'payload' => [
                'delivery_id' => $delivery->id,
                'event_type' => $delivery->event_type,
                'channel' => $delivery->channel,
                'recipient' => $delivery->recipient,
            ],
            'status' => 'success',
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $delivery = NotificationDelivery::query()->find($this->deliveryId);
        $delivery?->update(['status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 2000)]);

        if ($delivery) {
            AgentLog::query()->create([
                'product_id' => $delivery->payload['product_id'] ?? null,
                'action_type' => 'alert_sent',
                'reasoning' => 'Discount approval notification delivery failed after retries.',
                'payload' => ['delivery_id' => $delivery->id, 'channel' => $delivery->channel],
                'status' => 'failed',
            ]);
        }
    }
}
