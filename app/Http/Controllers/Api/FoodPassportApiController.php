<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FoodProduct;
use App\Models\IoTDevice;
use App\Services\FoodPassportService;
use App\Services\IoTService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

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
     * ดูรายการสินค้าทั้งหมด.
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
     * ดูรายละเอียดสินค้า + trace ทั้งหมด (สแกน QR).
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
     * สถิติ FoodPassport.
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->foodPassportService->getStats(),
        ]);
    }

    /**
     * ดูใบรับรอง NFT ทั้งหมด.
     */
    public function certificates(Request $request): JsonResponse
    {
        $certificates = $this->foodPassportService->getCertificates(
            perPage: min($request->integer('per_page', 20), 100),
        );

        return response()->json(['success' => true, 'data' => $certificates]);
    }

    /**
     * ดู IoT sensor data ล่าสุดของสินค้า.
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
     * ลงทะเบียนสินค้าใหม่.
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
     * เพิ่ม trace record (manual).
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
     * Mint ใบรับรอง NFT.
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
                'error' => ['code' => 'MINT_FAILED', 'message' => 'Operation failed. Please try again.'],
            ], 422);
        }
    }

    /**
     * ดูสินค้าของฉัน (producer).
     *
     * เลขกระเป๋ามาจาก wallet_address ที่ VerifyWalletOwnership ตรวจแล้วเท่านั้น
     * ⚠️ เดิมอ่าน ?address= ซึ่งด่านมองไม่เห็น ด่านจึงปล่อยผ่านโดยไม่ตรวจอะไร —
     *    ใครก็ใส่เลขกระเป๋าคนอื่นแล้วดูสินค้า/อุปกรณ์ IoT (พร้อม device_id) ของเขาได้
     */
    public function myProducts(Request $request): JsonResponse
    {
        $address = $this->verifiedWallet($request);
        if (! $address) {
            return $this->walletRequired();
        }

        $products = $this->foodPassportService->getProductsByProducer($address);

        return response()->json(['success' => true, 'data' => $products]);
    }

    // ═══════════════════════════════════════════
    //  IoT ENDPOINTS
    // ═══════════════════════════════════════════

    /**
     * IoT device ส่งข้อมูลเข้าระบบ — ต้องส่ง API key ของเครื่องนี้มาใน Authorization: Bearer.
     */
    public function iotIngest(Request $request): JsonResponse
    {
        // ตรวจกุญแจก่อน validate ที่เหลือ — คนไม่มีกุญแจไม่ควรใช้ข้อความ validation ส่องได้ว่ามีสินค้าเลขไหน
        $request->validate(['device_id' => 'required|string|max:100']);

        $device = $this->authenticateDevice($request, $request->input('device_id'));
        if ($device instanceof JsonResponse) {
            return $device;
        }

        $validated = $request->validate([
            'device_id' => 'required|string|max:100',
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

        if ($inactive = $this->rejectInactiveDevice($device)) {
            return $inactive;
        }

        try {
            $trace = $this->ioTService->ingestData($validated);

            return response()->json(['success' => true, 'data' => $trace], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INGEST_FAILED', 'message' => 'Operation failed. Please try again.'],
            ], 422);
        }
    }

    /**
     * IoT batch ingest — หลาย records พร้อมกัน (เครื่องที่เก็บข้อมูลไว้แล้วส่งทีเดียว).
     *
     * กุญแจหนึ่งดอก = อุปกรณ์หนึ่งเครื่อง ทุกแถวในชุดต้องเป็นของเครื่องที่ยืนยันตัวแล้ว
     * ⚠️ เดิมตรวจกุญแจแค่ของแถวแรก — ลงทะเบียนอุปกรณ์ของตัวเอง (ได้กุญแจทันที) ใส่เป็นแถวแรก
     *    แล้วแถวที่เหลือใส่ device_id ของคนอื่น = ส่งข้อมูลปลอมในนามอุปกรณ์ใครก็ได้
     */
    public function iotBatchIngest(Request $request): JsonResponse
    {
        $request->validate([
            'records' => 'required|array|list|min:1|max:100',
            'records.*.device_id' => 'required|string|max:100',
        ]);

        $deviceId = $request->input('records.0.device_id');
        $device = $this->authenticateDevice($request, $deviceId);
        if ($device instanceof JsonResponse) {
            return $device;
        }

        // กฎเดียวกับ ingest ทีละแถว — เดิมระบุแค่ 3 ฟิลด์ validated() เลยตัดค่า sensor ทิ้งหมด
        // (อุณหภูมิ/ความชื้นที่ส่งมาเป็นชุดไม่เคยถูกบันทึก)
        $validated = $request->validate([
            'records' => 'required|array|list|min:1|max:100',
            'records.*.device_id' => 'required|string|max:100',
            'records.*.product_id' => 'required|integer',
            'records.*.stage' => 'required|string|in:farm,processing,storage,transport,retail',
            'records.*.location' => 'nullable|string',
            'records.*.temperature' => 'nullable|numeric',
            'records.*.humidity' => 'nullable|numeric',
            'records.*.weight_kg' => 'nullable|numeric',
            'records.*.ph_level' => 'nullable|numeric',
            'records.*.data' => 'nullable|array',
            'records.*.image_url' => 'nullable|string|max:500',
        ]);

        foreach ($validated['records'] as $record) {
            if ($record['device_id'] !== $deviceId) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'DEVICE_MISMATCH',
                        'message' => 'Every record in a batch must come from the device that owns the API key.',
                    ],
                ], 403);
            }
        }

        if ($inactive = $this->rejectInactiveDevice($device)) {
            return $inactive;
        }

        $results = $this->ioTService->batchIngest($validated['records']);

        return response()->json(['success' => true, 'data' => $results]);
    }

    /**
     * ลงทะเบียน IoT device ใหม่ — ได้ API key กลับไปครั้งเดียว.
     *
     * เจ้าของอุปกรณ์คือกระเป๋าที่ VerifyWalletOwnership ตรวจแล้วเท่านั้น
     * ⚠️ เดิมรับ owner_address ตรงๆ ซึ่งด่านมองไม่เห็น — ใครก็ลงทะเบียนอุปกรณ์ในชื่อกระเป๋าคนอื่น
     *    แล้วถือกุญแจของเครื่องที่บันทึกข้อมูลโดยขึ้นชื่อเหยื่อเป็นผู้บันทึกได้
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wallet_address' => 'required|string',
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:temperature,humidity,gps,camera,weight,ph,multi',
            'owner_address' => 'nullable|string|size:42',
            'location' => 'nullable|string|max:255',
            'config' => 'nullable|array',
        ]);

        $owner = $validated['wallet_address'];

        if (isset($validated['owner_address']) && strtolower($validated['owner_address']) !== $owner) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'WALLET_MISMATCH',
                    'message' => 'owner_address must be the wallet you verified.',
                ],
            ], 403);
        }

        // ไม่ส่ง wallet_address ของคำขอต่อ — ใน IoTService ฟิลด์ชื่อเดียวกันหมายถึง "กระเป๋าของตัวเครื่อง"
        $device = $this->ioTService->registerDevice([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'owner_address' => $owner,
            'location' => $validated['location'] ?? null,
            // กุญแจห้ามอยู่ใน config — config ออกไปพร้อมอุปกรณ์ในหน้า /verify สาธารณะ
            'config' => Arr::except($validated['config'] ?? [], ['api_key']) ?: null,
        ]);

        $apiKey = $this->ioTService->issueApiKey($device);

        // กุญแจตัวจริงออกจากเซิร์ฟเวอร์ครั้งนี้ครั้งเดียว — หายแล้วออกใหม่ได้ที่ rotate-key
        return response()->json([
            'success' => true,
            'data' => array_merge($device->toArray(), ['api_key' => $apiKey]),
        ], 201);
    }

    /**
     * ดูอุปกรณ์ IoT ของฉัน (เลขกระเป๋าจาก wallet_address ที่ด่านตรวจแล้ว — ดู myProducts).
     */
    public function myDevices(Request $request): JsonResponse
    {
        $address = $this->verifiedWallet($request);
        if (! $address) {
            return $this->walletRequired();
        }

        $devices = $this->ioTService->getDevices($address);

        return response()->json(['success' => true, 'data' => $devices]);
    }

    /**
     * ออก API key ใหม่ให้อุปกรณ์ของฉัน — กุญแจเดิมใช้ไม่ได้ทันที.
     *
     * ทางย้ายของอุปกรณ์ที่ลงทะเบียนก่อนมีระบบกุญแจ (ไม่มีกุญแจเลย ส่งข้อมูลไม่ได้)
     * และทางแก้เมื่อกุญแจหายหรือหลุด
     */
    public function rotateDeviceKey(Request $request, string $deviceId): JsonResponse
    {
        $owner = $this->verifiedWallet($request);
        if (! $owner) {
            return $this->walletRequired();
        }

        $device = IoTDevice::where('device_id', $deviceId)->first();

        // อุปกรณ์ของคนอื่นตอบเหมือนไม่มีอยู่ — ไม่บอกว่าเลขนี้มีเจ้าของ
        if (! $device || strtolower($device->owner_address) !== $owner) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'DEVICE_NOT_FOUND', 'message' => 'Device not found.'],
            ], 404);
        }

        $apiKey = $this->ioTService->issueApiKey($device);

        return response()->json([
            'success' => true,
            'data' => ['device_id' => $device->device_id, 'api_key' => $apiKey],
        ]);
    }

    /**
     * ทดสอบ connection ของ device — ตัวเครื่องเรียกพร้อมกุญแจเพื่อเช็คว่ากุญแจใช้ได้.
     *
     * ping นับเป็น "อุปกรณ์ยังออนไลน์" (last_ping_at) — ต้องเป็นตัวเครื่องจริง ไม่ใช่ใครก็ยิงได้
     */
    public function testDevice(Request $request, string $deviceId): JsonResponse
    {
        $device = $this->authenticateDevice($request, $deviceId);
        if ($device instanceof JsonResponse) {
            return $device;
        }

        $result = $this->ioTService->testConnection($deviceId);

        return response()->json([
            'success' => $result['success'],
            'data' => $result,
        ], $result['success'] ? 200 : 404);
    }

    /**
     * Generate config สำหรับ device (API endpoint, payload template).
     *
     * ให้เฉพาะตัวเครื่อง (มีกุญแจ) — ค่าตั้งมีตำแหน่งติดตั้ง ซึ่งมักเป็นพิกัดฟาร์ม
     */
    public function deviceConfig(Request $request, string $deviceId): JsonResponse
    {
        $device = $this->authenticateDevice($request, $deviceId);
        if ($device instanceof JsonResponse) {
            return $device;
        }

        return response()->json([
            'success' => true,
            'data' => $this->ioTService->generateConfig($device),
        ]);
    }

    /**
     * FDP Token info — ข้อมูลเหรียญ FoodPassport Token.
     */
    public function fdpTokenInfo(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'name' => 'FoodPassport Token',
                'symbol' => 'FDP',
                'total_supply' => '100,000,000',
                'chain' => 'TPIX Chain (ID: 4289)',
                'decimals' => 18,
                'rewards' => [
                    'per_certificate' => 100,
                    'per_trace' => 1,
                    'budget' => '40,000,000 FDP (40%)',
                ],
                'use_cases' => [
                    'reward' => 'เกษตรกรได้ FDP เมื่อผ่านการรับรอง',
                    'payment' => 'จ่าย FDP เป็นค่าตรวจสอบคุณภาพ',
                    'staking' => 'ล็อค FDP เพื่อเพิ่ม Trust Score',
                    'governance' => 'โหวตเรื่อง food safety standards',
                    'trade' => 'เทรดบน TPIX TRADE DEX (FDP/TPIX)',
                ],
                'tokenomics' => [
                    ['label' => 'Farmer Rewards', 'percent' => 40, 'amount' => '40,000,000'],
                    ['label' => 'Ecosystem Dev', 'percent' => 20, 'amount' => '20,000,000'],
                    ['label' => 'Liquidity', 'percent' => 15, 'amount' => '15,000,000'],
                    ['label' => 'Team & Advisors', 'percent' => 10, 'amount' => '10,000,000'],
                    ['label' => 'Community', 'percent' => 10, 'amount' => '10,000,000'],
                    ['label' => 'Reserve', 'percent' => 5, 'amount' => '5,000,000'],
                ],
            ],
        ]);
    }

    /**
     * อุปกรณ์ที่ส่ง API key ของตัวเองมาใน Authorization: Bearer — หรือคำตอบปฏิเสธ.
     *
     * ไม่มีอุปกรณ์เลขนี้ · ยังไม่เคยออกกุญแจ · กุญแจผิด ตอบเหมือนกันหมด
     * ⚠️ เดิมเทียบกับ config.api_key ตัวจริงที่หลุดออกหน้า /verify สาธารณะ และตอบ 404
     *    แยกจาก 403 ทำให้ใช้ส่องได้ว่า device_id ไหนมีอยู่จริง
     */
    private function authenticateDevice(Request $request, mixed $deviceId): IoTDevice|JsonResponse
    {
        $apiKey = $request->bearerToken();
        if (! $apiKey) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'AUTH_REQUIRED', 'message' => 'Device API key is required (Authorization: Bearer <api_key>).'],
            ], 401);
        }

        $device = is_string($deviceId) ? $this->ioTService->authenticateDevice($deviceId, $apiKey) : null;
        if (! $device) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_API_KEY',
                    'message' => 'Invalid API key for this device. The owner can issue a new key from FoodPassport → IoT Devices.',
                ],
            ], 403);
        }

        return $device;
    }

    /** เครื่องที่แอดมิน/เจ้าของปิดไว้ (inactive, maintenance) ห้ามส่งข้อมูลเข้า */
    private function rejectInactiveDevice(IoTDevice $device): ?JsonResponse
    {
        if ($device->status === 'active') {
            return null;
        }

        return response()->json([
            'success' => false,
            'error' => ['code' => 'DEVICE_INACTIVE', 'message' => 'This device is not active.'],
        ], 422);
    }

    /**
     * กระเป๋าที่ VerifyWalletOwnership ตรวจและแปลงเป็นตัวเล็กแล้ว.
     *
     * null = คำขอไม่ได้ส่ง wallet_address มา — กรณีนี้ด่านปล่อยผ่านโดยไม่ตรวจ จึงห้ามทำงานต่อ
     */
    private function verifiedWallet(Request $request): ?string
    {
        $wallet = $request->input('wallet_address');

        return is_string($wallet) && $wallet !== '' ? $wallet : null;
    }

    private function walletRequired(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => 'INVALID_ADDRESS', 'message' => 'Valid wallet_address required'],
        ], 400);
    }
}
