<?php

namespace App\Console\Commands;

use App\Models\AgentLog;
use App\Models\Product;
use App\Services\AiAgentService;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class ScanInventoryCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ecoloop:scan-inventory 
                            {--days=7 : Number of days ahead to scan for expiring items}
                            {--dry-run : Evaluate decisions without mutating product statuses}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Autonomous AI inventory evaluation for food waste prevention and clearance markdown';

    /**
     * Execute the console command.
     */
    public function handle(AiAgentService $aiAgentService, InventoryService $inventoryService): int
    {
        $days = (int) $this->option('days');
        $isDryRun = (bool) $this->option('dry-run');

        $this->components->info("Starting EcoLoop Autonomous AI Inventory Scan (Horizon: {$days} days)...");

        $today = now()->startOfDay();
        $targetDate = now()->addDays($days)->toDateString();

        $products = Product::query()
            ->with(['category:id,name'])
            ->whereIn('status', ['active', 'discounted'])
            ->whereDate('expiration_date', '>=', $today->toDateString())
            ->whereDate('expiration_date', '<=', $targetDate)
            ->orderBy('expiration_date', 'asc')
            ->get();

        if ($products->isEmpty()) {
            $this->components->warn('No expiring products found within the specified time window.');

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Discovered %d product(s) requiring shelf-life evaluation.', $products->count()));

        $tableRows = [];
        $discountsApplied = 0;
        $donationApprovalRequests = 0;
        $noActionCount = 0;

        foreach ($products as $product) {
            $expDate = Carbon::parse($product->expiration_date)->startOfDay();
            $daysRemaining = (int) $today->diffInDays($expDate, false);

            try {
                $decision = $aiAgentService->evaluateProduct($product);
                $action = $decision['action'];
                $provider = $decision['provider'];
                $reasoning = $decision['reasoning'];

                if ($isDryRun) {
                    // Dry run: write audit alert log without mutating product
                    AgentLog::create([
                        'product_id' => $product->id,
                        'action_type' => 'alert_sent',
                        'reasoning' => "[Dry Run / {$provider}] {$reasoning}",
                        'payload' => [
                            'product_sku' => $product->sku,
                            'days_remaining' => $daysRemaining,
                            'decision' => $decision,
                            'dry_run' => true,
                        ],
                        'status' => 'success',
                    ]);

                    $tableRows[] = [
                        $product->sku,
                        $product->name,
                        "{$daysRemaining}d",
                        strtoupper($action),
                        $provider,
                        'Dry Run Logged',
                    ];

                    continue;
                }

                // Production execution: Apply decision and record to agent_logs
                if ($action === 'discount') {
                    $pct = (float) ($decision['discount_percentage'] ?: 30.0);
                    $inventoryService->applyDiscount($product->id, $pct, "[AI / {$provider}] {$reasoning}");
                    $discountsApplied++;

                    $tableRows[] = [
                        $product->sku,
                        $product->name,
                        "{$daysRemaining}d",
                        "DISCOUNT ({$pct}%)",
                        $provider,
                        'Applied',
                    ];
                } elseif ($action === 'donate') {
                    $approvalRequests = app(ExpiringDonationApprovalService::class)->createRequestsForProduct($product);
                    $donationApprovalRequests += $approvalRequests;
                    $tableRows[] = [
                        $product->sku,
                        $product->name,
                        "{$daysRemaining}d",
                        'DONATE',
                        $provider,
                        $approvalRequests > 0 ? 'Waiting for manager Telegram approval' : 'Already pending or no Telegram recipient',
                    ];
                } else {
                    // No action needed
                    AgentLog::create([
                        'product_id' => $product->id,
                        'action_type' => 'alert_sent',
                        'reasoning' => "[AI / {$provider}] {$reasoning}",
                        'payload' => [
                            'product_sku' => $product->sku,
                            'days_remaining' => $daysRemaining,
                            'decision' => $decision,
                        ],
                        'status' => 'success',
                    ]);

                    $noActionCount++;

                    $tableRows[] = [
                        $product->sku,
                        $product->name,
                        "{$daysRemaining}d",
                        'NONE',
                        $provider,
                        'Safe',
                    ];
                }
            } catch (Throwable $e) {
                $this->components->error("Error evaluating {$product->sku}: {$e->getMessage()}");

                AgentLog::create([
                    'product_id' => $product->id,
                    'action_type' => 'alert_sent',
                    'reasoning' => "Failed to evaluate product: {$e->getMessage()}",
                    'payload' => [
                        'product_sku' => $product->sku,
                        'error' => $e->getMessage(),
                    ],
                    'status' => 'failed',
                ]);
            }
        }

        $this->table(
            ['SKU', 'Product', 'Shelf Life', 'Decision', 'AI Provider', 'Outcome'],
            $tableRows
        );

        $this->components->twoColumnDetail('Discount Clearances Executed', (string) $discountsApplied);
        $this->components->twoColumnDetail('Donation Approval Requests Created', (string) $donationApprovalRequests);
        $this->components->twoColumnDetail('Safe Products Monitored', (string) $noActionCount);
        $this->components->info('EcoLoop inventory scan completed successfully.');

        return self::SUCCESS;
    }
}
