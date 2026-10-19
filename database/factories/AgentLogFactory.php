<?php

namespace Database\Factories;

use App\Models\AgentLog;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentLog>
 */
class AgentLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'action_type' => fake()->randomElement(['discount_applied', 'donation_requested', 'alert_sent', 'manual_override']),
            'reasoning' => fake()->paragraph(),
            'payload' => [
                'rule' => 'expiry_threshold_48h',
                'confidence' => 0.94,
                'evaluated_at' => now()->toIso8601String(),
            ],
            'status' => 'success',
        ];
    }
}
