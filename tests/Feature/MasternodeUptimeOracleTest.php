<?php

namespace Tests\Feature;

use App\Models\MasternodeUptimePeriod;
use App\Services\MasterNode\ChainHeadService;
use App\Services\MasterNode\NodeRegistryContract;
use App\Services\MasterNode\UptimeCalculator;
use App\Services\MasterNode\UptimeOracleService;
use App\Services\MasterNode\UptimeOracleSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * งานปิดรอบ uptime → updateUptimeBatch.
 *
 * โหนดในรอบทดสอบ (รอบละ 12 ช่อง × 5 นาที):
 *   A  รันครบทั้งรอบ และ bootnode เห็นทุกช่อง                        → 100%
 *   B  stake ไว้เฉย ๆ ไม่ส่งอะไรเลย (ลงทะเบียนก่อนตั้ง oracle = 100% บนเชน) → 0%
 *   C  ลงทะเบียนกลางรอบ รันครบทุกช่องหลังจากนั้น                       → 100% (ไม่ถูกหักช่องก่อนเป็นสมาชิก)
 *   D  ส่ง heartbeat ที่แฮชถูกทุกช่อง แต่ bootnode ไม่เคยเห็น (สคริปต์ปลอม) → 0%
 *   E  RPC ของเราตรวจบล็อกไม่ได้ (null) แต่ bootnode เห็นทุกช่อง          → 100%
 */
class MasternodeUptimeOracleTest extends TestCase
{
    use RefreshDatabase;

    private const ORACLE = '0x1111111111111111111111111111111111111111';
    private const REGISTRY = '0x2222222222222222222222222222222222222222';
    private const OWNER = '0x3333333333333333333333333333333333333333';
    private const DEPLOYER = '0x4444444444444444444444444444444444444444';
    private const CODE_HASH = '0xd7e2e84e40b4abe29cb54e3ee0cba2cd1077a4d6b26f62a25b6f8e713c49ebd9';

    private const A = '0x00000000000000000000000000000000000000aa';
    private const B = '0x00000000000000000000000000000000000000bb';
    private const C = '0x00000000000000000000000000000000000000cc';
    private const D = '0x00000000000000000000000000000000000000dd';
    private const E = '0x00000000000000000000000000000000000000ee';

    private int $period;

    private $registry;

    private $signer;

    private $chain;

    /** @var array<string,int> on-chain uptime ก่อนรอบ */
    private array $onchain = [];

    /** @var array<int,string> calldata ที่ถูกส่งให้เซ็น */
    private array $signed = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->period = (new UptimeCalculator())->lastClosedPeriodStart(now()->timestamp);
        $first = intdiv($this->period, 300);

        $this->onchain = [self::A => 10000, self::B => 10000, self::C => 0, self::D => 10000, self::E => 5000];
        $registered = [
            self::A => $this->period - 86400,
            self::B => $this->period - 86400,
            self::C => $this->period + 1800,
            self::D => $this->period - 86400,
            self::E => $this->period - 86400,
        ];

        for ($s = $first; $s < $first + 12; $s++) {
            $this->slot(self::A, $s, 'peerA', true);
            $this->seen('peerA', $s);
            if ($s >= $first + 6) {
                $this->slot(self::C, $s, 'peerC', true);
                $this->seen('peerC', $s);
            }
            $this->slot(self::D, $s, 'peerD', true);
            $this->slot(self::E, $s, 'peerE', null);
            $this->seen('peerE', $s);
            DB::table('masternode_peer_reports')->insert(['source' => 'tpix-bootnode', 'slot' => $s, 'reports' => 5, 'peers' => 3]);
        }

        $this->registry = Mockery::mock(NodeRegistryContract::class);
        $this->registry->shouldReceive('deploymentState')->andReturn(true)->byDefault();
        $this->registry->shouldReceive('address')->andReturn(self::REGISTRY);
        // งานต้องไม่พึ่ง getActiveOperators() (พังเมื่อเกิน ~1,100 กระเป๋า) — เรียกเมื่อไหร่เทสต์ล้ม
        $this->registry->shouldNotReceive('activeOperators');
        $this->registry->shouldReceive('activeNodes')->andReturnUsing(fn () => array_map(
            fn ($w) => ['status' => 'active', 'registered_at' => $registered[$w], 'uptime' => $this->onchain[$w]],
            array_combine(array_keys($registered), array_keys($registered)),
        ))->byDefault();
        $this->registry->shouldReceive('uptimeOracle')->andReturn('0x'.str_repeat('0', 40))->byDefault();
        $this->registry->shouldReceive('runtimeCodeHash')->andReturn(self::CODE_HASH)->byDefault();
        $this->registry->shouldReceive('owner')->andReturn(self::OWNER)->byDefault();

        $this->signer = Mockery::mock(UptimeOracleSigner::class);
        $this->signer->shouldReceive('configured')->andReturn(true)->byDefault();
        $this->signer->shouldReceive('expectedAddress')->andReturn(self::ORACLE);
        $this->signer->shouldReceive('sign')->andReturnUsing(function ($to, $data) {
            $this->signed[] = $data;

            return ['ok' => true, 'txHash' => '0x'.str_pad(dechex(count($this->signed)), 64, '0', STR_PAD_LEFT), 'raw' => '0xf8'];
        })->byDefault();
        $this->signer->shouldReceive('send')->andReturn(['ok' => true])->byDefault();

        $this->chain = Mockery::mock(ChainHeadService::class);
        $this->chain->shouldReceive('transactionReceipt')->andReturn(['status' => true, 'block_number' => 1])->byDefault();
    }

    public function test_shadow_mode_computes_everything_and_sends_nothing(): void
    {
        $summary = $this->service()->run($this->period);

        $this->assertSame('shadow', $summary['mode']);
        $this->assertSame('oracle_not_set_on_chain', $summary['shadow_reason']);
        $this->assertFalse($summary['degraded']);
        $this->assertSame([], $this->signed);

        $this->assertPeriod(self::A, 12, 12, 10000);
        $this->assertPeriod(self::B, 12, 0, 0);
        $this->assertPeriod(self::C, 6, 6, 10000);
        $this->assertPeriod(self::D, 12, 0, 0);
        $this->assertPeriod(self::E, 12, 12, 10000);
        $this->assertSame(5, MasternodeUptimePeriod::where('status', 'shadow')->count());
    }

    public function test_live_mode_sends_only_changed_nodes_and_confirms(): void
    {
        $this->registry->shouldReceive('uptimeOracle')->andReturn(self::ORACLE);

        $summary = $this->service()->run($this->period);

        $this->assertSame('live', $summary['mode']);
        $this->assertSame(4, $summary['confirmed']);
        $this->assertCount(1, $this->signed);
        // A อยู่ที่ 100% อยู่แล้ว ไม่ต้องส่ง · B, D ถูกลดเป็น 0 · C, E ขึ้นเป็น 100%
        $this->assertSame(
            UptimeOracleService::encodeBatch([self::B, self::C, self::D, self::E], [0, 10000, 0, 10000]),
            $this->signed[0],
        );
        $this->assertSame('unchanged', MasternodeUptimePeriod::where('wallet_address', self::A)->value('status'));
        $this->assertSame(4, MasternodeUptimePeriod::where('status', 'confirmed')->whereNotNull('tx_hash')->count());
    }

    public function test_missing_bootnode_reports_never_lower_anyone(): void
    {
        $this->registry->shouldReceive('uptimeOracle')->andReturn(self::ORACLE);
        DB::table('masternode_peer_reports')->delete();

        $summary = $this->service()->run($this->period);

        $this->assertTrue($summary['degraded']);
        $this->assertContains('peer_feed_gap', $summary['reasons']);
        // B วัดได้ 0 แต่รอบนี้ข้อมูลเราขาด → คงค่าบนเชนไว้ · C ยังขึ้นได้
        $this->assertSame(10000, MasternodeUptimePeriod::where('wallet_address', self::B)->value('target_bps'));
        $this->assertSame('unchanged', MasternodeUptimePeriod::where('wallet_address', self::B)->value('status'));
        $this->assertSame(
            UptimeOracleService::encodeBatch([self::C, self::E], [10000, 10000]),
            $this->signed[0],
        );
    }

    public function test_web_outage_after_a_normal_period_is_degraded(): void
    {
        // รอบก่อนมี 3 กระเป๋าส่งปกติ รอบนี้ไม่มีสักแถว = เว็บรับไม่ได้ ไม่ใช่ทุกคนดับพร้อมกัน
        $first = intdiv($this->period, 300);
        DB::table('masternode_uptime_slots')->delete();
        foreach ([self::A, self::D, self::E] as $w) {
            $this->slot($w, $first - 3, 'p'.$w, true);
        }

        $summary = $this->service()->run($this->period);

        $this->assertContains('heartbeat_intake_gap', $summary['reasons']);
    }

    public function test_nobody_running_from_the_start_is_not_an_outage(): void
    {
        // เครือข่ายที่ยังไม่มีใครรันโหนด — คนที่ stake เฉย ๆ ต้องถูกลดเป็น 0 ได้ตั้งแต่รอบแรก
        DB::table('masternode_uptime_slots')->delete();

        $summary = $this->service()->run($this->period);

        $this->assertFalse($summary['degraded']);
        $this->assertPeriod(self::B, 12, 0, 0);
    }

    public function test_failed_signing_marks_rows_failed_and_stops_later_batches(): void
    {
        config(['masternode.uptime.batch_size' => 1]);
        $this->registry->shouldReceive('uptimeOracle')->andReturn(self::ORACLE);
        $this->signer->shouldReceive('sign')->andReturn(['ok' => false, 'code' => 'estimate_failed', 'error' => 'Not owner or uptime oracle']);

        $summary = $this->service()->run($this->period);

        $this->assertSame(4, $summary['failed']);
        $this->assertSame(4, MasternodeUptimePeriod::where('status', 'failed')->count());
        $this->assertStringContainsString(
            'Not owner or uptime oracle',
            (string) MasternodeUptimePeriod::where('wallet_address', self::B)->value('error')
        );
    }

    public function test_reverted_transaction_is_failed(): void
    {
        $this->registry->shouldReceive('uptimeOracle')->andReturn(self::ORACLE);
        $this->chain->shouldReceive('transactionReceipt')->andReturn(['status' => false, 'block_number' => 9]);

        $this->service()->run($this->period);

        $this->assertSame('transaction reverted', MasternodeUptimePeriod::where('wallet_address', self::C)->value('error'));
    }

    public function test_a_processed_period_is_never_rewritten(): void
    {
        $this->registry->shouldReceive('uptimeOracle')->andReturn(self::ORACLE);
        $this->service()->run($this->period);
        $hash = MasternodeUptimePeriod::where('wallet_address', self::B)->value('tx_hash');

        $again = $this->service()->run($this->period);

        $this->assertSame('already_processed', $again['mode']);
        $this->assertCount(1, $this->signed);
        $this->assertSame($hash, MasternodeUptimePeriod::where('wallet_address', self::B)->value('tx_hash'));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->registry->shouldReceive('uptimeOracle')->andReturn(self::ORACLE);

        $summary = $this->service()->run($this->period, dryRun: true);

        $this->assertSame('dry_run', $summary['mode']);
        $this->assertSame(0, $summary['results'][self::B]['bps']);
        $this->assertDatabaseCount('masternode_uptime_periods', 0);
        $this->assertSame([], $this->signed);
    }

    public function test_unconfigured_key_or_foreign_oracle_stays_in_shadow(): void
    {
        $this->registry->shouldReceive('uptimeOracle')->andReturn('0x9999999999999999999999999999999999999999');
        $this->assertSame('oracle_on_chain_is_another_key', $this->service()->shadowReason());

        $this->signer->shouldReceive('configured')->andReturn(false);
        $this->assertSame('oracle_key_not_configured', $this->service()->shadowReason());
    }

    public function test_unknown_bytecode_aborts_even_a_dry_run(): void
    {
        // ที่อยู่ในหลังบ้านถูกชี้ไปสัญญาอื่น (หรือสัญญาเก่า 0x9c36…) — ห้ามคิด/ส่งอะไรจากมัน
        $this->registry->shouldReceive('runtimeCodeHash')->andReturn('0x'.str_repeat('ab', 32));

        try {
            $this->service()->run($this->period, dryRun: true);
            $this->fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('bytecode', $e->getMessage());
        }
        $this->assertDatabaseCount('masternode_uptime_periods', 0);
    }

    public function test_oracle_key_must_not_be_the_owner_or_the_deployer_key(): void
    {
        $this->registry->shouldReceive('uptimeOracle')->andReturn(self::ORACLE);

        $this->registry->shouldReceive('owner')->andReturn(self::ORACLE);
        $this->assertSame('oracle_key_is_owner_or_deployer', $this->service()->shadowReason());

        $sameAsDeployer = new UptimeOracleService(
            $this->registry,
            new UptimeCalculator(),
            $this->signer,
            $this->chain,
            fn () => null,
            fn () => self::ORACLE,
        );
        $this->assertSame('oracle_key_is_owner_or_deployer', $sameAsDeployer->shadowReason());
    }

    public function test_unreadable_owner_keeps_shadow_mode(): void
    {
        $this->registry->shouldReceive('uptimeOracle')->andReturn(self::ORACLE);
        $this->registry->shouldReceive('owner')->andReturn(null);

        $summary = $this->service()->run($this->period);

        $this->assertSame('shadow', $summary['mode']);
        $this->assertSame('cannot_read_owner', $summary['shadow_reason']);
        $this->assertSame([], $this->signed);
    }

    public function test_incomplete_operator_list_aborts_the_period(): void
    {
        $this->registry->shouldReceive('activeNodes')->andReturn(null);

        $this->expectException(RuntimeException::class);
        $this->service()->run($this->period);
    }

    public function test_without_peer_proof_a_correct_hash_is_enough(): void
    {
        // โหมดทดสอบเท่านั้น — D (สคริปต์ปลอม) ได้ 100% ซึ่งเป็นเหตุผลที่ค่าเริ่มต้นต้องเปิด require_peer_seen
        config(['masternode.uptime.require_peer_seen' => false]);

        $this->service()->run($this->period);

        $this->assertPeriod(self::D, 12, 12, 10000);
        $this->assertPeriod(self::E, 12, 0, 0);
    }

    public function test_command_rejects_an_unaligned_or_open_period(): void
    {
        $this->artisan('masternode:uptime-oracle', ['--period' => $this->period + 7])->assertFailed();
        $this->artisan('masternode:uptime-oracle', ['--period' => $this->period + 7200])->assertFailed();
    }

    public function test_command_prints_the_period_table(): void
    {
        $this->app->instance(UptimeOracleService::class, $this->service());

        $this->artisan('masternode:uptime-oracle', ['--period' => $this->period])
            ->expectsOutputToContain('shadow')
            ->assertSuccessful();
    }

    private function service(): UptimeOracleService
    {
        return new UptimeOracleService(
            $this->registry,
            new UptimeCalculator(),
            $this->signer,
            $this->chain,
            fn () => null,
            fn () => self::DEPLOYER,
        );
    }

    private function slot(string $wallet, int $slot, string $peer, ?bool $ok): void
    {
        DB::table('masternode_uptime_slots')->insert([
            'wallet_address' => $wallet, 'slot' => $slot, 'peer_id' => $peer,
            'block_number' => 1, 'block_ok' => $ok, 'heartbeats' => 2,
        ]);
    }

    private function seen(string $peer, int $slot): void
    {
        DB::table('masternode_peer_sightings')->insert(['peer_id' => $peer, 'slot' => $slot]);
    }

    private function assertPeriod(string $wallet, int $expected, int $ok, int $bps): void
    {
        $row = MasternodeUptimePeriod::where('wallet_address', $wallet)->where('period_start', $this->period)->first();
        $this->assertNotNull($row, "no period row for {$wallet}");
        $this->assertSame([$expected, $ok, $bps], [$row->slots_expected, $row->slots_ok, $row->uptime_bps], $wallet);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
