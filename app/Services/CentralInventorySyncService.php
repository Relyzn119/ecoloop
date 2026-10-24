<?php

namespace App\Services;

use App\Models\Category;
use App\Models\InventorySyncSetting;
use App\Models\Product;
use App\Models\ProductBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    public function synchronize(bool $force = false): array
    {
        return DB::transaction(function () use ($force): array {
            $settings = InventorySyncSetting::query()->lockForUpdate()->findOrFail(1);

            if (! $force && ! $settings->auto_sync_enabled) {
                return ['skipped' => true, 'reason' => 'Auto Sync is paused.'];
            }

            if (! $force && $settings->last_synced_at?->gt(now()->subMinutes(4))) {
                return ['skipped' => true, 'reason' => 'A sync already ran during the last four minutes.'];
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

            if ($newCatalogItems->isNotEmpty()) {
                $productCount = random_int(1, min(3, $newCatalogItems->count()));
                $incomingItems = $newCatalogItems->shuffle()->take($productCount);

                foreach ($incomingItems as $item) {
                    $product = $this->createCentralProduct($item);
                    $quantity = random_int(12, 48);
                    $this->receiveCentralBatch($product, $quantity);
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
                    $this->receiveCentralBatch($product, $quantity);
                    $newBatches++;
                    $receivedUnits += $quantity;
                    $affectedProductIds[] = $product->id;
                }
            }

            $saleBatches = ProductBatch::query()
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
                if ($batch->quantity_available < 1) {
                    continue;
                }

                $quantitySold = random_int(1, min(4, $batch->quantity_available));
                $quantityRemaining = $batch->quantity_available - $quantitySold;
                $batch->update([
                    'quantity_available' => $quantityRemaining,
                    'quantity_sold' => $batch->quantity_sold + $quantitySold,
                    'status' => $quantityRemaining === 0 ? 'sold_out' : $batch->status,
                    'source_synced_at' => now(),
                ]);

                $salesTransactions++;
                $soldUnits += $quantitySold;
                $affectedProductIds[] = $batch->product_id;
            }

            $affectedProductIds = array_values(array_unique($affectedProductIds));
            $inventoryService = app(InventoryService::class);

            foreach ($affectedProductIds as $productId) {
                $inventoryService->refreshProductStockSummary($productId);
            }

            $summary = [
                'new_products' => $newProducts,
                'new_batches' => $newBatches,
                'received_units' => $receivedUnits,
                'sales_transactions' => $salesTransactions,
                'sold_units' => $soldUnits,
                'changed_products' => count($affectedProductIds),
            ];

            $settings->update([
                'last_synced_at' => now(),
                'last_sync_summary' => $summary,
            ]);

            return [
                'skipped' => false,
                'synced_at' => $settings->fresh()->last_synced_at?->toIso8601String(),
                ...$summary,
            ];
        });
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

    private function receiveCentralBatch(Product $product, int $quantity): void
    {
        $storeCode = ['JKT-01', 'BDG-02', 'SBY-01', 'DPS-01'][array_rand(['JKT-01', 'BDG-02', 'SBY-01', 'DPS-01'])];
        $batchReference = (string) Str::uuid();

        $product->batches()->create([
            'batch_code' => $storeCode.'-'.$product->sku.'-'.now()->format('ymd').'-'.Str::upper(Str::random(4)),
            'quantity_received' => $quantity,
            'quantity_available' => $quantity,
            'quantity_donated' => 0,
            'quantity_sold' => 0,
            'expiration_date' => now()->addDays(random_int(8, 60))->toDateString(),
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
