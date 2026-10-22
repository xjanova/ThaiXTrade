<?php

namespace Tests\Feature\Api;

use App\Models\FoodCertificate;
use App\Models\FoodProduct;
use App\Models\FoodTrace;
use App\Models\User;
use App\Services\FoodPassportService;
use App\Services\WalletSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — FoodPassport: ลงทะเบียนสินค้า / เพิ่ม trace / mint ใบรับรอง ต้องพิสูจน์กระเป๋า.
 *
 * ช่องโหว่ที่ปิด: ทั้งสามเส้นรับที่อยู่ในชื่อ producer_address / recorder_address / owner_address
 * ซึ่ง VerifyWalletOwnership มองไม่เห็น ด่านจึงปล่อยผ่านโดยไม่ตรวจอะไร —
 * ใครก็ลงทะเบียนสินค้าในชื่อผู้ผลิตคนอื่น เติม trace ปลอม และออกใบรับรองให้สินค้าของใครก็ได้
 *
 * Developed by Xman Studio.
 */
class FoodPassportWriteOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = '0xabababababababababababababababababababab';

    private const STRANGER = '0x2222222222222222222222222222222222222222';

    private const OWNER_IP = '203.0.113.10';

    private const OTHER_IP = '198.51.100.77';

    private const CONTRACT = '0x3333333333333333333333333333333333333333';

    private User $owner;

    private FoodProduct $product;

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
            'batch_number' => 'BATCH-2026-00042',
            'status' => 'registered',
        ]);
    }

    // ── 1. คนแปลกหน้า ────────────────────────────────────────────────────

    /** เส้นทางที่เคยรั่ว: ส่งแค่ฟิลด์เดิม ไม่มี wallet_address — เดิมผ่านทั้งหมด */
    #[Test]
    public function ส่งแค่ฟิลด์ที่อยู่แบบเดิมโดยไม่พิสูจน์กระเป๋าไม่ได้(): void
    {
        $this->fromIp(self::OTHER_IP)
            ->postJson('/api/v1/food-passport/register', $this->productBody(['producer_address' => self::OWNER]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('wallet_address');

        $this->fromIp(self::OTHER_IP)
            ->postJson($this->tracePath(), $this->traceBody(['recorder_address' => self::OWNER]))
            ->assertStatus(422);

        $this->addTwoTraces();
        $this->fromIp(self::OTHER_IP)
            ->postJson($this->mintPath(), $this->mintBody(['owner_address' => self::OWNER]))
            ->assertStatus(422);

        $this->assertSame(1, FoodProduct::count());
        $this->assertSame(2, FoodTrace::count());
        $this->assertSame(0, FoodCertificate::count());
    }

    /** รู้เลขกระเป๋าเจ้าของ (เห็นได้บนเชน) แต่ไม่ได้เซ็น */
    #[Test]
    #[DataProvider('writes')]
    public function คนแปลกหน้าที่อ้างกระเป๋าเจ้าของถูกปฏิเสธ(string $kind): void
    {
        $this->addTwoTraces();

        $this->fromIp(self::OTHER_IP)
            ->postJson(...$this->request($kind, self::OWNER))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_IP_MISMATCH');

        $this->assertNothingWritten();
    }

    /** พิสูจน์กระเป๋าตัวเองแล้วใส่ที่อยู่ของเจ้าของในฟิลด์เดิม */
    #[Test]
    #[DataProvider('writes')]
    public function พิสูจน์กระเป๋าตัวเองแล้วอ้างที่อยู่คนอื่นในฟิลด์เดิมไม่ได้(string $kind): void
    {
        $this->addTwoTraces();
        $this->actingAs($this->stranger());

        $field = ['register' => 'producer_address', 'trace' => 'recorder_address', 'mint' => 'owner_address'][$kind];

        $this->fromIp(self::OTHER_IP)
            ->postJson(...$this->request($kind, self::STRANGER, [$field => self::OWNER]))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WALLET_MISMATCH');

        $this->assertNothingWritten();
    }

    public static function writes(): array
    {
        return [
            'ลงทะเบียนสินค้า' => ['register'],
            'เพิ่ม trace' => ['trace'],
            'mint ใบรับรอง' => ['mint'],
        ];
    }

    /** พิสูจน์กระเป๋าตัวเองถูกต้อง แต่สินค้าเป็นของคนอื่น */
    #[Test]
    #[DataProvider('productWrites')]
    public function เพิ่ม_trace_หรือ_mint_ให้สินค้าของคนอื่นไม่ได้(string $kind): void
    {
        $this->addTwoTraces();
        $this->actingAs($this->stranger());

        $this->fromIp(self::OTHER_IP)
            ->postJson(...$this->request($kind, self::STRANGER))
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'NOT_PRODUCER');

        $this->assertNothingWritten();
        $this->assertSame('in_transit', $this->product->fresh()->status);
    }

    public static function productWrites(): array
    {
        return [
            'เพิ่ม trace' => ['trace'],
            'mint ใบรับรอง' => ['mint'],
        ];
    }

    // ── 2. เจ้าของ ──────────────────────────────────────────────────────

    /** เว็บ: เข้าสู่ระบบด้วยบัญชีที่ผูกกระเป๋านี้ — สลับ Wi-Fi เป็น 4G แล้วก็ยังทำได้ */
    #[Test]
    public function เจ้าของบนเว็บลงทะเบียน_trace_และ_mint_ได้ด้วย_session(): void
    {
        $this->actingAs($this->owner);

        $this->ownerFlow(fn () => $this->fromIp(self::OTHER_IP));
    }

    /** แอป: โทเคน X-Wallet-Session ของกระเป๋าใบนี้ — ไม่ผูก IP */
    #[Test]
    public function แอปของเจ้าของลงทะเบียน_trace_และ_mint_ได้ด้วยโทเคน(): void
    {
        $token = app(WalletSessionService::class)->issue(self::OWNER, 4289, self::OWNER_IP)['token'];

        $this->ownerFlow(fn () => $this->fromIp(self::OTHER_IP)->withHeader(WalletSessionService::HEADER, $token));
    }

    /** ผู้เรียกรุ่นเก่ายังส่งฟิลด์เดิมมาด้วย — ถ้าเป็นกระเป๋าเดียวกัน (ตัวพิมพ์ต่างกันก็ได้) ต้องผ่าน */
    #[Test]
    public function ส่งฟิลด์เดิมที่ตรงกับกระเป๋าที่ยืนยันแล้วยังผ่าน(): void
    {
        $this->actingAs($this->owner);

        $this->postJson('/api/v1/food-passport/register', $this->productBody([
            'wallet_address' => '0x'.str_repeat('AB', 20),
            'producer_address' => '0x'.str_repeat('Ab', 20),
        ]))->assertCreated()->assertJsonPath('data.producer_address', self::OWNER);
    }

    #[Test]
    public function trace_หลังได้ใบรับรองไม่ลบสถานะ_certified(): void
    {
        $this->product->update(['status' => 'certified']);

        app(FoodPassportService::class)->addTrace($this->product, [
            'recorder_address' => self::OWNER,
            'stage' => 'retail',
        ]);

        $this->assertSame('certified', $this->product->fresh()->status);
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** ลงทะเบียน → เพิ่ม trace 2 จุด → mint ทุกคำขอผ่านด่านจริง */
    private function ownerFlow(\Closure $client): void
    {
        $productId = $client()
            ->postJson('/api/v1/food-passport/register', $this->productBody(['wallet_address' => self::OWNER]))
            ->assertCreated()
            ->assertJsonPath('data.producer_address', self::OWNER)
            ->json('data.id');

        foreach (['farm', 'transport'] as $stage) {
            $client()
                ->postJson("/api/v1/food-passport/trace/{$productId}", $this->traceBody([
                    'wallet_address' => self::OWNER,
                    'stage' => $stage,
                ]))
                ->assertCreated()
                ->assertJsonPath('data.recorder_address', self::OWNER);
        }

        $client()
            ->postJson("/api/v1/food-passport/mint/{$productId}", $this->mintBody(['wallet_address' => self::OWNER]))
            ->assertCreated()
            ->assertJsonPath('data.owner_address', self::OWNER);

        $this->assertSame('certified', FoodProduct::find($productId)->status);
    }

    /** @return array{0: string, 1: array} */
    private function request(string $kind, string $wallet, array $extra = []): array
    {
        $extra['wallet_address'] = $wallet;

        return match ($kind) {
            'register' => ['/api/v1/food-passport/register', $this->productBody($extra)],
            'trace' => [$this->tracePath(), $this->traceBody($extra)],
            'mint' => [$this->mintPath(), $this->mintBody($extra)],
        };
    }

    private function productBody(array $extra = []): array
    {
        return array_merge([
            'name' => 'มะม่วงปลอม',
            'category' => 'fruit',
            'origin' => 'ไม่มีจริง',
        ], $extra);
    }

    private function traceBody(array $extra = []): array
    {
        return array_merge(['stage' => 'retail', 'location' => 'ร้านปลอม'], $extra);
    }

    private function mintBody(array $extra = []): array
    {
        return array_merge(['token_id' => 77, 'contract_address' => self::CONTRACT], $extra);
    }

    private function tracePath(): string
    {
        return "/api/v1/food-passport/trace/{$this->product->id}";
    }

    private function mintPath(): string
    {
        return "/api/v1/food-passport/mint/{$this->product->id}";
    }

    /** mint ต้องมี trace 2 จุด — ใส่ไว้ก่อน เพื่อให้คำขอที่ "ควรถูกปฏิเสธ" ไม่ตกเพราะเหตุอื่น */
    private function addTwoTraces(): void
    {
        $service = app(FoodPassportService::class);
        foreach (['farm', 'transport'] as $stage) {
            $service->addTrace($this->product, ['recorder_address' => self::OWNER, 'stage' => $stage]);
        }
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(1, FoodProduct::count(), 'ไม่มีสินค้าใหม่');
        $this->assertSame(2, FoodTrace::count(), 'ไม่มี trace ใหม่');
        $this->assertSame(0, FoodCertificate::count(), 'ไม่มีใบรับรอง');
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
}
