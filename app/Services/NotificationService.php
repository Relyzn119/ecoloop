<?php

namespace App\Services;

use App\Jobs\SendDiscountApprovalNotifications;
use App\Jobs\SendDonationNotifications;
use App\Jobs\SendPartnerOfferNotifications;
use App\Models\AgentLog;
use App\Models\DiscountApproval;
use App\Models\DonationPartner;
use App\Models\NotificationDelivery;
use App\Models\PartnerDonationClaim;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\TelegramAiAction;
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
    public function queueRequestedDonationAlerts(Product $product, array $allocations, string $reference, bool $stockReductionApplied = true): int
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
            "EcoLoop - Donasi atas persetujuan manager\nProduk: %s (%s)\nJumlah total: %d %s\nRincian stok:\n%s\n\n%s\nProduk donasi sudah bisa diambil di supermarket. Pengambilan paling lambat 3 jam sebelum toko tutup. Donation Partner dipersilakan berkoordinasi dan berbagi produk sesuai kebutuhan bersama.",
            $product->name,
            $product->sku,
            $quantity,
            $product->unit,
            $batchLines !== '' ? $batchLines : 'Rincian batch tidak tersedia.',
            $stockReductionApplied
                ? 'Stok supermarket sudah dikurangi sesuai jumlah donasi.'
                : 'Stok supermarket tidak dikurangi; penggantian stok telah dipilih sebagai penyesuaian.'
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

    /** Queue one actionable near-expiry donation review to a manager Telegram account. */
    public function queueDonationApproval(TelegramAiAction $action, ProductBatch $batch, bool $preAlert): void
    {
        $this->queueInventoryReviewBatch(collect([$action]), collect());
    }

    /** Queue grouped donation decisions using the unified manager summary. */
    public function queueDonationApprovalBatch(iterable $actions): void
    {
        $this->queueInventoryReviewBatch($actions, []);
    }

    /** Queue grouped discount decisions using the unified manager summary. */
    public function queueDiscountApprovalBatch(iterable $approvals): void
    {
        $this->queueInventoryReviewBatch([], $approvals);
    }

    /** Queue one manager summary containing donation and discount decisions with per-item buttons. */
    public function queueInventoryReviewBatch(iterable $donationActions, iterable $discountApprovals): void
    {
        $donationActions = collect($donationActions)
            ->filter(fn ($action): bool => $action instanceof TelegramAiAction);
        $discountApprovals = collect($discountApprovals)
            ->filter(fn ($approval): bool => $approval instanceof DiscountApproval);
        $entriesByChat = [];

        foreach ($donationActions->groupBy('telegram_chat_id') as $chatId => $chatActions) {
            $batchIds = $chatActions
                ->map(fn (TelegramAiAction $action): int => (int) ($action->payload['batch_id'] ?? 0))
                ->filter()
                ->unique()
                ->values();
            $batches = ProductBatch::query()
                ->with('product')
                ->whereIn('id', $batchIds)
                ->get()
                ->keyBy('id');

            foreach ($chatActions
                ->filter(fn (TelegramAiAction $action): bool => $batches->has((int) ($action->payload['batch_id'] ?? 0)))
                ->sortBy(fn (TelegramAiAction $action): string => sprintf(
                    '%s-%s',
                    $batches[(int) $action->payload['batch_id']]->expiration_date->toDateString(),
                    $batches[(int) $action->payload['batch_id']]->batch_code
                ))
                ->values() as $action) {
                $entriesByChat[(string) $chatId]['donations'][] = [$action, $batches[(int) $action->payload['batch_id']]];
            }
        }

        if ($discountApprovals->isNotEmpty()) {
            $recipients = User::query()->notificationRecipients()->whereNotNull('telegram_chat_id')->get(['id', 'telegram_chat_id']);
            foreach ($recipients as $user) {
                foreach ($discountApprovals as $approval) {
                    $entriesByChat[(string) $user->telegram_chat_id]['discounts'][] = $approval;
                }
            }
        }

        foreach ($entriesByChat as $chatId => $groups) {
            $donations = collect($groups['donations'] ?? []);
            $discounts = collect($groups['discounts'] ?? [])->unique('id')->values();
            $entries = collect();
            foreach ($donations as [$action, $batch]) {
                $entries->push(['type' => 'donation', 'action' => $action, 'batch' => $batch]);
            }
            foreach ($discounts as $approval) {
                $entries->push(['type' => 'discount', 'approval' => $approval]);
            }

            foreach ($entries->chunk(15) as $chunkIndex => $chunk) {
                $lines = [];
                $keyboard = [];
                $dedupeParts = [];
                $firstProductId = null;
                $hasDonation = false;
                $hasDiscount = false;

                foreach ($chunk->values() as $index => $action) {
                    $number = $index + 1;
                    if ($action['type'] === 'donation') {
                        /** @var TelegramAiAction $donationAction */
                        $donationAction = $action['action'];
                        /** @var ProductBatch $batch */
                        $batch = $action['batch'];
                        $product = $batch->product;
                        $payload = $donationAction->payload ?? [];
                        if (! $product) {
                            continue;
                        }
                        $hasDonation = true;
                        $quantity = (int) ($payload['recommended_quantity'] ?? $batch->quantity_available);
                        $preAlert = ($payload['stage'] ?? null) === 'prealert';
                        $when = $preAlert ? 'DONASI BESOK - menunggu pilihan owner/manager' : 'DONASI SEGERA - menunggu persetujuan';
                        $lines[] = sprintf(
                            '%d. DONASI · %s (%s), batch %s · %d %s dari %d %s · exp %s · %s',
                            $number,
                            $product->name,
                            $product->sku,
                            $batch->batch_code,
                            $quantity,
                            $product->unit,
                            $batch->quantity_available,
                            $product->unit,
                            $batch->expiration_date->toDateString(),
                            $when
                        );

                        $suffix = ':'.$donationAction->id;
                        if ($preAlert) {
                            $keyboard[] = [
                                ['text' => '#'.$number.' Besok · kurangi', 'callback_data' => 'eco:donation:reduce'.$suffix],
                                ['text' => '#'.$number.' Besok · stok tetap', 'callback_data' => 'eco:donation:keep'.$suffix],
                            ];
                            $keyboard[] = [
                                ['text' => '#'.$number.' Jumlah sendiri', 'callback_data' => 'eco:donation:custom'.$suffix],
                                ['text' => '#'.$number.' Tinjau besok', 'callback_data' => 'eco:donation:defer'.$suffix],
                            ];
                        } else {
                            $keyboard[] = [
                                ['text' => '#'.$number.' Donasi · kurangi', 'callback_data' => 'eco:donation:reduce'.$suffix],
                                ['text' => '#'.$number.' Donasi · stok tetap', 'callback_data' => 'eco:donation:keep'.$suffix],
                            ];
                            $keyboard[] = [['text' => '#'.$number.' Jumlah sendiri', 'callback_data' => 'eco:donation:custom'.$suffix]];
                        }
                        $keyboard[] = [['text' => '#'.$number.' Lewati batch ini', 'callback_data' => 'eco:donation:reject'.$suffix]];
                        $dedupeParts[] = 'donation:'.$donationAction->id.':'.($payload['notification_date'] ?? now('Asia/Jakarta')->toDateString());
                        $firstProductId ??= $product->id;
                    } else {
                        /** @var DiscountApproval $approval */
                        $approval = $action['approval'];
                        $approval->loadMissing(['product', 'batch']);
                        if (! $approval->product || ! $approval->batch) {
                            continue;
                        }
                        $hasDiscount = true;
                        $lines[] = sprintf(
                            "%d. DISKON · %s (%s), batch %s · stok %d %s · exp %s · rekomendasi %d%% (Rp %s)\n   Alasan: %s · ubah sendiri: /discount %d 25",
                            $number,
                            $approval->product->name,
                            $approval->product->sku,
                            $approval->batch->batch_code,
                            $approval->batch->quantity_available,
                            $approval->product->unit,
                            $approval->batch->expiration_date->toDateString(),
                            $approval->recommended_percentage,
                            number_format((float) $approval->recommended_price, 0, ',', '.'),
                            $approval->reasoning,
                            $approval->id
                        );
                        $keyboard[] = [[
                            'text' => '#'.$number.' Setujui '.$approval->recommended_percentage.'%',
                            'callback_data' => 'approve:'.$approval->id,
                        ], [
                            'text' => '#'.$number.' Tolak diskon',
                            'callback_data' => 'reject:'.$approval->id,
                        ]];
                        $dedupeParts[] = 'discount:'.$approval->id;
                        $firstProductId ??= $approval->product_id;
                    }
                }

                if ($lines === []) {
                    continue;
                }

                $text = "EcoLoop · Ringkasan keputusan inventaris\nPilih tindakan untuk tiap produk. Diskon dan donasi belum dijalankan sampai disetujui.\n\n";
                if ($hasDonation) {
                    $text .= "DONASI / PRE-ALERT\n";
                }
                if ($hasDiscount) {
                    $text .= "DISKON MENUNGGU PERSETUJUAN\n";
                }
                $text .= implode("\n", $lines);
                $dedupe = 'inventory-review-batch:'.sha1((string) $chatId.':'.$chunkIndex.':'.implode('|', $dedupeParts));
                $this->queueDelivery('inventory_review_summary', 'telegram', (string) $chatId, null, [
                    'text' => $text,
                    'reply_markup' => ['inline_keyboard' => $keyboard],
                    'product_id' => $firstProductId,
                ], $dedupe, 'approval');
            }
        }
    }

    /** Notify managers in grouped Telegram messages about pre-approved donations scheduled for tomorrow. */
    public function queueAutomaticDonationScheduleBatch(iterable $actions): void
    {
        $actions = collect($actions)
            ->filter(fn ($action): bool => $action instanceof TelegramAiAction && ($action->payload['stage'] ?? null) === 'auto_preapproved');
        if ($actions->isEmpty()) {
            return;
        }

        $batchIds = $actions->map(fn (TelegramAiAction $action): int => (int) ($action->payload['batch_id'] ?? 0))->filter()->unique();
        $batches = ProductBatch::query()->with('product')->whereIn('id', $batchIds)->get()->keyBy('id');
        $recipients = User::query()->notificationRecipients()->whereNotNull('telegram_chat_id')->get(['id', 'telegram_chat_id']);

        foreach ($recipients as $recipient) {
            foreach ($actions->chunk(15) as $chunkIndex => $chunk) {
                $lines = [];
                $actionIds = [];
                $firstProductId = null;
                foreach ($chunk as $action) {
                    $batch = $batches->get((int) ($action->payload['batch_id'] ?? 0));
                    if (! $batch?->product) {
                        continue;
                    }
                    $lines[] = sprintf(
                        '• %s (%s), batch %s — %d %s — kedaluwarsa %s',
                        $batch->product->name,
                        $batch->product->sku,
                        $batch->batch_code,
                        (int) ($action->payload['quantity'] ?? $batch->quantity_available),
                        $batch->product->unit,
                        $batch->expiration_date->toDateString()
                    );
                    $actionIds[] = $action->id;
                    $firstProductId ??= $batch->product_id;
                }
                if ($lines === []) {
                    continue;
                }

                $text = "EcoLoop · Donasi otomatis dijadwalkan\nSesuai kebijakan otomatis yang disetujui owner/manager, produk berikut akan didonasikan besok dan stok dikurangi saat eksekusi:\n\n".implode("\n", $lines);
                $dedupe = 'auto-donation-schedule:'.sha1($recipient->telegram_chat_id.':'.$chunkIndex.':'.implode('|', $actionIds));
                $this->queueDelivery('automatic_donation_scheduled', 'telegram', $recipient->telegram_chat_id, null, [
                    'text' => $text,
                    'product_id' => $firstProductId,
                ], $dedupe);
            }
        }
    }

    public function queueTelegramActionResult(string $chatId, string $text, string $reference): void
    {
        $this->queueDelivery('donation_approval_result', 'telegram', $chatId, null, ['text' => $text], $reference);
    }

    /** Notify the taking partner and ask managers how to reconcile the supermarket stock. */
    public function queuePartnerDonationClaim(PartnerDonationClaim $claim): void
    {
        $claim->loadMissing(['batch.product', 'partner']);
        $product = $claim->batch->product;
        $partner = $claim->partner;
        $text = sprintf(
            "EcoLoop - Pengambilan donasi dari scan kamera\nMitra: %s\nProduk: %s (%s)\nBatch: %s\nJumlah diambil: %d %s\n\nMitra dapat mengambil produk ini di supermarket. Pengambilan paling lambat 3 jam sebelum toko tutup. Manager/kasir akan mengonfirmasi pencatatan stok secara terpisah.",
            $partner->name,
            $product->name,
            $product->sku,
            $claim->batch->batch_code,
            $claim->quantity,
            $claim->unit
        );
        $reference = 'partner-photo-claim:'.$claim->id;

        $this->queueDelivery('partner_donation_claimed', 'email', $partner->email, 'EcoLoop: pengambilan donasi tercatat', [
            'body' => "Halo {$partner->contact_person},\n\n{$text}\n\nEcoLoop Agent",
            'product_id' => $product->id,
        ], $reference.':partner:email');
        if (filled($partner->telegram_chat_id)) {
            $this->queueDelivery('partner_donation_claimed', 'telegram', $partner->telegram_chat_id, null, [
                'text' => $text,
                'product_id' => $product->id,
            ], $reference.':partner:telegram');
        }

        $managerText = sprintf(
            "EcoLoop - konfirmasi pencatatan stok setelah pengambilan mitra\nMitra %s telah mengambil %d %s %s dari batch %s. Donasi telah tercatat; tentukan apakah stok supermarket ikut dikurangi.\n\nJika stok dikurangi, sistem mengurangi %d %s sekarang. Jika stok tetap, sistem mempertahankan angka stok (misalnya karena stok pengganti akan segera masuk).",
            $partner->name,
            $claim->quantity,
            $claim->unit,
            $product->name,
            $claim->batch->batch_code,
            $claim->quantity,
            $claim->unit
        );
        $keyboard = ['inline_keyboard' => [[
            ['text' => 'Kurangi stok', 'callback_data' => 'eco:partner-stock:reduce:'.$claim->id],
            ['text' => 'Stok tetap', 'callback_data' => 'eco:partner-stock:keep:'.$claim->id],
        ]]];

        foreach (User::query()->notificationRecipients()->get(['id', 'name', 'email', 'telegram_chat_id']) as $user) {
            $managerReference = $reference.':manager:'.$user->id;
            $this->queueDelivery('partner_donation_stock_decision', 'email', $user->email, 'EcoLoop: konfirmasi stok donasi mitra', [
                'body' => "Halo {$user->name},\n\n{$managerText}\n\nPilih Kurangi stok atau Stok tetap melalui tombol pada bot Telegram EcoLoopPartner_Bot.",
                'product_id' => $product->id,
            ], $managerReference.':email');
            if (filled($user->telegram_chat_id)) {
                $this->queueDelivery('partner_donation_stock_decision', 'telegram', $user->telegram_chat_id, null, [
                    'text' => $managerText,
                    'reply_markup' => $keyboard,
                    'product_id' => $product->id,
                ], $managerReference.':telegram');
            }
        }
    }

    /** Send the manager's stock decision back to the partner that took the goods. */
    public function queuePartnerDonationStockResult(PartnerDonationClaim $claim): void
    {
        $claim->loadMissing(['batch.product', 'partner']);
        $reduction = $claim->stock_reduction_applied ? 'dikurangi' : 'tetap sesuai pencatatan';
        $text = sprintf(
            "EcoLoop - pencatatan donasi diperbarui\nProduk: %s\nJumlah diambil: %d %s\nStok supermarket: %s.\nPengambilan: paling lambat 3 jam sebelum toko tutup.",
            $claim->batch->product->name,
            $claim->quantity,
            $claim->unit,
            $reduction
        );
        $reference = 'partner-photo-claim:'.$claim->id.':stock-result';
        $this->queueDelivery('partner_donation_stock_result', 'email', $claim->partner->email, 'EcoLoop: keputusan stok donasi', [
            'body' => "Halo {$claim->partner->contact_person},\n\n{$text}\n\nEcoLoop Agent",
            'product_id' => $claim->batch->product_id,
        ], $reference.':email');
        if (filled($claim->partner->telegram_chat_id)) {
            $this->queueDelivery('partner_donation_stock_result', 'telegram', $claim->partner->telegram_chat_id, null, [
                'text' => $text,
                'product_id' => $claim->batch->product_id,
            ], $reference.':telegram');
        }
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
        $this->queueDiscountApprovalBatch([$approval]);
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
