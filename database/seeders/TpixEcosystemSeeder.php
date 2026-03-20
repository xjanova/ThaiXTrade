<?php

namespace Database\Seeders;

use App\Models\CarbonProject;
use App\Models\SalePhase;
use App\Models\TokenSale;
use Illuminate\Database\Seeder;

/**
 * TPIX Ecosystem Seeder
 * สร้างข้อมูลเริ่มต้นสำหรับ ICO Token Sale + Carbon Credit Projects
 * Developed by Xman Studio.
 */
class TpixEcosystemSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedTokenSale();
        $this->seedCarbonProjects();
    }

    /**
     * สร้าง ICO Token Sale พร้อม 3 Phases.
     */
    private function seedTokenSale(): void
    {
        if (TokenSale::count() > 0) {
            $this->command->info('Token Sale already exists, skipping.');

            return;
        }

        $sale = TokenSale::create([
            'name' => 'TPIX Public Sale',
            'slug' => 'tpix-public-sale',
            'description' => 'Official TPIX token sale — ซื้อเหรียญ TPIX ในราคาพิเศษก่อนลิสต์ตลาด',
            'total_supply_for_sale' => 700000000,
            'total_sold' => 0,
            'total_raised_usd' => 0,
            'accept_currencies' => ['USDT', 'STRIPE'],
            'accept_chain_id' => 56,
            'sale_wallet_address' => '0xF1CD82550E1145664a86f238AcC8AC67D0d68B4f',
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonths(6),
        ]);

        // Phase 1: Private Sale — กำลังขายอยู่
        SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Private Sale',
            'slug' => 'private-sale',
            'phase_order' => 1,
            'price_usd' => 0.05,
            'allocation' => 200000000,
            'sold' => 0,
            'min_purchase' => 100,
            'max_purchase' => 10000000,
            'vesting_cliff_days' => 30,
            'vesting_duration_days' => 180,
            'vesting_tge_percent' => 20,
            'whitelist_only' => false,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonths(2),
        ]);

        // Phase 2: Pre-Sale — ยังไม่เริ่ม
        SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Pre-Sale',
            'slug' => 'pre-sale',
            'phase_order' => 2,
            'price_usd' => 0.08,
            'allocation' => 300000000,
            'sold' => 0,
            'min_purchase' => 50,
            'max_purchase' => 5000000,
            'vesting_cliff_days' => 14,
            'vesting_duration_days' => 120,
            'vesting_tge_percent' => 30,
            'whitelist_only' => false,
            'status' => 'upcoming',
            'starts_at' => now()->addMonths(2),
            'ends_at' => now()->addMonths(4),
        ]);

        // Phase 3: Public Sale — ยังไม่เริ่ม
        SalePhase::create([
            'token_sale_id' => $sale->id,
            'name' => 'Public Sale',
            'slug' => 'public-sale',
            'phase_order' => 3,
            'price_usd' => 0.10,
            'allocation' => 200000000,
            'sold' => 0,
            'min_purchase' => 10,
            'max_purchase' => 1000000,
            'vesting_cliff_days' => 0,
            'vesting_duration_days' => 90,
            'vesting_tge_percent' => 50,
            'whitelist_only' => false,
            'status' => 'upcoming',
            'starts_at' => now()->addMonths(4),
            'ends_at' => now()->addMonths(6),
        ]);

        $this->command->info('✅ Token Sale created: '.$sale->name.' with 3 phases');
    }

    /**
     * สร้าง Carbon Credit Projects ตัวอย่าง.
     */
    private function seedCarbonProjects(): void
    {
        if (CarbonProject::count() > 0) {
            $this->command->info('Carbon Projects already exist, skipping.');

            return;
        }

        $projects = [
            [
                'name' => 'Thailand Northern Reforestation',
                'slug' => 'thailand-northern-reforestation',
                'description' => 'โครงการปลูกป่าภาคเหนือ ครอบคลุม 5,000 ไร่ ในเชียงใหม่ เชียงราย ลำพูน',
                'location' => 'Chiang Mai, Chiang Rai, Lamphun',
                'country' => 'TH',
                'project_type' => 'reforestation',
                'standard' => 'VCS',
                'total_credits' => 50000,
                'available_credits' => 50000,
                'retired_credits' => 0,
                'price_per_credit_usd' => 15.00,
                'price_per_credit_tpix' => 150.00,
                'status' => 'active',
                'is_featured' => true,
            ],
            [
                'name' => 'ASEAN Solar Energy Initiative',
                'slug' => 'asean-solar-energy',
                'description' => 'โครงการพลังงานแสงอาทิตย์ในภูมิภาคอาเซียน ลดการปล่อย CO2 จากโรงไฟฟ้าถ่านหิน',
                'location' => 'Bangkok, Ho Chi Minh City, Jakarta',
                'country' => 'TH',
                'project_type' => 'renewable_energy',
                'standard' => 'Gold Standard',
                'total_credits' => 100000,
                'available_credits' => 100000,
                'retired_credits' => 0,
                'price_per_credit_usd' => 12.50,
                'price_per_credit_tpix' => 125.00,
                'status' => 'active',
                'is_featured' => true,
            ],
            [
                'name' => 'Smart Farm Carbon Offset',
                'slug' => 'smart-farm-carbon-offset',
                'description' => 'ฟาร์มอัจฉริยะที่ใช้ IoT ลดการปล่อยก๊าซเรือนกระจกจากภาคเกษตร',
                'location' => 'Nakhon Ratchasima, Khon Kaen',
                'country' => 'TH',
                'project_type' => 'other',
                'standard' => 'VCS',
                'total_credits' => 25000,
                'available_credits' => 25000,
                'retired_credits' => 0,
                'price_per_credit_usd' => 10.00,
                'price_per_credit_tpix' => 100.00,
                'status' => 'active',
                'is_featured' => false,
            ],
        ];

        foreach ($projects as $project) {
            CarbonProject::create($project);
        }

        $this->command->info('✅ Created '.count($projects).' Carbon Credit projects');
    }
}
