<?php

namespace Database\Seeders;

use App\Models\DonationPartner;
use Illuminate\Database\Seeder;

class DonationPartnerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $partners = [
            [
                'name' => 'Metro Harvest Food Bank',
                'contact_person' => 'Sarah Jenkins',
                'phone' => '+1 (555) 234-5678',
                'email' => 'logistics@metroharvest.org',
                'address' => '450 Industrial Parkway, Warehouse 4B, Metro City',
                'is_active' => true,
            ],
            [
                'name' => 'Orphanage Care Haven',
                'contact_person' => 'David Morales',
                'phone' => '+1 (555) 876-5432',
                'email' => 'pantry@carehaven.org',
                'address' => '128 Hope Street, Greenview District',
                'is_active' => true,
            ],
            [
                'name' => 'Community Kitchen & Shelter',
                'contact_person' => 'Elena Rostova',
                'phone' => '+1 (555) 345-6789',
                'email' => 'chef.elena@communitykitchen.org',
                'address' => '79 Central Avenue, Downtown Core',
                'is_active' => true,
            ],
            [
                'name' => 'Second Harvest Animal Sanctuary',
                'contact_person' => 'Markus Thorne',
                'phone' => '+1 (555) 901-2345',
                'email' => 'feed@secondharvestsanctuary.org',
                'address' => '880 Country Road 12, Rural Valley',
                'is_active' => false, // Inactive example to test filtering
            ],
        ];

        foreach ($partners as $partner) {
            DonationPartner::updateOrCreate(
                ['email' => $partner['email']],
                $partner
            );
        }
    }
}
