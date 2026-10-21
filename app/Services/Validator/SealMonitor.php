<?php

namespace App\Services\Validator;

use App\Models\SystemAlert;
use App\Models\ValidatorKeyBinding;
use App\Models\ValidatorSealStat;
use App\Services\MasterNode\NodeRegistryContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SealMonitor — เฝ้าดูว่า validator แต่ละตัวยังปิดบล็อกอยู่จริงไหม.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *  ทำไมต้องวัดรายตัว
 * ═══════════════════════════════════════════════════════════════════════════
 *  5 ส.ค. 2026 เชนวิ่งด้วย 3/4 มานานโดยไม่มีใครรู้ (บล็อกช้าเป็น 2s-2s-14s) เพราะเครื่องมือทุกตัว
 *  ถามแค่ "เชนยังเดินไหม" — validator ที่ดับยังถูกหมุนคิวมาเป็นผู้เสนอและทำให้รอบ timeout
 *  ยิ่งเปิดรับ validator ภายนอก ยิ่งต้องรู้เป็นรายตัวว่าใครหาย
 *
 *  ต่อบล็อกอ่านได้ 3 อย่างจาก extraData (ดู IbftExtraDecoder):
 *    - ผู้เสนอ          → ecrecover(proposerSeal)
 *    - ผู้เซ็นรับรอง     → bitmap ของ committed seals (ครบ quorum ก็พอ บางบล็อกจึงขาดบางตัวเป็นปกติ)
 *    - round > 0       → ผู้เสนอคิวแรกพลาด · คิวถัดไปของ polygon-edge คือ (ผู้เสนอบล็อกก่อน + 1 + round)
 *
 *  ตัวที่ "ไม่เซ็นเลย" ต่อเนื่องเกิน SILENT_BLOCKS = ดับหรือหลุดจากเครือข่าย → คาดแดงหลังบ้าน
 *  ดับจนไม่เหลือตัวสำรอง (จำนวนที่เงียบ ≥ ค่าที่ทนได้) = critical เพราะดับอีกตัวเดียวเชนหยุด
 *
 *  อ่านเชนไม่ได้ = ไม่ยกเหตุ (chain-watchdog บนเครื่องเชนรับผิดชอบเรื่องเชนดับอยู่แล้ว)
 *
 * Developed by Xman Studio
 */
class SealMonitor
{
    public const ALERT_NODE = 'validators';

    private const RING_KEY = 'validators:seal-monitor:ring';

    /** หน้าต่างสถิติ — 300 บล็อก ≈ 10 นาทีที่ 2 วินาที/บล็อก */
    public const WINDOW = 300;

    /** ไม่เซ็นเลยต่อเนื่องกี่บล็อกถึงถือว่าเงียบ — 150 ≈ 5 นาที */
    public const SILENT_BLOCKS = 150;

    /** อ่านย้อนได้มากสุดต่อรอบ (ตามไม่ทัน = กระโดดไปข้างหน้า สถิติเป็นตัวอย่างอยู่แล้ว) */
    public const MAX_PER_RUN = 60;

    public function __construct(
        private readonly TpixRpc $rpc,
        private readonly IbftExtraDecoder $decoder,
        private readonly NodeRegistryContract $registry,
    ) {}

    /**
     * @return array{head:?int,scanned:int,set_size:int,silent:list<string>,error:?string}
     */
    public function run(int $maxBlocks = self::MAX_PER_RUN): array
    {
        $head = $this->rpc->blockNumber();
        if ($head === null) {
            return ['head' => null, 'scanned' => 0, 'set_size' => 0, 'silent' => [], 'error' => 'CHAIN_UNREACHABLE'];
        }

        $ring = Cache::get(self::RING_KEY, []);
        $last = $ring === [] ? null : end($ring)['n'];
        $from = $last === null ? $head - $maxBlocks + 1 : max($last + 1, $head - $maxBlocks + 1);

        $scanned = 0;
        $seals = []; // ตำแหน่งใน ring => [hash, seal, ได้จากสูตร?] ของรอบนี้ — ใช้ตรวจท้ายรอบ
        for ($n = max(1, $from); $n <= $head; $n++) {
            $block = $this->rpc->block($n);
            $summary = $block === null ? null : $this->decoder->summarize($block, false);
            if ($summary === null) {
                break; // RPC สะดุดกลางทาง — เก็บเท่าที่ได้ รอบหน้าต่อจากตรงนี้
            }

            $addresses = array_column($summary['validators'], 'address');
            $prev = $ring === [] ? null : end($ring);
            $inferred = $prev !== null && $prev['n'] === $summary['number'] - 1
                ? IbftExtraDecoder::expectedProposer($addresses, $prev['p'], $summary['round'])
                : null;
            // ไม่มีบล็อกก่อนหน้าให้คำนวณต่อ = จุดยึด ต้องกู้ลายเซ็นจริง
            $proposer = $inferred ?? $this->decoder->proposer($summary['hash'], $summary['proposer_seal']);

            $ring[] = [
                'n' => $summary['number'],
                'v' => $addresses,
                'b' => array_column($summary['validators'], 'bls'),
                'p' => $proposer,
                's' => $summary['signers'],
                'r' => $summary['round'],
            ];
            $seals[array_key_last($ring)] = [$summary['hash'], $summary['proposer_seal'], $inferred !== null];
            $scanned++;
        }

        if ($ring === []) {
            return ['head' => $head, 'scanned' => 0, 'set_size' => 0, 'silent' => [], 'error' => 'CHAIN_UNREACHABLE'];
        }

        $ring = $this->verifyInferredProposers($ring, $seals);

        $tip = end($ring)['n'];
        $window = (int) config('validators.seal_monitor.window', self::WINDOW);
        $ring = array_values(array_filter($ring, fn (array $e) => $e['n'] > $tip - $window));
        Cache::put(self::RING_KEY, $ring, now()->addHour());

        $stats = $this->compute($ring);
        $latest = end($ring);
        $this->persist($stats, $latest, $tip);
        $silent = $this->raiseAlerts($latest, $tip);
        $this->trackBindings($latest);

        return ['head' => $tip, 'scanned' => $scanned, 'set_size' => count($latest['v']), 'silent' => $silent, 'error' => null];
    }

    /**
     * ตรวจบล็อกสุดท้ายของรอบด้วยการกู้ลายเซ็นจริง — ถ้าสูตรหมุนคิวให้คนละคน (เช่น polygon-edge
     * เปลี่ยนกติกาหลังอัปเกรด) กู้ลายเซ็นทุกบล็อกของรอบนี้แทน ไม่ปล่อยให้นับผิดคนเงียบ ๆ.
     *
     * @param  array<int,array{0:string,1:string,2:bool}>  $seals
     */
    private function verifyInferredProposers(array $ring, array $seals): array
    {
        if ($seals === []) {
            return $ring;
        }
        $lastIndex = array_key_last($seals);
        [$hash, $seal, $wasInferred] = $seals[$lastIndex];
        if (! $wasInferred) {
            return $ring;
        }

        $actual = $this->decoder->proposer($hash, $seal);
        if ($actual === null || $actual === $ring[$lastIndex]['p']) {
            return $ring;
        }

        Log::warning('Seal monitor: proposer rotation rule disagreed with the signature — recovering every block', [
            'block' => $ring[$lastIndex]['n'],
            'inferred' => $ring[$lastIndex]['p'],
            'actual' => $actual,
        ]);
        foreach ($seals as $i => [$h, $s]) {
            $ring[$i]['p'] = $this->decoder->proposer($h, $s);
        }

        return $ring;
    }

    /**
     * สถิติในหน้าต่างจากรายการบล็อก — แยกออกมาให้เทสต์ได้ตรง ๆ.
     *
     * @param  list<array{n:int,v:list<string>,p:?string,s:list<string>,r:int}>  $ring
     * @return array<string,array{blocks:int,signed:int,proposed:int,missed:int,last_signed:?int,last_proposed:?int,first_seen:int}>
     */
    public static function compute(array $ring): array
    {
        $stats = [];
        $prev = null;
        foreach ($ring as $e) {
            foreach ($e['v'] as $addr) {
                $stats[$addr] ??= ['blocks' => 0, 'signed' => 0, 'proposed' => 0, 'missed' => 0, 'last_signed' => null, 'last_proposed' => null, 'first_seen' => $e['n']];
                $stats[$addr]['blocks']++;
            }
            foreach ($e['s'] as $addr) {
                if (isset($stats[$addr])) {
                    $stats[$addr]['signed']++;
                    $stats[$addr]['last_signed'] = $e['n'];
                }
            }
            if ($e['p'] !== null && isset($stats[$e['p']])) {
                $stats[$e['p']]['proposed']++;
                $stats[$e['p']]['last_proposed'] = $e['n'];
            }

            // round > 0: คิวของใครที่พลาด — ต้องรู้ผู้เสนอบล็อกก่อนหน้าในชุดเดียวกัน
            if ($e['r'] > 0 && $prev !== null && $prev['n'] === $e['n'] - 1 && $prev['v'] === $e['v'] && $prev['p'] !== null) {
                $count = count($e['v']);
                $start = array_search($prev['p'], $e['v'], true);
                if ($start !== false && $count > 0) {
                    for ($k = 0; $k < min($e['r'], $count); $k++) {
                        $stats[$e['v'][($start + 1 + $k) % $count]]['missed']++;
                    }
                }
            }
            $prev = $e;
        }

        return $stats;
    }

    /**
     * @param  array<string,array<string,mixed>>  $stats
     * @param  array{n:int,v:list<string>,b:list<?string>}  $latest
     */
    private function persist(array $stats, array $latest, int $tip): void
    {
        $known = ValidatorSealStat::all()->keyBy('address');

        foreach ($stats as $addr => $s) {
            $row = $known->get($addr) ?? new ValidatorSealStat(['address' => $addr]);
            $index = array_search($addr, $latest['v'], true);
            $row->fill([
                'bls_public_key' => $index === false ? $row->bls_public_key : $latest['b'][$index],
                'in_set' => $index !== false,
                'set_index' => $index === false ? null : $index,
                'first_seen_block' => $row->first_seen_block === null ? $s['first_seen'] : min($row->first_seen_block, $s['first_seen']),
                // "ล่าสุด" จำข้ามหน้าต่างได้ — หน้าต่างสั้นกว่าเวลาที่ตัวที่ดับนาน ๆ หายไป
                'last_proposed_block' => max((int) $row->last_proposed_block, (int) $s['last_proposed']) ?: null,
                'last_signed_block' => max((int) $row->last_signed_block, (int) $s['last_signed']) ?: null,
                'window_blocks' => $s['blocks'],
                'window_signed' => $s['signed'],
                'window_proposed' => $s['proposed'],
                'window_missed_turns' => $s['missed'],
                'scanned_to_block' => $tip,
            ]);
            $row->save();
        }

        // หลุดจากชุดไปแล้วทั้งหน้าต่าง — ไม่อยู่ใน $stats แต่ต้องไม่ค้าง in_set
        ValidatorSealStat::whereNotIn('address', array_keys($stats))->where('in_set', true)
            ->update(['in_set' => false, 'set_index' => null, 'window_blocks' => 0, 'window_signed' => 0, 'window_proposed' => 0, 'window_missed_turns' => 0, 'scanned_to_block' => $tip]);
    }

    /**
     * @return list<string> ที่อยู่ที่เงียบ
     */
    private function raiseAlerts(array $latest, int $tip): array
    {
        $rows = ValidatorSealStat::whereIn('address', $latest['v'])->get();
        $threshold = (int) config('validators.seal_monitor.silent_blocks', self::SILENT_BLOCKS);
        $silent = [];
        foreach ($rows as $row) {
            $watchedFor = $tip - (int) $row->first_seen_block;
            $quietFor = $row->last_signed_block === null ? $watchedFor : $tip - $row->last_signed_block;
            if ($watchedFor >= $threshold && $quietFor >= $threshold) {
                $silent[$row->address] = $quietFor;
            }
        }

        $setSize = count($latest['v']);
        $tolerance = IbftValidatorSet::faultTolerance($setSize);
        $severity = count($silent) >= $tolerance ? 'critical' : 'warning';

        foreach ($silent as $addr => $quietFor) {
            $minutes = (int) round($quietFor * 2 / 60);
            SystemAlert::raise(
                self::ALERT_NODE,
                self::silentKey($addr),
                $severity,
                sprintf(
                    'validator %s ไม่ได้เซ็นรับรองบล็อกมา %d บล็อก (~%d นาที) · เงียบ %d จาก %d ตัว ทนได้ %d — %s',
                    $addr,
                    $quietFor,
                    $minutes,
                    count($silent),
                    $setSize,
                    $tolerance,
                    $severity === 'critical'
                        ? 'ไม่เหลือตัวสำรองแล้ว ดับอีกตัวเดียวเชนหยุด: ตรวจโหนดนั้นทันที หรือโหวตถอด (validator-vote.sh drop)'
                        : 'ตรวจโหนดนั้น ถ้าดับเกิน 30 นาทีให้โหวตถอด (validator-vote.sh drop)',
                ),
                ['address' => $addr, 'quiet_blocks' => $quietFor, 'head' => $tip],
            );
        }

        $allKeys = ValidatorSealStat::pluck('address')->map(fn ($a) => self::silentKey($a))->all();
        $activeKeys = array_map(fn ($a) => self::silentKey($a), array_keys($silent));
        SystemAlert::resolveKeys(self::ALERT_NODE, array_values(array_diff($allKeys, $activeKeys)), 'auto:seal-monitor');

        return array_keys($silent);
    }

    /**
     * จำว่ากุญแจที่ผูกไว้เข้า/ออกจากชุดเมื่อไหร่ + เตือนเมื่อ stake หายแต่ยังปิดบล็อกอยู่.
     */
    private function trackBindings(array $latest): void
    {
        $dropKeys = [];
        foreach (ValidatorKeyBinding::active()->get() as $binding) {
            $inSet = in_array($binding->validator_address, $latest['v'], true);
            if ($inSet && $binding->voted_in_at === null) {
                $binding->update(['voted_in_at' => now(), 'left_set_at' => null]);
            } elseif (! $inSet && $binding->voted_in_at !== null && $binding->left_set_at === null) {
                $binding->update(['left_set_at' => now()]);
            }

            if (! $inSet) {
                continue;
            }
            // nodeInfo null = อ่านไม่ได้ (ไม่ทราบ) — ไม่เดาว่า stake หาย
            $node = $this->registry->nodeInfo($binding->staking_wallet);
            if ($node !== null && ($node['status'] !== 'active' || $node['tier'] !== 'Validator')) {
                $key = 'validator_drop_needed_'.substr($binding->validator_address, 2, 8);
                $dropKeys[] = $key;
                SystemAlert::raise(
                    self::ALERT_NODE,
                    $key,
                    'warning',
                    sprintf(
                        'validator %s ยังปิดบล็อกอยู่ แต่ stake ของ %s เป็น %s แล้ว — โหวตถอด: validator-vote.sh drop --addr %s',
                        $binding->validator_address,
                        $binding->staking_wallet,
                        $node['status'],
                        $binding->validator_address,
                    ),
                );
            }
        }

        $allDropKeys = ValidatorKeyBinding::pluck('validator_address')
            ->map(fn ($a) => 'validator_drop_needed_'.substr($a, 2, 8))->unique()->all();
        SystemAlert::resolveKeys(self::ALERT_NODE, array_values(array_diff($allDropKeys, $dropKeys)), 'auto:seal-monitor');
    }

    public static function silentKey(string $address): string
    {
        return 'validator_silent_'.substr(strtolower($address), 2, 8);
    }
}
