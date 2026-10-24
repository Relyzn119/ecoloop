<?php

namespace App\Console\Commands;

use App\Services\CentralInventorySyncService;
use Illuminate\Console\Command;

class SyncCentralInventory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ecoloop:sync-central-inventory {--force : Run even when Auto Sync is paused}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulate central supermarket product receipts and point-of-sale stock changes';

    /**
     * Execute the console command.
     */
    public function handle(CentralInventorySyncService $sync): int
    {
        $result = $sync->synchronize((bool) $this->option('force'));

        if ($result['skipped']) {
            $this->components->info($result['reason']);

            return self::SUCCESS;
        }

        $this->table(
            ['Sync metric', 'Result'],
            [
                ['New products', $result['new_products']],
                ['New batches', $result['new_batches']],
                ['Units received', $result['received_units']],
                ['POS sales transactions', $result['sales_transactions']],
                ['Units sold (stock reduced)', $result['sold_units']],
                ['Products updated', $result['changed_products']],
                ['Synced at', $result['synced_at']],
            ]
        );

        return self::SUCCESS;
    }
}
