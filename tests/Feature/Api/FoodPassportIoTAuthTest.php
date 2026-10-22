<?php

namespace Tests\Feature\Api;

use App\Models\AdminUser;
use App\Models\FoodProduct;
use App\Models\FoodTrace;
use App\Models\IoTDevice;
use App\Models\User;
use App\Services\IoTService;
use App\Services\WalletSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — FoodPassport: รายการ "ของฉัน" ต้องพิสูจน์กระเป๋า + อุปกรณ์ IoT ต้องมีกุญแจของตัวเอง.
 *
 * ช่องโหว่ที่ปิด:
 * 1. my-products / iot/my-devices อ่าน ?address= ซึ่ง VerifyWalletOwnership มองไม่เห็น ด่านจึงปล่อยผ่าน —
 *    ใครก็ดูสินค้าและอุปกรณ์ (พร้อม device_id) ของกระเป๋าไหนก็ได้
 * 2. ส่งข้อมูล sensor ได้แค่รู้ device_id: กุญแจเดิมเก็บตัวจริงใน config ที่หลุดออกหน้า /verify สาธารณะ
 *    และ batch-ingest ตรวจกุญแจแค่แถวแรก — ใช้กุญแจเครื่องตัวเองส่งข้อมูลในนามเครื่องใครก็ได้
 *
 * Developed by Xman Studio.
 */
class FoodPassportIoTAuthTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = '0x1111111111111111111111111111111111111111';

    private const STRANGER = '0x2222222222222222222222222222222222222222';

    /** IP ของเจ้าของตอนเซ็น */
    private const OWNER_IP = '203.0.113.10';

    /** IP อื่น — คนแปลกหน้า หรือเจ้าของเองหลังสลับ Wi-Fi เป็น 4G */
    private const OTHER_IP = '198.51.100.77';

    private const OWNER_DEVICE = 'TPIX-IOT-0WNER001';

    private const OWNER_BATCH = 'BATCH-2026-00042';

    private User $owner;

    private FoodProduct $product;

    private IoTDevice $device;

    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->owner = User::create([
            'wallet_address' => self::OWNER,
            'email' => 'owner@tpix.test',
            'password' => 'secret-password',
            'name' => 'เจ้าของฟาร์ม',
        ]);

        // เจ้าของเพิ่งเซ็นจากมือถือของตัวเอง — แถวเดียวกับที่ WalletController::verifySignature เขียน
        Cache::put('wallet_verified:'.self::OWNER, [
            'chain_id' => 4289,
            'ip' => self::OWNER_IP,
            'verified_at' => now()->toIso8601String(),
            'signature_verified' => true,
        ], 14400);

        $this->product = FoodProduct::create([
            'name' => 'ข้าวหอมมะลิ ทุ่งกุลา',
            'category' => 'grain',
            'origin' => 'สุรินทร์',
            'producer_address' => self::OWNER,
            'batch_number' => self::OWNER_BATCH,
        ]);

        $this->device = $this->makeDevice(self::OWNER_DEVICE, self::OWNER);
        $this->apiKey = app(IoTService::class)->issueApiKey($this->device);
    }

    public static function myLists(): array
    {
        return [
            'สินค้าของฉัน' => ['/api/v1/food-passport/my-products', self::OWNER_BATCH],
            'อุปกรณ์ IoT ของฉัน' => ['/api/v1/food-passport/iot/my-devices', self::OWNER_DEVICE],
        ];
    }

    // ── 1. รายการของฉัน ─────────────────────────────────────────────────

    #[Test]
    #[DataProvider('myLists')]
    public function คนแปลกหน้าที่รู้แค่เลขกระเป๋าดูรายการไม่ได้(string $path, string $ownerMarker): void
    {
        $this->fromIp(self::OTHER_IP)
            ->getJson($path.'?wallet_address='.self::OWNER)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_IP_MISMATCH')
            ->assertDontSee($ownerMarker);
    }

    /** เส้นทางที่เคยรั่ว: ?address= ด่านมองไม่เห็น เดิมได้รายการของเหยื่อครบ */
    #[Test]
    #[DataProvider('myLists')]
    public function พารามิเตอร์_address_แบบเดิมไม่คืนรายการของใคร(string $path, string $ownerMarker): void
    {
        $this->fromIp(self::OTHER_IP)
            ->getJson($path.'?address='.self::OWNER)
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'INVALID_ADDRESS')
            ->assertDontSee($ownerMarker);
    }

    #[Test]
    #[DataProvider('myLists')]
    public function พิสูจน์กระเป๋าตัวเองแล้วแอบใส่_address_ของคนอื่นก็ได้แค่ของตัวเอง(string $path, string $ownerMarker): void
    {
        $this->actingAs($this->stranger());

        $this->fromIp(self::OTHER_IP)
            ->getJson($path.'?wallet_address='.self::STRANGER.'&address='.self::OWNER)
            ->assertOk()
            ->assertJsonCount(0, 'data.data')
            ->assertDontSee($ownerMarker);
    }

    /** เว็บ: เข้าสู่ระบบด้วยบัญชีที่ผูกกระเป๋าใบนี้ — สลับ Wi-Fi เป็น 4G แล้วก็ยังดูได้ */
    #[Test]
    #[DataProvider('myLists')]
    public function เจ้าของบนเว็บดูรายการของตัวเองได้ด้วย_session(string $path, string $ownerMarker): void
    {
        $this->actingAs($this->owner);

        $this->fromIp(self::OTHER_IP)
            ->getJson($path.'?wallet_address='.self::OWNER)
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertSee($ownerMarker);
    }

    /** แอป: โทเคน X-Wallet-Session ของกระเป๋าใบนี้ — ไม่ผูก IP */
    #[Test]
    #[DataProvider('myLists')]
    public function แอปของเจ้าของดูรายการของตัวเองได้ด้วยโทเคน(string $path, string $ownerMarker): void
    {
        $token = app(WalletSessionService::class)->issue(self::OWNER, 4289, self::OWNER_IP)['token'];

        $this->fromIp(self::OTHER_IP)
            ->withHeader(WalletSessionService::HEADER, $token)
            ->getJson($path.'?wallet_address='.self::OWNER)
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertSee($ownerMarker);
    }

    #[Test]
    public function รายการอุปกรณ์ของเจ้าของไม่มี_hash_ของกุญแจ(): void
    {
        $this->actingAs($this->owner);

        $response = $this->getJson('/api/v1/food-passport/iot/my-devices?wallet_address='.self::OWNER)
            ->assertOk()
            ->assertJsonPath('data.data.0.has_api_key', true)
            ->assertJsonMissingPath('data.data.0.api_key_hash');

        $this->assertStringNotContainsString(hash('sha256', $this->apiKey), $response->getContent());
    }

    // ── 2. ส่งข้อมูลเข้าต้องมีกุญแจของเครื่องนั้น ─────────────────────────────

    #[Test]
    public function ส่งข้อมูลโดยไม่มีกุญแจไม่ได้(): void
    {
        $this->ingest()
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');

        $this->assertSame(0, FoodTrace::count());
    }

    #[Test]
    public function ส่งข้อมูลด้วยกุญแจผิดไม่ได้(): void
    {
        $this->ingest([], 'fpk_'.str_repeat('0', 64))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INVALID_API_KEY');

        $this->assertSame(0, FoodTrace::count());
        $this->assertNull($this->device->fresh()->last_ping_at);
    }

    /** ใครก็ลงทะเบียนเครื่องของตัวเองได้ — กุญแจที่ถูกต้องหนึ่งดอกต้องใช้ได้กับเครื่องนั้นเครื่องเดียว */
    #[Test]
    public function กุญแจของเครื่องตัวเองใช้ส่งในนามเครื่องคนอื่นไม่ได้(): void
    {
        $attackerKey = app(IoTService::class)->issueApiKey($this->makeDevice('TPIX-IOT-ATTACKER', self::STRANGER));

        $this->ingest([], $attackerKey)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'INVALID_API_KEY');

        $this->assertSame(0, FoodTrace::count());
    }

    /** ไม่มีกุญแจ = ไม่ได้ข้อความ validation ที่บอกว่าสินค้าเลขไหนมีอยู่จริง */
    #[Test]
    public function ไม่มีกุญแจก็ส่องเลขสินค้าไม่ได้(): void
    {
        $this->ingest(['product_id' => 999999])
            ->assertStatus(401)
            ->assertJsonMissingValidationErrors(['product_id']);
    }

    #[Test]
    public function เครื่องที่มีกุญแจถูกต้องส่งข้อมูลได้(): void
    {
        $this->ingest([], $this->apiKey)
            ->assertCreated()
            ->assertJsonPath('data.iot_device_id', $this->device->id);

        $trace = FoodTrace::sole();
        $this->assertSame($this->product->id, $trace->food_product_id);
        $this->assertSame('4.50', $trace->temperature);
        $this->assertNotNull($this->device->fresh()->last_ping_at);
    }

    #[Test]
    public function เครื่องที่ถูกปิดไว้ส่งข้อมูลไม่ได้แม้กุญแจถูก(): void
    {
        $this->device->update(['status' => 'inactive']);

        $this->ingest([], $this->apiKey)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'DEVICE_INACTIVE');

        $this->assertSame(0, FoodTrace::count());
    }

    // ── batch: ทุกแถวต้องเป็นของเครื่องเจ้าของกุญแจ ──────────────────────────

    /** ช่องเดิม: แถวแรกเป็นเครื่องตัวเอง (กุญแจถูก) แล้วแถวที่เหลือใส่เครื่องของเหยื่อ */
    #[Test]
    public function batch_แอบใส่แถวของเครื่องคนอื่นถูกปฏิเสธทั้งชุด(): void
    {
        $attacker = $this->makeDevice('TPIX-IOT-ATTACKER', self::STRANGER);
        $attackerKey = app(IoTService::class)->issueApiKey($attacker);

        $this->batch([
            ['device_id' => $attacker->device_id, 'product_id' => $this->product->id, 'stage' => 'farm'],
            ['device_id' => self::OWNER_DEVICE, 'product_id' => $this->product->id, 'stage' => 'retail', 'temperature' => 35],
        ], $attackerKey)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'DEVICE_MISMATCH');

        $this->assertSame(0, FoodTrace::count());
    }

    #[Test]
    public function batch_ไม่มีกุญแจหรือกุญแจผิดไม่ได้(): void
    {
        $records = [['device_id' => self::OWNER_DEVICE, 'product_id' => $this->product->id, 'stage' => 'storage']];

        $this->batch($records)->assertStatus(401);
        $this->batch($records, 'fpk_wrong')->assertStatus(403);

        $this->assertSame(0, FoodTrace::count());
    }

    /** เดิม validated() ตัดค่า sensor ทิ้งเพราะกฎระบุแค่ 3 ฟิลด์ — อุณหภูมิที่ส่งเป็นชุดไม่เคยถูกบันทึก */
    #[Test]
    public function batch_ของเครื่องตัวเองบันทึกพร้อมค่า_sensor_และไม่คืนข้อความ_exception_ดิบ(): void
    {
        $this->batch([
            ['device_id' => self::OWNER_DEVICE, 'product_id' => $this->product->id, 'stage' => 'storage', 'temperature' => 3.8, 'humidity' => 80],
            ['device_id' => self::OWNER_DEVICE, 'product_id' => 999999, 'stage' => 'storage', 'temperature' => 4.1],
        ], $this->apiKey)
            ->assertOk()
            ->assertJsonPath('data.0.success', true)
            ->assertJsonPath('data.1.success', false)
            ->assertJsonPath('data.1.error', 'PRODUCT_NOT_FOUND')
            ->assertDontSee('No query results');

        $trace = FoodTrace::sole();
        $this->assertSame('3.80', $trace->temperature);
        $this->assertSame('80.00', $trace->humidity);
    }

    // ── test / config ก็ต้องเป็นตัวเครื่อง ─────────────────────────────────

    #[Test]
    public function ping_ทดสอบเครื่องต้องมีกุญแจ_คนอื่นแกล้งให้ดูออนไลน์ไม่ได้(): void
    {
        $url = '/api/v1/food-passport/iot/test/'.self::OWNER_DEVICE;

        $this->getJson($url)->assertStatus(401);
        $this->withToken('fpk_wrong')->getJson($url)->assertStatus(403);
        $this->assertNull($this->device->fresh()->last_ping_at);

        $this->withToken($this->apiKey)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.device_id', self::OWNER_DEVICE);
        $this->assertNotNull($this->device->fresh()->last_ping_at);
    }

    #[Test]
    public function ค่าตั้งเครื่องให้เฉพาะเครื่องที่มีกุญแจ_และไม่มีกุญแจตัวจริงอยู่ในนั้น(): void
    {
        $url = '/api/v1/food-passport/iot/config/'.self::OWNER_DEVICE;

        $this->getJson($url)->assertStatus(401)->assertDontSee('14.8826');
        $this->withToken('fpk_wrong')->getJson($url)->assertStatus(403)->assertDontSee('14.8826');

        $response = $this->withToken($this->apiKey)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.device_id', self::OWNER_DEVICE)
            ->assertJsonPath('data.headers.Authorization', 'Bearer <<YOUR_API_KEY>>');

        $this->assertStringNotContainsString($this->apiKey, $response->getContent());
    }

    // ── ออกกุญแจ: ตอนลงทะเบียน / ออกใหม่โดยเจ้าของ / แอดมิน ────────────────────

    #[Test]
    public function ลงทะเบียนเครื่องได้กุญแจครั้งเดียว_เก็บแค่_hash_และใช้ส่งข้อมูลได้(): void
    {
        $this->actingAs($this->owner);

        $response = $this->fromIp(self::OTHER_IP)->postJson('/api/v1/food-passport/iot/register-device', [
            'wallet_address' => self::OWNER,
            'owner_address' => self::OWNER,
            'name' => 'Sensor รถห้องเย็น',
            'type' => 'temperature',
            // ทำตามเอกสารเก่าที่ใส่กุญแจเองใน config — ต้องไม่ถูกเก็บ (config ออกหน้า /verify สาธารณะ)
            'config' => ['protocol' => 'http', 'interval_minutes' => 15, 'api_key' => 'fpk_chosen_by_client'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.has_api_key', true)
            ->assertJsonMissingPath('data.api_key_hash');

        $apiKey = $response->json('data.api_key');
        $this->assertMatchesRegularExpression('/^fpk_[0-9a-f]{64}$/', $apiKey);

        $device = IoTDevice::where('device_id', $response->json('data.device_id'))->sole();
        $this->assertSame(hash('sha256', $apiKey), $device->api_key_hash);
        $this->assertSame(self::OWNER, $device->owner_address);
        $this->assertSame(['protocol' => 'http', 'interval_minutes' => 15], $device->config);

        $this->ingest(['device_id' => $device->device_id], $apiKey)->assertCreated();
    }

    #[Test]
    public function ลงทะเบียนเครื่องในชื่อกระเป๋าคนอื่นไม่ได้(): void
    {
        $payload = ['name' => 'Sensor ปลอม', 'type' => 'temperature', 'owner_address' => self::OWNER];

        // แบบเดิม: ส่งแค่ owner_address ที่ด่านมองไม่เห็น
        $this->fromIp(self::OTHER_IP)
            ->postJson('/api/v1/food-passport/iot/register-device', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['wallet_address']);

        // อ้างกระเป๋าเจ้าของโดยไม่มีหลักฐาน
        $this->fromIp(self::OTHER_IP)
            ->postJson('/api/v1/food-passport/iot/register-device', $payload + ['wallet_address' => self::OWNER])
            ->assertStatus(403);

        // พิสูจน์กระเป๋าตัวเองแล้ว แต่ตั้งเจ้าของเป็นคนอื่น
        $this->actingAs($this->stranger());
        $this->fromIp(self::OTHER_IP)
            ->postJson('/api/v1/food-passport/iot/register-device', $payload + ['wallet_address' => self::STRANGER])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_MISMATCH')
            ->assertJsonMissingPath('data.api_key');

        $this->assertSame(1, IoTDevice::count());
    }

    #[Test]
    public function เจ้าของออกกุญแจใหม่ได้_กุญแจเดิมใช้ไม่ได้ทันที(): void
    {
        $token = app(WalletSessionService::class)->issue(self::OWNER, 4289, self::OWNER_IP)['token'];

        $newKey = $this->fromIp(self::OTHER_IP)
            ->withHeader(WalletSessionService::HEADER, $token)
            ->postJson('/api/v1/food-passport/iot/rotate-key/'.self::OWNER_DEVICE, ['wallet_address' => self::OWNER])
            ->assertOk()
            ->assertJsonPath('data.device_id', self::OWNER_DEVICE)
            ->json('data.api_key');

        $this->assertNotSame($this->apiKey, $newKey);
        $this->ingest([], $this->apiKey)->assertStatus(403);
        $this->ingest([], $newKey)->assertCreated();
    }

    /** ทางย้ายของเครื่องที่ลงทะเบียนก่อนมีระบบกุญแจ: ส่งไม่ได้จนเจ้าของกดออกกุญแจ */
    #[Test]
    public function เครื่องเก่าที่ยังไม่มีกุญแจ_เจ้าของออกกุญแจแล้วส่งได้(): void
    {
        $legacy = $this->makeDevice('TPIX-IOT-LEGACY01', self::OWNER);

        $this->ingest(['device_id' => $legacy->device_id], 'fpk_anything')->assertStatus(403);

        $this->actingAs($this->owner);
        $this->getJson('/api/v1/food-passport/iot/my-devices?wallet_address='.self::OWNER)
            ->assertOk()
            ->assertJsonFragment(['device_id' => 'TPIX-IOT-LEGACY01', 'has_api_key' => false]);

        $apiKey = $this->postJson('/api/v1/food-passport/iot/rotate-key/'.$legacy->device_id, ['wallet_address' => self::OWNER])
            ->assertOk()
            ->json('data.api_key');

        $this->ingest(['device_id' => $legacy->device_id], $apiKey)->assertCreated();
    }

    #[Test]
    public function คนอื่นออกกุญแจใหม่ให้เครื่องของเราไม่ได้(): void
    {
        $url = '/api/v1/food-passport/iot/rotate-key/'.self::OWNER_DEVICE;

        // อ้างเป็นเจ้าของโดยไม่มีหลักฐาน → ด่านกระเป๋าปฏิเสธ
        $this->fromIp(self::OTHER_IP)->postJson($url, ['wallet_address' => self::OWNER])->assertStatus(403);

        // ไม่ส่งเลขกระเป๋าเลย (ด่านปล่อยผ่าน) → controller ไม่ทำต่อ
        $this->fromIp(self::OTHER_IP)->postJson($url)->assertStatus(400);

        // พิสูจน์กระเป๋าตัวเองแล้วขอเครื่องของเรา → ตอบเหมือนไม่มีเครื่องนี้
        $this->actingAs($this->stranger());
        $this->fromIp(self::OTHER_IP)
            ->postJson($url, ['wallet_address' => self::STRANGER])
            ->assertStatus(404)
            ->assertJsonMissingPath('data.api_key');

        $this->assertSame(hash('sha256', $this->apiKey), $this->device->fresh()->api_key_hash);
        $this->ingest([], $this->apiKey)->assertCreated();
    }

    #[Test]
    public function แอดมินออกกุญแจใหม่_เห็นครั้งเดียวในข้อความแจ้งผล_กุญแจเดิมใช้ไม่ได้(): void
    {
        $admin = AdminUser::create([
            'name' => 'ผู้ดูแลระบบ',
            'email' => 'admin@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->from('/admin/food-passport/devices')
            ->post('/admin/food-passport/devices/'.$this->device->id.'/regenerate-key')
            ->assertRedirect('/admin/food-passport/devices');

        $this->assertSame(1, preg_match('/fpk_[0-9a-f]{64}/', (string) session('success'), $match));
        $device = $this->device->fresh();
        $this->assertSame(hash('sha256', $match[0]), $device->api_key_hash);
        $this->assertArrayNotHasKey('api_key', $device->config ?? []);

        $this->ingest([], $this->apiKey)->assertStatus(403);
        $this->ingest([], $match[0])->assertCreated();
    }

    // ── กุญแจ/hash ไม่หลุดออกหน้าสาธารณะ + กุญแจเดิมที่หลุดไปแล้วถูกลบ ───────────

    #[Test]
    public function หน้า_verify_สาธารณะไม่มีกุญแจหรือ_hash_ของเครื่อง(): void
    {
        $this->ingest([], $this->apiKey)->assertCreated();

        $response = $this->getJson('/api/v1/food-passport/verify/'.$this->product->id)
            ->assertOk()
            ->assertJsonPath('data.traces.0.device.device_id', self::OWNER_DEVICE)
            ->assertJsonMissingPath('data.traces.0.device.api_key_hash')
            ->assertJsonMissingPath('data.traces.0.device.config.api_key');

        $this->assertStringNotContainsString($this->apiKey, $response->getContent());
        $this->assertStringNotContainsString(hash('sha256', $this->apiKey), $response->getContent());
    }

    /** กุญแจเดิมอยู่ใน config ตัวจริง ซึ่งหลุดออกหน้า /verify ไปแล้ว — ลบ ไม่ย้าย */
    #[Test]
    public function migration_ลบกุญแจเดิมออกจาก_config_และกุญแจนั้นใช้ไม่ได้(): void
    {
        $migration = require database_path('migrations/2026_10_22_130000_add_api_key_hash_to_iot_devices_table.php');
        $migration->down();

        foreach ([
            'TPIX-IOT-OLDKEY01' => ['protocol' => 'http', 'interval_minutes' => 5, 'api_key' => 'fpk_leaked_one'],
            'TPIX-IOT-OLDKEY02' => ['api_key' => 'fpk_leaked_two'],
            'TPIX-IOT-NOKEY001' => ['protocol' => 'mqtt'],
        ] as $deviceId => $config) {
            DB::table('iot_devices')->insert([
                'device_id' => $deviceId,
                'name' => 'เครื่องรุ่นก่อน',
                'type' => 'temperature',
                'owner_address' => self::OWNER,
                'status' => 'active',
                'config' => json_encode($config),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $migration->up();

        $this->assertSame(['protocol' => 'http', 'interval_minutes' => 5], IoTDevice::where('device_id', 'TPIX-IOT-OLDKEY01')->sole()->config);
        $this->assertNull(IoTDevice::where('device_id', 'TPIX-IOT-OLDKEY02')->sole()->config);
        $this->assertSame(['protocol' => 'mqtt'], IoTDevice::where('device_id', 'TPIX-IOT-NOKEY001')->sole()->config);
        $this->assertSame(0, IoTDevice::whereNotNull('api_key_hash')->count());

        $this->ingest(['device_id' => 'TPIX-IOT-OLDKEY01'], 'fpk_leaked_one')->assertStatus(403);
        $this->assertSame(0, FoodTrace::count());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function makeDevice(string $deviceId, string $owner): IoTDevice
    {
        return IoTDevice::create([
            'device_id' => $deviceId,
            'name' => 'Sensor ฟาร์มข้าว',
            'type' => 'multi',
            'owner_address' => $owner,
            'location' => '14.8826,103.4945',
            'status' => 'active',
        ]);
    }

    private function stranger(): User
    {
        return User::create([
            'wallet_address' => self::STRANGER,
            'email' => 'stranger@tpix.test',
            'password' => 'secret-password',
            'name' => 'คนแปลกหน้า',
        ]);
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    /** เครื่องส่งค่า sensor หนึ่งแถว — ใส่กุญแจเมื่อส่ง $apiKey (ไม่ส่ง = ไม่มี Authorization เลย) */
    private function ingest(array $overrides = [], ?string $apiKey = null): TestResponse
    {
        return $this->asDevice($apiKey)->postJson('/api/v1/food-passport/iot/ingest', array_merge([
            'device_id' => self::OWNER_DEVICE,
            'product_id' => $this->product->id,
            'stage' => 'transport',
            'temperature' => 4.5,
            'humidity' => 65.2,
            'location' => '13.7563,100.5018',
        ], $overrides));
    }

    private function batch(array $records, ?string $apiKey = null): TestResponse
    {
        return $this->asDevice($apiKey)->postJson('/api/v1/food-passport/iot/batch-ingest', ['records' => $records]);
    }

    /** หัว Authorization ค้างข้ามคำขอในเทสต์เดียวกัน — ล้างทุกครั้งก่อนตั้งใหม่ */
    private function asDevice(?string $apiKey): static
    {
        $this->withoutToken();

        return $apiKey === null ? $this : $this->withToken($apiKey);
    }
}
