<?php

namespace App\Services;

use App\Models\AgentLog;
use App\Models\DonationPartner;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    /**
     * Dispatch automated donation alerts to partners via external webhooks or simulated channels.
     *
     * @return array{
     *     success: bool,
     *     status: 'success'|'failed'|'simulated',
     *     payload: array<string, mixed>,
     * }
     */
    public function sendDonationAlert(DonationPartner $partner, Product $product, string $reason): array
    {
        $payload = $this->buildNotificationPayload($partner, $product, $reason);
        $webhookUrl = config('services.n8n.webhook_url');

        $status = 'simulated';
        $httpSuccess = true;

        if (! empty($webhookUrl) && filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            try {
                $response = Http::timeout(8)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post($webhookUrl, $payload);

                $httpSuccess = $response->successful();
                $status = $httpSuccess ? 'success' : 'failed';

                if (! $httpSuccess) {
                    Log::warning('External donation alert webhook returned an error.', [
                        'partner_id' => $partner->id,
                        'product_id' => $product->id,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);
                }
            } catch (Throwable $e) {
                $httpSuccess = false;
                $status = 'failed';

                Log::error('Exception while dispatching donation alert webhook.', [
                    'partner_id' => $partner->id,
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Record communication dispatch to agent_logs
        AgentLog::create([
            'product_id' => $product->id,
            'action_type' => 'alert_sent',
            'reasoning' => sprintf(
                'Dispatched automated surplus food donation alert to %s (%s) for %d %s of %s via WhatsApp/Email.',
                $partner->name,
                $partner->contact_person,
                $product->quantity,
                $product->unit,
                $product->name
            ),
            'payload' => [
                'channel' => 'multi_channel',
                'recipient' => [
                    'partner_id' => $partner->id,
                    'name' => $partner->name,
                    'contact_person' => $partner->contact_person,
                    'phone' => $partner->phone,
                    'email' => $partner->email,
                ],
                'product' => [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'quantity' => $product->quantity,
                    'unit' => $product->unit,
                    'expiration_date' => $product->expiration_date->toDateString(),
                ],
                'dispatch_status' => $status,
                'webhook_configured' => ! empty($webhookUrl),
                'message_preview' => $payload['channels']['whatsapp']['body'],
            ],
            'status' => $status === 'failed' ? 'failed' : 'success',
        ]);

        return [
            'success' => $httpSuccess,
            'status' => $status,
            'payload' => $payload,
        ];
    }

    /**
     * Build the structured notification payload for WhatsApp and Email delivery.
     *
     * @return array<string, mixed>
     */
    protected function buildNotificationPayload(DonationPartner $partner, Product $product, string $reason): array
    {
        $expDateFormatted = $product->expiration_date->format('M d, Y');

        $whatsappMessage = sprintf(
            "🌱 *EcoLoop Urgent Food Donation Alert*\n\n"
            ."Hello %s,\n"
            ."We have allocated *%d %s of %s* (SKU: `%s`) for immediate donation pickup to *%s*.\n\n"
            ."📌 *Batch Details*:\n"
            ."• Category: %s\n"
            ."• Expiration Date: %s\n"
            ."• Justification: %s\n\n"
            ."Please coordinate cold-chain pickup as soon as possible to ensure fresh distribution to those in need.\n"
            .'— EcoLoop Circular Economy Agent',
            $partner->contact_person,
            $product->quantity,
            $product->unit,
            $product->name,
            $product->sku,
            $partner->name,
            $product->category?->name ?? 'General Food',
            $expDateFormatted,
            $reason
        );

        $emailSubject = sprintf(
            'EcoLoop Surplus Donation Available: %s (%d %s) for %s',
            $product->name,
            $product->quantity,
            $product->unit,
            $partner->name
        );

        $emailBody = sprintf(
            "Dear %s,\n\n"
            ."We are pleased to inform you that EcoLoop has reserved surplus food for %s.\n\n"
            ."Product: %s (SKU: %s)\n"
            ."Quantity: %d %s\n"
            ."Expiration Date: %s\n"
            ."Reason: %s\n\n"
            ."Address for pickup: Supermarket Hub Central\n\n"
            ."Thank you for partnering with us to eliminate food waste and support the community.\n\n"
            ."Warm regards,\n"
            .'EcoLoop Agent Team',
            $partner->contact_person,
            $partner->name,
            $product->name,
            $product->sku,
            $product->quantity,
            $product->unit,
            $expDateFormatted,
            $reason
        );

        return [
            'event' => 'donation.reserved',
            'timestamp' => now()->toIso8601String(),
            'recipient' => [
                'id' => $partner->id,
                'name' => $partner->name,
                'contact_person' => $partner->contact_person,
                'phone' => $partner->phone,
                'email' => $partner->email,
                'address' => $partner->address,
            ],
            'donation' => [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'quantity' => $product->quantity,
                'unit' => $product->unit,
                'expiration_date' => $product->expiration_date->toDateString(),
                'reason' => $reason,
            ],
            'channels' => [
                'whatsapp' => [
                    'to' => $partner->phone,
                    'body' => $whatsappMessage,
                ],
                'email' => [
                    'to' => $partner->email,
                    'subject' => $emailSubject,
                    'body' => $emailBody,
                ],
            ],
        ];
    }
}
