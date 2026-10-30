<?php

namespace Tests\Feature\Validator;

use App\Models\SystemAlert;
use App\Models\ValidatorKeyBinding;
use App\Models\ValidatorSealStat;
use App\Services\Validator\IbftExtraDecoder;
use App\Services\Validator\SealMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakesValidatorChain;
use Tests\Support\SignsWithTestKeys;
use Tests\TestCase;

/**
 * validators:seal-monitor — validator รายตัวยังปิดบล็อกอยู่จริงไหม
 *
 * กันไม่ให้ซ้ำเหตุ 5 ส.ค. 2026: เชนวิ่ง 3/4 นานโดยไม่มีเครื่องมือไหนบอกว่าตัวไหนหาย
 * และหน้าแอดมินโชว์ "Blocks Sealed 0 / Last Sealed —" ตายตัวทุกแถว
 */
class SealMonitorTest extends TestCase
{
    use FakesValidatorChain;
    use RefreshDatabase;
    use SignsWithTestKeys;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerValidatorContracts();
        $this->fakeValidatorChain();
        // เชนสังเคราะห์ต้องเซ็นทุกบล็อก (bcmath ~50ms) — ลดเกณฑ์ให้เทสต์ใช้บล็อกน้อยลง
        config(['validators.seal_monitor.silent_blocks' => 30, 'validators.seal_monitor.window' => 60]);
    }

    /** @return list<string> */
    private function keys(int $count): array
    {
        return array_map(fn ($i) => $this->testKey(20 + $i), range(0, $count - 1));
    }

    /**
     * เชนสังเคราะห์ตามกติกา polygon-edge: คิวถัดไป = ผู้เสนอก่อนหน้า + 1
     * คิวที่ตกกับตัวที่เงียบ = round ขึ้น แล้วตัวถัดไปเสนอแทน · ทุกตัวเซ็นยกเว้น $silent.
     */
    private function buildChain(array $keys, int $from, int $to, array $silent = []): void
    {
        $count = count($keys);
        $signers = array_values(array_diff(range(0, $count - 1), $silent));
        $prev = $this->lastProposer ?? -1;
        for ($n = $from; $n <= $to; $n++) {
            $round = 0;
            $proposer = ($prev + 1) % $count;
            while (in_array($proposer, $silent, true)) {
                $round++;
                $proposer = ($prev + 1 + $round) % $count;
            }
            $this->chainBlocks[$n] = $this->makeBlock($n, $keys, $proposer, $signers, $round);
            $prev = $proposer;
        }
        $this->lastProposer = $prev;
        $this->chainHead = $to;
    }

    private ?int $lastProposer = null;

    public function test_reads_real_tpix_blocks(): void
    {
        $this->useFixtureBlocks();

        $result = app(SealMonitor::class)->run(12);

        $this->assertNull($result['error']);
        $this->assertSame(12, $result['scanned']);
        $this->assertSame(4, $result['set_size']);
        $this->assertSame([], $result['silent'], 'สังเกตแค่ 12 บล็อก ยังไม่พอจะตัดสินว่าใครเงียบ');

        $stats = ValidatorSealStat::orderBy('set_index')->get();
        $this->assertCount(4, $stats);
        $this->assertSame([3, 3, 3, 3], $stats->pluck('window_proposed')->all());
        $this->assertSame(3207999, $stats[2]->last_proposed_block); // ลำดับ 3-0-1-2 → บล็อกสุดท้ายคือตัวที่ 2
        $this->assertSame(12, $stats->sum('window_signed') / 3, 'บล็อกละ 3 ลายเซ็น (quorum ของ 4 ตัว)');
        $this->assertSame('0x00affa6379fbd969cbe5906cdeee793960a500da', $stats[0]->address);
        $this->assertSame(0, SystemAlert::count());
    }

    public function test_attributes_round_changes_to_the_proposer_that_missed_its_turn(): void
    {
        $v = ['0xa', '0xb', '0xc', '0xd'];
        $stats = SealMonitor::compute([
            ['n' => 10, 'v' => $v, 'p' => '0xb', 's' => ['0xa', '0xb', '0xc'], 'r' => 0],
            // คิวถัดจาก 0xb คือ 0xc แต่ round 2 → 0xc และ 0xd พลาด ผู้เสนอจริงคือ 0xa
            ['n' => 11, 'v' => $v, 'p' => '0xa', 's' => ['0xa', '0xb', '0xc'], 'r' => 2],
        ]);

        $this->assertSame(0, $stats['0xa']['missed']);
        $this->assertSame(0, $stats['0xb']['missed']);
        $this->assertSame(1, $stats['0xc']['missed']);
        $this->assertSame(1, $stats['0xd']['missed']);
        $this->assertSame(0, $stats['0xd']['signed']);
        $this->assertNull($stats['0xd']['last_signed']);
        $this->assertSame(11, $stats['0xa']['last_proposed']);
    }

    public function test_a_silent_validator_raises_a_critical_alert_and_clears_when_it_returns(): void
    {
        $keys = $this->keys(4);
        $this->buildChain($keys, 1, 40, silent: [3]);

        $result = app(SealMonitor::class)->run(40);

        $silentAddr = $this->addressOf($keys[3]);
        $this->assertSame([$silentAddr], $result['silent']);

        $alert = SystemAlert::where('alert_key', SealMonitor::silentKey($silentAddr))->sole();
        $this->assertSame('critical', $alert->severity, '4 ตัวทนได้ 1 — เงียบไปแล้ว 1 = ไม่เหลือสำรอง');
        $this->assertStringContainsString('validator-vote.sh drop', $alert->message);
        $stat = ValidatorSealStat::where('address', $silentAddr)->sole();
        $this->assertSame(0, $stat->window_signed);
        $this->assertSame(0, $stat->window_proposed);
        // คิวของตัวที่ดับมาถึงทุก 3 บล็อก (หลังตัวที่ 2 เสนอ) และพลาดทุกครั้ง: บล็อก 4, 7, …, 40
        $this->assertSame(13, $stat->window_missed_turns);

        // กลับมาเซ็น → ปิดเหตุเอง
        $this->buildChain($keys, 41, 45);
        app(SealMonitor::class)->run(60);
        $this->assertSame('resolved', $alert->fresh()->status);
    }

    public function test_one_silent_validator_out_of_seven_is_only_a_warning(): void
    {
        $this->buildChain($this->keys(7), 1, 35, silent: [6]);

        app(SealMonitor::class)->run(35);

        $this->assertSame('warning', SystemAlert::sole()->severity, '7 ตัวทนได้ 2 — ยังเหลือสำรองอีก 1');
    }

    public function test_chain_outage_does_not_raise_validator_alerts(): void
    {
        $this->chainDown = true;

        $result = app(SealMonitor::class)->run();

        $this->assertSame('CHAIN_UNREACHABLE', $result['error']);
        $this->assertSame(0, SystemAlert::count());
        $this->assertSame(0, ValidatorSealStat::count());
    }

    public function test_tracks_bound_keys_entering_the_set_and_flags_drop_needed(): void
    {
        $keys = $this->keys(4);
        $operator = '0x'.str_repeat('5', 40);
        ValidatorKeyBinding::create([
            'staking_wallet' => $operator,
            'validator_address' => $this->addressOf($keys[3]),
            'bls_public_key' => '0x'.str_repeat('83', 48),
            'node_id' => '16Uiu2HAmSmFLK2z9HhZifQ3xaZmbZkZbT3hcEodRvVdX9tNnB8xv',
            'message' => 'm', 'staking_signature' => '0x', 'validator_signature' => '0x',
            'signed_at' => time(), 'status' => 'active',
        ]);
        $this->registryNode($operator);
        $this->buildChain($keys, 1, 20);

        app(SealMonitor::class)->run();
        $binding = ValidatorKeyBinding::sole();
        $this->assertNotNull($binding->voted_in_at);
        $this->assertSame(0, SystemAlert::count());

        // ถอน stake แล้วแต่ยังอยู่ในชุด
        $this->registryNode($operator, tier: 3, status: 3);
        $this->buildChain($keys, 21, 25);
        app(SealMonitor::class)->run();
        $alert = SystemAlert::where('alert_key', 'like', 'validator_drop_needed_%')->sole();
        $this->assertStringContainsString('validator-vote.sh drop --addr '.$this->addressOf($keys[3]), $alert->message);

        // ถอดออกจากชุดแล้ว → จำเวลา + ปิดเหตุ
        $this->buildChain(array_slice($keys, 0, 3), 26, 30);
        app(SealMonitor::class)->run();
        $this->assertNotNull($binding->fresh()->left_set_at);
        $this->assertSame('resolved', $alert->fresh()->status);
        $this->assertFalse(ValidatorSealStat::where('address', $this->addressOf($keys[3]))->value('in_set'));
    }

    public function test_catches_up_without_rereading_and_jumps_when_far_behind(): void
    {
        $keys = $this->keys(4);
        $this->buildChain($keys, 1, 40);
        $this->assertSame(40, app(SealMonitor::class)->run()['scanned']);

        $this->buildChain($keys, 41, 50);
        $this->assertSame(10, app(SealMonitor::class)->run()['scanned']);

        $this->buildChain($keys, 51, 130);
        $result = app(SealMonitor::class)->run();
        $this->assertSame(SealMonitor::MAX_PER_RUN, $result['scanned']);
        $this->assertSame(130, $result['head']);
    }

    public function test_rotation_rule_matches_the_real_chain(): void
    {
        $decoder = new IbftExtraDecoder();
        $prev = null;
        foreach ($this->fixtureBlocks() as $block) {
            $summary = $decoder->summarize($block);
            if ($prev !== null) {
                $this->assertSame(
                    $summary['proposer'],
                    IbftExtraDecoder::expectedProposer(array_column($summary['validators'], 'address'), $prev, $summary['round']),
                );
            }
            $prev = $summary['proposer'];
        }
    }

    public function test_falls_back_to_signatures_when_the_rotation_rule_disagrees(): void
    {
        // ตัวที่ 0 เสนอทุกบล็อก (ผิดกติกาหมุนคิว) — สูตรจะเดาผิด ต้องกู้ลายเซ็นทุกบล็อกแทน
        $keys = $this->keys(4);
        for ($n = 1; $n <= 6; $n++) {
            $this->chainBlocks[$n] = $this->makeBlock($n, $keys, 0, [0, 1, 2]);
        }
        $this->chainHead = 6;

        app(SealMonitor::class)->run(6);

        $this->assertSame(6, ValidatorSealStat::where('address', $this->addressOf($keys[0]))->value('window_proposed'));
        $this->assertSame(0, ValidatorSealStat::where('address', $this->addressOf($keys[1]))->value('window_proposed'));
    }

    public function test_command_prints_the_table(): void
    {
        $this->useFixtureBlocks();

        $this->artisan('validators:seal-monitor', ['--max' => 12])
            ->expectsOutputToContain('validator 4 ตัว')
            ->assertSuccessful();

        Cache::flush();
        $this->chainDown = true;
        $this->artisan('validators:seal-monitor')->expectsOutputToContain('CHAIN_UNREACHABLE')->assertSuccessful();
    }
}
