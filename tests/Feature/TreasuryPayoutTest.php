<?php

namespace Tests\Feature;

use App\Models\TreasuryLedger;
use App\Models\TreasuryPayout;
use App\Models\TreasuryWhitelist;
use App\Services\TreasuryService;
use App\Support\Wei;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * กฎของเงินในชั้นคลัง — เทสต์ชุดนี้กันความผิดพลาดที่ทำให้เงินหายหรือจ่ายซ้ำ.
 *
 * เน้นสี่เรื่อง:
 *   1. ระบบต้องไม่จ่ายเงินตอนที่ยังไม่พร้อม
 *   2. รายการที่ส่งไปแล้ว (มี tx_hash) ต้องไม่ถูกเซ็นใหม่เด็ดขาด
 *   3. ลงสมุดเฉพาะตอนเชนยืนยันว่าสำเร็จเท่านั้น
 *   4. วงเงินและ whitelist ต้องกันได้จริง
 */
class TreasuryPayoutTest extends TestCase
{
    use RefreshDatabase;

    private string $hotAddress = '0xced8640bf18b8cf9ca10af176fac6d8bf8d2dfe1';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'treasury.hot_wallet.address' => $this->hotAddress,
            'treasury.limits.per_transaction' => '1000000',
            'treasury.limits.per_day' => '10000000',
            'treasury.limits.require_whitelist' => true,
        ]);
    }

    /** ตอบ RPC ตามที่กำหนด — balance ของกระเป๋าร้อน, receipt, block ปัจจุบัน */
    private function fakeChain(string $hotBalanceWei = '0', ?array $receipt = null, string $head = '0x2270'): void
    {
        Http::fake(function ($request) use ($hotBalanceWei, $receipt, $head) {
            $body = json_decode($request->body(), true);

            return match ($body['method'] ?? '') {
                'eth_getBalance' => Http::response(['result' => '0x'.self::toHex($hotBalanceWei)]),
                'eth_getTransactionReceipt' => Http::response(['result' => $receipt]),
                default => Http::response(['result' => $head]),
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

    private function whitelisted(string $address = '0x4bcc1844ad9e8587f7005f092928a5d14c30f463'): TreasuryWhitelist
    {
        return TreasuryWhitelist::create([
            'address' => $address,
            'label' => 'ปลายทางทดสอบ',
            'purpose' => 'other',
            'is_active' => true,
        ]);
    }

    private function payout(string $status, array $extra = []): TreasuryPayout
    {
        return TreasuryPayout::create(array_merge([
            'idempotency_key' => 'test-'.uniqid('', true),
            'to_address' => '0x4bcc1844ad9e8587f7005f092928a5d14c30f463',
            'amount_wei' => Wei::toWei('1.5'),
            'purpose' => 'other',
            'status' => $status,
        ], $extra));
    }

    // ── 1. ต้องไม่จ่ายตอนยังไม่พร้อม ────────────────────────────────────

    public function test_readiness_blocks_when_hot_wallet_is_empty(): void
    {
        $this->fakeChain('0');
        config(['treasury.payouts_enabled' => true]);

        $readiness = app(TreasuryService::class)->readiness();

        $this->assertFalse($readiness['ready']);
        $this->assertContains('funded', array_column($readiness['blocking'], 'key'));
    }

    public function test_queued_payout_is_left_alone_when_system_not_ready(): void
    {
        $this->fakeChain('0');
        config(['treasury.payouts_enabled' => false]);

        $payout = $this->payout(TreasuryPayout::STATUS_BROADCASTING);
        Artisan::call('tpix:treasury-payouts');

        // ต้องคงสถานะไว้รอ ไม่ใช่ทำให้ล้มเหลว — ผู้ใช้จะได้ไม่ต้องสร้างใหม่
        $payout->refresh();
        $this->assertSame(TreasuryPayout::STATUS_BROADCASTING, $payout->status);
        $this->assertNull($payout->tx_hash);
    }

    // ── 2. ห้ามเซ็นซ้ำรายการที่ส่งไปแล้ว ────────────────────────────────

    public function test_payout_with_tx_hash_is_never_signed_again(): void
    {
        // receipt ยังไม่มี = tx ยังไม่เข้าบล็อก
        $this->fakeChain('0', null);
        config(['treasury.payouts_enabled' => true]);

        $hash = '0x'.str_repeat('ab', 32);
        $payout = $this->payout(TreasuryPayout::STATUS_BROADCASTING, ['tx_hash' => $hash]);

        Artisan::call('tpix:treasury-payouts');

        $payout->refresh();
        // hash เดิมต้องไม่ถูกเขียนทับ และสถานะยังรออยู่
        $this->assertSame($hash, $payout->tx_hash);
        $this->assertSame(TreasuryPayout::STATUS_BROADCASTING, $payout->status);
    }

    // ── 3. ลงสมุดเฉพาะตอนสำเร็จจริง ─────────────────────────────────────

    public function test_confirmed_receipt_marks_payout_and_writes_ledger_once(): void
    {
        $this->fakeChain('0', ['status' => '0x1', 'blockNumber' => '0x2260']);

        $hash = '0x'.str_repeat('cd', 32);
        $payout = $this->payout(TreasuryPayout::STATUS_BROADCASTING, ['tx_hash' => $hash]);

        Artisan::call('tpix:treasury-payouts');
        $payout->refresh();

        $this->assertSame(TreasuryPayout::STATUS_CONFIRMED, $payout->status);
        $this->assertSame(8800, $payout->block_number);

        $ledger = TreasuryLedger::where('tx_hash', $hash)->get();
        $this->assertCount(1, $ledger);
        $this->assertSame('hot_wallet', $ledger[0]->wallet_key);
        $this->assertSame(TreasuryLedger::DIRECTION_DEBIT, $ledger[0]->direction);
        $this->assertSame($payout->amount_wei, $ledger[0]->amount_wei);

        // รันซ้ำต้องไม่เพิ่มแถว ไม่งั้นตัวกระทบยอดจะเพี้ยน
        Artisan::call('tpix:treasury-payouts');
        $this->assertCount(1, TreasuryLedger::where('tx_hash', $hash)->get());
    }

    public function test_rejected_transaction_writes_no_ledger_entry(): void
    {
        $this->fakeChain('0', ['status' => '0x0', 'blockNumber' => '0x2260']);

        $hash = '0x'.str_repeat('ef', 32);
        $payout = $this->payout(TreasuryPayout::STATUS_BROADCASTING, ['tx_hash' => $hash]);

        Artisan::call('tpix:treasury-payouts');
        $payout->refresh();

        $this->assertSame(TreasuryPayout::STATUS_FAILED, $payout->status);
        // เงินไม่ได้ออกจริง ห้ามลงสมุด ไม่งั้นยอดที่ระบบเชื่อจะต่ำกว่าความจริง
        $this->assertCount(0, TreasuryLedger::where('tx_hash', $hash)->get());
    }

    // ── 4. วงเงินและ whitelist ──────────────────────────────────────────

    public function test_payout_to_address_outside_whitelist_is_rejected(): void
    {
        $this->fakeChain(Wei::toWei('1000000'));

        $check = app(TreasuryService::class)->validatePayout(
            '0x1111111111111111111111111111111111111111',
            Wei::toWei('1'),
        );

        $this->assertFalse($check['ok']);
        $this->assertNotEmpty(array_filter($check['errors'], fn ($e) => str_contains($e, 'whitelist')));
    }

    public function test_token_sale_payouts_skip_whitelist_but_other_purposes_do_not(): void
    {
        $this->fakeChain(Wei::toWei('99999999'));
        $buyer = '0x1111111111111111111111111111111111111111';   // ไม่อยู่ใน whitelist

        // ผู้ซื้อ claim vesting — ปลายทางเป็นกระเป๋าลูกค้าซึ่งเอาเข้า whitelist
        // ล่วงหน้าไม่ได้ จึงต้องผ่าน
        $sale = app(TreasuryService::class)->validatePayout($buyer, Wei::toWei('1'), null, 'token_sale');
        $this->assertTrue($sale['ok'], 'การจ่ายให้ผู้ซื้อควรผ่านโดยไม่ต้องอยู่ใน whitelist');

        // แต่การยกเว้นต้องไม่ลามไปวัตถุประสงค์อื่น
        foreach (['masternode', 'refund', 'other', null] as $purpose) {
            $other = app(TreasuryService::class)->validatePayout($buyer, Wei::toWei('1'), null, $purpose);
            $this->assertFalse($other['ok'], "purpose '{$purpose}' ต้องยังบังคับ whitelist");
        }
    }

    public function test_token_sale_payouts_still_obey_the_amount_limits(): void
    {
        $this->fakeChain(Wei::toWei('99999999'));

        // ยกเว้น whitelist ไม่ได้แปลว่ายกเว้นวงเงิน
        $check = app(TreasuryService::class)->validatePayout(
            '0x1111111111111111111111111111111111111111',
            Wei::toWei('2000000'),   // วงเงินต่อครั้งคือ 1,000,000
            null,
            'token_sale',
        );

        $this->assertFalse($check['ok']);
        $this->assertNotEmpty(array_filter($check['errors'], fn ($e) => str_contains($e, 'ต่อครั้ง')));
    }

    public function test_amount_over_per_transaction_limit_is_rejected(): void
    {
        $this->fakeChain(Wei::toWei('99999999'));
        $this->whitelisted();

        $check = app(TreasuryService::class)->validatePayout(
            '0x4bcc1844ad9e8587f7005f092928a5d14c30f463',
            Wei::toWei('2000000'),   // วงเงินต่อครั้งคือ 1,000,000
        );

        $this->assertFalse($check['ok']);
        $this->assertNotEmpty(array_filter($check['errors'], fn ($e) => str_contains($e, 'ต่อครั้ง')));
    }

    public function test_pending_payouts_count_toward_the_daily_limit(): void
    {
        $this->fakeChain(Wei::toWei('99999999'));
        $this->whitelisted();

        // ค้างอยู่ในคิวแล้ว 9.5M จากวงเงินวันละ 10M
        $this->payout(TreasuryPayout::STATUS_PENDING, ['amount_wei' => Wei::toWei('9500000')]);

        $check = app(TreasuryService::class)->validatePayout(
            '0x4bcc1844ad9e8587f7005f092928a5d14c30f463',
            Wei::toWei('900000'),
        );

        // ถ้าไม่นับ pending รายการนี้จะผ่าน แล้วพออนุมัติพร้อมกันจะทะลุวงเงิน
        $this->assertFalse($check['ok']);
        $this->assertNotEmpty(array_filter($check['errors'], fn ($e) => str_contains($e, 'ต่อวัน')));
    }

    public function test_insufficient_hot_wallet_balance_is_rejected(): void
    {
        $this->fakeChain(Wei::toWei('10'));
        $this->whitelisted();

        $check = app(TreasuryService::class)->validatePayout(
            '0x4bcc1844ad9e8587f7005f092928a5d14c30f463',
            Wei::toWei('1000'),
        );

        $this->assertFalse($check['ok']);
        $this->assertNotEmpty(array_filter($check['errors'], fn ($e) => str_contains($e, 'ไม่พอ')));
    }

    // ── 5. กันสร้างรายการซ้ำ ────────────────────────────────────────────

    public function test_duplicate_idempotency_key_is_rejected_by_database(): void
    {
        $key = 'same-key-twice';
        $this->payout(TreasuryPayout::STATUS_PENDING, ['idempotency_key' => $key]);

        $this->expectException(QueryException::class);
        $this->payout(TreasuryPayout::STATUS_PENDING, ['idempotency_key' => $key]);
    }

    // ── 6. readiness ต้องไม่ทำให้หน้าพัง ────────────────────────────────

    public function test_readiness_never_throws_on_unreachable_keystore_path(): void
    {
        $this->fakeChain('0');

        // พาธที่ PHP แตะไม่ได้ — ของจริงคือ /etc/tpix/ ที่อยู่นอก open_basedir
        // is_readable() จะปล่อย warning ซึ่ง Laravel แปลงเป็น ErrorException
        // แล้วหน้าคลังทั้งหน้ากลายเป็น 500 ทั้งที่แค่ยังไม่ได้วางไฟล์
        foreach (['/etc/tpix/hot-wallet.keystore.json', '/root/secret.json', ''] as $path) {
            config(['treasury.hot_wallet.keystore_path' => $path]);

            $readiness = app(TreasuryService::class)->readiness();

            $this->assertFalse($readiness['ready']);
            $this->assertContains('keystore', array_column($readiness['blocking'], 'key'));

            // ต้องบอกเหตุผลได้ ไม่ใช่เงียบ
            $keystoreCheck = collect($readiness['checks'])->firstWhere('key', 'keystore');
            $this->assertNotEmpty($keystoreCheck['hint']);
        }
    }

    public function test_readiness_accepts_a_keystore_inside_an_allowed_path(): void
    {
        $this->fakeChain('0');

        $path = storage_path('framework/testing/fake-keystore.json');
        @mkdir(dirname($path), 0700, true);
        file_put_contents($path, '{"version":3}');

        config(['treasury.hot_wallet.keystore_path' => $path]);
        $readiness = app(TreasuryService::class)->readiness();

        $keystoreCheck = collect($readiness['checks'])->firstWhere('key', 'keystore');
        $this->assertTrue($keystoreCheck['ok'], 'ไฟล์ที่มีจริงและอ่านได้ต้องผ่าน');

        @unlink($path);
    }

    // ── 7. อ่านยอดไม่ได้ ต้องไม่กลายเป็นศูนย์ ──────────────────────────

    public function test_unreadable_balance_is_null_not_zero(): void
    {
        Http::fake(fn () => Http::response('เซิร์ฟเวอร์ล่ม', 503));

        $hot = app(TreasuryService::class)->hotWallet();

        // ถ้าคืน '0' หน้าจอจะโชว์ว่ากระเป๋าว่างทั้งที่แค่อ่านไม่ได้
        $this->assertNull($hot['balance_wei']);
        $this->assertFalse($hot['readable']);
        $this->assertFalse($hot['is_empty']);
    }
}
