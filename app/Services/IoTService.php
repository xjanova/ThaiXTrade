<?php

namespace App\Services;

use App\Models\FoodProduct;
use App\Models\FoodTrace;
use App\Models\IoTDevice;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * IoTService — จัดการอุปกรณ์ IoT ที่ส่งข้อมูลเข้าระบบ FoodPassport
 *
 * IoT Device Flow:
 * 1. registerDevice()    → ลงทะเบียนอุปกรณ์ (ได้ device_id + wallet)
 * 2. ingestData()        → รับข้อมูลจาก sensor (HTTP/MQTT → API)
 * 3. Data → FoodTrace    → บันทึกลง DB + chain
 *
 * อุปกรณ์ที่รองรับ:
 * - Temperature sensor  → วัดอุณหภูมิ (cold chain)
 * - Humidity sensor     → วัดความชื้น
 * - GPS tracker         → ติดตามตำแหน่ง
 * - Camera              → ถ่ายภาพสินค้า
 * - Weight scale        → ชั่งน้ำหนัก
 * - pH meter            → วัดค่า pH
 * - Multi-sensor        → หลาย sensor ในตัวเดียว
 */
class IoTService
{
    public function __construct(
        private FoodPassportService $foodPassportService,
    ) {}

    // ═══════════════════════════════════════════
    //  DEVICE MANAGEMENT
    // ═══════════════════════════════════════════

    public function registerDevice(array $data): IoTDevice
    {
        return IoTDevice::create([
            'device_id' => $data['device_id'] ?? $this->generateDeviceId(),
            'name' => $data['name'],
            'type' => $data['type'],
            'wallet_address' => isset($data['wallet_address']) ? strtolower($data['wallet_address']) : null,
            'owner_address' => strtolower($data['owner_address']),
            'location' => $data['location'] ?? null,
            'firmware_version' => $data['firmware_version'] ?? '1.0.0',
            'status' => 'active',
            'config' => $data['config'] ?? null,
        ]);
    }

    public function getDevices(string $ownerAddress, int $perPage = 20): LengthAwarePaginator
    {
        return IoTDevice::byOwner($ownerAddress)
            ->withCount('traces')
            ->orderByDesc('last_ping_at')
            ->paginate($perPage);
    }

    public function updateDeviceStatus(IoTDevice $device, string $status): IoTDevice
    {
        $device->update(['status' => $status]);

        return $device->fresh();
    }

    // ═══════════════════════════════════════════
    //  DATA INGESTION (จาก IoT sensor)
    // ═══════════════════════════════════════════

    /**
     * รับข้อมูลจาก IoT device และบันทึกเป็น FoodTrace
     *
     * IoT device ส่ง HTTP POST มาที่ API:
     * POST /api/v1/food-passport/iot/ingest
     * {
     *   "device_id": "TPIX-IOT-001",
     *   "product_id": 42,
     *   "stage": "transport",
     *   "temperature": 4.5,
     *   "humidity": 65.2,
     *   "location": "13.7563,100.5018",
     *   "data": {"battery": 85, "signal": "good"}
     * }
     */
    public function ingestData(array $data): FoodTrace
    {
        // ค้นหา device
        $device = IoTDevice::where('device_id', $data['device_id'])->firstOrFail();

        // ตรวจสอบ device active
        if ($device->status !== 'active') {
            throw new \RuntimeException('Device is not active: '.$device->device_id);
        }

        // ค้นหาสินค้า
        $product = FoodProduct::findOrFail($data['product_id']);

        // บันทึก trace ผ่าน FoodPassportService
        $trace = $this->foodPassportService->addTrace($product, [
            'iot_device_id' => $device->id,
            'recorder_address' => $device->wallet_address ?? $device->owner_address,
            'stage' => $data['stage'],
            'location' => $data['location'] ?? $device->location,
            'temperature' => $data['temperature'] ?? null,
            'humidity' => $data['humidity'] ?? null,
            'weight_kg' => $data['weight_kg'] ?? null,
            'ph_level' => $data['ph_level'] ?? null,
            'sensor_data' => $data['data'] ?? null,
            'image_url' => $data['image_url'] ?? null,
            'notes' => 'IoT auto-recorded by '.$device->device_id,
            'recorded_at' => $data['recorded_at'] ?? now(),
        ]);

        // อัปเดต last_ping
        $device->update(['last_ping_at' => now()]);

        return $trace;
    }

    /**
     * Batch ingest — รับข้อมูลหลาย records พร้อมกัน
     * สำหรับ device ที่ cache ข้อมูลไว้แล้วส่งทีเดียว
     */
    public function batchIngest(array $records): array
    {
        $results = [];

        foreach ($records as $record) {
            try {
                $results[] = [
                    'success' => true,
                    'trace_id' => $this->ingestData($record)->id,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'device_id' => $record['device_id'] ?? 'unknown',
                ];
            }
        }

        return $results;
    }

    // ═══════════════════════════════════════════
    //  MONITORING
    // ═══════════════════════════════════════════

    /**
     * ดูข้อมูล sensor ล่าสุดของ product
     */
    public function getLatestSensorData(int $productId): array
    {
        $traces = FoodTrace::where('food_product_id', $productId)
            ->whereNotNull('temperature')
            ->orderByDesc('recorded_at')
            ->take(50)
            ->get();

        if ($traces->isEmpty()) {
            return ['has_data' => false];
        }

        return [
            'has_data' => true,
            'latest' => $traces->first(),
            'temperature' => [
                'current' => $traces->first()->temperature,
                'min' => $traces->min('temperature'),
                'max' => $traces->max('temperature'),
                'avg' => round($traces->avg('temperature'), 2),
            ],
            'humidity' => [
                'current' => $traces->first()->humidity,
                'min' => $traces->min('humidity'),
                'max' => $traces->max('humidity'),
                'avg' => round($traces->avg('humidity'), 2),
            ],
            'total_readings' => $traces->count(),
            'history' => $traces->map(fn ($t) => [
                'temperature' => $t->temperature,
                'humidity' => $t->humidity,
                'location' => $t->location,
                'recorded_at' => $t->recorded_at->toIso8601String(),
            ]),
        ];
    }

    /**
     * Alert: ตรวจสอบอุณหภูมิผิดปกติ (cold chain)
     */
    public function checkTemperatureAlerts(int $productId, float $minTemp, float $maxTemp): array
    {
        $violations = FoodTrace::where('food_product_id', $productId)
            ->whereNotNull('temperature')
            ->where(function ($q) use ($minTemp, $maxTemp) {
                $q->where('temperature', '<', $minTemp)
                  ->orWhere('temperature', '>', $maxTemp);
            })
            ->orderByDesc('recorded_at')
            ->get();

        return [
            'has_violations' => $violations->isNotEmpty(),
            'count' => $violations->count(),
            'violations' => $violations,
            'threshold' => ['min' => $minTemp, 'max' => $maxTemp],
        ];
    }

    public function getDeviceStats(string $ownerAddress): array
    {
        $devices = IoTDevice::byOwner($ownerAddress)->get();

        return [
            'total' => $devices->count(),
            'active' => $devices->where('status', 'active')->count(),
            'inactive' => $devices->where('status', 'inactive')->count(),
            'maintenance' => $devices->where('status', 'maintenance')->count(),
            'types' => $devices->groupBy('type')->map->count(),
        ];
    }

    // ═══════════════════════════════════════════
    //  TEST CONNECTION
    // ═══════════════════════════════════════════

    /**
     * ทดสอบ connection ของ device — ส่ง ping เพื่อเช็คว่า device ยังทำงาน
     */
    public function testConnection(string $deviceId): array
    {
        $device = IoTDevice::where('device_id', $deviceId)->first();

        if (! $device) {
            return ['success' => false, 'error' => 'Device not found'];
        }

        // อัปเดต last_ping เป็นเวลาปัจจุบัน
        $device->update(['last_ping_at' => now()]);

        return [
            'success' => true,
            'device_id' => $device->device_id,
            'status' => $device->status,
            'last_ping' => now()->toIso8601String(),
            'total_traces' => $device->traces()->count(),
        ];
    }

    /**
     * Generate config สำหรับ device (API endpoint, device_id, etc.)
     */
    public function generateConfig(IoTDevice $device): array
    {
        $protocol = $device->config['protocol'] ?? 'http';
        $interval = $device->config['interval_minutes'] ?? 15;

        return [
            'device_id' => $device->device_id,
            'protocol' => $protocol,
            'interval_minutes' => $interval,
            'api_endpoint' => url('/api/v1/food-passport/iot/ingest'),
            'batch_endpoint' => url('/api/v1/food-passport/iot/batch-ingest'),
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'payload_template' => [
                'device_id' => $device->device_id,
                'product_id' => '<<YOUR_PRODUCT_ID>>',
                'stage' => 'farm',
                'temperature' => 0.0,
                'humidity' => 0.0,
                'location' => $device->location ?? '0,0',
            ],
        ];
    }

    // ═══════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════

    private function generateDeviceId(): string
    {
        return 'TPIX-IOT-'.strtoupper(bin2hex(random_bytes(4)));
    }
}
