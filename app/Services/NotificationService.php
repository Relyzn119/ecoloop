<?php

namespace App\Services;

use App\Jobs\SendDiscountApprovalNotifications;
use App\Jobs\SendDonationNotifications;
use App\Jobs\SendPartnerOfferNotifications;
use App\Models\AgentLog;
use App\Models\DiscountApproval;
use App\Models\DonationPartner;
use App\Models\NotificationDelivery;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    /** Queue donation alerts for the partner and all manager/cashier accounts. */
    public function queueDonationAlerts(
        DonationPartner $partner,
        Product $product,
        ProductBatch $batch,
        string $reason,
        bool $broadcastToActivePartners = false
    ): void {
        $details = sprintf(
            "EcoLoop - Informasi Donasi\nProduk: %s (%s)\nBatch: %s\nJumlah: %d %s\nTanggal kedaluwarsa: %s\nAlasan donasi: %s\n\nProduk donasi sudah bisa diambil di supermarket. Pengambilan paling lambat 3 jam sebelum toko tutup. Silakan koordinasikan waktu dan titik pengambilan dengan pihak supermarket.",
            $product->name,
            $product->sku,
            $batch->batch_code,
            $batch->quantity_donated,
            $product->unit,
            $batch->expiration_date->toDateString(),
            $reason
        );
        if ($broadcastToActivePartners) {
            $details .= "\n\nPemberitahuan ini juga dibagikan kepada Donation Partner aktif lainnya. Mitra dipersilakan berkoordinasi dan berbagi produk sesuai kebutuhan bersama.";
        }

        $managerDetails = $this->buildManagerDonationSummary();

        $notificationPartners = $broadcastToActivePartners
            ? DonationPartner::query()->active()->where('partner_type', 'foundation')->orderBy('id')->get()
            : collect([$partner]);

        foreach ($notificationPartners as $notificationPartner) {
            $this->queueDelivery(
                'donation_completed',
                'email',
                $notificationPartner->email,
                'EcoLoop food donation completed',
                [
                    'body' => "Hello {$notificationPartner->contact_person},\n\n{$details}\n\nEcoLoop Agent",
                    'product_id' => $product->id,
                ],
                "donation:{$batch->id}:partner:{$notificationPartner->id}:email"
            );

            if ($notificationPartner->telegram_chat_id) {
                $this->queueDelivery('donation_completed', 'telegram', $notificationPartner->telegram_chat_id, null, [
                    'text' => $details,
                    'product_id' => $product->id,
                ], "donation:{$batch->id}:partner:{$notificationPartner->id}:telegram");
            }
        }

        $users = User::query()->notificationRecipients()->get(['id', 'name', 'email', 'telegram_chat_id']);
        foreach ($users as $user) {
            $this->queueDelivery('donation_completed', 'email', $user->email, 'EcoLoop donation completed', [
                'body' => "Halo {$user->name},\n\n{$managerDetails}\n\nEcoLoop Agent",
                'product_id' => $product->id,
            ], "donation:{$batch->id}:user:{$user->id}:email");

            if ($user->telegram_chat_id) {
                $this->queueDelivery('donation_completed', 'telegram', $user->telegram_chat_id, null, [
                    'text' => $managerDetails,
                    'product_id' => $product->id,
                ], "donation:{$batch->id}:user:{$user->id}:telegram");
            }
        }
    }

    /** Broadcast one manager-requested product donation to every active foundation partner. */
    public function queueRequestedDonationAlerts(Product $product, array $allocations, string $reference): int
    {
        $quantity = (int) collect($allocations)->sum('quantity');
        $batchLines = collect($allocations)
            ->map(fn (array $allocation): string => sprintf(
                '• Batch %s: %d %s (kedaluwarsa %s)',
                $allocation['batch_code'] ?: $allocation['batch_id'],
                $allocation['quantity'],
                $product->unit,
                $allocation['expiration_date']
            ))
            ->implode("\n");
        $details = sprintf(
            "EcoLoop - Donasi atas permintaan manager\nProduk: %s (%s)\nJumlah total: %d %s\nRincian stok:\n%s\n\nProduk donasi sudah bisa diambil di supermarket. Pengambilan paling lambat 3 jam sebelum toko tutup. Donation Partner dipersilakan berkoordinasi dan berbagi produk sesuai kebutuhan bersama.",
            $product->name,
            $product->sku,
            $quantity,
            $product->unit,
            $batchLines !== '' ? $batchLines : 'Rincian batch tidak tersedia.'
        );
        $managerDetails = $this->buildManagerDonationSummary();

        $partners = DonationPartner::query()->active()->where('partner_type', 'foundation')->orderBy('id')->get();
        foreach ($partners as $partner) {
            $key = "telegram-ai-donation:{$reference}:partner:{$partner->id}";
            $this->queueDelivery('donation_completed', 'email', $partner->email, 'EcoLoop: produk donasi siap diambil', [
                'body' => "Halo {$partner->contact_person},\n\n{$details}\n\nEcoLoop Agent",
                'product_id' => $product->id,
            ], "{$key}:email");

            if (filled($partner->telegram_chat_id)) {
                $this->queueDelivery('donation_completed', 'telegram', $partner->telegram_chat_id, null, [
                    'text' => $details,
                    'product_id' => $product->id,
                ], "{$key}:telegram");
            }
        }

        foreach (User::query()->notificationRecipients()->get(['id', 'name', 'email', 'telegram_chat_id']) as $user) {
            $key = "telegram-ai-donation:{$reference}:user:{$user->id}";
            $this->queueDelivery('donation_completed', 'email', $user->email, 'EcoLoop: donasi telah diproses', [
                'body' => "Halo {$user->name},\n\n{$managerDetails}\n\nEcoLoop Agent",
                'product_id' => $product->id,
            ], "{$key}:email");

            if (filled($user->telegram_chat_id)) {
                $this->queueDelivery('donation_completed', 'telegram', $user->telegram_chat_id, null, [
                    'text' => $managerDetails,
                    'product_id' => $product->id,
                ], "{$key}:telegram");
            }
        }

        return $partners->count();
    }

    /** Build a same-day summary for managers and cashiers in the Jakarta store timezone. */
    private function buildManagerDonationSummary(): string
    {
        $storeNow = now('Asia/Jakarta');
        $storageTimezone = (string) config('app.timezone', 'UTC');
        $startOfStoreDay = $storeNow->copy()->startOfDay()->setTimezone($storageTimezone);
        $endOfStoreDay = $storeNow->copy()->endOfDay()->setTimezone($storageTimezone);

        $donations = AgentLog::query()
            ->with('product:id,name,unit')
            ->where('action_type', 'donation_requested')
            ->where('status', 'success')
            ->where('payload->event_type', 'donation_completed')
            ->whereBetween('created_at', [$startOfStoreDay, $endOfStoreDay])
            ->get(['id', 'product_id', 'payload']);

        $products = [];
        $totalsByUnit = [];
        foreach ($donations as $donation) {
            $payload = $donation->payload ?? [];
            $name = $donation->product?->name ?? $payload['product_name'] ?? 'Produk';
            $unit = $payload['unit'] ?? $donation->product?->unit ?? 'pcs';
            $quantity = (int) ($payload['quantity_donated'] ?? $payload['quantity'] ?? 0);
            $key = $name.'|'.$unit;

            $products[$key] ??= ['name' => $name, 'unit' => $unit, 'quantity' => 0];
            $products[$key]['quantity'] += $quantity;
            $totalsByUnit[$unit] = ($totalsByUnit[$unit] ?? 0) + $quantity;
        }

        $productLines = collect($products)
            ->map(fn (array $item): string => sprintf('• %s × %d %s', $item['name'], $item['quantity'], $item['unit']))
            ->implode("\n");
        $totalLines = collect($totalsByUnit)
            ->map(fn (int $quantity, string $unit): string => sprintf('%d %s', $quantity, $unit))
            ->implode(', ');

        return sprintf(
            "Rekap donasi hari ini (%s)\n%s\nTotal produk yang didonasikan hari ini: %s\n\nMohon staff segera menyiapkan dan mengemas (packing) produk yang akan didonasikan. Produk sudah dapat diambil di supermarket; pengambilan paling lambat 3 jam sebelum toko tutup. Koordinasikan waktu dan titik pengambilan dengan mitra.",
            $storeNow->format('d-m-Y'),
            $productLines !== '' ? $productLines : 'Belum ada produk tercatat.',
            $totalLines !== '' ? $totalLines : '0 pcs'
        );
    }

    /** Queue an approval request with Telegram buttons and a custom discount command. */
    public function queueDiscountApproval(DiscountApproval $approval): void
    {
        $approval->loadMissing(['product', 'batch']);
        $product = $approval->product;
        $batch = $approval->batch;
        $text = sprintf(
            "Discount approval required\n%s (%s), batch %s\nStock: %d %s\nExpires: %s\nRecommended discount: %d%% (price %s)\nReason: %s\n\nChoose an action. For a custom rate, reply: /discount %d 25",
            $product->name,
            $product->sku,
            $batch->batch_code,
            $batch->quantity_available,
            $product->unit,
            $batch->expiration_date->toDateString(),
            $approval->recommended_percentage,
            number_format((float) $approval->recommended_price, 2),
            $approval->reasoning,
            $approval->id
        );
        $keyboard = ['inline_keyboard' => [[
            ['text' => 'Approve AI '.$approval->recommended_percentage.'%', 'callback_data' => 'approve:'.$approval->id],
            ['text' => 'Reject', 'callback_data' => 'reject:'.$approval->id],
        ]]];

        foreach (User::query()->notificationRecipients()->whereNotNull('telegram_chat_id')->get(['id', 'telegram_chat_id']) as $user) {
            $this->queueDelivery('discount_approval', 'telegram', $user->telegram_chat_id, null, [
                'text' => $text,
                'reply_markup' => $keyboard,
                'product_id' => $product->id,
            ], "approval:{$approval->id}:user:{$user->id}:telegram", 'approval');
        }
    }

    /** Notify every active UMKM partner when a discount has been approved and is available. */
    public function queueDiscountOffers(DiscountApproval $approval): void
    {
        $approval->loadMissing(['product', 'batch']);
        $this->queueDiscountOffer(
            $approval->product,
            $approval->batch,
            (int) $approval->approved_percentage,
            'approval:'.$approval->id
        );
    }

    public function queueDiscountOffer(Product $product, ProductBatch $batch, int $percentage, string $reference): void
    {
        $price = number_format((float) $batch->discount_price, 0, ',', '.');
        $originalPrice = number_format((float) $batch->original_price, 0, ',', '.');
        $text = sprintf(
            "EcoLoop - Penawaran Bahan Baku Diskon\nProduk: %s (%s)\nDiskon: %d%%\nHarga awal: Rp %s / %s\nHarga diskon: Rp %s / %s\nStok tersedia: %d %s\nKedaluwarsa: %s\n\nProduk dapat dibeli oleh Mitra UMKM selama stok masih tersedia. Mohon hubungi supermarket untuk konfirmasi dan pengambilan.",
            $product->name,
            $product->sku,
            max(0, min(100, $percentage)),
            $originalPrice,
            $product->unit,
            $price,
            $product->unit,
            $batch->quantity_available,
            $product->unit,
            $batch->expiration_date->toDateString()
        );

        $partners = DonationPartner::query()->active()->umkm()->orderBy('id')->get();
        foreach ($partners as $partner) {
            $key = "discount-offer:{$reference}:partner:{$partner->id}";
            $this->queueDelivery('discount_available', 'email', $partner->email, 'EcoLoop: bahan baku tersedia dengan harga diskon', [
                'body' => "Halo {$partner->contact_person},\n\n{$text}\n\nEcoLoop",
                'product_id' => $product->id,
            ], "{$key}:email", 'partner_offer');

            if (filled($partner->telegram_chat_id)) {
                $this->queueDelivery('discount_available', 'telegram', $partner->telegram_chat_id, null, [
                    'text' => $text,
                    'product_id' => $product->id,
                ], "{$key}:telegram", 'partner_offer');
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private function queueDelivery(string $event, string $channel, string $recipient, ?string $subject, array $payload, string $key, string $deliveryType = 'donation'): void
    {
        $delivery = NotificationDelivery::query()->firstOrCreate(
            ['dedupe_key' => $key],
            [
                'event_type' => $event,
                'channel' => $channel,
                'recipient' => $recipient,
                'subject' => $subject,
                'payload' => $payload,
                'status' => 'pending',
            ]
        );

        if ($delivery->wasRecentlyCreated) {
            $job = match ($deliveryType) {
                'approval' => new SendDiscountApprovalNotifications($delivery->id),
                'partner_offer' => new SendPartnerOfferNotifications($delivery->id),
                default => new SendDonationNotifications($delivery->id),
            };
            dispatch($job->afterCommit());
        }
    }

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
