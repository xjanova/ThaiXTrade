<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FoodProduct;
use App\Services\FoodPassportService;
use App\Services\IoTService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FoodPassportApiController extends Controller
{
    public function __construct(
        private FoodPassportService $foodPassportService,
        private IoTService $ioTService,
    ) {}

    // ═══════════════════════════════════════════
    //  PUBLIC ENDPOINTS
    // ═══════════════════════════════════════════

    /**
     * ดูรายการสินค้าทั้งหมด
     */
    public function products(Request $request): JsonResponse
    {
        $products = $this->foodPassportService->getProducts(
            category: $request->query('category'),
            status: $request->query('status'),
            perPage: min($request->integer('per_page', 20), 100),
        );

        return response()->json(['success' => true, 'data' => $products]);
    }

    /**
     * ดูรายละเอียดสินค้า + trace ทั้งหมด (สแกน QR)
     */
    public function verify(int $productId): JsonResponse
    {
        try {
            $data = $this->foodPassportService->verifyProduct($productId);

            return response()->json(['success' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'Product not found'],
            ], 404);
        }
    }

    /**
     * สถิติ FoodPassport
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->foodPassportService->getStats(),
        ]);
    }

    /**
     * ดูใบรับรอง NFT ทั้งหมด
     */
    public function certificates(Request $request): JsonResponse
    {
        $certificates = $this->foodPassportService->getCertificates(
            perPage: min($request->integer('per_page', 20), 100),
        );

        return response()->json(['success' => true, 'data' => $certificates]);
    }

    /**
     * ดู IoT sensor data ล่าสุดของสินค้า
     */
    public function sensorData(int $productId): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->ioTService->getLatestSensorData($productId),
        ]);
    }

    // ═══════════════════════════════════════════
    //  PROTECTED ENDPOINTS (ต้อง verify wallet)
    // ═══════════════════════════════════════════

    /**
     * ลงทะเบียนสินค้าใหม่
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|string|in:fruit,vegetable,meat,dairy,seafood,grain,processed,beverage',
            'origin' => 'required|string|max:255',
            'producer_address' => 'required|string|size:42',
            'producer_name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'weight_kg' => 'nullable|numeric|min:0',
            'harvest_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after:harvest_date',
        ]);

        $product = $this->foodPassportService->registerProduct($validated);

        return response()->json(['success' => true, 'data' => $product], 201);
    }

    /**
     * เพิ่ม trace record (manual)
     */
    public function addTrace(Request $request, int $productId): JsonResponse
    {
        $validated = $request->validate([
            'recorder_address' => 'required|string|size:42',
            'stage' => 'required|string|in:farm,processing,storage,transport,retail',
            'location' => 'nullable|string|max:255',
            'temperature' => 'nullable|numeric',
            'humidity' => 'nullable|numeric|min:0|max:100',
            'weight_kg' => 'nullable|numeric|min:0',
            'ph_level' => 'nullable|numeric|min:0|max:14',
            'notes' => 'nullable|string|max:500',
        ]);

        $product = FoodProduct::findOrFail($productId);
        $trace = $this->foodPassportService->addTrace($product, $validated);

        return response()->json(['success' => true, 'data' => $trace], 201);
    }

    /**
     * Mint ใบรับรอง NFT
     */
    public function mint(Request $request, int $productId): JsonResponse
    {
        $validated = $request->validate([
            'owner_address' => 'required|string|size:42',
            'token_id' => 'required|integer|min:1',
            'contract_address' => 'required|string|size:42',
            'token_uri' => 'nullable|string|max:500',
            'tx_hash' => 'nullable|string|size:66',
        ]);

        try {
            $product = FoodProduct::findOrFail($productId);
            $certificate = $this->foodPassportService->mintCertificate($product, $validated);

            return response()->json(['success' => true, 'data' => $certificate], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'MINT_FAILED', 'message' => $e->getMessage()],
            ], 422);
        }
    }

    /**
     * ดูสินค้าของฉัน (producer)
     */
    public function myProducts(Request $request): JsonResponse
    {
        $address = $request->query('address');
        if (! $address || strlen($address) !== 42) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_ADDRESS', 'message' => 'Valid wallet address required'],
            ], 400);
        }

        $products = $this->foodPassportService->getProductsByProducer($address);

        return response()->json(['success' => true, 'data' => $products]);
    }

    // ═══════════════════════════════════════════
    //  IoT ENDPOINTS
    // ═══════════════════════════════════════════

    /**
     * IoT device ส่งข้อมูลเข้าระบบ
     */
    public function iotIngest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|exists:iot_devices,device_id',
            'product_id' => 'required|integer|exists:food_products,id',
            'stage' => 'required|string|in:farm,processing,storage,transport,retail',
            'location' => 'nullable|string',
            'temperature' => 'nullable|numeric',
            'humidity' => 'nullable|numeric',
            'weight_kg' => 'nullable|numeric',
            'ph_level' => 'nullable|numeric',
            'data' => 'nullable|array',
            'image_url' => 'nullable|string|max:500',
        ]);

        try {
            $trace = $this->ioTService->ingestData($validated);

            return response()->json(['success' => true, 'data' => $trace], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INGEST_FAILED', 'message' => $e->getMessage()],
            ], 422);
        }
    }

    /**
     * IoT batch ingest — หลาย records พร้อมกัน
     */
    public function iotBatchIngest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'records' => 'required|array|min:1|max:100',
            'records.*.device_id' => 'required|string',
            'records.*.product_id' => 'required|integer',
            'records.*.stage' => 'required|string',
        ]);

        $results = $this->ioTService->batchIngest($validated['records']);

        return response()->json(['success' => true, 'data' => $results]);
    }

    /**
     * ลงทะเบียน IoT device ใหม่
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:temperature,humidity,gps,camera,weight,ph,multi',
            'owner_address' => 'required|string|size:42',
            'location' => 'nullable|string|max:255',
            'config' => 'nullable|array',
        ]);

        $device = $this->ioTService->registerDevice($validated);

        return response()->json(['success' => true, 'data' => $device], 201);
    }

    /**
     * ดูอุปกรณ์ IoT ของฉัน
     */
    public function myDevices(Request $request): JsonResponse
    {
        $address = $request->query('address');
        if (! $address || strlen($address) !== 42) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_ADDRESS', 'message' => 'Valid wallet address required'],
            ], 400);
        }

        $devices = $this->ioTService->getDevices($address);

        return response()->json(['success' => true, 'data' => $devices]);
    }
}
