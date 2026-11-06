<?php

namespace App\Services\Launchpad;

use App\Models\LaunchpadSale;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\EvmRpc;
use App\Support\Wei;
use Illuminate\Support\Facades\Cache;

/**
 * ข้อมูลความเสี่ยงของหน้าขาย อ่านสด ๆ จากเชน 4289 — ไม่มีช่องให้ใคร (รวมถึงแอดมิน) พิมพ์ป้าย "ปลอดภัย" เอง.
 *
 * ป้ายแต่ละอันคือ { key, level, params } — หน้าเว็บแปลจาก launchpad.badges.{key}.{level}
 *   level: ok | warn | danger | info | unknown (อ่านจากเชนไม่ได้ ≠ ปลอดภัย — บอกตรง ๆ ว่าไม่รู้)
 *
 * แคช 60 วินาทีต่อหน้าขาย (อ่านไม่ได้เลยแคชแค่ 15 วินาที) — คนเปิดหน้าพร้อมกันเยอะไม่ทำให้ RPC ล่ม
 */
class LaunchpadRiskReader
{
    private const ZERO = '0x0000000000000000000000000000000000000000';

    public function __construct(private BridgeConfig $bridge) {}

    /** @return array{available: bool, checked_at: string, stats: array, badges: list<array>} */
    public function read(LaunchpadSale $sale, ?EvmRpc $rpc = null): array
    {
        $key = "launchpad:risk:{$sale->id}";
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $facts = $this->compute($sale, $rpc ?? $this->bridge->tpixRpc());
        Cache::put($key, $facts, $facts['available'] ? 60 : 15);

        return $facts;
    }

    public function forget(LaunchpadSale $sale): void
    {
        Cache::forget("launchpad:risk:{$sale->id}");
    }

    private function compute(LaunchpadSale $sale, EvmRpc $rpc): array
    {
        $saleAddr = $sale->sale_address;
        $token = $sale->token_address;
        $feeder = $sale->feeder_address;

        $uint = fn (string $to, string $sig, string $args = '') => $rpc->callUint($to, $sig, $args);
        $bool = fn (string $to, string $sig) => $rpc->callBool($to, $sig);
        $balance = fn (string $tokenAddr, string $owner) => $rpc->tokenBalance($tokenAddr, $owner);

        $stats = [
            'sold' => $uint($saleAddr, 'sold()'),
            'raised' => $uint($saleAddr, 'raised()'),
            'remaining' => $uint($saleAddr, 'remaining()'),
            'paused' => $bool($saleAddr, 'paused()'),
            'active' => $bool($saleAddr, 'isActive()'),
            'min_purchase' => $uint($saleAddr, 'minPurchase()'),
            'max_per_wallet' => $uint($saleAddr, 'maxPerWallet()'),
            'quote_to_pool' => $uint($feeder, 'totalQuoteToPool()'),
            'token_to_pool' => $uint($feeder, 'totalTokenToPool()'),
            'pool_token' => null,
            'pool_quote' => null,
        ];

        $tokenCheck = $sale->factory_address ? $uint($sale->factory_address, 'tokenCheck(address)', Abi::encAddress($token)) : null;
        $flags = [
            'mint' => $bool($token, 'isMintable()'),
            'pause' => $bool($token, 'isPausable()'),
            'blacklist' => $bool($token, 'isBlacklistEnabled()'),
            'autoburn' => $bool($token, 'isAutoBurnEnabled()'),
        ];

        $supply = $uint($token, 'totalSupply()');
        $creatorBal = $balance($token, $sale->creator_address);
        $vestingBal = $sale->vesting_address ? $balance($token, $sale->vesting_address) : '0';
        $feederBal = $balance($token, $feeder);

        $pair = Abi::decAddress($rpc->call($feeder, Abi::selector('pair()')));
        $lpTotal = $lpHeld = null;
        if ($pair !== null && $pair !== self::ZERO) {
            $lpTotal = $uint($pair, 'totalSupply()');
            $lpHeld = $balance($pair, $feeder);
            $words = Abi::words($rpc->call($feeder, Abi::selector('poolReserves()')));
            if (count($words) >= 2) {
                $stats['pool_token'] = Wei::hexToInt('0x'.$words[0]);
                $stats['pool_quote'] = Wei::hexToInt('0x'.$words[1]);
            }
        }

        $badges = [];

        // มาจาก Token Factory ของระบบไหม (2 = มาจาก Factory แต่เปิดสวิตช์เสี่ยง — ป้ายสวิตช์ข้างล่างบอกเอง)
        $badges[] = ['key' => 'factory', 'level' => match ($tokenCheck) {
            '0', '2' => 'ok',
            null => 'unknown',
            default => 'danger',
        }, 'params' => []];

        foreach ($flags as $name => $on) {
            $badges[] = ['key' => $name, 'level' => $on === null ? 'unknown' : ($on ? 'danger' : 'ok'), 'params' => []];
        }

        $unlock = $sale->lp_unlock_at;
        $badges[] = ['key' => 'lp_lock', 'level' => $unlock->isFuture() ? 'ok' : 'warn', 'params' => ['date' => $unlock->toIso8601String()]];

        if ($pair === null) {
            $badges[] = ['key' => 'lp_held', 'level' => 'unknown', 'params' => []];
        } elseif ($pair === self::ZERO || $lpTotal === null || bccomp($lpTotal, '0', 0) === 0) {
            $badges[] = ['key' => 'lp_held', 'level' => 'info', 'params' => []]; // ยังไม่มีพูล — ซื้อแรกจะสร้าง
        } else {
            $pct = $lpHeld === null ? null : $this->pct($lpHeld, $lpTotal);
            $badges[] = ['key' => 'lp_held', 'level' => $pct === null ? 'unknown' : $this->tier($pct, 95, 50, higherIsBetter: true), 'params' => ['pct' => $pct]];
        }

        $sharePct = number_format($sale->pool_share_bps / 100, 2, '.', '');
        $badges[] = ['key' => 'pool_share', 'level' => $sale->pool_share_bps >= 5000 ? 'ok' : 'danger', 'params' => ['pct' => $sharePct]];

        if ($supply === null || bccomp($supply, '0', 0) === 0) {
            $badges[] = ['key' => 'creator_free', 'level' => 'unknown', 'params' => []];
            $badges[] = ['key' => 'locked_supply', 'level' => 'unknown', 'params' => []];
        } else {
            $creatorPct = $creatorBal === null ? null : $this->pct($creatorBal, $supply);
            $badges[] = ['key' => 'creator_free', 'level' => $creatorPct === null ? 'unknown' : $this->tier($creatorPct, 5, 20, higherIsBetter: false), 'params' => ['pct' => $creatorPct]];

            // ล็อกอยู่ = เหรียญที่ยังขายอยู่ในสัญญาขาย + สำรองในตัวป้อนพูล + vesting ที่ยังไม่ปลด
            $parts = [$stats['remaining'], $feederBal, $vestingBal];
            $lockedPct = in_array(null, $parts, true) ? null : $this->pct(bcadd(bcadd($parts[0], $parts[1], 0), $parts[2], 0), $supply);
            $badges[] = ['key' => 'locked_supply', 'level' => $lockedPct === null ? 'unknown' : $this->tier($lockedPct, 80, 50, higherIsBetter: true), 'params' => ['pct' => $lockedPct]];

            if ($sale->vesting_address && $vestingBal !== null && bccomp($vestingBal, '0', 0) > 0) {
                $badges[] = ['key' => 'vesting', 'level' => 'info', 'params' => ['pct' => $this->pct($vestingBal, $supply)]];
            }
        }

        if ($stats['paused'] === true) {
            $badges[] = ['key' => 'sale_paused', 'level' => 'warn', 'params' => []];
        }

        $unknown = count(array_filter($badges, fn ($b) => $b['level'] === 'unknown'));

        return [
            // อ่านไม่ได้แทบทุกข้อ = RPC ล่ม — หน้าเว็บบอกว่า "ตรวจไม่ได้ตอนนี้" แทนการโชว์ป้ายเทาทั้งแผง
            'available' => $unknown < 5 && $tokenCheck !== null,
            'checked_at' => now()->toIso8601String(),
            'stats' => $stats,
            'badges' => $badges,
        ];
    }

    /** a/b เป็นเปอร์เซ็นต์ 2 ตำแหน่ง (สตริง) */
    private function pct(string $part, string $whole): string
    {
        return bcdiv(bcmul($part, '100', 0), $whole, 2);
    }

    private function tier(string $pct, float $good, float $bad, bool $higherIsBetter): string
    {
        $v = (float) $pct;

        return $higherIsBetter
            ? ($v >= $good ? 'ok' : ($v >= $bad ? 'warn' : 'danger'))
            : ($v <= $good ? 'ok' : ($v <= $bad ? 'warn' : 'danger'));
    }
}
