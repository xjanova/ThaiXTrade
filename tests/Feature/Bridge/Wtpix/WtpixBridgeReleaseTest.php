<?php

namespace Tests\Feature\Bridge\Wtpix;

use App\Models\WtpixBridgeTransfer;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\Wtpix\WtpixAbi;
use App\Services\Bridge\Wtpix\WtpixBridgeConfig;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesWtpixBridgeChains;
use Tests\TestCase;

/**
 * ทิศ BSC → TPIX: vault.release() บนเชน 4289 (legacy · gasPrice 0) — ต้องไม่ปล่อยซ้ำ.
 */
class WtpixBridgeReleaseTest extends TestCase
{
    use FakesWtpixBridgeChains;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWtpixBridge();
        $this->chain[4289]['native'][$this->wVault] = $this->tpix('10000');
    }

    private function burnRow(string $amount = '300', array $extra = []): WtpixBridgeTransfer
    {
        static $n = 0;
        $n++;
        $tx = '0x'.hash('sha256', "wtpix-release-{$n}");
        $wei = $this->tpix($amount);

        return WtpixBridgeTransfer::create(array_merge([
            'direction' => WtpixBridgeTransfer::TO_TPIX,
            'source_chain_id' => 56,
            'source_contract' => $this->wToken,
            'tx_hash' => $tx,
            'log_index' => 3,
            'block_number' => 4900 + $n,
            'from_address' => $this->bob,
            'recipient' => $this->bob,
            'amount_wei' => $wei,
            'fee_wei' => '0',
            'payout_wei' => $wei,
            'deposit_id' => Abi::depositId(56, $tx, 3),
            'status' => WtpixBridgeTransfer::STATUS_CONFIRMED,
            'dest_chain_id' => 4289,
        ], $extra));
    }

    private function relay(): void
    {
        $this->artisan('bridge:wtpix-relay', ['--direction' => 'to_tpix'])->assertSuccessful();
    }

    #[Test]
    public function a_confirmed_burn_releases_tpix_from_the_vault_once_with_gas_price_zero(): void
    {
        $row = $this->burnRow();

        $this->relay();
        $this->relay();

        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $row->fresh()->status);
        $calls = $this->signCallsFor($this->wRelayer);
        $this->assertCount(1, $calls);
        $this->assertSame('4289', $calls[0]['BRIDGE_CHAIN_ID']);
        $this->assertSame('0', $calls[0]['BRIDGE_GAS_PRICE_WEI']);
        $this->assertSame($this->wVault, strtolower($calls[0]['BRIDGE_TO']));
        $this->assertSame(WtpixAbi::releaseCalldata($this->bob, $this->tpix('300'), $row->deposit_id), $calls[0]['BRIDGE_DATA']);
        $this->assertSame($this->tpix('300'), $this->chain[4289]['native'][$this->bob]);
        $this->assertSame($this->tpix('9700'), $this->chain[4289]['native'][$this->wVault]);
    }

    #[Test]
    public function a_crash_after_signing_rebroadcasts_the_same_raw_and_releases_only_once(): void
    {
        $row = $this->burnRow();
        $this->sendFailCode = 'broadcast_failed';
        $this->relay();
        $raw = $row->fresh()->dest_raw_tx;

        $this->sendFailCode = null;
        $this->travel(3)->minutes();
        $this->relay();
        $this->relay();

        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $row->fresh()->status);
        $this->assertCount(1, $this->signCallsFor($this->wRelayer));
        $this->assertSame($raw, end($this->sendCalls));
        $this->assertSame($this->tpix('300'), $this->chain[4289]['native'][$this->bob]);
    }

    #[Test]
    public function nothing_is_released_while_the_vault_is_paused(): void
    {
        $row = $this->burnRow();
        $this->chain[4289]['vault']['paused'] = true;

        $this->relay();

        $this->assertSame([], $this->signCalls);
        $this->assertSame(WtpixBridgeTransfer::STATUS_CONFIRMED, $row->fresh()->status);
    }

    #[Test]
    public function over_the_vault_caps_is_reviewed_or_waits_for_the_next_utc_day(): void
    {
        $big = $this->burnRow('150000');
        $this->chain[4289]['native'][$this->wVault] = $this->tpix('1000000');
        $this->chain[4289]['vault']['remaining'] = $this->tpix('50');
        $normal = $this->burnRow('300');

        $this->relay();

        $this->assertSame([WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'exceeds_onchain_max'], [$big->fresh()->status, $big->fresh()->review_reason]);
        $this->assertSame(WtpixBridgeTransfer::STATUS_CONFIRMED, $normal->fresh()->status);
        $this->assertSame([], $this->signCalls);
    }

    #[Test]
    public function a_vault_short_of_tpix_waits_as_awaiting_liquidity_and_never_pays_partially(): void
    {
        $row = $this->burnRow('300');
        $this->chain[4289]['native'][$this->wVault] = $this->tpix('100');

        $this->relay();

        $this->assertSame(WtpixBridgeTransfer::STATUS_AWAITING_LIQUIDITY, $row->fresh()->status);
        $this->assertSame([], $this->signCalls);
    }

    #[Test]
    public function a_deposit_id_released_by_someone_else_is_held_and_halts_the_bridge(): void
    {
        $row = $this->burnRow();
        $this->chain[4289]['vault']['processed'][$row->deposit_id] = true;

        $this->relay();

        $this->assertSame([WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'released_elsewhere'], [$row->fresh()->status, $row->fresh()->review_reason]);
        $this->assertSame([], $this->signCallsFor($this->wRelayer));
        $this->assertFalse(app(WtpixBridgeConfig::class)->siteSwitchOn());
        $this->assertTrue($this->chain[4289]['vault']['paused']);
    }

    #[Test]
    public function a_reverted_release_goes_to_review(): void
    {
        $row = $this->burnRow();
        $this->mineOnSend = false;
        $this->relay();
        $hash = $row->fresh()->dest_tx_hash;

        $this->revertOnMine[$hash] = true;
        $this->mineHash($hash);
        $this->relay();

        $this->assertSame([WtpixBridgeTransfer::STATUS_NEEDS_REVIEW, 'release_reverted'], [$row->fresh()->status, $row->fresh()->review_reason]);
        $this->assertCount(1, $this->signCallsFor($this->wRelayer));
    }

    #[Test]
    public function an_unreadable_tpix_chain_blocks_releases_but_not_mints(): void
    {
        $burn = $this->burnRow();
        $this->chain[4289]['down'] = true;
        $lockTx = '0x'.hash('sha256', 'lock-while-tpix-down');
        $lock = WtpixBridgeTransfer::create([
            'direction' => WtpixBridgeTransfer::TO_BSC, 'source_chain_id' => 4289, 'source_contract' => $this->wVault,
            'tx_hash' => $lockTx, 'log_index' => 0, 'block_number' => 1990, 'from_address' => $this->alice, 'recipient' => $this->alice,
            'amount_wei' => $this->tpix('50'), 'fee_wei' => $this->tpix('1'), 'payout_wei' => $this->tpix('49'),
            'deposit_id' => Abi::depositId(4289, $lockTx, 0), 'status' => WtpixBridgeTransfer::STATUS_CONFIRMED,
        ]);

        $this->artisan('bridge:wtpix-relay')->assertSuccessful();

        $this->assertSame(WtpixBridgeTransfer::STATUS_CONFIRMED, $burn->fresh()->status);
        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $lock->fresh()->status);
    }

    #[Test]
    public function a_relayer_key_shared_with_the_usdt_bridge_waits_for_the_usdt_relay_lock(): void
    {
        // เจ้าของชี้กุญแจสองสะพานไปไฟล์เดียวกัน — relay ของ USDT ถือ nonce บนเชน 4289 อยู่ ห้ามเซ็นแทรก
        config(['bridge.signers.relayer.keystore' => config('wtpix_bridge.signers.relayer.keystore'), 'bridge.signers.relayer.passphrase' => 'x']);
        Cache::flush();
        $this->assertSame($this->wRelayer, app(BridgeConfig::class)->signerAddress('relayer'));
        $row = $this->burnRow();

        $held = Cache::lock('bridge:relayer:lock', 600);
        $this->assertTrue($held->get());
        $this->relay();
        $this->assertSame([], $this->signCalls);
        $held->release();

        $this->relay();
        $this->assertSame(WtpixBridgeTransfer::STATUS_COMPLETED, $row->fresh()->status);
    }
}
