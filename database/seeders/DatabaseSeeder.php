<?php

namespace Database\Seeders;

use App\Models\AgentLog;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Core Domain Seeders
        $this->call([
            ManagerAccountSeeder::class,
            DonationPartnerSeeder::class,
            ProductSeeder::class,
        ]);

        // General System Agent Log
        AgentLog::create([
            'product_id' => null,
            'action_type' => 'alert_sent',
            'reasoning' => 'EcoLoop AI Daemon completed daily inventory health assessment. 19 items monitored, 4 markdowns active, 1 donation dispatch pending.',
            'payload' => [
                'event' => 'daily_health_check',
                'total_monitored' => 19,
                'status' => 'optimal',
                'timestamp' => now()->toIso8601String(),
            ],
            'status' => 'success',
        ]);
    }
}
