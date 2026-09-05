<?php

namespace App\Console\Commands;

use App\Models\Chain;
use App\Models\Kline;
use App\Models\SiteSetting;
use App\Models\Token;
use App\Models\TradingPair;
use App\Services\ChainResolver;
use App\Services\TpixDexService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * dex:sync — ทำให้ "ทุกเหรียญบนเชน TPIX เทรดได้" โดยไม่ต้องมีใครมาเพิ่มคู่เอง.
 *
 * ทุกนาที:
 *   1. ถ้า DEX ครบ 4 สัญญาและมีโค้ดบนเชน → เปิดเชน 4289 เป็น live (จาก coming_soon)
 *      ถ้าไม่ครบ/สัญญาหาย (เช่นเชน regenesis) → ถอยกลับเป็น coming_soon แล้วแจ้ง log
 *      ไม่แตะเชนที่แอดมินตั้ง maintenance ไว้เอง
 *   2. อ่านพูลทั้งหมดจาก TPIXDEXFactory แล้วสร้าง/อัปเดต Token + TradingPair
 *        พูล X/WTPIX  → คู่  X-TPIX   (quote = TPIX native ในฐานข้อมูลคือ 0x0)
 *        พูล X/USDT   → คู่  X-USDT
 *        พูล WTPIX/USDT → คู่ TPIX-USDT (แถวเดิมของระบบ)
 *      พูลที่ไม่มีทั้ง WTPIX และ USDT ข้าม — ไม่มีสกุลอ้างอิงให้ตั้งราคา
 *      คู่เปิดใช้เมื่อพูลมีสภาพคล่อง ปิดเมื่อพูลว่าง (สวอปไม่ได้อยู่แล้ว)
 *   3. บันทึกราคากลางของแต่ละพูลเป็นแท่ง 1 นาที (กราฟบนหน้าเทรดใช้ต่อ)
 *   4. อัปเดต trading.tpix_price จากพูล WTPIX/USDT ถ้ามี
 *
 * ทำซ้ำได้ตลอด — ทุก upsert คีย์ตาม unique index จริง
 * (tokens: chain_id+contract_address · trading_pairs: base+quote+chain · klines: pair+interval+open_time)
 *
 * Developed by Xman Studio
 */
class DexSync extends Command
{
    protected $signature = 'dex:sync
                            {--dry-run : ดูอย่างเดียว ไม่เขียนฐานข้อมูล}';

    protected $description = 'ซิงก์พูลบน TPIX DEX เป็นคู่เทรด + เปิด/ปิดเชน TPIX ตามความพร้อมของสัญญา';

    private bool $dryRun = false;

    public function __construct(
        private TpixDexService $dex,
        private ChainResolver $chains,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        $chain = $this->chains->resolve((int) config('blockchain.tpix_chain_id', 4289));
        if (! $chain) {
            $this->error('ไม่พบเชน TPIX ในตาราง chains — รัน migration/seeder ก่อน');

            return self::FAILURE;
        }

        $cfg = $this->dex->config();
        $this->syncChainStatus($chain, $cfg);

        if (! $cfg['ready']) {
            $this->line('DEX ยังไม่พร้อม (ขาด: '.implode(', ', $cfg['missing']).') — ข้ามการซิงก์พูล');

            return self::SUCCESS;
        }

        $tpix = $this->ensureNativeToken($chain);
        $usdt = $this->ensureUsdtToken($chain, $cfg['USDT']);

        $pairs = $this->dex->allPairs();
        $this->line('พูลบน factory: '.count($pairs));

        $synced = 0;
        foreach ($pairs as $pairAddress) {
            if ($this->syncPool($chain, $pairAddress, $tpix, $usdt)) {
                $synced++;
            }
        }

        $this->syncTpixPrice();

        if (! $this->dryRun) {
            Cache::forget('dex:pairs:public');
            Cache::forget('tpix_trading_pair');
        }

        $this->info("ซิงก์คู่เทรดแล้ว {$synced}/".count($pairs).' พูล');

        return self::SUCCESS;
    }

    /**
     * เปิด/ปิดเชนตามความพร้อมของสัญญา — เชนที่แอดมินปิดซ่อม (maintenance) ไม่แตะ.
     */
    private function syncChainStatus(Chain $chain, array $cfg): void
    {
        $target = $cfg['ready'] ? Chain::STATUS_LIVE : Chain::STATUS_COMING_SOON;

        if ($chain->status === Chain::STATUS_MAINTENANCE || $chain->status === $target) {
            return;
        }

        $this->warn("เชน TPIX: {$chain->status} → {$target}");
        Log::info('dex:sync เปลี่ยนสถานะเชน TPIX', [
            'from' => $chain->status,
            'to' => $target,
            'missing' => $cfg['missing'],
        ]);

        if ($this->dryRun) {
            return;
        }

        $chain->status = $target;
        $chain->save();
        Cache::forget('chains:supported');
        // เชนปริยายผูกกับสถานะนี้โดยตรง — ไม่ล้างแคช ผู้ใช้จะยังถูกส่งไป BSC อีกนาน
        $this->chains->forgetDefault();
    }

    /** TPIX native ในฐานข้อมูลคือ address 0x0 บนเชน TPIX */
    private function ensureNativeToken(Chain $chain): Token
    {
        $token = Token::where('chain_id', $chain->id)
            ->where('contract_address', TpixDexService::ZERO)
            ->first();

        if ($token) {
            return $token;
        }

        $token = new Token([
            'chain_id' => $chain->id,
            'symbol' => 'TPIX',
            'name' => 'TPIX',
            'contract_address' => TpixDexService::ZERO,
            'decimals' => 18,
            'is_active' => true,
            'logo' => '/tpixlogo.webp',
            'sort_order' => 1,
        ]);

        if (! $this->dryRun) {
            $token->save();
        }

        return $token;
    }

    /**
     * แถว USDT บนเชน TPIX — seeder เก่าใส่ที่อยู่หลอก 0x…01 ไว้ ต้องชี้ไปที่ USDT_TPIX ตัวจริง.
     */
    private function ensureUsdtToken(Chain $chain, string $usdtAddress): Token
    {
        $meta = $this->dex->tokenMeta($usdtAddress) ?? ['symbol' => 'USDT', 'name' => 'Tether USD (TPIX)', 'decimals' => 6];

        $token = Token::where('chain_id', $chain->id)
            ->whereRaw('LOWER(contract_address) = ?', [strtolower($usdtAddress)])
            ->first()
            ?? Token::where('chain_id', $chain->id)->where('symbol', 'USDT')->first();

        if (! $token) {
            $token = new Token(['chain_id' => $chain->id, 'symbol' => 'USDT', 'sort_order' => 2]);
        }

        $token->fill([
            'name' => $meta['name'],
            'contract_address' => $usdtAddress,
            'decimals' => $meta['decimals'],
            'is_active' => true,
        ]);

        if (! $this->dryRun && $token->isDirty()) {
            $token->save();
        }

        return $token;
    }

    /**
     * พูลหนึ่งใบ → Token ฝั่ง base (ถ้ายังไม่มี) + TradingPair + แท่ง 1 นาที.
     */
    private function syncPool(Chain $chain, string $pairAddress, Token $tpix, Token $usdt): bool
    {
        $info = $this->dex->pairInfo($pairAddress);
        if ($info === null) {
            $this->line("  {$pairAddress} อ่านพูลไม่ได้ — ข้าม");

            return false;
        }

        $t0 = strtolower($info['token0']);
        $t1 = strtolower($info['token1']);
        $has = fn (string $addr) => $t0 === strtolower($addr) || $t1 === strtolower($addr);

        $wtpixIn = $this->dex->isWtpix($t0) || $this->dex->isWtpix($t1);
        $usdtIn = $has($usdt->contract_address);

        // เลือกสกุลอ้างอิง: USDT ชนะ WTPIX (TPIX-USDT คือคู่หลักของระบบ)
        if ($usdtIn) {
            $quote = $usdt;
            $baseAddress = $t0 === strtolower($usdt->contract_address) ? $t1 : $t0;
            $base = $this->dex->isWtpix($baseAddress) ? $tpix : $this->ensureToken($chain, $baseAddress);
        } elseif ($wtpixIn) {
            $quote = $tpix;
            $baseAddress = $this->dex->isWtpix($t0) ? $t1 : $t0;
            $base = $this->ensureToken($chain, $baseAddress);
        } else {
            $this->line("  {$pairAddress} ไม่มีสกุลอ้างอิง (WTPIX/USDT) — ข้าม");

            return false;
        }

        if ($base === null) {
            return false;
        }

        // reserve เรียงตาม base/quote
        $baseOnchain = $this->dex->onchainAddress($base->contract_address);
        $baseIsToken0 = $baseOnchain === $t0;
        $reserveBase = $baseIsToken0 ? $info['reserve0'] : $info['reserve1'];
        $reserveQuote = $baseIsToken0 ? $info['reserve1'] : $info['reserve0'];
        $hasLiquidity = bccomp($reserveBase, '0', 0) > 0 && bccomp($reserveQuote, '0', 0) > 0;

        $symbol = strtoupper($base->symbol.'-'.$quote->symbol);
        $price = TpixDexService::midPrice($reserveBase, $reserveQuote, (int) $base->decimals, (int) $quote->decimals);

        $this->line(sprintf(
            '  %-14s พูล %s · ราคา %s · %s',
            $symbol,
            substr($pairAddress, 0, 10).'…',
            $price ?? '-',
            $hasLiquidity ? 'มีสภาพคล่อง' : 'พูลว่าง',
        ));

        if ($this->dryRun) {
            return true;
        }

        // ต้องมี id ของโทเคนก่อนผูกคู่ (โหมด dry-run ไม่ถึงตรงนี้)
        if (! $base->exists) {
            $base->save();
        }
        if (! $quote->exists) {
            $quote->save();
        }

        $pair = TradingPair::firstOrNew([
            'base_token_id' => $base->id,
            'quote_token_id' => $quote->id,
            'chain_id' => $chain->id,
        ]);

        $wasNew = ! $pair->exists;
        $pair->fill([
            'symbol' => $symbol,
            'is_active' => $hasLiquidity,
            'execution_mode' => 'onchain',
            'dex_pair_address' => $pairAddress,
        ]);

        if ($wasNew) {
            $pair->fill([
                'price_precision' => 8,
                'amount_precision' => 4,
                'min_trade_amount' => 0,
                'max_trade_amount' => 0,
                'sort_order' => $quote->id === $tpix->id ? 50 : 20,
            ]);
        }

        if ($pair->isDirty()) {
            $pair->save();
        }

        if ($price !== null) {
            $this->recordCandle($pair, $price);
        }

        return true;
    }

    /**
     * โทเคนบนเชน TPIX ที่ระบบยังไม่รู้จัก — อ่าน symbol/decimals จากเชนแล้วเพิ่มให้.
     * ชื่อชนกับโทเคนดัชนี (เช่นมีคนสร้างเหรียญชื่อ BTC) ก็อยู่ร่วมกันได้ เพราะ unique คือ chain+address
     */
    private function ensureToken(Chain $chain, string $address): ?Token
    {
        $existing = Token::where('chain_id', $chain->id)
            ->whereRaw('LOWER(contract_address) = ?', [strtolower($address)])
            ->first();

        if ($existing) {
            return $existing;
        }

        $meta = $this->dex->tokenMeta($address);
        if ($meta === null) {
            $this->line("  {$address} อ่าน symbol/decimals ไม่ได้ — ข้าม");

            return null;
        }

        $token = new Token([
            'chain_id' => $chain->id,
            'symbol' => strtoupper($meta['symbol']),
            'name' => $meta['name'],
            'contract_address' => $address,
            'decimals' => $meta['decimals'],
            'is_active' => true,
            'sort_order' => 100,
        ]);

        if (! $this->dryRun) {
            $token->save();
        }

        return $token;
    }

    /**
     * แท่ง 1 นาทีจากราคากลางของพูล — เปิด/สูง/ต่ำ/ปิดอัปเดตในแท่งเดียวกันเมื่อรันซ้ำภายในนาที.
     * ปริมาณเป็น 0 เพราะอ่านจาก reserve ไม่ใช่จาก event สวอป (ราคาจริง แต่ไม่แต่งปริมาณ)
     */
    private function recordCandle(TradingPair $pair, string $price): void
    {
        $openTime = now()->startOfMinute();

        $candle = Kline::firstOrNew([
            'trading_pair_id' => $pair->id,
            'interval' => '1m',
            'open_time' => $openTime,
        ]);

        if (! $candle->exists) {
            $candle->fill(['open' => $price, 'high' => $price, 'low' => $price, 'close' => $price]);
        } else {
            $candle->close = $price;
            if (bccomp($price, (string) $candle->high, 18) > 0) {
                $candle->high = $price;
            }
            if (bccomp($price, (string) $candle->low, 18) < 0) {
                $candle->low = $price;
            }
        }

        $candle->save();
    }

    /** ราคา TPIX อ้างอิงของทั้งระบบตามพูล WTPIX/USDT */
    private function syncTpixPrice(): void
    {
        $price = $this->dex->tpixUsdPrice();
        if ($price === null || $price <= 0) {
            return;
        }

        $this->line('ราคา TPIX จากพูล: '.number_format($price, 6).' USDT');

        if (! $this->dryRun) {
            SiteSetting::set('trading', 'tpix_price', (string) round($price, 8));
            Cache::forget('tpix_price_data');
        }
    }
}
