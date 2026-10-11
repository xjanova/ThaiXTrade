<?php

namespace App\Services\Stabilizer;

use App\Models\Kline;
use App\Models\StabilityDeposit;
use App\Models\StabilityTransaction;
use App\Models\TradingPair;
use App\Services\TpixDexService;
use App\Support\Wei;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * อ่านทุกอย่างที่กฎต้องใช้ — จากเชน (สถานะที่ลงบล็อกแล้วเท่านั้น) และจากบันทึกของเราเอง.
 *
 * ⚠️ อ่านแบบ 'latest' เสมอ ไม่ดู mempool/pending ของใคร — บอทนี้ห้ามรู้ว่าผู้ใช้กำลังจะทำอะไร
 *    (ไม่งั้นมันกลายเป็นเครื่องมือ front-run ทันที แม้จะไม่ได้ตั้งใจก็ตาม)
 *
 * ราคาอ้างอิง = ค่าเฉลี่ยของราคากลางพูลที่ dex:sync บันทึกไว้ทุกนาที (แท่ง 1m) ย้อนหลัง N วัน
 * นาทีที่ไม่มีข้อมูลไม่ถูกเดาเติม — นับเป็น "ความครอบคลุม" ที่ขาดไปแทน
 *
 * Developed by Xman Studio
 */
class StabilizerMarket
{
    public function __construct(
        private TpixDexService $dex,
        private StabilizerSettings $settings,
        private BridgeReserveSource $bridge,
    ) {}

    /**
     * ที่อยู่สัญญาที่ใช้ — null ถ้า DEX ยังไม่พร้อม/หาพูลไม่เจอ.
     *
     * @return array{router: string, wtpix: string, usdt: string, pair: string}|null
     */
    public function contracts(): ?array
    {
        $cfg = $this->dex->config();
        if (! $cfg['ready']) {
            return null;
        }

        $pair = $this->dex->getPair((string) $cfg['WTPIX'], (string) $cfg['USDT']);
        if ($pair === null) {
            return null;
        }

        return [
            'router' => strtolower((string) $cfg['ROUTER']),
            'wtpix' => strtolower((string) $cfg['WTPIX']),
            'usdt' => strtolower((string) $cfg['USDT']),
            'pair' => strtolower($pair),
        ];
    }

    public function usdtDecimals(?array $contracts = null): int
    {
        $contracts ??= $this->contracts();
        $meta = $contracts ? $this->dex->tokenMeta($contracts['usdt']) : null;

        return (int) ($meta['decimals'] ?? config('stabilizer.usdt_decimals', 6));
    }

    /**
     * reserve ของพูลเรียงเป็นฝั่ง TPIX / USDT.
     *
     * @return array{reserve_tpix: string, reserve_usdt: string, price: ?string}|null
     */
    public function pool(array $contracts, int $usdtDecimals): ?array
    {
        $info = $this->dex->pairInfo($contracts['pair']);
        if ($info === null) {
            return null;
        }

        $tpixIsToken0 = strtolower($info['token0']) === $contracts['wtpix'];
        $reserveTpix = $tpixIsToken0 ? $info['reserve0'] : $info['reserve1'];
        $reserveUsdt = $tpixIsToken0 ? $info['reserve1'] : $info['reserve0'];

        return [
            'reserve_tpix' => $reserveTpix,
            'reserve_usdt' => $reserveUsdt,
            'price' => PoolMath::price($reserveTpix, $reserveUsdt, $usdtDecimals),
        ];
    }

    /**
     * ราคาอ้างอิงแบบถัวเฉลี่ยตามเวลา (TWAP) จากแท่ง 1 นาทีของคู่ TPIX-USDT.
     *
     * ทุกแท่งมีน้ำหนักเท่ากัน (1 นาที) — นาทีที่ไม่มีข้อมูลถูกข้าม ไม่เดาราคามาเติม
     * ใช้ bcmath ทั้งหมด ไม่ใช้ AVG() ของ SQL (SQLite คิดเป็น float ผลต่างจาก MySQL)
     *
     * @return array{price: ?string, samples: int, coverage_pct: float, latest_at: ?CarbonInterface, lookback_days: int}
     */
    public function reference(string $pairAddress, int $lookbackDays, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $empty = ['price' => null, 'samples' => 0, 'coverage_pct' => 0.0, 'latest_at' => null, 'lookback_days' => $lookbackDays];

        $pair = TradingPair::whereRaw('LOWER(dex_pair_address) = ?', [strtolower($pairAddress)])->first();
        if ($pair === null) {
            return $empty;
        }

        $since = $now->copy()->subDays($lookbackDays);
        $sum = '0';
        $count = 0;
        $latest = null;

        Kline::where('trading_pair_id', $pair->id)
            ->where('interval', '1m')
            ->where('open_time', '>=', $since)
            ->where('open_time', '<=', $now)
            ->orderBy('open_time')
            ->select(['id', 'open_time', 'close'])
            ->chunk(2000, function ($rows) use (&$sum, &$count, &$latest) {
                foreach ($rows as $row) {
                    $close = (string) $row->close;
                    if (bccomp($close, '0', 18) <= 0) {
                        continue;
                    }
                    $sum = bcadd($sum, $close, 18);
                    $count++;
                    $latest = $row->open_time;
                }
            });

        if ($count === 0) {
            return $empty;
        }

        $minutes = max(1, $lookbackDays * 1440);

        return [
            'price' => bcdiv($sum, (string) $count, 18),
            'samples' => $count,
            'coverage_pct' => round(min(100, $count * 100 / $minutes), 2),
            'latest_at' => $latest instanceof CarbonInterface ? $latest : ($latest ? Carbon::parse($latest) : null),
            'lookback_days' => $lookbackDays,
        ];
    }

    /**
     * ยอดในกระเป๋ากองทุน + วงเงินที่อนุญาตให้ router ดึง USDT.
     *
     * @return array{tpix: ?string, usdt: ?string, allowance: ?string}
     */
    public function wallet(array $contracts): array
    {
        $address = $this->settings->walletAddress();
        if ($address === null) {
            return ['tpix' => null, 'usdt' => null, 'allowance' => null];
        }

        return [
            'tpix' => $this->dex->nativeBalance($address),
            'usdt' => $this->dex->balanceOf($contracts['usdt'], $address),
            'allowance' => $this->dex->allowance($contracts['usdt'], $address, $contracts['router']),
        ];
    }

    /** สถานะหลักประกันของ USDT_TPIX — ปิดธงไว้ = ถือว่าไม่มีข้อมูล (ใช้ได้แค่งบที่ฝากไว้) */
    public function bridge(): array
    {
        if (! config('stabilizer.bridge_reserves.enabled', false)) {
            return (new NullBridgeReserveSource())->status();
        }

        try {
            $status = $this->bridge->status();
        } catch (\Throwable) {
            return (new NullBridgeReserveSource())->status();
        }

        return [
            'available' => (bool) ($status['available'] ?? false),
            'backed' => isset($status['backed']) ? (bool) $status['backed'] : null,
            'reserve_usdt' => $status['reserve_usdt'] ?? null,
            'supply_usdt' => $status['supply_usdt'] ?? null,
            'checked_at' => $status['checked_at'] ?? null,
            'source' => (string) ($status['source'] ?? 'bridge'),
        ];
    }

    /**
     * งบและประวัติไม้ของเราเอง (จากฐานข้อมูล ไม่ใช่จากเชน).
     *
     * @return array<string, mixed>
     */
    public function ledger(?CarbonInterface $now = null): array
    {
        $now ??= now();
        $dayStart = $now->copy()->startOfDay();

        $swaps = StabilityTransaction::swaps()->whereIn('status', StabilityTransaction::SPENDING_STATUSES);

        $sumOf = fn ($query) => Wei::sum($query->pluck('amount_in_wei')->all());

        $lastOf = function (?string $side) {
            // ไม้ที่ส่งแล้วแต่ล้ม (revert) ก็นับเป็น "ไม้ล่าสุด" สำหรับช่วงพัก — กันยิงซ้ำถี่ ๆ หลังล้ม
            $query = StabilityTransaction::swaps()->where('status', '!=', StabilityTransaction::STATUS_DROPPED);
            if ($side !== null) {
                $query->where('side', $side)->whereIn('status', StabilityTransaction::SPENDING_STATUSES);
            }

            return $query->max('signed_at');
        };

        $toCarbon = fn ($v) => $v ? Carbon::parse($v) : null;

        return [
            'day_start' => $dayStart,
            'usdt_spent_today' => $sumOf((clone $swaps)->where('side', StabilityTransaction::SIDE_BUY)->where('signed_at', '>=', $dayStart)),
            'tpix_sold_today' => $sumOf((clone $swaps)->where('side', StabilityTransaction::SIDE_SELL)->where('signed_at', '>=', $dayStart)),
            'usdt_spent_total' => $sumOf((clone $swaps)->where('side', StabilityTransaction::SIDE_BUY)),
            'usdt_deposits' => Wei::sum(StabilityDeposit::where('token', StabilityDeposit::TOKEN_USDT)->pluck('amount_wei')->all()),
            'last_trade_at' => $toCarbon($lastOf(null)),
            'last_buy_at' => $toCarbon($lastOf(StabilityTransaction::SIDE_BUY)),
            'last_sell_at' => $toCarbon($lastOf(StabilityTransaction::SIDE_SELL)),
        ];
    }

    /**
     * ภาพรวมทั้งหมดที่กฎต้องใช้ในรอบเดียว.
     *
     * @return array<string, mixed>
     */
    public function snapshot(array $params, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $contracts = $this->contracts();

        if ($contracts === null) {
            return ['now' => $now, 'contracts' => null];
        }

        $decimals = $this->usdtDecimals($contracts);
        $head = $this->dex->latestBlock();

        return [
            'now' => $now,
            'contracts' => $contracts,
            'usdt_decimals' => $decimals,
            'pool' => $this->pool($contracts, $decimals),
            'chain_head_at' => $head ? Carbon::createFromTimestamp($head['timestamp']) : null,
            'chain_head_block' => $head['number'] ?? null,
            'reference' => $this->reference($contracts['pair'], (int) $params['lookback_days'], $now),
            'wallet' => $this->wallet($contracts),
            'bridge' => $this->bridge(),
            'ledger' => $this->ledger($now),
        ];
    }
}
