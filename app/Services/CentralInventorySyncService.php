<?php

namespace App\Services;

use App\Models\Category;
use App\Models\DiscountApproval;
use App\Models\DonationPartner;
use App\Models\InventorySyncRun;
use App\Models\InventorySyncSetting;
use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CentralInventorySyncService
{
    private const SOURCE_SYSTEM = 'supermarket_central';

    /**
     * Demo catalog modeled after a supermarket product master table.
     *
     * @var list<array{
     *     external_id: string,
     *     sku: string,
     *     name: string,
     *     description: string,
     *     category_slug: string,
     *     unit: string,
     *     price: int
     * }>
     */
    private const CATALOG = [
        ['external_id' => 'POS-DAI-0001', 'sku' => 'DAI-0001', 'name' => 'Fresh Milk 1L', 'description' => 'Pasteurized whole milk, synchronized from central supermarket inventory.', 'category_slug' => 'dairy-eggs', 'unit' => 'cartons', 'price' => 22000],
        ['external_id' => 'POS-BAK-0002', 'sku' => 'BAK-0002', 'name' => 'Whole Wheat Sandwich Bread', 'description' => 'Daily bakery stock synchronized from the central point of sale.', 'category_slug' => 'bakery', 'unit' => 'loaves', 'price' => 18000],
        ['external_id' => 'POS-PRD-0003', 'sku' => 'PRD-0003', 'name' => 'Sweet Red Apples 1kg', 'description' => 'Fresh produce stock synchronized from the central supermarket inventory.', 'category_slug' => 'fresh-produce', 'unit' => 'kg', 'price' => 35000],
        ['external_id' => 'POS-MEA-0004', 'sku' => 'MEA-0004', 'name' => 'Chicken Breast 500g', 'description' => 'Chilled poultry stock synchronized from the central inventory system.', 'category_slug' => 'meat-seafood', 'unit' => 'packs', 'price' => 42000],
        ['external_id' => 'POS-DAI-0005', 'sku' => 'DAI-0005', 'name' => 'Plain Greek Yogurt 400g', 'description' => 'Refrigerated dairy stock synchronized from the central supermarket inventory.', 'category_slug' => 'dairy-eggs', 'unit' => 'cups', 'price' => 27000],
        ['external_id' => 'POS-PRD-0006', 'sku' => 'PRD-0006', 'name' => 'Roma Tomatoes 500g', 'description' => 'Fresh produce stock synchronized from the central supermarket inventory.', 'category_slug' => 'fresh-produce', 'unit' => 'packs', 'price' => 16000],
        ['external_id' => 'POS-BAK-0007', 'sku' => 'BAK-0007', 'name' => 'Butter Croissants 4pk', 'description' => 'Daily bakery stock synchronized from the central point of sale.', 'category_slug' => 'bakery', 'unit' => 'packs', 'price' => 32000],
        ['external_id' => 'POS-PKG-0008', 'sku' => 'PKG-0008', 'name' => 'Rolled Oats 1kg', 'description' => 'Packaged grocery stock synchronized from the central supermarket inventory.', 'category_slug' => 'packaged-goods', 'unit' => 'packs', 'price' => 29000],
        ['external_id' => 'POS-DAI-0009', 'sku' => 'DAI-0009', 'name' => 'Cheddar Cheese 250g', 'description' => 'Refrigerated dairy stock synchronized from the central supermarket inventory.', 'category_slug' => 'dairy-eggs', 'unit' => 'blocks', 'price' => 39000],
        ['external_id' => 'POS-PRD-0010', 'sku' => 'PRD-0010', 'name' => 'Baby Spinach 200g', 'description' => 'Fresh produce stock synchronized from the central supermarket inventory.', 'category_slug' => 'fresh-produce', 'unit' => 'packs', 'price' => 21000],
        ['external_id' => 'POS-MEA-0011', 'sku' => 'MEA-0011', 'name' => 'Ground Beef 500g', 'description' => 'Chilled meat stock synchronized from the central inventory system.', 'category_slug' => 'meat-seafood', 'unit' => 'packs', 'price' => 59000],
        ['external_id' => 'POS-PKG-0012', 'sku' => 'PKG-0012', 'name' => 'Brown Rice 2kg', 'description' => 'Packaged grocery stock synchronized from the central supermarket inventory.', 'category_slug' => 'packaged-goods', 'unit' => 'packs', 'price' => 47000],
    ];

    public function settings(): InventorySyncSetting
    {
        return InventorySyncSetting::query()->findOrFail(1);
    }

    public function setAutoSyncEnabled(bool $enabled): InventorySyncSetting
    {
        $settings = $this->settings();
        $settings->update(['auto_sync_enabled' => $enabled]);

        return $settings->fresh();
    }

    /**
     * Add realistic central-stock receipts and mirror random point-of-sale sales.
     *
     * @return array{
     *     skipped: bool,
     *     reason?: string,
     *     synced_at?: string,
     *     new_products?: int,
     *     new_batches?: int,
     *     received_units?: int,
     *     sales_transactions?: int,
     *     sold_units?: int,
     *     changed_products?: int
     * }
     */
    public function synchronize(bool $force = false, string $trigger = 'auto'): array
    {
        $trigger = in_array($trigger, ['auto', 'sync_now', 'manual_cli'], true) ? $trigger : 'auto';
        $run = InventorySyncRun::query()->create([
            'trigger_type' => $trigger,
            'status' => 'running',
            'started_at' => now(),
            'summary' => [],
            'details' => [],
        ]);

        try {
            return DB::transaction(function () use ($force, $run): array {
                $settings = InventorySyncSetting::query()->lockForUpdate()->findOrFail(1);

                if (! $force && ! $settings->auto_sync_enabled) {
                    $reason = 'Auto Sync is paused.';
                    $run->update([
                        'status' => 'skipped',
                        'completed_at' => now(),
                        'summary' => ['reason' => $reason],
                        'details' => ['skip_reason' => $reason],
                    ]);

                    return ['skipped' => true, 'reason' => $reason, 'sync_run_id' => $run->id];
                }

                if (! $force && $settings->last_synced_at?->gt(now()->subMinutes(4))) {
                    $reason = 'A sync already ran during the last four minutes.';
                    $run->update([
                        'status' => 'skipped',
                        'completed_at' => now(),
                        'summary' => ['reason' => $reason],
                        'details' => ['skip_reason' => $reason],
                    ]);

                    return ['skipped' => true, 'reason' => $reason, 'sync_run_id' => $run->id];
                }

                $existingExternalIds = Product::query()
                    ->where('source_system', self::SOURCE_SYSTEM)
                    ->pluck('external_id')
                    ->all();
                $newCatalogItems = collect(self::CATALOG)
                    ->reject(fn (array $item): bool => in_array($item['external_id'], $existingExternalIds, true))
                    ->values();

                $newProducts = 0;
                $newBatches = 0;
                $receivedUnits = 0;
                $affectedProductIds = [];
                $events = ['received' => [], 'sold' => [], 'demo_received' => [], 'donated' => []];

                if ($newCatalogItems->isNotEmpty()) {
                    $productCount = random_int(1, min(3, $newCatalogItems->count()));
                    $incomingItems = $newCatalogItems->shuffle()->take($productCount);

                    foreach ($incomingItems as $item) {
                        $product = $this->createCentralProduct($item);
                        $quantity = random_int(12, 48);
                        $stockBefore = 0;
                        $batch = $this->receiveCentralBatch($product, $quantity);
                        $events['received'][] = $this->stockEvent('received', $product, $batch, $quantity, $stockBefore, $stockBefore + $quantity);
                        $newProducts++;
                        $newBatches++;
                        $receivedUnits += $quantity;
                        $affectedProductIds[] = $product->id;
                    }
                } else {
                    $centralProducts = Product::query()
                        ->where('source_system', self::SOURCE_SYSTEM)
                        ->inRandomOrder()
                        ->limit(random_int(1, 2))
                        ->get();

                    foreach ($centralProducts as $product) {
                        $quantity = random_int(8, 32);
                        $stockBefore = (int) ProductBatch::query()->where('product_id', $product->id)->sum('quantity_available');
                        $batch = $this->receiveCentralBatch($product, $quantity);
                        $events['received'][] = $this->stockEvent('received', $product, $batch, $quantity, $stockBefore, $stockBefore + $quantity);
                        $newBatches++;
                        $receivedUnits += $quantity;
                        $affectedProductIds[] = $product->id;
                    }
                }

                $saleBatches = ProductBatch::query()
                    ->with('product')
                    ->where('source', 'central_sync')
                    ->where('quantity_available', '>', 0)
                    ->whereIn('status', ['active', 'discounted'])
                    ->whereDate('expiration_date', '>=', now()->toDateString())
                    ->inRandomOrder()
                    ->limit(random_int(1, 4))
                    ->lockForUpdate()
                    ->get();
                $salesTransactions = 0;
                $soldUnits = 0;

                foreach ($saleBatches as $batch) {
                    if ($batch->quantity_available < 1 || ! $batch->product) {
                        continue;
                    }

                    $stockBefore = (int) ProductBatch::query()->where('product_id', $batch->product_id)->sum('quantity_available');
                    $quantitySold = random_int(1, min(4, $batch->quantity_available));
                    $quantityRemaining = $batch->quantity_available - $quantitySold;
                    $batch->update([
                        'quantity_available' => $quantityRemaining,
                        'quantity_sold' => $batch->quantity_sold + $quantitySold,
                        'status' => $quantityRemaining === 0 ? 'sold_out' : $batch->status,
                        'source_synced_at' => now(),
                    ]);

                    $events['sold'][] = $this->stockEvent('sold', $batch->product, $batch->fresh(), $quantitySold, $stockBefore, $stockBefore - $quantitySold);
                    $salesTransactions++;
                    $soldUnits += $quantitySold;
                    $affectedProductIds[] = $batch->product_id;
                }

                $affectedProductIds = array_values(array_unique($affectedProductIds));
                $inventoryService = app(InventoryService::class);

                foreach ($affectedProductIds as $productId) {
                    $inventoryService->refreshProductStockSummary($productId);
                }

                $updatedProducts = $this->updatedProductSnapshots($affectedProductIds);
                $discountRecommendations = $this->pendingDiscountRecommendationSnapshots($affectedProductIds);
                $summary = [
                    'new_products' => $newProducts,
                    'new_batches' => $newBatches,
                    'received_units' => $receivedUnits,
                    'sales_transactions' => $salesTransactions,
                    'sold_units' => $soldUnits,
                    'changed_products' => count($affectedProductIds),
                    'updated_product_rows' => count($updatedProducts),
                    'donated_units' => 0,
                    'discount_recommendations' => count($discountRecommendations),
                ];
                $details = [
                    'events' => $events,
                    'updated_products' => $updatedProducts,
                    'discount_recommendations' => $discountRecommendations,
                ];

                $settings->update([
                    'last_synced_at' => now(),
                    'last_sync_summary' => $summary,
                ]);
                $run->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                    'summary' => $summary,
                    'details' => $details,
                ]);

                return [
                    'skipped' => false,
                    'sync_run_id' => $run->id,
                    'synced_at' => $settings->fresh()->last_synced_at?->toIso8601String(),
                    'details' => $details,
                    ...$summary,
                ];
            });
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => mb_substr($exception->getMessage(), 0, 4000),
            ]);

            throw $exception;
        }
    }

    /** Run an immediate demo sync and request approval for urgent synthetic batches. */
    public function synchronizeForDemo(): array
    {
        $syncResult = $this->synchronize(force: true, trigger: 'sync_now');
        $partners = DonationPartner::query()->active()->where('partner_type', 'foundation')->orderBy('id')->get();

        if ($partners->isEmpty()) {
            $details = $this->appendDemoAuditDetails(
                (int) $syncResult['sync_run_id'],
                [],
                [],
                [],
                0,
                0,
                'No active donation partner is configured.'
            );

            return [
                ...$syncResult,
                'details' => $details,
                'demo_donated_products' => 0,
                'demo_donated_units' => 0,
                'demo_partner_count' => 0,
                'demo_expected_products' => 0,
                'demo_donation_error' => 'No active donation partner is configured.',
            ];
        }

        $demoBatchCount = max(3, $partners->count());
        $catalog = collect(self::CATALOG);
        $demoStock = DB::transaction(function () use ($catalog, $demoBatchCount): array {
            $batches = [];
            $receiptEvents = [];
            $newProducts = 0;
            for ($index = 0; $index < $demoBatchCount; $index++) {
                $item = $catalog[$index % $catalog->count()];
                $product = Product::query()->where('external_id', $item['external_id'])->first();
                if (! $product) {
                    $product = $this->createCentralProduct($item);
                    $newProducts++;
                }

                $quantity = random_int(4, 12);
                $stockBefore = (int) ProductBatch::query()->where('product_id', $product->id)->sum('quantity_available');
                $batch = $this->receiveCentralBatch(
                    $product,
                    $quantity,
                    $index % 3 === 1 ? 2 : 1
                );
                $batches[] = $batch;
                $receiptEvents[] = $this->stockEvent('demo_received', $product, $batch, $quantity, $stockBefore, $stockBefore + $quantity);
            }

            return ['batches' => $batches, 'new_products' => $newProducts, 'receipt_events' => $receiptEvents];
        });

        $approvalService = app(ExpiringDonationApprovalService::class);
        $approvalEvents = [];
        $approvalActionCount = 0;
        foreach ($demoStock['batches'] as $batch) {
            $managerCount = $approvalService->createApprovalForBatch(
                $batch,
                false,
                'Urgent demo batch created by Sync Now; manager approval is required before donation.'
            );
            $approvalActionCount += $managerCount;
            if ($managerCount > 0) {
                $approvalEvents[] = [
                    'batch_id' => $batch->id,
                    'batch_code' => $batch->batch_code,
                    'product_id' => $batch->product_id,
                    'product_name' => $batch->product->name,
                    'quantity_available' => $batch->quantity_available,
                    'unit' => $batch->product->unit,
                    'expiration_date' => $batch->expiration_date->toDateString(),
                    'manager_actions' => $managerCount,
                ];
            }
        }

        $demoReceivedUnits = (int) collect($demoStock['receipt_events'])->sum('quantity');
        $summary = [
            'new_products' => (int) ($syncResult['new_products'] ?? 0),
            'new_batches' => (int) ($syncResult['new_batches'] ?? 0) + count($demoStock['batches']),
            'received_units' => (int) ($syncResult['received_units'] ?? 0) + $demoReceivedUnits,
            'demo_received_units' => $demoReceivedUnits,
            'sales_transactions' => (int) ($syncResult['sales_transactions'] ?? 0),
            'sold_units' => (int) ($syncResult['sold_units'] ?? 0),
            'changed_products' => (int) ($syncResult['changed_products'] ?? 0),
            'demo_new_products' => $demoStock['new_products'],
            'demo_donated_products' => 0,
            'demo_donated_units' => 0,
            'demo_partner_count' => $partners->count(),
            'demo_expected_products' => $demoBatchCount,
            'donation_approvals_pending' => count($approvalEvents),
            'donated_units' => 0,
        ];

        $donationError = count($approvalEvents) < $demoBatchCount
            ? 'No manager/cashier Telegram approval requests could be created. Link manager Telegram chat IDs and ensure at least one active foundation partner.'
            : null;
        $details = $this->appendDemoAuditDetails(
            (int) $syncResult['sync_run_id'],
            $demoStock['receipt_events'],
            [],
            array_map(fn (ProductBatch $batch): int => $batch->product_id, $demoStock['batches']),
            (int) $summary['demo_new_products'],
            (int) $summary['demo_donated_units'],
            $donationError,
            $summary,
            $approvalEvents,
        );

        return [
            ...$syncResult,
            'details' => $details,
            ...$summary,
            'demo_donated_products' => 0,
            'demo_donated_units' => 0,
            'demo_partner_count' => $partners->count(),
            'demo_expected_products' => $demoBatchCount,
            'demo_approval_actions' => $approvalActionCount,
            'demo_pending_products' => count($approvalEvents),
            'demo_donation_error' => $donationError,
        ];
    }

    /** @return array<string, mixed> */
    private function stockEvent(string $action, Product $product, ProductBatch $batch, int $quantity, int $stockBefore, int $stockAfter): array
    {
        return [
            'action' => $action,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'batch_id' => $batch->id,
            'batch_code' => $batch->batch_code,
            'unit' => $product->unit,
            'quantity' => $quantity,
            'stock_before' => $stockBefore,
            'stock_after' => $stockAfter,
            'stock_change' => $stockAfter - $stockBefore,
            'expiration_date' => $batch->expiration_date->toDateString(),
            'store_code' => $batch->store_code,
        ];
    }

    /** @param list<int> $productIds
     * @return list<array{id: int, name: string, sku: string, quantity: int, unit: string, status: string}>
     */
    private function updatedProductSnapshots(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return Product::query()
            ->whereIn('id', array_values(array_unique($productIds)))
            ->withSum('batches as available_stock', 'quantity_available')
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'quantity' => (int) ($product->available_stock ?? 0),
                'unit' => $product->unit,
                'status' => $product->status,
            ])->all();
    }

    /** @param list<int> $productIds
     * @return list<array{product_name: string, sku: string, batch_code: string, quantity_available: int, unit: string, expiration_date: string, recommended_percentage: int, recommended_price: float, status: string}>
     */
    private function pendingDiscountRecommendationSnapshots(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DiscountApproval::query()
            ->with(['product:id,sku,name,unit', 'batch:id,batch_code,quantity_available,expiration_date'])
            ->whereIn('product_id', array_values(array_unique($productIds)))
            ->where('status', 'pending')
            ->latest('id')
            ->get()
            ->filter(fn (DiscountApproval $approval): bool => $approval->product !== null && $approval->batch !== null)
            ->map(fn (DiscountApproval $approval): array => [
                'product_name' => $approval->product->name,
                'sku' => $approval->product->sku,
                'batch_code' => $approval->batch->batch_code,
                'quantity_available' => (int) $approval->batch->quantity_available,
                'unit' => $approval->product->unit,
                'expiration_date' => $approval->batch->expiration_date->toDateString(),
                'recommended_percentage' => $approval->recommended_percentage,
                'recommended_price' => (float) $approval->recommended_price,
                'status' => $approval->status,
            ])->values()->all();
    }

    /** @param list<array<string, mixed>> $receivedEvents
     * @param  list<array<string, mixed>>  $donationEvents
     * @param  list<int>  $demoProductIds
     * @param  array<string, int>|null  $summaryOverrides
     * @param  list<array<string, mixed>>  $approvalEvents
     * @return array<string, mixed>
     */
    private function appendDemoAuditDetails(
        int $runId,
        array $receivedEvents,
        array $donationEvents,
        array $demoProductIds,
        int $demoNewProducts,
        int $donatedUnits,
        ?string $donationError,
        ?array $summaryOverrides = null,
        array $approvalEvents = [],
    ): array {
        $run = InventorySyncRun::query()->findOrFail($runId);
        $details = $run->details ?? [];
        $events = $details['events'] ?? [];
        $events['demo_received'] = [...($events['demo_received'] ?? []), ...$receivedEvents];
        $events['donated'] = [...($events['donated'] ?? []), ...$donationEvents];
        $events['donation_approvals'] = [...($events['donation_approvals'] ?? []), ...$approvalEvents];
        $details['events'] = $events;

        $productIds = array_values(array_unique([
            ...$demoProductIds,
            ...array_map(fn (array $product): int => (int) $product['id'], $details['updated_products'] ?? []),
        ]));
        $details['updated_products'] = $this->updatedProductSnapshots($productIds);
        $details['discount_recommendations'] = $this->pendingDiscountRecommendationSnapshots($productIds);
        if ($donationError !== null) {
            $details['donation_error'] = $donationError;
        }

        $previousSummary = $run->summary ?? [];
        $summary = $summaryOverrides ?? $previousSummary;
        if ($summaryOverrides === null) {
            $summary['new_batches'] = (int) ($summary['new_batches'] ?? 0) + count($receivedEvents);
            $summary['received_units'] = (int) ($summary['received_units'] ?? 0) + (int) collect($receivedEvents)->sum('quantity');
        }
        $summary['demo_received_units'] = (int) collect($receivedEvents)->sum('quantity');
        $summary['demo_new_products'] = $demoNewProducts;
        $summary['demo_donated_products'] = count(array_filter($donationEvents, fn (array $event): bool => (bool) ($event['success'] ?? false)));
        $summary['demo_donated_units'] = $donatedUnits;
        if ($summaryOverrides === null) {
            $summary['donated_units'] = (int) ($summary['donated_units'] ?? 0) + $donatedUnits;
        }
        $summary['demo_expected_products'] = count($receivedEvents);
        $summary['donation_approvals_pending'] = count($approvalEvents);
        $summary['discount_recommendations'] = count($details['discount_recommendations']);
        $summary['updated_product_rows'] = count($details['updated_products']);
        $summary['changed_products'] = count($details['updated_products']);
        $summary['demo_donation_error'] = $donationError ?? '';

        $run->update(['summary' => $summary, 'details' => $details]);
        InventorySyncSetting::query()->findOrFail(1)->update(['last_sync_summary' => $summary]);

        return $details;
    }

    /**
     * @param  array{
     *     external_id: string,
     *     sku: string,
     *     name: string,
     *     description: string,
     *     category_slug: string,
     *     unit: string,
     *     price: int
     * }  $item
     */
    private function createCentralProduct(array $item): Product
    {
        $category = Category::query()->where('slug', $item['category_slug'])->first()
            ?? Category::query()->firstOrFail();

        return Product::query()->create([
            'category_id' => $category->id,
            'external_id' => $item['external_id'],
            'source_system' => self::SOURCE_SYSTEM,
            'sku' => 'CENTRAL-'.$item['sku'],
            'name' => $item['name'],
            'description' => $item['description'],
            'quantity' => 0,
            'unit' => $item['unit'],
            'original_price' => $item['price'],
            'discount_price' => null,
            'expiration_date' => now()->addDays(30)->toDateString(),
            'status' => 'active',
        ]);
    }

    private function receiveCentralBatch(Product $product, int $quantity, ?int $daysUntilExpiry = null): ProductBatch
    {
        $storeCode = ['JKT-01', 'BDG-02', 'SBY-01', 'DPS-01'][array_rand(['JKT-01', 'BDG-02', 'SBY-01', 'DPS-01'])];
        $batchReference = (string) Str::uuid();

        return $product->batches()->create([
            'batch_code' => $storeCode.'-'.$product->sku.'-'.now()->format('ymd').'-'.Str::upper(Str::random(4)),
            'quantity_received' => $quantity,
            'quantity_available' => $quantity,
            'quantity_donated' => 0,
            'quantity_sold' => 0,
            'expiration_date' => now()->addDays($daysUntilExpiry ?? random_int(8, 60))->toDateString(),
            'original_price' => $product->original_price,
            'discount_price' => null,
            'status' => 'active',
            'source' => 'central_sync',
            'source_reference' => 'POS-'.$storeCode.'-'.$batchReference,
            'store_code' => $storeCode,
            'source_synced_at' => now(),
        ]);
    }
}
