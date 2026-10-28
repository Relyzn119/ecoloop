<?php

namespace Database\Factories;

use App\Models\InventorySyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventorySyncRun>
 */
class InventorySyncRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trigger_type' => fake()->randomElement(['auto', 'sync_now', 'manual_cli']),
            'status' => 'completed',
            'started_at' => now()->subMinutes(fake()->numberBetween(1, 30)),
            'completed_at' => now(),
            'summary' => [
                'new_products' => fake()->numberBetween(0, 2),
                'received_units' => fake()->numberBetween(10, 50),
                'sold_units' => fake()->numberBetween(0, 8),
                'donated_units' => 0,
                'discount_recommendations' => 0,
            ],
            'details' => [
                'events' => [
                    'received' => [],
                    'sold' => [],
                    'demo_received' => [],
                    'donated' => [],
                ],
                'updated_products' => [],
                'discount_recommendations' => [],
            ],
        ];
    }
}
