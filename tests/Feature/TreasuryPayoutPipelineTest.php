<?php

namespace Tests\Feature;

use App\Console\Commands\TreasuryProcessPayouts;
use App\Models\AdminUser;
use App\Models\FactoryToken;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\SystemAlert;
use App\Models\TokenSale;
use App\Models\TreasuryLedger;
use App\Models\TreasuryPayout;
use App\Models\TreasuryWhitelist;
use App\Services\HotWalletSigner;
use App\Services\TokenFactory\TokenFactoryRefunds;
use App\Services\TreasuryService;
use App\Support\Wei;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\FakeHotWalletSigner;
use Tests\TestCase;

/**
 * คิวจ่ายจากกระเป๋าร้อนต้องไม่จ่ายซ้ำ ไม่ค้างเงียบ และไม่ข้าม whitelist (ออดิท 2026-10-29: C3 C5 L1 L7).
 *
 * ตัวเซ็นเป็นของปลอม (ไม่มีคีย์ ไม่เรียก Node) และเชนเป็น Http::fake ที่ตอบตาม state ในเทสต์
 * — ไม่มีอะไรออกไปนอกเครื่อง
 */
class TreasuryPayoutPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const HOT = '0x43d9000000000000000000000000000000009b96';

    private const LISTED = '0x4bcc1844ad9e8587f7005f092928a5d14c30f463';

    private const BUYER = '0x1111111111111111111111111111111111111111';

    private FakeHotWalletSigner $signer;

    private string $keystore;

    /** @var array<string, array> tx hash → ใบเสร็จ */
    private array $receipts = [];

    /** @var array<string, array> tx hash → ธุรกรรมที่โหนดรู้จัก (mempool หรือลงบล็อกแล้ว) */
    private array $known = [];

    /** nonce ล่าสุดที่ลงบล็อกแล้วของกระเป๋าร้อน (eth_getTransactionCount latest) */
    private int $latestNonce = 0;

    private int $head = 9000;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // keystore ปลอมที่มีแค่ address ให้ด่าน readiness ผ่าน — ตัวเซ็นปลอมไม่อ่านไฟล์นี้
        $this->keystore = storage_path('framework/testing/hot-wallet-'.getmypid().'.json');
        if (! is_dir(dirname($this->keystore))) {
            mkdir(dirname($this->keystore), 0777, true);
        }
        file_put_contents($this->keystore, json_encode(['address' => substr(self::HOT, 2), 'version' => 3]));

        config([
            'treasury.payouts_enabled' => true,
            'treasury.hot_wallet.address' => self::HOT,
            'treasury.hot_wallet.keystore_path' => $this->keystore,
            'treasury.hot_wallet.passphrase' => 'test-only',
            'treasury.limits.per_transaction' => '1000000',
            'treasury.limits.per_day' => '10000000',
            'treasury.limits.require_whitelist' => true,
            'treasury.stuck_after_minutes' => 10,
        ]);

        $this->signer = new FakeHotWalletSigner(self::HOT);
        $this->app->instance(HotWalletSigner::class, $this->signer);

        TreasuryWhitelist::create(['address' => self::LISTED, 'label' => 'ปลายทางทดสอบ', 'purpose' => 'other', 'is_active' => true]);

        $this->fakeChain();
    }

    protected function tearDown(): void
    {
        if (is_file($this->keystore)) {
            unlink($this->keystore);
        }

        parent::tearDown();
    }

    // ── เชนจำลอง + ตัวช่วย ───────────────────────────────────────────────

    private function fakeChain(): void
    {
        Http::fake(function (Request $request) {
            $method = $request->data()['method'] ?? '';
            $params = $request->data()['params'] ?? [];
            $ok = fn ($result) => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]);

            return match ($method) {
                'eth_getBalance' => $ok('0x'.self::toHex(Wei::toWei('5000000'))),
                'eth_blockNumber' => $ok('0x'.dechex($this->head)),
                'eth_getTransactionReceipt' => $ok($this->receipts[strtolower($params[0] ?? '')] ?? null),
                'eth_getTransactionByHash' => $ok($this->known[strtolower($params[0] ?? '')] ?? null),
                'eth_getTransactionCount' => $ok('0x'.dechex($this->latestNonce)),
                default => $ok(null),
            };
        });
    }

    private static function toHex(string $dec): string
    {
        $hex = '';
        while (bccomp($dec, '0', 0) > 0) {
            $hex = dechex((int) bcmod($dec, '16')).$hex;
            $dec = bcdiv($dec, '16', 0);
        }

        return $hex === '' ? '0' : $hex;
    }

    private function payoutRound(): string
    {
        Artisan::call('tpix:treasury-payouts');

        return Artisan::output();
    }

    /** รายการที่อนุมัติแล้วและสั่งส่งแล้ว รอ CLI เซ็น */
    private function queued(array $extra = []): TreasuryPayout
    {
        return TreasuryPayout::create(array_merge([
            'idempotency_key' => 'test-'.uniqid('', true),
            'to_address' => self::LISTED,
            'amount_wei' => Wei::toWei('100'),
            'purpose' => 'other',
            'origin' => TreasuryPayout::ORIGIN_ADMIN,
            'status' => TreasuryPayout::STATUS_BROADCASTING,
            'approved_at' => now(),
        ], $extra));
    }

    private function mined(string $hash, bool $success = true): void
    {
        $block = '0x'.dechex($this->head - 5);
        $this->receipts[strtolower($hash)] = ['transactionHash' => $hash, 'status' => $success ? '0x1' : '0x0', 'blockNumber' => $block];
        $this->known[strtolower($hash)] = ['hash' => $hash, 'blockNumber' => $block];
    }

    private function stuckAlert(): ?SystemAlert
    {
        return SystemAlert::where('node', TreasuryProcessPayouts::ALERT_NODE)
            ->where('alert_key', TreasuryProcessPayouts::ALERT_STUCK)
            ->where('status', 'active')
            ->first();
    }

    // ── C5: ส่งแล้วไม่ยืนยันผล ต้องไม่กลายเป็น failed ───────────────────

    public static function ambiguousSendResults(): array
    {
        return [
            'Cloudflare 502 หลังโหนดรับไปแล้ว' => ['broadcast_failed'],
            'Process ถูกตัดที่ 120 วิ' => ['process_failed'],
            'nonce ถูกใช้แล้ว' => ['nonce_used'],
        ];
    }

    #[Test]
    #[DataProvider('ambiguousSendResults')]
    public function ส่งแล้วได้_error_กำกวม_คง_broadcasting_พร้อม_raw_ไม่ตั้ง_failed(string $sendResult): void
    {
        $this->signer->sendResult = $sendResult;
        $payout = $this->queued();

        $this->payoutRound();
        $payout->refresh();

        $this->assertSame(TreasuryPayout::STATUS_BROADCASTING, $payout->status);
        $this->assertNull($payout->failure_reason);
        $this->assertNotNull($payout->last_error);
        $this->assertSame(FakeHotWalletSigner::hashOf((string) $payout->raw_tx), $payout->tx_hash);
        $this->assertSame(0, $payout->nonce);
        $this->assertSame(self::HOT, $payout->from_address);
        $this->assertSame([], $this->signer->sentBeforePersist, 'ต้องบันทึก hash + raw ก่อนส่งเสมอ');

        // เงินอาจออกไปแล้ว — ต้องยังกินวงเงินรายวัน (แถว failed ไม่ถูกนับ)
        $this->assertSame((string) $payout->amount_wei, app(TreasuryService::class)->spentTodayWei());

        // ธุรกรรมที่เซ็นแล้วไม่ไหลไปหน้าจอแอดมิน
        $this->assertArrayNotHasKey('raw_tx', $payout->toArray());
        $this->assertArrayNotHasKey('claim_token', $payout->toArray());
    }

    #[Test]
    public function ส่งไม่ยืนยันผลแต่จริงๆ_ลงบล็อกแล้ว_ปิดเป็น_confirmed_ไม่เซ็นใหม่(): void
    {
        $this->signer->sendResult = 'broadcast_failed';
        $payout = $this->queued();
        $this->payoutRound();
        $payout->refresh();

        // โหนดรับไปแล้วจริง และลงบล็อกแล้ว
        $this->mined($payout->tx_hash);
        $this->signer->sendResult = 'ok';
        $this->payoutRound();
        $payout->refresh();

        $this->assertSame(TreasuryPayout::STATUS_CONFIRMED, $payout->status);
        $this->assertNull($payout->last_error);
        $this->assertCount(1, $this->signer->signed, 'ห้ามเซ็นรายการที่มี hash แล้วซ้ำ');
        $this->assertCount(1, $this->signer->sent, 'โหนดรู้จักแล้ว ไม่ต้องส่งซ้ำ');
        $this->assertCount(1, TreasuryLedger::where('tx_hash', $payout->tx_hash)->get());
    }

    #[Test]
    public function โหนดไม่เคยได้รับธุรกรรม_และ_nonce_ยังว่าง_ส่ง_raw_เดิมซ้ำ_ไม่เซ็นใหม่(): void
    {
        $this->signer->sendResult = 'broadcast_failed';
        $payout = $this->queued();
        $this->payoutRound();
        $payout->refresh();
        $raw = (string) $payout->raw_tx;

        // โหนดไม่รู้จัก hash นี้ ไม่มีใบเสร็จ และ nonce 0 ยังว่าง
        $this->latestNonce = 0;
        $this->signer->sendResult = 'ok';
        $this->payoutRound();

        $this->assertCount(1, $this->signer->signed);
        $this->assertSame([$raw, $raw], $this->signer->sent, 'ต้องส่ง raw ตัวเดิม (hash เดิม nonce เดิม)');
        $this->assertSame(TreasuryPayout::STATUS_BROADCASTING, $payout->fresh()->status);
        $this->assertStringContainsString('ส่ง raw เดิมซ้ำแล้ว', (string) $payout->fresh()->last_error);

        $this->mined($payout->tx_hash);
        $this->payoutRound();

        $this->assertSame(TreasuryPayout::STATUS_CONFIRMED, $payout->fresh()->status);
        $this->assertCount(1, $this->signer->signed);
    }

    #[Test]
    public function ปิดสวิตช์จ่ายแล้ว_ไม่ส่ง_raw_เดิมซ้ำ_แต่ยังตามผลรายการที่ลงบล็อกแล้ว(): void
    {
        // รอบก่อนบันทึก raw แล้วดับก่อนส่ง — โหนดไม่รู้จัก hash และ nonce 0 ยังว่าง
        $pending = $this->queued([
            'tx_hash' => FakeHotWalletSigner::hashOf('0xfeed'),
            'raw_tx' => '0xfeed',
            'nonce' => 0,
            'from_address' => self::HOT,
            'broadcast_at' => now(),
        ]);
        // อีกรายการที่ส่งไปแล้วและเพิ่งลงบล็อก
        $landed = $this->queued([
            'tx_hash' => FakeHotWalletSigner::hashOf('0xbeef'),
            'raw_tx' => '0xbeef',
            'nonce' => 1,
            'from_address' => self::HOT,
            'broadcast_at' => now(),
        ]);
        $this->mined($landed->tx_hash);

        config(['treasury.payouts_enabled' => false]);
        $this->payoutRound();

        $this->assertSame([], $this->signer->sent, 'สวิตช์ปิด = ห้ามมีธุรกรรมออกจากกระเป๋าร้อน');
        $this->assertSame(TreasuryPayout::STATUS_BROADCASTING, $pending->fresh()->status);
        $this->assertStringContainsString('สวิตช์จ่ายปิดอยู่', (string) $pending->fresh()->last_error);
        $this->assertSame(TreasuryPayout::STATUS_CONFIRMED, $landed->fresh()->status, 'ตามผลยังทำงานแม้สวิตช์ปิด');

        config(['treasury.payouts_enabled' => true]);
        $this->payoutRound();

        $this->assertSame(['0xfeed'], $this->signer->sent);
        $this->assertSame([], $this->signer->signed);
    }

    #[Test]
    public function nonce_ถูกใช้แล้วแต่ไม่เจอใบเสร็จ_ไม่ตัดสินเอง_แจ้งเตือนทันที(): void
    {
        $this->signer->sendResult = 'broadcast_failed';
        $first = $this->queued();
        $this->payoutRound();
        $first->refresh();
        $hash = $first->tx_hash;

        // nonce 0 ถูกใช้ไปแล้ว แต่ RPC ไม่มีใบเสร็จของ hash เรา — อาจจ่ายแล้วหรือไม่ก็ได้
        $this->latestNonce = 1;
        $this->signer->sendResult = 'ok';
        $this->signer->nextNonce = 1;
        $next = $this->queued();

        $this->payoutRound();
        $first->refresh();

        $this->assertSame(TreasuryPayout::STATUS_BROADCASTING, $first->status, 'ห้ามตั้ง failed — จะถูกสร้างรายการใหม่แล้วจ่ายซ้ำ');
        $this->assertSame($hash, $first->tx_hash);
        $this->assertNull($first->failure_reason);
        $this->assertStringContainsString('nonce 0 ถูกใช้แล้ว', (string) $first->last_error);

        $alert = $this->stuckAlert();
        $this->assertNotNull($alert, 'ต้องมีคาดแดงในหลังบ้าน');
        $this->assertSame('critical', $alert->severity);
        $this->assertStringContainsString("#{$first->id}", $alert->message);

        // รายการที่ nonce ถูกใช้ไปแล้วไม่มีทางทับ nonce ใหม่ — คิวที่เหลือเดินต่อได้
        $this->assertNotNull($next->fresh()->tx_hash);
        $this->assertSame(1, $next->fresh()->nonce);
        $this->assertCount(2, $this->signer->signed);
    }

    #[Test]
    public function เซ็นไม่ผ่าน_ยังไม่มีธุรกรรม_ตั้ง_failed_ได้อย่างปลอดภัย(): void
    {
        $this->signer->signOk = false;
        $payout = $this->queued();

        $this->payoutRound();
        $payout->refresh();

        $this->assertSame(TreasuryPayout::STATUS_FAILED, $payout->status);
        $this->assertNull($payout->tx_hash);
        $this->assertNull($payout->claim_token);
        $this->assertTrue($payout->endedWithoutPayment());
        $this->assertSame([], $this->signer->sent);
    }

    #[Test]
    public function คืนค่าธรรมเนียมอัตโนมัติไม่สร้างรอบใหม่ระหว่างที่รอบเดิมยังไม่รู้ผล(): void
    {
        $token = FactoryToken::create([
            'name' => 'Good Coin', 'symbol' => 'GOOD', 'decimals' => 18, 'total_supply' => '1000000',
            'creator_address' => self::BUYER, 'chain_id' => 4289, 'token_type' => 'standard',
            'token_category' => 'fungible', 'status' => 'rejected',
            'fee_tx_hash' => '0x'.str_repeat('a', 64),
            'refund_status' => FactoryToken::REFUND_PENDING,
            'refund_mode' => 'auto',
            'refund_amount_wei' => Wei::toWei('50'),
        ]);
        $refunds = app(TokenFactoryRefunds::class);

        // ระบบสร้างเอง — ปลายทางไม่อยู่ใน whitelist ก็ยังส่งได้
        $payout = $refunds->queueAuto($token);
        $this->assertSame(TreasuryPayout::ORIGIN_FACTORY_REFUND, $payout->origin);

        $this->signer->sendResult = 'broadcast_failed';
        $this->payoutRound();
        $payout->refresh();
        $this->assertSame(TreasuryPayout::STATUS_BROADCASTING, $payout->status);
        $this->assertSame(FactoryToken::REFUND_SENDING, $token->fresh()->refund_status);

        // แอดมินกด "คืนเงินอัตโนมัติ" ซ้ำ — ต้องได้รายการเดิม ไม่ใช่รอบ 2
        $this->assertSame($payout->id, $refunds->queueAuto($token->fresh())->id);
        $this->assertSame(1, TreasuryPayout::count());

        // เชนยืนยันว่า revert (เงินไม่ออกจริง) → failed แล้วจึงสั่งรอบใหม่ได้
        $this->mined($payout->tx_hash, false);
        $this->payoutRound();
        $payout->refresh();

        $this->assertSame(TreasuryPayout::STATUS_FAILED, $payout->status);
        $this->assertNotNull($payout->block_number);
        $this->assertSame(FactoryToken::REFUND_FAILED, $token->fresh()->refund_status);

        $second = $refunds->queueAuto($token->fresh());
        $this->assertNotSame($payout->id, $second->id);
        $this->assertStringEndsWith(':2', $second->idempotency_key);
    }

    #[Test]
    public function failed_แบบเก่าที่มี_hash_แต่ไม่มีใบเสร็จ_ไม่นับว่าจบ(): void
    {
        $token = FactoryToken::create([
            'name' => 'Old Coin', 'symbol' => 'OLD', 'decimals' => 18, 'total_supply' => '1000000',
            'creator_address' => self::BUYER, 'chain_id' => 4289, 'token_type' => 'standard',
            'token_category' => 'fungible', 'status' => 'rejected',
            'fee_tx_hash' => '0x'.str_repeat('b', 64),
            'refund_status' => FactoryToken::REFUND_FAILED,
            'refund_mode' => 'auto',
            'refund_amount_wei' => Wei::toWei('50'),
        ]);

        // แถวที่โค้ดเดิมตั้ง failed ตอน Cloudflare ตอบ 502 — ธุรกรรมอาจลงบล็อกไปแล้ว
        $legacy = TreasuryPayout::create([
            'idempotency_key' => TokenFactoryRefunds::PAYOUT_PURPOSE.":{$token->id}:1",
            'to_address' => self::BUYER,
            'amount_wei' => Wei::toWei('50'),
            'purpose' => TokenFactoryRefunds::PAYOUT_PURPOSE,
            'origin' => TreasuryPayout::ORIGIN_FACTORY_REFUND,
            'status' => TreasuryPayout::STATUS_FAILED,
            'tx_hash' => '0x'.str_repeat('c', 64),
            'failure_reason' => 'ส่งขึ้นเชนไม่สำเร็จ: 502',
        ]);

        $this->assertFalse($legacy->endedWithoutPayment());
        $this->assertSame($legacy->id, app(TokenFactoryRefunds::class)->queueAuto($token)->id);
        $this->assertSame(1, TreasuryPayout::count());
    }

    // ── L1: สองรอบทำงานทับกัน ────────────────────────────────────────────

    #[Test]
    public function มีรอบอื่นถือล็อกอยู่_รอบนี้ไม่แตะอะไร(): void
    {
        $payout = $this->queued();
        $lock = Cache::lock(TreasuryProcessPayouts::LOCK_KEY, 60);
        $this->assertTrue($lock->get());

        $output = $this->payoutRound();

        $this->assertStringContainsString('ข้ามรอบนี้', $output);
        $this->assertSame([], $this->signer->signed);
        $this->assertNull($payout->fresh()->tx_hash);

        $lock->release();
        $this->payoutRound();
        $this->assertCount(1, $this->signer->signed);
    }

    #[Test]
    public function แถวที่อีกรอบจองไว้และยังไม่หมดเวลา_ไม่ถูกเซ็นซ้อน(): void
    {
        $payout = $this->queued(['claimed_at' => now()->subSeconds(30), 'claim_token' => 'other-runner']);

        $this->payoutRound();

        $this->assertSame([], $this->signer->signed);
        $this->assertNull($payout->fresh()->tx_hash);
        $this->assertSame('other-runner', $payout->fresh()->claim_token);
    }

    #[Test]
    public function แถวที่รอบก่อนจองแล้วดับกลางคัน_พ้นเวลาจองแล้วรอบใหม่ทำต่อได้(): void
    {
        $payout = $this->queued([
            'claimed_at' => now()->subSeconds(TreasuryProcessPayouts::CLAIM_TTL_SECONDS + 60),
            'claim_token' => 'crashed-runner',
        ]);

        $this->payoutRound();
        $payout->refresh();

        $this->assertCount(1, $this->signer->signed);
        $this->assertNotNull($payout->tx_hash);
        $this->assertNotSame('crashed-runner', $payout->claim_token);
    }

    #[Test]
    public function ล็อกโดน_cache_clear_กลางรอบ_รอบที่สองก็ไม่เซ็นรายการซ้อน(): void
    {
        $payout = $this->queued();
        $next = $this->queued();
        $second = '';
        $signedByBoth = 0;

        // ระหว่างรอบแรกกำลังเซ็น: deploy รัน cache:clear (ล็อกหาย) แล้ว scheduler ปลุกอีกรอบขึ้นมา
        $this->signer->duringSign = function () use (&$second, &$signedByBoth) {
            $this->signer->duringSign = null;
            Cache::lock(TreasuryProcessPayouts::LOCK_KEY)->forceRelease();

            $command = $this->app->make(TreasuryProcessPayouts::class);
            $command->setLaravel($this->app);
            $output = new BufferedOutput();
            $command->run(new ArrayInput([]), $output);
            $second = $output->fetch();
            $signedByBoth = count($this->signer->signed);
        };

        $this->payoutRound();

        // ถ้ารอบสองไปเซ็นรายการถัดไป ทั้งสองจะได้ nonce pending ตัวเดียวกัน — ตัวหนึ่งค้างตลอดไป
        $this->assertStringContainsString('กำลังเซ็นรายการอยู่', $second);
        $this->assertSame(1, $signedByBoth, 'รอบสองต้องไม่เซ็นอะไรเลยระหว่างที่รอบแรกยังเซ็นอยู่');
        $this->assertCount(1, $this->signer->sent);
        $this->assertSame(FakeHotWalletSigner::hashOf($this->signer->sent[0]), $payout->fresh()->tx_hash);
        $this->assertNull($next->fresh()->tx_hash);
        $this->assertNull($next->fresh()->claim_token);
    }

    #[Test]
    public function รอบที่เสียสิทธิ์จองระหว่างเซ็น_ทิ้งธุรกรรมของตัวเองไม่ส่ง(): void
    {
        $payout = $this->queued();
        $theirs = '0x'.str_repeat('9', 64);

        // รอบนี้ค้างจนเลยเวลาจอง อีกรอบจองต่อแล้วบันทึกธุรกรรมของตัวเองไปก่อน
        $this->signer->duringSign = function () use ($payout, $theirs) {
            TreasuryPayout::whereKey($payout->id)->update([
                'claim_token' => 'other-runner',
                'tx_hash' => $theirs,
                'raw_tx' => '0xother',
                'nonce' => 0,
                'from_address' => self::HOT,
                'broadcast_at' => now(),
            ]);
        };

        $this->payoutRound();

        $this->assertSame([], $this->signer->sent, 'ธุรกรรมที่บันทึกไม่ได้ต้องไม่ถูกส่ง');
        $this->assertSame($theirs, $payout->fresh()->tx_hash);
    }

    // ── L7: รายการค้างต้องมีคนรู้ ────────────────────────────────────────

    #[Test]
    public function ส่งไปนานแล้วยังไม่ลงบล็อก_แจ้งเตือน_หยุดคิว_แล้วปิดเองเมื่อลงบล็อก(): void
    {
        $hash = '0x'.str_repeat('d', 64);
        $stuck = $this->queued([
            'tx_hash' => $hash,
            'raw_tx' => '0xd00d',
            'nonce' => 0,
            'from_address' => self::HOT,
            'broadcast_at' => now()->subMinutes(15),
        ]);
        $this->known[$hash] = ['hash' => $hash, 'blockNumber' => null]; // ค้างใน mempool
        $waiting = $this->queued();

        $this->payoutRound();

        $alert = $this->stuckAlert();
        $this->assertNotNull($alert);
        $this->assertStringContainsString("#{$stuck->id}", $alert->message);
        $this->assertStringContainsString('ยังไม่ลงบล็อก', $alert->message);
        $this->assertSame([], $this->signer->signed, 'ห้ามเซ็นตัวใหม่ทับ ระหว่างที่ตัวก่อนยังลงบล็อกได้');

        $this->mined($hash);
        $this->payoutRound();

        $this->assertSame(TreasuryPayout::STATUS_CONFIRMED, $stuck->fresh()->status);
        $this->assertNull($this->stuckAlert(), 'ลงบล็อกแล้วคาดแดงต้องปิดเอง');
        $this->assertNotNull($waiting->fresh()->tx_hash, 'คิวเดินต่อหลังตัวก่อนจบ');
    }

    #[Test]
    public function claim_ที่ใหญ่กว่าวงเงินต่อครั้ง_ถูกปฏิเสธก่อนแตะรายการซื้อ(): void
    {
        $sale = TokenSale::create([
            'name' => 'TPIX Private Sale', 'slug' => 'tpix-private-'.uniqid(), 'status' => 'active',
            'total_supply_for_sale' => 700000000, 'sale_wallet_address' => '0x'.str_repeat('ab', 20),
        ]);
        $phase = SalePhase::create([
            'token_sale_id' => $sale->id, 'name' => 'Phase', 'slug' => 'phase-'.uniqid(), 'phase_order' => 1,
            'price_usd' => 0.05, 'allocation' => 200000000, 'min_purchase' => 1, 'max_purchase' => 10000000,
            'status' => 'active', 'vesting_tge_percent' => 100, 'vesting_cliff_days' => 0, 'vesting_duration_days' => 0,
        ]);
        $tx = SaleTransaction::create([
            'token_sale_id' => $sale->id, 'sale_phase_id' => $phase->id, 'wallet_address' => self::BUYER,
            'payment_currency' => 'CARD', 'payment_amount' => 100000, 'payment_usd_value' => 100000,
            'tpix_amount' => 2000000, 'price_per_tpix' => 0.05, 'tx_hash' => 'stripe:cs_test_'.uniqid(),
            'status' => 'confirmed', 'vesting_start_at' => now(),
        ]);

        $this->withoutMiddleware();
        $claim = fn (int $amount) => $this->postJson('/api/v1/token-sale/claim', [
            'wallet_address' => self::BUYER, 'transaction_id' => $tx->uuid, 'amount' => $amount,
        ]);

        // เกินวงเงินต่อครั้ง 1,000,000 — ถ้าปล่อยผ่าน จะได้รายการจ่ายที่อนุมัติ/ส่งไม่ได้ตลอดไป
        $claim(1500000)->assertStatus(400)->assertJsonPath('error.code', 'EXCEEDS_PAYOUT_LIMIT');
        $this->assertEquals(0, (float) $tx->fresh()->claimed_amount);
        $this->assertSame(0, TreasuryPayout::count());

        // แบ่งเป็นก้อนในวงเงิน — ผ่าน และเป็นรายการที่ระบบสร้าง (ยกเว้น whitelist)
        $claim(1000000)->assertOk()->assertJsonPath('success', true);
        $payout = TreasuryPayout::sole();
        $this->assertSame(TreasuryPayout::ORIGIN_SALE_CLAIM, $payout->origin);
        $this->assertSame(Wei::toWei('1000000'), (string) $payout->amount_wei);
        $this->assertTrue(app(TreasuryService::class)->validatePayout(
            $payout->to_address,
            (string) $payout->amount_wei,
            $payout->id,
            $payout->origin,
        )['ok']);
    }

    // ── C3: whitelist ยกเว้นเฉพาะรายการที่ระบบสร้าง ─────────────────────

    #[Test]
    public function แอดมินสร้างหรืออนุมัติรายการไปกระเป๋านอก_whitelist_ไม่ได้_แม้เลือก_token_sale(): void
    {
        $admin = AdminUser::create([
            'name' => 'Owner', 'email' => 'owner@tpix.test', 'password' => bcrypt('x'),
            'role' => 'super_admin', 'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/treasury/payouts', [
                'to_address' => self::BUYER, 'amount' => '1000', 'purpose' => 'token_sale', 'idempotency_key' => 'manual-1',
            ])
            ->assertStatus(422)
            ->assertJsonPath('errors.0', 'ปลายทางนี้ไม่อยู่ใน whitelist');
        $this->assertSame(0, TreasuryPayout::count());

        // ปลายทางใน whitelist ยังสร้างได้ตามปกติ และถูกบันทึกเป็นรายการของแอดมิน
        $this->actingAs($admin, 'admin')
            ->postJson('/admin/treasury/payouts', [
                'to_address' => self::LISTED, 'amount' => '1000', 'purpose' => 'token_sale', 'idempotency_key' => 'manual-2',
            ])
            ->assertOk();
        $this->assertSame(TreasuryPayout::ORIGIN_ADMIN, TreasuryPayout::sole()->origin);

        // อนุมัติ: รายการจาก claim จริงผ่าน · รายการของแอดมินที่ตั้ง purpose เดียวกันไม่ผ่าน
        $fromClaim = $this->queued(['to_address' => self::BUYER, 'purpose' => 'token_sale', 'origin' => TreasuryPayout::ORIGIN_SALE_CLAIM, 'status' => TreasuryPayout::STATUS_PENDING]);
        $byAdmin = $this->queued(['to_address' => self::BUYER, 'purpose' => 'token_sale', 'status' => TreasuryPayout::STATUS_PENDING]);

        $this->actingAs($admin, 'admin')->postJson("/admin/treasury/payouts/{$fromClaim->id}/approve")->assertOk();
        $this->actingAs($admin, 'admin')->postJson("/admin/treasury/payouts/{$byAdmin->id}/approve")->assertStatus(422);
        $this->assertSame(TreasuryPayout::STATUS_PENDING, $byAdmin->fresh()->status);
    }

    #[Test]
    public function รายการของแอดมินที่ตั้ง_purpose_token_sale_ถูกหยุดตอนจะเซ็น(): void
    {
        // เช่นแถวที่เข้าคิวก่อนแก้ หรือปลายทางถูกถอดจาก whitelist ระหว่างรอคิว
        $payout = $this->queued(['to_address' => self::BUYER, 'purpose' => 'token_sale']);

        $this->payoutRound();
        $payout->refresh();

        $this->assertSame(TreasuryPayout::STATUS_FAILED, $payout->status);
        $this->assertStringContainsString('whitelist', (string) $payout->failure_reason);
        $this->assertSame([], $this->signer->signed);
    }

    #[Test]
    public function รายการจาก_claim_จริงส่งได้โดยไม่ต้องอยู่ใน_whitelist(): void
    {
        $payout = $this->queued(['to_address' => self::BUYER, 'purpose' => 'token_sale', 'origin' => TreasuryPayout::ORIGIN_SALE_CLAIM]);

        $this->payoutRound();
        $payout->refresh();

        $this->assertSame(TreasuryPayout::STATUS_BROADCASTING, $payout->status);
        $this->assertNotNull($payout->tx_hash);
        $this->assertCount(1, $this->signer->sent);
        $this->assertSame([], $this->signer->sentBeforePersist);
    }
}
