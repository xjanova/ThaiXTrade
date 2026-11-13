<?php

namespace App\Services\AiBot\Live;

use App\Models\AiBotConfig;
use App\Models\AiBotPosition;
use App\Models\AiBotTrade;
use App\Models\AiBotWallet;
use App\Models\AiBotWalletTransfer;
use App\Services\AiBot\Wallet\BotWalletKeyring;
use App\Services\AiBot\Wallet\BotWalletService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TPIX TRADE — โบรกเกอร์เงินจริงของบอท AI (กระเป๋าบอทบน BSC ↔ PancakeSwap).
 *
 * แบ่งงานเป็นสองฝั่งโดยตั้งใจ:
 *   ที่นี่ (เรียกจาก BotRunner — ทั้ง cron และคำขอจากหน้าเว็บ)
 *       ตรวจกติกา คิดขนาดไม้ แล้ว "เข้าคิวคำสั่ง" เท่านั้น — ไม่แตะกุญแจ ไม่ส่งธุรกรรม
 *   aibot:wallet-transfers (CLI เท่านั้น)
 *       หาเส้นทาง ตรวจราคากับเชนตอนนั้น approve เซ็น ส่ง และตามใบเสร็จ
 *       แล้วเรียก settle() ที่นี่เพื่อลงบัญชี
 *
 * บัญชี (ai_bot_trades / ai_bot_positions โหมด live) เขียนจาก "ใบเสร็จบนเชน" เท่านั้น:
 * จำนวนที่จ่ายและได้รับอ่านจาก event Transfer ของธุรกรรมนั้น ไม่ใช่จากใบเสนอราคา
 * คำสั่งที่เข้าคิวแล้วแต่ไม่ลงบล็อก (หมดอายุ / ราคาเบี่ยง / revert) ไม่แตะบัญชีเลย
 *
 * Developed by Xman Studio.
 */
class LiveBroker
{
    public const SIDE_BUY = 'buy';

    public const SIDE_SELL = 'sell';

    /** keccak256("Transfer(address,address,uint256)") */
    private const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public function __construct(
        private readonly BotWalletService $wallets,
        private readonly BotWalletKeyring $keyring,
        private readonly LivePairs $pairs,
    ) {}

    /** บอทส่งคำสั่งเงินจริงเองได้หรือยัง — ต้องเปิดครบทุกธง และกระเป๋าบอทพร้อมเซ็น */
    public function operational(): bool
    {
        return (bool) config('aibot.live_enabled', false)
            && (bool) config('aibot.live.execution', false)
            && $this->wallets->enabled()
            && $this->keyring->available();
    }

    public function supports(string $pair): bool
    {
        return $this->pairs->supports($pair);
    }

    /** คำสั่งของบอทตัวนี้ที่ยังไม่รู้ผล (รอคิว · กำลังเซ็น · รอใบเสร็จ) */
    public function pendingOrder(AiBotConfig $bot): ?AiBotWalletTransfer
    {
        return AiBotWalletTransfer::swaps()
            ->where('ai_bot_config_id', $bot->id)
            ->inFlight()
            ->latest('id')
            ->first();
    }

    /** ของที่บอทถืออยู่ในโหมดเงินจริง */
    public function position(AiBotConfig $bot): ?AiBotPosition
    {
        return AiBotPosition::where('ai_bot_config_id', $bot->id)
            ->where('mode', 'live')
            ->where('quantity', '>', 0)
            ->first();
    }

    /** บอทยังมีเงินจริงค้างอยู่ไหม (ถือของ หรือมีคำสั่งที่ยังไม่รู้ผล) — ห้ามลบ/สลับโหมด/เปลี่ยนคู่ */
    public function hasExposure(AiBotConfig $bot): bool
    {
        return $this->position($bot) !== null || $this->pendingOrder($bot) !== null;
    }

    /**
     * เปิด/เติมไม้ซื้อ — เข้าคิวคำสั่ง USDT → เหรียญของคู่.
     *
     * @return array{ok: bool, queued?: bool, reason?: string, order?: AiBotWalletTransfer}
     */
    public function buy(AiBotConfig $bot, float $marketPrice, float $budgetUsd, array $context): array
    {
        if ((bool) config('aibot.live.entries_paused', false)) {
            return $this->refuse('ระบบพักการเปิดไม้ใหม่ของโหมดเงินจริงไว้ชั่วคราว — ไม้ที่ถืออยู่ยังปิดได้ตามปกติ');
        }

        return $this->place($bot, self::SIDE_BUY, $marketPrice, $budgetUsd, $context, false);
    }

    /**
     * ปิดไม้ (ขายทั้งหมดที่บอทตัวนี้ถือ) — เข้าคิวคำสั่ง เหรียญของคู่ → USDT.
     *
     * @param  bool  $manual  เจ้าของกดปิดเอง — ไม่ติดช่วงพักหลังคำสั่งล้ม และยอมราคาเบี่ยงได้มากกว่า
     * @return array{ok: bool, queued?: bool, reason?: string, order?: AiBotWalletTransfer}
     */
    public function sell(AiBotConfig $bot, float $marketPrice, array $context, bool $manual = false): array
    {
        return $this->place($bot, self::SIDE_SELL, $marketPrice, null, $context, $manual);
    }

    private function place(AiBotConfig $bot, string $side, float $marketPrice, ?float $budgetUsd, array $context, bool $manual): array
    {
        if (! $this->operational()) {
            return $this->refuse('ระบบยังไม่เปิดให้บอทส่งคำสั่งเงินจริง');
        }

        if ($marketPrice <= 0) {
            return $this->refuse('ไม่มีราคาตลาดให้ใช้คำนวณ');
        }

        $position = $this->position($bot);

        if ($side === self::SIDE_SELL && ! $position) {
            return $this->refuse('ไม่มีของให้ขาย');
        }

        // ขาย = คู่ของไม้ที่ถืออยู่จริง ไม่ใช่คู่ปัจจุบันของบอท (ถ้าสองอย่างนี้ไม่ตรงกัน ต้องขายเหรียญที่ถือ ไม่ใช่เหรียญของคู่ใหม่)
        $pair = $this->pairs->resolve($side === self::SIDE_SELL ? $position->pair : $bot->pair);

        if (! $pair) {
            return $this->refuse('คู่เหรียญนี้ยังเทรดเงินจริงไม่ได้ — ใช้ได้เฉพาะคู่ในรายการของโหมดเงินจริง');
        }

        $wallet = $this->wallets->find($bot->wallet_address);

        if (! $wallet) {
            return $this->refuse('ยังไม่มีกระเป๋าบอท — สร้างกระเป๋าบอทและโอนทุนเข้าก่อน');
        }

        if (! $wallet->isActive()) {
            return $this->refuse('กระเป๋าบอทถูกล็อกไว้ — ติดต่อทีมงาน');
        }

        if ($this->pendingOrder($bot)) {
            return $this->refuse('รอผลคำสั่งก่อนหน้าบนเชนอยู่');
        }

        if (! $manual && ($wait = $this->backoff($bot, $side)) !== null) {
            return $this->refuse($wait);
        }

        // อ่านยอดจากเชน "นอก" transaction — RPC ช้าได้ ห้ามถือล็อกแถวกระเป๋าไว้ระหว่างรอ
        try {
            $this->wallets->refreshBalances($wallet);
        } catch (\Throwable $e) {
            Log::warning('aibot-live: อ่านยอดกระเป๋าบอทไม่ได้', ['wallet_id' => $wallet->id, 'error' => $e->getMessage()]);

            return $this->refuse('อ่านยอดกระเป๋าบอทจากเชนไม่ได้ — ข้ามรอบนี้');
        }

        $gasReserve = (float) config('aibot.bot_wallet.gas_reserve_bnb', 0.002);

        if ($wallet->balanceOf($this->wallets->nativeSymbol()) + 1e-12 < $gasReserve) {
            return $this->refuse('BNB ค่าแก๊สในกระเป๋าบอทไม่พอ — โอน BNB เข้ากระเป๋าบอทเพิ่ม');
        }

        $minOrder = (float) config('aibot.live.min_order_usd', 10);

        if ($side === self::SIDE_BUY && (float) $budgetUsd < $minOrder) {
            return $this->refuse(sprintf('ทุนต่อไม้ที่ตั้งไว้ต่ำกว่าขั้นต่ำของโหมดเงินจริง ($%s) — แก้ได้ที่กรอบความเสี่ยงของบอท', $this->money($minOrder)));
        }

        $deviationKey = $side === self::SIDE_BUY
            ? 'entry_max_deviation_bps'
            : ($manual ? 'manual_exit_max_deviation_bps' : 'exit_max_deviation_bps');

        try {
            /*
             * ด่านทั้งหมดอยู่ใต้ล็อกแถวกระเป๋าเดียวกับการสร้างคำสั่ง — สองรอบคิดที่ซ้อนกัน
             * (cron กับหน้าเว็บ / สองบอทของกระเป๋าเดียว) ต่างคนต่างเห็นยอด USDT ก้อนเดียวกันไม่ได้
             */
            $order = DB::transaction(function () use ($bot, $wallet, $pair, $side, $marketPrice, $budgetUsd, $context, $manual, $position, $minOrder, $deviationKey) {
                $locked = AiBotWallet::whereKey($wallet->id)->lockForUpdate()->first();

                if (! $locked || ! $locked->isActive()) {
                    throw new RuntimeException('กระเป๋าบอทถูกล็อกไว้ — ติดต่อทีมงาน');
                }

                if ($this->pendingOrder($bot)) {
                    throw new RuntimeException('รอผลคำสั่งก่อนหน้าบนเชนอยู่');
                }

                if ($side === self::SIDE_BUY) {
                    // USDT ที่คำสั่งซื้ออื่นของกระเป๋านี้จองไว้แล้ว (ยังไม่ลงบล็อก) ใช้ซ้ำไม่ได้
                    $reserved = (float) $locked->transfers()->swaps()->inFlight()
                        ->where('token_address', $pair['quote_address'])
                        ->sum('amount');

                    $available = $wallet->balanceOf($pair['quote']) - $reserved;
                    $budget = floor(min((float) $budgetUsd, $available, (float) config('aibot.live.max_order_usd', 500)) * 100) / 100;

                    if ($budget < $minOrder) {
                        throw new RuntimeException(sprintf('%s ในกระเป๋าบอทไม่พอสำหรับไม้นี้ — โอนทุนเข้ากระเป๋าบอทเพิ่ม', $pair['quote']));
                    }

                    $asset = $pair['quote'];
                    $tokenIn = $pair['quote_address'];
                    $tokenOut = $pair['base_address'];
                    $amount = number_format($budget, 8, '.', '');
                    $amountWei = BotWalletService::toWei($budget, $pair['quote_decimals']);
                } else {
                    $asset = $pair['base_symbol'];
                    $tokenIn = $pair['base_address'];
                    $tokenOut = $pair['quote_address'];
                    // อ่านไม้ใหม่ใต้ล็อก — ระหว่างรอ RPC ไม้อาจถูกปิดไปแล้ว (คำสั่งอื่นเพิ่งลงบล็อก)
                    $held = AiBotPosition::whereKey($position->id)->lockForUpdate()->first();

                    if (! $held || (float) $held->quantity <= 0) {
                        throw new RuntimeException('ไม่มีของให้ขาย');
                    }

                    $amount = (string) $held->quantity;
                    $amountWei = bcmul($amount, bcpow('10', (string) $pair['base_decimals'], 0), 0);

                    if (bccomp($amountWei, '0', 0) <= 0) {
                        throw new RuntimeException('ไม่มีของให้ขาย');
                    }
                }

                return $locked->transfers()->create([
                    'ai_bot_config_id' => $bot->id,
                    'owner_address' => $locked->owner_address,
                    'direction' => AiBotWalletTransfer::DIRECTION_SWAP,
                    'asset' => $asset,
                    'token_address' => $tokenIn,
                    'amount' => $amount,
                    'amount_wei' => $amountWei,
                    // เหรียญที่ได้กลับเข้ากระเป๋าบอทใบเดิมเสมอ — ตัวเซ็นไม่รับผู้รับอื่น ช่องนี้มีไว้ให้คนอ่านเท่านั้น
                    'to_address' => $locked->address,
                    'status' => AiBotWalletTransfer::STATUS_QUEUED,
                    'expires_at' => now()->addMinutes(max(1, (int) config('aibot.live.order_ttl_minutes', 10))),
                    'meta' => [
                        'side' => $side,
                        'pair' => $pair['pair'],
                        'token_out' => $tokenOut,
                        'ref_price' => $marketPrice,
                        'max_deviation_bps' => max(0, (int) config("aibot.live.{$deviationKey}", 300)),
                        'slippage_bps' => max(0, (int) config('aibot.live.slippage_bps', 50)),
                        'manual' => $manual,
                        'strategy' => $bot->strategy,
                        'reason' => (string) ($context['reason'] ?? ''),
                        'signal_meta' => $context['meta'] ?? null,
                        'risk_level' => (string) ($context['risk_level'] ?? 'calm'),
                    ],
                ]);
            });
        } catch (RuntimeException $e) {
            // ข้อความของด่านข้างบนเท่านั้น — QueryException (ก็เป็น RuntimeException) ห้ามถึงผู้ใช้
            if ($e instanceof QueryException) {
                Log::error('aibot-live: เข้าคิวคำสั่งไม่สำเร็จ', ['bot' => $bot->id, 'error' => $e->getMessage()]);

                return $this->refuse('เข้าคิวคำสั่งไม่สำเร็จ — ระบบจะลองใหม่รอบถัดไป');
            }

            return $this->refuse($e->getMessage());
        }

        $bot->update(['last_signal_at' => now()]);

        Log::info('aibot-live: เข้าคิวคำสั่งเงินจริง', [
            'order_id' => $order->id,
            'bot' => $bot->id,
            'side' => $side,
            'pair' => $pair['pair'],
            'amount' => $order->amount,
            'asset' => $order->asset,
            'manual' => $manual,
        ]);

        return ['ok' => true, 'queued' => true, 'order' => $order];
    }

    /**
     * คำสั่งล่าสุดของบอทเพิ่งล้ม = พักก่อนสั่งใหม่.
     *
     * ไม่มีช่วงพัก บอทที่ติดด่านเดิมซ้ำ ๆ (ราคาเบี่ยง / พูลบาง) จะสั่งใหม่ทุกรอบคิด
     * และคำสั่งที่ revert บนเชนเสียค่าแก๊สทุกครั้ง
     */
    private function backoff(AiBotConfig $bot, string $side): ?string
    {
        $minutes = max(0, (int) config('aibot.live.retry_backoff_minutes', 5));

        if ($minutes === 0) {
            return null;
        }

        $last = AiBotWalletTransfer::swaps()->where('ai_bot_config_id', $bot->id)->latest('id')->first();

        if (! $last || $last->status !== AiBotWalletTransfer::STATUS_FAILED || $last->updated_at?->lt(now()->subMinutes($minutes))) {
            return null;
        }

        /*
         * การปิดไม้พักเฉพาะเมื่อคำสั่งก่อนหน้า "เสียค่าแก๊สไปแล้ว" (revert / approve ล้ม)
         * คำสั่งที่ถูกปิดก่อนเซ็น (ราคาเบี่ยง · หมดอายุ) ไม่เสียอะไร — หน่วงการตัดขาดทุนไว้ 5 นาที
         * เพราะเหตุนั้นคือปล่อยให้ขาดทุนวิ่งต่อโดยไม่ได้อะไรกลับมา
         */
        if ($side === self::SIDE_SELL && ! in_array($last->failure_code, [AiBotWalletTransfer::FAIL_REVERTED, AiBotWalletTransfer::FAIL_APPROVE], true)) {
            return null;
        }

        return 'คำสั่งก่อนหน้าไม่สำเร็จ — พักสักครู่ก่อนส่งคำสั่งใหม่';
    }

    /**
     * ลงบัญชีไม้เงินจริงจากใบเสร็จของธุรกรรมที่ยืนยันแล้ว.
     *
     * ⚠️ ผู้เรียกต้องเรียกภายใน DB transaction เดียวกับการเปลี่ยนสถานะคำสั่งเป็น confirmed —
     *    โยน exception = ย้อนทั้งก้อน คำสั่งยังค้างรอผล แล้วรอบหน้าลองใหม่ (ไม่มีทางที่คำสั่งปิดแล้วแต่บัญชีไม่ลง)
     *
     * เรียกซ้ำกับธุรกรรมเดิมได้ — ไม้ผูกกับ tx_hash (unique) จึงลงบัญชีได้ครั้งเดียว
     */
    public function settle(AiBotWalletTransfer $order, array $receipt): ?AiBotTrade
    {
        if (! $order->isSwap() || ! $order->tx_hash) {
            return null;
        }

        if ($existing = AiBotTrade::where('tx_hash', $order->tx_hash)->first()) {
            return $existing;
        }

        $wallet = $order->wallet;
        $side = (string) $order->metaValue('side');
        $tokenIn = strtolower((string) $order->token_address);
        $tokenOut = strtolower((string) $order->metaValue('token_out'));

        [$spentWei, $receivedWei] = $this->flows($receipt, strtolower((string) $wallet->address), $tokenIn, $tokenOut);

        if (bccomp($spentWei, '0', 0) <= 0 || bccomp($receivedWei, '0', 0) <= 0) {
            throw new RuntimeException("aibot-live: ใบเสร็จของคำสั่ง #{$order->id} ไม่มีการโอนเหรียญเข้า/ออกกระเป๋าบอทตามที่คาด");
        }

        $bot = AiBotConfig::find($order->ai_bot_config_id);

        if (! $bot) {
            // บอทถูกลบระหว่างทาง (API กันไว้แล้ว) — เหรียญยังอยู่ในกระเป๋าบอท ไม่มีบัญชีให้ลง
            Log::critical('aibot-live: คำสั่งลงบล็อกแล้วแต่ไม่พบบอทเจ้าของคำสั่ง — เหรียญอยู่ในกระเป๋าบอท ต้องตรวจด้วยมือ', [
                'order_id' => $order->id,
                'tx_hash' => $order->tx_hash,
            ]);

            return null;
        }

        $decimalsIn = (int) $order->metaValue('decimals_in', 18);
        $decimalsOut = (int) $order->metaValue('decimals_out', 18);
        $spent = bcdiv($spentWei, bcpow('10', (string) $decimalsIn, 0), 18);
        $received = bcdiv($receivedWei, bcpow('10', (string) $decimalsOut, 0), 18);

        // ค่าแก๊สของไม้นี้ = สวอป + approve ที่เปิดทางให้ (ตีเป็นดอลลาร์ด้วยราคา BNB ตอนเซ็น · ไม่มีราคา = 0)
        $gasBnb = bcadd(self::gasBnb($receipt, (string) $order->metaValue('gas_price', '0')), $this->approveGas($order), 18);
        $fee = round((float) $gasBnb * (float) $order->metaValue('bnb_usd', 0), 8);
        $refPrice = (float) $order->metaValue('ref_price', 0);
        $pair = (string) $order->metaValue('pair', $bot->pair);

        $common = [
            'ai_bot_config_id' => $bot->id,
            'wallet_address' => $bot->wallet_address,
            'pair' => $pair,
            'mode' => 'live',
            'side' => $side,
            'fee' => $fee,
            'strategy' => (string) $order->metaValue('strategy', $bot->strategy),
            'reason' => (string) $order->metaValue('reason', ''),
            'signal_meta' => $order->metaValue('signal_meta'),
            'risk_level' => (string) $order->metaValue('risk_level', 'calm'),
            'tx_hash' => $order->tx_hash,
        ];

        $position = AiBotPosition::where('ai_bot_config_id', $bot->id)->where('mode', 'live')->lockForUpdate()->first();

        if ($side === self::SIDE_BUY) {
            // ตัดทศนิยมลงเสมอ (12 ตำแหน่งของบัญชี) — บัญชีต้องไม่มากกว่าเหรียญที่อยู่ในกระเป๋าจริง
            $quantity = bcdiv($receivedWei, bcpow('10', (string) $decimalsOut, 0), 12);
            $usd = (float) $spent;

            if (bccomp($quantity, '0', 12) <= 0) {
                throw new RuntimeException("aibot-live: คำสั่ง #{$order->id} ได้เหรียญน้อยกว่าหน่วยเล็กสุดของบัญชี");
            }

            $price = $usd / (float) $quantity;
            $cost = $usd + $fee;

            if ($position) {
                $newQty = bcadd((string) $position->quantity, $quantity, 12);
                $newCost = (float) $position->cost_basis + $cost;

                $position->update([
                    'quantity' => $newQty,
                    'cost_basis' => $newCost,
                    'entry_price' => $newCost / (float) $newQty,
                    'entry_count' => $position->entry_count + 1,
                ]);
            } else {
                AiBotPosition::create([
                    'ai_bot_config_id' => $bot->id,
                    'pair' => $pair,
                    'mode' => 'live',
                    'quantity' => $quantity,
                    'entry_price' => $cost / (float) $quantity,
                    'cost_basis' => $cost,
                    'entry_count' => 1,
                    'opened_at' => now(),
                ]);
            }

            return AiBotTrade::create($common + [
                'price' => $price,
                'quantity' => $quantity,
                'gross_value' => $usd,
                'slippage_cost' => $refPrice > 0 ? ($price - $refPrice) * (float) $quantity : 0,
            ]);
        }

        $sold = (float) $spent;
        $usd = (float) $received;
        $price = $sold > 0 ? $usd / $sold : 0.0;
        $proceeds = $usd - $fee;
        $pnl = null;

        if ($position) {
            $held = (float) $position->quantity;
            // ขายได้ไม่ครบ (ยอดในกระเป๋าน้อยกว่าบัญชี) = ปิดเฉพาะส่วนที่ขายจริง ต้นทุนตัดตามสัดส่วน
            $share = $held > 0 ? min(1.0, $sold / $held) : 1.0;

            if ($share >= 1 - 1e-9) {
                $pnl = $proceeds - (float) $position->cost_basis;
                $position->delete();
            } else {
                $costPart = (float) $position->cost_basis * $share;
                $pnl = $proceeds - $costPart;

                $position->update([
                    'quantity' => bcsub((string) $position->quantity, bcdiv($spentWei, bcpow('10', (string) $decimalsIn, 0), 12), 12),
                    'cost_basis' => (float) $position->cost_basis - $costPart,
                ]);
            }
        } else {
            Log::warning('aibot-live: ขายลงบล็อกแล้วแต่ไม่มีรายการไม้ในบัญชี — บันทึกไม้ขายโดยไม่มีกำไรขาดทุน', ['order_id' => $order->id]);
        }

        return AiBotTrade::create($common + [
            'price' => $price,
            'quantity' => $sold,
            'gross_value' => $usd,
            'slippage_cost' => $refPrice > 0 ? ($refPrice - $price) * $sold : 0,
            'realized_pnl' => $pnl,
        ]);
    }

    /**
     * เหรียญที่ออกจากและเข้ากระเป๋าบอทในธุรกรรมนี้ (หน่วยเล็กสุด) — อ่านจาก event Transfer.
     *
     * @return array{0: string, 1: string} [จ่ายสุทธิของเหรียญขาเข้า, รับสุทธิของเหรียญขาออก]
     */
    private function flows(array $receipt, string $wallet, string $tokenIn, string $tokenOut): array
    {
        $spent = '0';
        $received = '0';

        foreach ((array) ($receipt['logs'] ?? []) as $log) {
            $topics = (array) ($log['topics'] ?? []);

            if (count($topics) !== 3 || strtolower((string) $topics[0]) !== self::TRANSFER_TOPIC) {
                continue;
            }

            $token = strtolower((string) ($log['address'] ?? ''));
            $from = '0x'.substr(strtolower((string) $topics[1]), -40);
            $to = '0x'.substr(strtolower((string) $topics[2]), -40);
            $value = self::hexToDec((string) ($log['data'] ?? '0x0'));

            if ($token === $tokenIn) {
                if ($from === $wallet) {
                    $spent = bcadd($spent, $value, 0);
                }
                if ($to === $wallet) {
                    $spent = bcsub($spent, $value, 0);   // เงินทอนกลับเข้ากระเป๋า
                }
            }

            if ($token === $tokenOut) {
                if ($to === $wallet) {
                    $received = bcadd($received, $value, 0);
                }
                if ($from === $wallet) {
                    $received = bcsub($received, $value, 0);
                }
            }
        }

        return [$spent, $received];
    }

    /** ค่าแก๊สของ approve ที่ยืนยันแล้วของคำสั่งนี้ (BNB) */
    private function approveGas(AiBotWalletTransfer $order): string
    {
        $total = '0';

        $approves = AiBotWalletTransfer::where('parent_id', $order->id)
            ->where('direction', AiBotWalletTransfer::DIRECTION_APPROVE)
            ->get();

        foreach ($approves as $approve) {
            $gas = (string) $approve->metaValue('gas_bnb', '0');

            if (preg_match('/^\d+(\.\d+)?$/', $gas) === 1) {
                $total = bcadd($total, $gas, 18);
            }
        }

        return $total;
    }

    /** ค่าแก๊สที่จ่ายจริงของธุรกรรมหนึ่ง (BNB) — ใช้ได้ทั้งธุรกรรมที่สำเร็จและที่ revert */
    public static function gasBnb(array $receipt, string $fallbackGasPrice = '0'): string
    {
        $used = self::hexToDec((string) ($receipt['gasUsed'] ?? '0x0'));
        $price = isset($receipt['effectiveGasPrice'])
            ? self::hexToDec((string) $receipt['effectiveGasPrice'])
            : (preg_match('/^\d+$/', $fallbackGasPrice) === 1 ? $fallbackGasPrice : '0');

        return bcdiv(bcmul($used, $price, 0), bcpow('10', '18', 0), 18);
    }

    /** เลขฐานสิบหก (มีหรือไม่มี 0x) → สตริงเลขฐานสิบ — ไม่ผ่าน float (uint256 เกินความละเอียด) */
    public static function hexToDec(string $hex): string
    {
        $hex = strtolower(trim($hex));
        $hex = str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;

        if ($hex === '' || preg_match('/^[0-9a-f]+$/', $hex) !== 1) {
            return '0';
        }

        $dec = '0';

        foreach (str_split($hex) as $char) {
            $dec = bcadd(bcmul($dec, '16', 0), (string) hexdec($char), 0);
        }

        return $dec;
    }

    /** คำสั่งหนึ่งรายการสำหรับหน้าเว็บ/แอป */
    public function present(AiBotWalletTransfer $order): array
    {
        $explorer = rtrim((string) config('chains.chains.'.$this->wallets->chainId().'.explorer', ''), '/');

        $approving = $order->status === AiBotWalletTransfer::STATUS_QUEUED
            && AiBotWalletTransfer::where('parent_id', $order->id)->inFlight()->exists();

        return [
            'id' => $order->id,
            'bot_id' => $order->ai_bot_config_id,
            'side' => $order->metaValue('side'),
            'pair' => $order->metaValue('pair'),
            'asset' => $order->asset,
            'amount' => (float) $order->amount,
            'ref_price' => (float) $order->metaValue('ref_price', 0),
            'manual' => (bool) $order->metaValue('manual', false),
            'status' => $order->status,
            // queued แต่กำลังรอ approve ลงบล็อก — หน้าจอบอกได้ว่าอยู่ขั้นไหน
            'stage' => $approving ? 'approving' : $order->status,
            'tx_hash' => $order->tx_hash,
            'tx_url' => $order->tx_hash && $explorer !== '' ? "{$explorer}/tx/{$order->tx_hash}" : null,
            'failure_code' => $order->failure_code,
            'failure_reason' => $order->failureMessage(),
            'reason' => (string) $order->metaValue('reason', ''),
            'created_at' => $order->created_at?->toIso8601String(),
            'expires_at' => $order->expires_at?->toIso8601String(),
            'confirmed_at' => $order->confirmed_at?->toIso8601String(),
        ];
    }

    private function refuse(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason];
    }

    private function money(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
