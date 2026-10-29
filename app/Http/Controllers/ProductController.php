<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Category;
use App\Models\DonationPartner;
use App\Models\InventorySyncSetting;
use App\Models\Product;
use App\Services\CentralInventorySyncService;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    /**
     * Display a paginated listing of inventory items with filtering and urgency metrics.
     */
    public function index(Request $request): Response
    {
        $today = now()->startOfDay();

        $query = Product::query()->with([
            'category:id,name,slug',
            'batches' => fn ($batchQuery) => $batchQuery
                ->with('discountApprovals')
                ->orderBy('expiration_date')
                ->orderBy('created_at'),
        ]);

        // Search by name, SKU, or description
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by Category
        if ($categoryId = $request->input('category')) {
            $query->where('category_id', $categoryId);
        }

        // Filter by Status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Filter by Expiration Urgency
        if ($urgency = $request->input('urgency')) {
            $criticalDate = $today->copy()->addDays(2)->toDateString();
            $warningDate = $today->copy()->addDays(5)->toDateString();
            $safeDate = $today->copy()->addDays(5)->toDateString();
            $todayDate = $today->toDateString();

            if ($urgency === 'critical') {
                $query->whereDate('expiration_date', '<=', $criticalDate)
                    ->whereDate('expiration_date', '>=', $todayDate);
            } elseif ($urgency === 'warning') {
                $query->whereDate('expiration_date', '>', $criticalDate)
                    ->whereDate('expiration_date', '<=', $warningDate);
            } elseif ($urgency === 'safe') {
                $query->whereDate('expiration_date', '>', $safeDate);
            } elseif ($urgency === 'expired') {
                $query->whereDate('expiration_date', '<', $todayDate);
            }
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'expiration_date');
        $sortOrder = $request->input('sort_order', 'asc');
        $allowedSorts = ['name', 'sku', 'expiration_date', 'quantity', 'original_price', 'status', 'created_at'];

        if (in_array($sortBy, $allowedSorts, true)) {
            $query->orderBy($sortBy, $sortOrder === 'desc' ? 'desc' : 'asc');
        } else {
            $query->orderBy('expiration_date', 'asc');
        }

        $products = $query->paginate(12)
            ->withQueryString()
            ->through(function (Product $product) use ($today) {
                $expDate = Carbon::parse($product->expiration_date)->startOfDay();
                $daysRemaining = (int) $today->diffInDays($expDate, false);

                if ($daysRemaining < 0) {
                    $urgencyLevel = 'expired';
                } elseif ($daysRemaining <= 2) {
                    $urgencyLevel = 'critical';
                } elseif ($daysRemaining <= 5) {
                    $urgencyLevel = 'warning';
                } else {
                    $urgencyLevel = 'safe';
                }

                return [
                    'id' => $product->id,
                    'external_id' => $product->external_id,
                    'source_system' => $product->source_system,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'description' => $product->description,
                    'category_id' => $product->category_id,
                    'category' => $product->category ? [
                        'id' => $product->category->id,
                        'name' => $product->category->name,
                        'slug' => $product->category->slug,
                    ] : null,
                    'quantity' => $product->quantity,
                    'unit' => $product->unit,
                    'original_price' => (float) $product->original_price,
                    'discount_price' => $product->discount_price !== null ? (float) $product->discount_price : null,
                    'original_price_formatted' => 'IDR '.number_format((float) $product->original_price, 0, ',', '.'),
                    'discount_price_formatted' => $product->discount_price !== null
                        ? 'IDR '.number_format((float) $product->discount_price, 0, ',', '.')
                        : null,
                    'expiration_date' => $product->expiration_date->toDateString(),
                    'expiration_date_formatted' => $product->expiration_date->format('M d, Y'),
                    'days_remaining' => $daysRemaining,
                    'urgency' => $urgencyLevel,
                    'status' => $product->status,
                    'created_at' => $product->created_at?->toIso8601String(),
                    'received_quantity' => (int) $product->batches->sum('quantity_received'),
                    'donated_quantity' => (int) $product->batches->sum('quantity_donated'),
                    'sold_quantity' => (int) $product->batches->sum('quantity_sold'),
                    'batches' => $product->batches->map(function ($batch) use ($today): array {
                        $batchDaysRemaining = (int) $today->diffInDays($batch->expiration_date->startOfDay(), false);
                        $approval = $batch->discountApprovals->sortByDesc('id')->first();

                        return [
                            'id' => $batch->id,
                            'batch_code' => $batch->batch_code,
                            'source' => $batch->source,
                            'received_at' => $batch->created_at?->toIso8601String(),
                            'quantity_received' => $batch->quantity_received,
                            'quantity_available' => $batch->quantity_available,
                            'quantity_donated' => $batch->quantity_donated,
                            'quantity_sold' => $batch->quantity_sold,
                            'expiration_date' => $batch->expiration_date->toDateString(),
                            'days_remaining' => $batchDaysRemaining,
                            'status' => $batch->status,
                            'discount_price' => $batch->discount_price !== null ? (float) $batch->discount_price : null,
                            'discount_approval' => $approval ? [
                                'status' => $approval->status,
                                'recommended_percentage' => $approval->recommended_percentage,
                                'approved_percentage' => $approval->approved_percentage,
                            ] : null,
                        ];
                    })->values(),
                ];
            });

        // Summary Statistics for Quick Navigation
        $totalItems = Product::count();
        $criticalCount = Product::query()
            ->whereDate('expiration_date', '<=', $today->copy()->addDays(2)->toDateString())
            ->whereDate('expiration_date', '>=', $today->toDateString())
            ->whereIn('status', ['active', 'discounted'])
            ->count();
        $warningCount = Product::query()
            ->whereDate('expiration_date', '>', $today->copy()->addDays(2)->toDateString())
            ->whereDate('expiration_date', '<=', $today->copy()->addDays(5)->toDateString())
            ->whereIn('status', ['active', 'discounted'])
            ->count();
        $discountedCount = Product::query()->where('status', 'discounted')->count();
        $reservedDonationCount = Product::query()->where('status', 'reserved_donation')->count();

        $categories = Category::query()->select('id', 'name', 'slug')->orderBy('name')->get();
        $donationPartners = DonationPartner::query()->where('is_active', true)->select('id', 'name', 'contact_person')->orderBy('name')->get();

        return Inertia::render('Inventory/Index', [
            'products' => $products,
            'is_business_owner' => $request->user()?->role === 'business_owner',
            'filters' => [
                'search' => $request->input('search', ''),
                'category' => $request->input('category', ''),
                'status' => $request->input('status', ''),
                'urgency' => $request->input('urgency', ''),
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
            ],
            'categories' => $categories,
            'donation_partners' => $donationPartners,
            'auto_sync' => InventorySyncSetting::query()->findOrFail(1)->only([
                'auto_sync_enabled',
                'last_synced_at',
                'last_sync_summary',
            ]),
            'stats' => [
                'total_items' => $totalItems,
                'critical_count' => $criticalCount,
                'warning_count' => $warningCount,
                'discounted_count' => $discountedCount,
                'reserved_donation_count' => $reservedDonationCount,
            ],
        ]);
    }

    /** Add one distinct inventory receipt batch to an existing catalog product. */
    public function addBatch(Request $request, Product $product, InventoryService $inventoryService): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'expiration_date' => ['required', 'date', 'after_or_equal:today'],
            'batch_code' => ['nullable', 'string', 'max:100'],
        ]);

        DB::transaction(function () use ($product, $validated, $inventoryService): void {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
            $lockedProduct->batches()->create([
                'batch_code' => filled($validated['batch_code'] ?? null)
                    ? $validated['batch_code']
                    : $lockedProduct->sku.'-'.now()->format('ymdHis').'-'.strtoupper(Str::random(4)),
                'quantity_received' => (int) $validated['quantity'],
                'quantity_available' => (int) $validated['quantity'],
                'expiration_date' => $validated['expiration_date'],
                'original_price' => $lockedProduct->original_price,
                'discount_price' => null,
                'status' => 'active',
                'source' => 'manual',
            ]);
            $inventoryService->refreshProductStockSummary($lockedProduct->id);
        });

        $product->refresh()->load('category');
        $evaluation = $inventoryService->evaluateProductBatches($product);
        $actions = collect($evaluation['results']);
        $pendingDonation = $actions->contains(fn (array $result): bool => in_array($result['action'] ?? null, ['awaiting_donation_approval', 'donation_prealert_sent'], true));
        $pendingDiscount = $actions->contains(fn (array $result): bool => ($result['action'] ?? null) === 'awaiting_discount_approval');

        $message = 'Batch baru berhasil ditambahkan dan tetap dipisah dari batch dengan tanggal kedaluwarsa lain.';
        if ($pendingDonation) {
            $message .= ' Batch mendekati kedaluwarsa dikirim untuk persetujuan manager/kasir melalui Telegram.';
        } elseif ($pendingDiscount) {
            $message .= ' Rekomendasi diskon dikirim ke manager/kasir untuk persetujuan.';
        }

        return to_route('inventory.index')
            ->with('success', $message);
    }

    /**
     * Show the form for creating a new inventory product.
     */
    public function create(): Response
    {
        $categories = Category::query()->select('id', 'name', 'slug')->orderBy('name')->get();

        return Inertia::render('Inventory/Form', [
            'product' => null,
            'categories' => $categories,
        ]);
    }

    /**
     * Store a newly created product in storage.
     */
    public function store(StoreProductRequest $request, InventoryService $inventoryService): RedirectResponse
    {
        $validated = $request->validated();
        $batches = $validated['batches'] ?? [[
            'quantity' => $validated['quantity'],
            'expiration_date' => $validated['expiration_date'],
        ]];
        unset($validated['batches']);

        $validated['quantity'] = collect($batches)->sum('quantity');
        $validated['expiration_date'] = collect($batches)->min('expiration_date');
        $validated['status'] = 'active';
        $validated['discount_price'] = null;

        $product = DB::transaction(function () use ($validated, $batches): Product {
            $product = Product::query()->create($validated);

            foreach ($batches as $index => $batch) {
                $quantity = (int) $batch['quantity'];
                $product->batches()->create([
                    'batch_code' => ($batch['batch_code'] ?? null) ?: $product->sku.'-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    'quantity_received' => $quantity,
                    'quantity_available' => $quantity,
                    'expiration_date' => $batch['expiration_date'],
                    'original_price' => $product->original_price,
                    'status' => 'active',
                    'source' => 'manual',
                ]);
            }

            return $product;
        });

        $evaluation = $inventoryService->evaluateProductBatches($product->load('category'));
        $donationPending = collect($evaluation['results'])->contains(fn (array $result): bool => in_array($result['action'] ?? null, ['awaiting_donation_approval', 'donation_prealert_sent'], true));
        $donationNotificationUnavailable = collect($evaluation['results'])->contains(fn (array $result): bool => in_array($result['action'] ?? null, ['donation_approval_unavailable', 'donation_prealert_unavailable'], true));

        return redirect()->route('inventory.index')
            ->with('success', $donationPending
                ? 'Product saved. Near-expiry batches are awaiting manager Telegram donation approval; stock has not changed yet.'
                : ($donationNotificationUnavailable
                    ? 'Product saved, but a donation Telegram approval could not be created. Link a manager Telegram account and verify an active foundation partner; stock is unchanged.'
                    : 'Product saved. Eligible discount batches are awaiting manager or cashier approval.'));
    }

    /**
     * Show the form for editing an existing product.
     */
    public function edit(Product $product): Response
    {
        $product->loadCount('batches')
            ->loadSum('batches', 'quantity_donated')
            ->loadSum('batches', 'quantity_sold');
        $stockLocked = $product->batches_count > 1
            || ((int) $product->batches_quantity_donated + (int) $product->batches_quantity_sold) > 0;
        $categories = Category::query()->select('id', 'name', 'slug')->orderBy('name')->get();

        return Inertia::render('Inventory/Form', [
            'product' => [
                'id' => $product->id,
                'category_id' => $product->category_id,
                'sku' => $product->sku,
                'name' => $product->name,
                'description' => $product->description,
                'quantity' => $product->quantity,
                'unit' => $product->unit,
                'original_price' => (float) $product->original_price,
                'discount_price' => $product->discount_price !== null ? (float) $product->discount_price : null,
                'expiration_date' => $product->expiration_date->toDateString(),
                'status' => $product->status,
                'batch_count' => $product->batches_count,
                'stock_locked' => $stockLocked,
            ],
            'categories' => $categories,
        ]);
    }

    /**
     * Update the specified product in storage.
     */
    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $validated = $request->validated();
        $batches = $product->batches()->get();

        $stockHasMovement = $batches->sum('quantity_donated') > 0 || $batches->sum('quantity_sold') > 0;

        if ($batches->count() > 1 || $stockHasMovement) {
            unset($validated['quantity'], $validated['expiration_date'], $validated['status'], $validated['discount_price']);
            $product->update($validated);
            if (isset($validated['original_price'])) {
                $product->batches()->where('quantity_available', '>', 0)->update([
                    'original_price' => $validated['original_price'],
                ]);
            }
        } else {
            $product->update($validated);
            if ($batches->count() === 1) {
                $batches->first()->update([
                    'quantity_received' => $validated['quantity'],
                    'quantity_available' => $validated['quantity'],
                    'expiration_date' => $validated['expiration_date'],
                    'original_price' => $validated['original_price'],
                    'discount_price' => $validated['discount_price'],
                    'status' => $validated['status'],
                ]);
            }
        }

        return redirect()->route('inventory.index')
            ->with('success', 'Product updated successfully.');
    }

    /**
     * Remove the specified product from storage.
     */
    public function destroy(Product $product): RedirectResponse
    {
        $productName = $product->name;
        $product->delete();

        return redirect()->route('inventory.index')
            ->with('success', "Product '{$productName}' deleted successfully.");
    }

    /**
     * Apply a manual markdown discount to a product.
     */
    public function applyDiscount(Request $request, Product $product, InventoryService $inventoryService): RedirectResponse
    {
        $validated = $request->validate([
            'discount_percentage' => ['required', 'integer', 'min:1', 'max:50'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = $validated['reason'] ?? 'Manual clearance discount applied by inventory manager.';
        $approvals = $inventoryService->requestDiscountApproval($product->id, (int) $validated['discount_percentage'], $reason);

        return redirect()->back()
            ->with('success', "Sent {$validated['discount_percentage']}% discount recommendation for '{$product->name}' to Telegram for approval (".count($approvals).' batch(es)).');
    }

    /**
     * Reserve a product for donation to a charitable partner.
     */
    public function reserveDonation(Request $request, Product $product, InventoryService $inventoryService): RedirectResponse
    {
        $validated = $request->validate([
            'partner_id' => ['required', 'integer', 'exists:donation_partners,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $partner = DonationPartner::findOrFail($validated['partner_id']);
        $reason = $validated['reason'] ?? "Manual donation reserved for {$partner->name}.";
        $inventoryService->reserveForDonation($product->id, (int) $partner->id, $reason);

        return redirect()->back()
            ->with('success', "Product '{$product->name}' reserved for donation to {$partner->name}.");
    }

    public function toggleAutoSync(Request $request, CentralInventorySyncService $sync): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $settings = $sync->setAutoSyncEnabled((bool) $validated['enabled']);
        $message = $settings->auto_sync_enabled
            ? 'Auto Sync enabled. Central inventory will update every 5 minutes while the scheduler is running.'
            : 'Auto Sync paused. Manual product entry and Sync Now remain available.';

        return redirect()->route('inventory.index')->with('success', $message);
    }

    public function syncNow(CentralInventorySyncService $sync): RedirectResponse
    {
        $result = $sync->synchronizeForDemo();

        if (($result['demo_donation_error'] ?? null) !== null) {
            return redirect()->route('inventory.sync-history')->with(
                'error',
                sprintf(
                    'Sync Now updated %d products, but only %d of %d urgent products received manager Telegram approval requests. %s',
                    $result['changed_products'],
                    $result['demo_pending_products'] ?? 0,
                    $result['demo_expected_products'],
                    $result['demo_donation_error']
                )
            );
        }

        return redirect()->route('inventory.sync-history')->with(
            'success',
            sprintf(
                'Sync Now completed: %d new products, %d products updated, %d units received, %d units sold, %d discount recommendations pending, %d donation pre-alerts for products expiring in 3 days, and %d urgent products await Telegram approval (%d approval buttons). No donation stock changed before confirmation.',
                $result['new_products'],
                $result['changed_products'],
                $result['received_units'],
                $result['sold_units'],
                $result['discount_recommendations'],
                $result['demo_prealert_products'],
                $result['demo_pending_products'],
                $result['demo_approval_actions']
            )
        );
    }
}
