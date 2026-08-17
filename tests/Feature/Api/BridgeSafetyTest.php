<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessBridgeJob;
use App\Models\BridgeTransaction;
use App\Services\BridgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — ลำดับการ bridge ต้องไม่ทำให้เหรียญหายแบบไม่มีร่องรอย.
 *
 * ลำดับเดิมคือ "โอนเหรียญบนเชนก่อน แล้วค่อยบอก backend" ซึ่งถ้า backend ปฏิเสธ
 * (ลายเซ็นหมดอายุ · IP เปลี่ยนเพราะสลับ WiFi ↔ 4G · แอดมินเพิ่งปิดบริการ)
 * เหรียญออกจากกระเป๋าไปแล้วโดยไม่มีรายการในระบบเลย ผู้ใช้ไม่มีอะไรไปเคลม
 *
 * ลำดับใหม่: จองก่อน → ถูกปฏิเสธก็หยุด เหรียญยังอยู่ → ค่อยโอน → แล้วแนบ hash
 *
 * Developed by Xman Studio.
 */
class BridgeSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0xcccccccccccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Queue::fake();

        // ตั้งค่าที่อยู่สัญญาให้ครบ ไม่งั้น bridge ถือว่าปิดบริการ
        config([
            'services.bridge.treasury_address' => '0x1111111111111111111111111111111111111111',
            'services.bridge.wtpix_bsc_address' => '0x2222222222222222222222222222222222222222',
        ]);

        Cache::put('wallet_verified:'.self::WALLET, [
            'ip' => '127.0.0.1',
            'verified_at' => now()->toIso8601String(),
        ], now()->addHours(4));
    }

    // ─────────────────── bridge ต้องปิดเมื่อยังไม่ได้ตั้งค่า ───────────────────

    /**
     * ⭐ ที่อยู่สัญญาว่าง = ปิดบริการ ไม่ใช่เปิดแล้วพังตอนกด.
     */
    #[Test]
    public function the_bridge_reports_itself_disabled_when_contract_addresses_are_missing(): void
    {
        config([
            'services.bridge.treasury_address' => '',
            'services.bridge.wtpix_bsc_address' => '',
        ]);

        $this->assertFalse(app(BridgeService::class)->isEnabled());

        $this->getJson('/api/v1/bridge/info')
            ->assertOk()
            ->assertJsonPath('data.enabled', false);
    }

    #[Test]
    public function a_half_configured_bridge_is_still_disabled(): void
    {
        // ตั้งมาแค่ฝั่งเดียวก็ใช้จริงไม่ได้ — ต้องปิดทั้งคู่
        config(['services.bridge.wtpix_bsc_address' => '']);

        $this->assertFalse(app(BridgeService::class)->isEnabled());
    }

    #[Test]
    public function initiating_is_refused_while_the_bridge_is_disabled(): void
    {
        config(['services.bridge.treasury_address' => '']);

        $this->postJson('/api/v1/bridge/initiate', [
            'wallet_address' => self::WALLET,
            'amount' => 100,
            'direction' => 'tpix_to_bsc',
        ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'BRIDGE_DISABLED');

        $this->assertSame(0, BridgeTransaction::count());
    }

    // ─────────────────── จองก่อน แล้วค่อยแนบ hash ───────────────────

    /**
     * ⭐ จองได้โดยยังไม่มี tx_hash — นี่คือขั้นที่เกิดก่อนเหรียญออกจากกระเป๋า.
     */
    #[Test]
    public function a_transfer_can_be_reserved_before_any_coins_move(): void
    {
        $response = $this->postJson('/api/v1/bridge/initiate', [
            'wallet_address' => self::WALLET,
            'amount' => 100,
            'direction' => 'tpix_to_bsc',
        ])->assertCreated();

        $id = $response->json('data.id');

        $this->assertNotNull($id);

        $tx = BridgeTransaction::find($id);
        $this->assertNotNull($tx);
        $this->assertNull($tx->source_tx_hash, 'ยังไม่ควรมี tx hash ตอนจอง');

        // ยังไม่มีอะไรให้ประมวลผลจนกว่าจะมี hash จริง
        Queue::assertNotPushed(ProcessBridgeJob::class);
    }

    /**
     * ⭐ แนบ hash แล้วระบบถึงเริ่มทำงาน.
     */
    #[Test]
    public function attaching_the_transaction_hash_starts_processing(): void
    {
        $id = $this->postJson('/api/v1/bridge/initiate', [
            'wallet_address' => self::WALLET,
            'amount' => 100,
            'direction' => 'tpix_to_bsc',
        ])->json('data.id');

        $hash = '0x'.str_repeat('a', 64);

        $this->postJson("/api/v1/bridge/{$id}/tx", [
            'wallet_address' => self::WALLET,
            'tx_hash' => $hash,
        ])
            ->assertOk()
            ->assertJsonPath('data.tx_hash', $hash)
            ->assertJsonPath('data.status', 'processing');

        Queue::assertPushed(ProcessBridgeJob::class);
        $this->assertSame($hash, BridgeTransaction::find($id)->source_tx_hash);
    }

    /**
     * หน้าเว็บอาจยิงซ้ำตอนเน็ตกระตุก — hash เดิมต้องไม่พังและไม่สั่งงานซ้ำ.
     */
    #[Test]
    public function re_attaching_the_same_hash_is_safe_and_does_not_double_process(): void
    {
        $id = $this->postJson('/api/v1/bridge/initiate', [
            'wallet_address' => self::WALLET,
            'amount' => 100,
            'direction' => 'tpix_to_bsc',
        ])->json('data.id');

        $hash = '0x'.str_repeat('b', 64);
        $payload = ['wallet_address' => self::WALLET, 'tx_hash' => $hash];

        $this->postJson("/api/v1/bridge/{$id}/tx", $payload)->assertOk();
        $this->postJson("/api/v1/bridge/{$id}/tx", $payload)->assertOk();

        Queue::assertPushed(ProcessBridgeJob::class, 1);
    }

    /**
     * ⭐ รายการหนึ่งผูกกับธุรกรรมเดียว — เปลี่ยน hash ทีหลังไม่ได้.
     */
    #[Test]
    public function a_reservation_cannot_be_pointed_at_a_different_transaction(): void
    {
        $id = $this->postJson('/api/v1/bridge/initiate', [
            'wallet_address' => self::WALLET,
            'amount' => 100,
            'direction' => 'tpix_to_bsc',
        ])->json('data.id');

        $this->postJson("/api/v1/bridge/{$id}/tx", [
            'wallet_address' => self::WALLET,
            'tx_hash' => '0x'.str_repeat('c', 64),
        ])->assertOk();

        $this->postJson("/api/v1/bridge/{$id}/tx", [
            'wallet_address' => self::WALLET,
            'tx_hash' => '0x'.str_repeat('d', 64),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ALREADY_ATTACHED');
    }

    #[Test]
    public function another_wallet_cannot_attach_to_your_reservation(): void
    {
        $id = $this->postJson('/api/v1/bridge/initiate', [
            'wallet_address' => self::WALLET,
            'amount' => 100,
            'direction' => 'tpix_to_bsc',
        ])->json('data.id');

        $other = '0xdddddddddddddddddddddddddddddddddddddddd';
        Cache::put('wallet_verified:'.$other, ['ip' => '127.0.0.1', 'verified_at' => now()->toIso8601String()], now()->addHour());

        $this->postJson("/api/v1/bridge/{$id}/tx", [
            'wallet_address' => $other,
            'tx_hash' => '0x'.str_repeat('e', 64),
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');

        Queue::assertNotPushed(ProcessBridgeJob::class);
    }

    #[Test]
    public function a_malformed_transaction_hash_is_rejected(): void
    {
        $id = $this->postJson('/api/v1/bridge/initiate', [
            'wallet_address' => self::WALLET,
            'amount' => 100,
            'direction' => 'tpix_to_bsc',
        ])->json('data.id');

        $this->postJson("/api/v1/bridge/{$id}/tx", [
            'wallet_address' => self::WALLET,
            'tx_hash' => 'ไม่ใช่แฮช',
        ])->assertStatus(422);
    }
}
