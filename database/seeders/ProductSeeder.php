<?php

namespace Database\Seeders;

use App\Models\AgentLog;
use App\Models\Category;
use App\Models\DonationPartner;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create Categories
        $categoriesData = [
            ['name' => 'Fresh Produce', 'slug' => 'fresh-produce'],
            ['name' => 'Bakery', 'slug' => 'bakery'],
            ['name' => 'Dairy & Eggs', 'slug' => 'dairy-eggs'],
            ['name' => 'Packaged Goods', 'slug' => 'packaged-goods'],
            ['name' => 'Meat & Seafood', 'slug' => 'meat-seafood'],
        ];

        $categories = [];
        foreach ($categoriesData as $cat) {
            $categories[$cat['slug']] = Category::firstOrCreate(
                ['slug' => $cat['slug']],
                ['name' => $cat['name']]
            );
        }

        // 2. Define Products across expiration tiers
        $productsData = [
            // Tier 1: Expiring in 1-2 days
            [
                'category_slug' => 'dairy-eggs',
                'sku' => 'ECL-DRY-1001',
                'name' => 'Organic Whole Milk 1L',
                'description' => 'Fresh pasteurized organic milk from local dairy farms.',
                'quantity' => 18,
                'unit' => 'pcs',
                'original_price' => 4.50,
                'discount_price' => 2.25,
                'expiration_date' => now()->addDays(1)->toDateString(),
                'status' => 'discounted',
                'agent_action' => [
                    'action_type' => 'discount_applied',
                    'reasoning' => 'Gemini AI Agent detected shelf-life < 36h. Applied 50% dynamic markdown to accelerate consumer velocity before waste threshold.',
                    'payload' => ['original_price' => 4.50, 'discount_price' => 2.25, 'markdown_pct' => 50, 'rule' => 'expiry_lt_36h'],
                    'status' => 'success',
                ],
            ],
            [
                'category_slug' => 'bakery',
                'sku' => 'ECL-BAK-2001',
                'name' => 'Artisan Sourdough Loaf',
                'description' => 'Naturally fermented sourdough baked fresh yesterday morning.',
                'quantity' => 12,
                'unit' => 'pcs',
                'original_price' => 6.00,
                'discount_price' => 3.00,
                'expiration_date' => now()->addDays(1)->toDateString(),
                'status' => 'discounted',
                'agent_action' => [
                    'action_type' => 'discount_applied',
                    'reasoning' => 'Automated clearance rule: Artisan bakery item entering final 24 hours marked down by 50%.',
                    'payload' => ['original_price' => 6.00, 'discount_price' => 3.00, 'markdown_pct' => 50, 'rule' => 'bakery_evening_markdown'],
                    'status' => 'success',
                ],
            ],
            [
                'category_slug' => 'fresh-produce',
                'sku' => 'ECL-PRD-3001',
                'name' => 'Ripe Hass Avocados',
                'description' => 'Ready-to-eat Hass avocados, rich and creamy texture.',
                'quantity' => 25,
                'unit' => 'kg',
                'original_price' => 5.20,
                'discount_price' => 3.12,
                'expiration_date' => now()->addDays(2)->toDateString(),
                'status' => 'discounted',
                'agent_action' => [
                    'action_type' => 'discount_applied',
                    'reasoning' => 'Ripeness index 4/5 reached. 40% promotional markdown recommended to prevent spoilage.',
                    'payload' => ['original_price' => 5.20, 'discount_price' => 3.12, 'markdown_pct' => 40, 'rule' => 'ripeness_spoilage_risk'],
                    'status' => 'success',
                ],
            ],
            [
                'category_slug' => 'meat-seafood',
                'sku' => 'ECL-MEA-4001',
                'name' => 'Fresh Atlantic Salmon Fillet',
                'description' => 'Responsibly farmed Norwegian salmon portioned fillets.',
                'quantity' => 8,
                'unit' => 'kg',
                'original_price' => 14.50,
                'discount_price' => null,
                'expiration_date' => now()->addDays(1)->toDateString(),
                'status' => 'reserved_donation',
                'agent_action' => [
                    'action_type' => 'donation_requested',
                    'reasoning' => 'High-protein item expiring within 24 hours reserved for immediate cold-chain pickup by Community Kitchen.',
                    'payload' => ['partner' => 'Community Kitchen & Shelter', 'pickup_deadline' => now()->addHours(12)->toIso8601String(), 'quantity_kg' => 8],
                    'status' => 'success',
                ],
            ],
            [
                'category_slug' => 'bakery',
                'sku' => 'ECL-BAK-2002',
                'name' => 'French Baguette Traditiion',
                'description' => 'Crisp crust traditional French baguette.',
                'quantity' => 15,
                'unit' => 'pcs',
                'original_price' => 3.50,
                'discount_price' => 1.75,
                'expiration_date' => now()->addDays(1)->toDateString(),
                'status' => 'discounted',
                'agent_action' => null,
            ],
            [
                'category_slug' => 'fresh-produce',
                'sku' => 'ECL-PRD-3002',
                'name' => 'Baby Spinach Clamshell 200g',
                'description' => 'Triple-washed organic tender baby spinach leaves.',
                'quantity' => 14,
                'unit' => 'packs',
                'original_price' => 4.00,
                'discount_price' => null,
                'expiration_date' => now()->addDays(2)->toDateString(),
                'status' => 'active',
                'agent_action' => [
                    'action_type' => 'alert_sent',
                    'reasoning' => 'Early warning: Baby spinach inventory volume is 14 packs with 48h shelf life remaining. Monitor for markdown at 18:00.',
                    'payload' => ['shelf_life_remaining_hours' => 48, 'projected_waste_risk' => 'medium'],
                    'status' => 'success',
                ],
            ],

            // Tier 2: Expiring in 3-5 days
            [
                'category_slug' => 'dairy-eggs',
                'sku' => 'ECL-DRY-1002',
                'name' => 'Greek Plain Yogurt 500g',
                'description' => 'Authentic strained thick Greek yogurt, 10% milk fat.',
                'quantity' => 22,
                'unit' => 'pcs',
                'original_price' => 4.80,
                'discount_price' => null,
                'expiration_date' => now()->addDays(3)->toDateString(),
                'status' => 'active',
                'agent_action' => null,
            ],
            [
                'category_slug' => 'fresh-produce',
                'sku' => 'ECL-PRD-3003',
                'name' => 'Sweet Strawberries 250g',
                'description' => 'Local greenhouse sweet red strawberries.',
                'quantity' => 30,
                'unit' => 'packs',
                'original_price' => 4.20,
                'discount_price' => null,
                'expiration_date' => now()->addDays(3)->toDateString(),
                'status' => 'active',
                'agent_action' => null,
            ],
            [
                'category_slug' => 'bakery',
                'sku' => 'ECL-BAK-2003',
                'name' => 'Sliced Multigrain Bread 600g',
                'description' => 'Hearty 7-grain sliced sandwich loaf with flax and chia seeds.',
                'quantity' => 20,
                'unit' => 'pcs',
                'original_price' => 4.50,
                'discount_price' => null,
                'expiration_date' => now()->addDays(4)->toDateString(),
                'status' => 'active',
                'agent_action' => null,
            ],
            [
                'category_slug' => 'meat-seafood',
                'sku' => 'ECL-MEA-4002',
                'name' => 'Grass-Fed Minced Beef 500g',
                'description' => 'Lean 85/15 ground beef from pasture-raised cattle.',
                'quantity' => 15,
                'unit' => 'packs',
                'original_price' => 9.80,
                'discount_price' => null,
                'expiration_date' => now()->addDays(4)->toDateString(),
                'status' => 'active',
                'agent_action' => null,
            ],
            [
                'category_slug' => 'dairy-eggs',
                'sku' => 'ECL-DRY-1003',
                'name' => 'Free-Range Brown Eggs (12pk)',
                'description' => 'Grade A large free-range fresh farm eggs.',
                'quantity' => 40,
                'unit' => 'packs',
                'original_price' => 5.50,
                'discount_price' => null,
                'expiration_date' => now()->addDays(5)->toDateString(),
                'status' => 'active',
                'agent_action' => null,
            ],
            [
                'category_slug' => 'fresh-produce',
                'sku' => 'ECL-PRD-3004',
                'name' => 'Sweet Cherry Tomatoes 500g',
                'description' => 'Vine-ripened sweet bite-sized cherry tomatoes.',
                'quantity' => 28,
                'unit' => 'packs',
                'original_price' => 3.80,
                'discount_price' => null,
                'expiration_date' => now()->addDays(4)->toDateString(),
                'status' => 'active',
                'agent_action' => null,
            ],

            // Tier 3: Expiring in > 7 days
            [
                'category_slug' => 'dairy-eggs',
                'sku' => 'ECL-DRY-1004',
                'name' => 'Aged Cheddar Cheese Block 250g',
                'description' => 'Sharp vintage cheddar aged for 12 months.',
                'quantity' => 24,
                'unit' => 'pcs',
                'original_price' => 7.20,
                'discount_price' => null,
                'expiration_date' => now()->addDays(14)->toDateString(),
                'status' => 'active',
                'agent_action' => null,
            ],
            [
                'category_slug' => 'fresh-produce',
                'sku' => 'ECL-PRD-3005',
                'name' => 'Crisp Honeycrisp Apples 1kg',
                'description' => 'Premium orchard crisp and juicy sweet apples.',
                'quantity' => 45,
                'unit' => 'kg',
                'original_price' => 4.90,
                'discount_price' => null,
                'expiration_date' => now()->addDays(10)->toDateString(),
                'status' => 'active',
                'agent_action' => null,
            ],
            [
                'category_slug' => 'packaged-goods',
                'sku' => 'ECL-PKG-5001',
                'name' => 'Extra Virgin Olive Oil 750ml',
                'description' => 'First cold pressed Mediterranean extra virgin olive oil.',
                'quantity' => 35,
                'unit' => 'pcs',
                'original_price' => 12.00,
                'discount_price' => null,
                'expiration_date' => now()->addDays(60)->toDateString(),
                'status' => 'active',
                'agent_action' => null,
            ],
            [
                'category_slug' => 'packaged-goods',
                'sku' => 'ECL-PKG-5002',
                'name' => 'Organic Rolled Oats 1kg',
                'description' => 'Whole grain non-GMO gluten-free rolled breakfast oats.',
                'quantity' => 50,
                'unit' => 'packs',
                'original_price' => 5.00,
                'discount_price' => null,
                'expiration_date' => now()->addDays(90)->toDateString(),
                'status' => 'active',
                'agent_action' => null,
            ],
            [
                'category_slug' => 'packaged-goods',
                'sku' => 'ECL-PKG-5003',
                'name' => 'Canned San Marzano Tomatoes 400g',
                'description' => 'D.O.P. certified whole peeled Italian plum tomatoes.',
                'quantity' => 120,
                'unit' => 'pcs',
                'original_price' => 2.50,
                'discount_price' => null,
                'expiration_date' => now()->addDays(180)->toDateString(),
                'status' => 'active',
                'agent_action' => null,
            ],
            [
                'category_slug' => 'packaged-goods',
                'sku' => 'ECL-PKG-5004',
                'name' => 'Organic Brown Rice 2kg',
                'description' => 'Long grain organic jasmine brown rice.',
                'quantity' => 40,
                'unit' => 'packs',
                'original_price' => 6.80,
                'discount_price' => null,
                'expiration_date' => now()->addDays(120)->toDateString(),
                'status' => 'active',
                'agent_action' => null,
            ],
            // Historic / Completed donation
            [
                'category_slug' => 'bakery',
                'sku' => 'ECL-BAK-2004',
                'name' => 'All-Butter Croissants (6pk)',
                'description' => 'Flaky French butter croissants packaged in tray.',
                'quantity' => 10,
                'unit' => 'packs',
                'original_price' => 6.50,
                'discount_price' => null,
                'expiration_date' => now()->subDay()->toDateString(),
                'status' => 'donated',
                'agent_action' => [
                    'action_type' => 'donation_requested',
                    'reasoning' => 'End of day surplus dispatched to Orphanage Care Haven. Confirmed delivery at 20:30.',
                    'payload' => ['recipient' => 'Orphanage Care Haven', 'delivered_at' => now()->subDay()->toIso8601String(), 'status' => 'completed'],
                    'status' => 'success',
                ],
            ],
        ];

        foreach ($productsData as $data) {
            $category = $categories[$data['category_slug']];
            $agentAction = $data['agent_action'];
            unset($data['category_slug'], $data['agent_action']);

            $product = Product::updateOrCreate(
                ['sku' => $data['sku']],
                array_merge($data, ['category_id' => $category->id])
            );

            if ($agentAction) {
                $partnerId = null;
                if (!empty($agentAction['payload']['partner_id'])) {
                    $partnerId = $agentAction['payload']['partner_id'];
                } elseif (!empty($agentAction['payload']['recipient'])) {
                    $partnerId = DonationPartner::where('name', $agentAction['payload']['recipient'])->value('id');
                }

                AgentLog::create(array_merge($agentAction, [
                    'product_id' => $product->id,
                    'partner_id' => $partnerId,
                ]));
            }
        }
    }
}
