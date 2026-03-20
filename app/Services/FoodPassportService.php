<?php

namespace App\Services;

use App\Models\FoodCertificate;
use App\Models\FoodProduct;
use App\Models\FoodTrace;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * FoodPassportService — Business logic สำหรับระบบตรวจสอบที่มาอาหาร
 *
 * Flow เหมือนกดตู้น้ำ:
 * 1. registerProduct() → ลงทะเบียนสินค้า → ได้ Product ID
 * 2. addTrace()        → บันทึก IoT data ทุกจุด (farm → retail)
 * 3. mintCertificate() → ออกใบรับรอง NFT เมื่อผ่านครบ
 * 4. verifyProduct()   → ผู้บริโภคสแกน QR ดูข้อมูลทั้งหมด
 */
class FoodPassportService
{
    // ═══════════════════════════════════════════
    //  STEP 1: ลงทะเบียนสินค้า
    // ═══════════════════════════════════════════

    public function registerProduct(array $data): FoodProduct
    {
        return FoodProduct::create([
            'name' => $data['name'],
            'category' => $data['category'],
            'origin' => $data['origin'],
            'producer_address' => strtolower($data['producer_address']),
            'producer_name' => $data['producer_name'] ?? null,
            'batch_number' => $data['batch_number'] ?? $this->generateBatchNumber(),
            'description' => $data['description'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'weight_kg' => $data['weight_kg'] ?? null,
            'harvest_date' => $data['harvest_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'status' => 'registered',
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    // ═══════════════════════════════════════════
    //  STEP 2: บันทึก Trace (IoT data)
    // ═══════════════════════════════════════════

    public function addTrace(FoodProduct $product, array $data): FoodTrace
    {
        $trace = FoodTrace::create([
            'food_product_id' => $product->id,
            'iot_device_id' => $data['iot_device_id'] ?? null,
            'recorder_address' => strtolower($data['recorder_address']),
            'stage' => $data['stage'],
            'location' => $data['location'] ?? null,
            'temperature' => $data['temperature'] ?? null,
            'humidity' => $data['humidity'] ?? null,
            'weight_kg' => $data['weight_kg'] ?? null,
            'ph_level' => $data['ph_level'] ?? null,
            'sensor_data' => $data['sensor_data'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'notes' => $data['notes'] ?? null,
            'recorded_at' => $data['recorded_at'] ?? now(),
        ]);

        // อัปเดต status ตาม stage
        $statusMap = [
            'farm' => 'registered',
            'processing' => 'in_transit',
            'storage' => 'at_storage',
            'transport' => 'in_transit',
            'retail' => 'at_retail',
        ];

        if (isset($statusMap[$data['stage']])) {
            $product->update(['status' => $statusMap[$data['stage']]]);
        }

        return $trace;
    }

    // ═══════════════════════════════════════════
    //  STEP 3: Mint ใบรับรอง NFT
    // ═══════════════════════════════════════════

    public function mintCertificate(FoodProduct $product, array $data): FoodCertificate
    {
        // ต้องมี trace อย่างน้อย 2 records
        $traceCount = $product->traces()->count();
        if ($traceCount < 2) {
            throw new \RuntimeException('ต้องมีข้อมูล trace อย่างน้อย 2 จุดก่อน mint ใบรับรอง');
        }

        // สร้าง certificate data summary
        $certificateData = $this->buildCertificateData($product);

        $certificate = FoodCertificate::create([
            'food_product_id' => $product->id,
            'token_id' => $data['token_id'],
            'owner_address' => strtolower($data['owner_address']),
            'contract_address' => strtolower($data['contract_address']),
            'token_uri' => $data['token_uri'] ?? null,
            'tx_hash' => $data['tx_hash'] ?? null,
            'certificate_data' => $certificateData,
            'status' => 'active',
        ]);

        // อัปเดตสถานะสินค้า
        $product->update([
            'status' => 'certified',
        ]);

        return $certificate;
    }

    // ═══════════════════════════════════════════
    //  STEP 4: ผู้บริโภคสแกน QR ดูข้อมูล
    // ═══════════════════════════════════════════

    public function verifyProduct(int $productId): array
    {
        $product = FoodProduct::with(['traces.device', 'certificate'])->findOrFail($productId);

        return [
            'product' => $product,
            'traces' => $product->traces,
            'certificate' => $product->certificate,
            'journey' => $this->buildJourney($product),
            'is_certified' => $product->status === 'certified',
            'total_checkpoints' => $product->traces->count(),
            'stages_passed' => $product->traces->pluck('stage')->unique()->values(),
        ];
    }

    // ═══════════════════════════════════════════
    //  QUERIES
    // ═══════════════════════════════════════════

    public function getProducts(?string $category = null, ?string $status = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = FoodProduct::with('certificate')->withCount('traces')->orderByDesc('created_at');

        if ($category) {
            $query->where('category', $category);
        }
        if ($status) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    public function getProductsByProducer(string $address, int $perPage = 20): LengthAwarePaginator
    {
        return FoodProduct::byProducer($address)
            ->with('certificate')
            ->withCount('traces')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function getCertificates(int $perPage = 20): LengthAwarePaginator
    {
        return FoodCertificate::with('product')
            ->active()
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function getStats(): array
    {
        return [
            'total_products' => FoodProduct::count(),
            'certified_products' => FoodProduct::certified()->count(),
            'total_traces' => FoodTrace::count(),
            'total_certificates' => FoodCertificate::active()->count(),
            'categories' => FoodProduct::selectRaw('category, COUNT(*) as count')
                ->groupBy('category')
                ->pluck('count', 'category'),
            'stages_coverage' => FoodTrace::selectRaw('stage, COUNT(*) as count')
                ->groupBy('stage')
                ->pluck('count', 'stage'),
        ];
    }

    // ═══════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════

    private function generateBatchNumber(): string
    {
        return 'BATCH-'.date('Y').'-'.str_pad(random_int(1, 99999), 5, '0', STR_PAD_LEFT);
    }

    private function buildCertificateData(FoodProduct $product): array
    {
        $traces = $product->traces()->orderBy('recorded_at')->get();

        return [
            'product_name' => $product->name,
            'category' => $product->category,
            'origin' => $product->origin,
            'producer' => $product->producer_name ?? $product->producer_address,
            'batch_number' => $product->batch_number,
            'total_checkpoints' => $traces->count(),
            'stages' => $traces->pluck('stage')->unique()->values()->toArray(),
            'temperature_range' => [
                'min' => $traces->min('temperature'),
                'max' => $traces->max('temperature'),
            ],
            'journey_days' => $traces->count() > 1
                ? $traces->first()->recorded_at->diffInDays($traces->last()->recorded_at)
                : 0,
            'certified_at' => now()->toIso8601String(),
            'chain' => 'TPIX Chain (ID: 4289)',
        ];
    }

    private function buildJourney(FoodProduct $product): array
    {
        $stageLabels = [
            'farm' => ['name' => 'ฟาร์ม / แหล่งผลิต', 'icon' => 'seedling', 'color' => '#22C55E'],
            'processing' => ['name' => 'โรงงานแปรรูป', 'icon' => 'factory', 'color' => '#F59E0B'],
            'storage' => ['name' => 'คลังสินค้า', 'icon' => 'warehouse', 'color' => '#6366F1'],
            'transport' => ['name' => 'ขนส่ง', 'icon' => 'truck', 'color' => '#3B82F6'],
            'retail' => ['name' => 'ร้านค้า / ผู้บริโภค', 'icon' => 'store', 'color' => '#EC4899'],
        ];

        $journey = [];
        $allStages = ['farm', 'processing', 'storage', 'transport', 'retail'];

        foreach ($allStages as $stage) {
            $stageTraces = $product->traces->where('stage', $stage);
            $journey[] = [
                'stage' => $stage,
                'label' => $stageLabels[$stage],
                'completed' => $stageTraces->isNotEmpty(),
                'traces' => $stageTraces->values(),
                'latest' => $stageTraces->last(),
            ];
        }

        return $journey;
    }
}
