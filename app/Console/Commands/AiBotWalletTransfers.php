<?php

namespace App\Console\Commands;

use App\Models\AiBotConfig;
use App\Models\AiBotWalletTransfer;
use App\Services\AiBot\Live\LiveBroker;
use App\Services\AiBot\Live\LivePairs;
use App\Services\AiBot\Wallet\BotWalletKeyring;
use App\Services\AiBot\Wallet\BotWalletService;
use App\Services\AiBot\Wallet\BotWalletSigner;
use App\Services\MarketDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ตัวส่งจริงของคิวถอนกระเป๋าบอท (CLI/cron เท่านั้น).
 *
 * ลำดับต่อรายการ (ห้ามสลับ):
 *   queued → signing (ถอดกุญแจ → เซ็น) → บันทึก tx_hash + raw → broadcasting → ส่งขึ้นเชน
 *   → ตาม receipt จนครบ confirmations → confirmed (แล้วอ่านยอดใหม่)
 *
 * "บันทึก hash ก่อนส่ง" คือหัวใจ: ถ้าเครื่องดับหลังส่งแต่ก่อนบันทึก รอบหน้าจะไม่รู้
 * ว่าเคยส่งแล้ว เซ็นใหม่ด้วย nonce ใหม่ = โอนซ้ำสองรอบ
 *
 * ═══ ไม่มีรายการไหนค้างตลอดกาล (ผู้ใช้ไม่มีกุญแจของกระเป๋าบอท — ค้าง = เงินถอนไม่ได้) ═══
 *   signing ไม่มี hash นานเกินรอบปกติ  → โปรเซสตายก่อนบันทึก ยังไม่มีอะไรออกจากเครื่อง → คืนเข้าคิว
 *   ส่งไม่ผ่าน (RPC ล่ม/หมดเวลา)       → ไม่ตีว่าล้ม (โหนดอาจรับไปแล้ว) คงรอผล แล้วตามด้วยใบเสร็จ
 *   เชนไม่รู้จัก tx                    → ส่ง raw เดิมซ้ำ (nonce เดิม ลงบล็อกได้ครั้งเดียว)
 *   ไม่รู้จัก + nonce ถูกตัวอื่นใช้     → (นานพอ) failed/nonce_consumed — ของเราไม่มีวันลงบล็อกแล้ว
 *   ไม่รู้จัก + nonce ยังว่าง นานเกิน   → failed/dropped — ปลอดภัย: รายการใหม่ได้ nonce เดิม ลงได้ตัวเดียว
 *   ตัดสินจาก "ไม่เห็น" แล้วโผล่มาลงบล็อกทีหลัง → แก้กลับเป็น confirmed (ตามต่ออีก recheck_failed_hours)
 *
 * ทุกการเปลี่ยนสถานะเป็น compare-and-set — cron สองรอบที่ซ้อนกัน (รอบหนึ่งช้าเกิน mutex)
 * หยิบรายการเดียวกันไปเซ็นสองครั้งไม่ได้ ตัวที่แพ้ทิ้งธุรกรรมที่เซ็นไว้โดยไม่ส่ง
 *
 * ทีละหนึ่งรายการต่อกระเป๋าต่อรอบ — กระเป๋าเดียวกันห้ามมีสอง nonce ค้างพร้อมกัน
 *
 * ═══ ไม้เงินจริงของบอท (แถว swap + approve ที่เปิดทางให้) เดินผ่านเครื่องสถานะเดียวกันนี้ ═══
 *   ถอน กับ เทรด ใช้ nonce ชุดเดียวของกระเป๋า จึงต้องเข้าคิวเดียวกันและนับ "กำลังส่ง" รวมกัน
 *   แถว swap ถูกตัดสินจากเชนตอนจะเซ็น (เส้นทาง · ราคา · สิทธิ์) — ดู sendSwap()
 *   ยืนยันแล้ว = ลงบัญชีไม้จากใบเสร็จใน transaction เดียวกับการปิดรายการ — ดู closeConfirmed()
 *
 * Developed by Xman Studio.
 */
class AiBotWalletTransfers extends Command
{
    /** เชนไม่รู้จัก tx นานเกินนี้ (วินาที) ถึงเริ่มถามต่อ/ส่ง raw เดิมซ้ำ — กันยิง RPC ทุกรอบตั้งแต่วินาทีแรก */
    public const REBROADCAST_AFTER_SECONDS = 60;

    protected $signature = 'aibot:wallet-transfers {--limit=10 : จำนวนกระเป๋าสูงสุดที่ส่งต่อรอบ}';

    protected $description = 'เซ็นและส่งรายการถอนของกระเป๋าบอทที่เข้าคิวไว้ แล้วตามผลบนเชน';

    private BotWalletService $wallets;

    private BotWalletKeyring $keyring;

    private BotWalletSigner $signer;

    private LiveBroker $live;

    private LivePairs $pairs;

    public function handle(BotWalletService $wallets, BotWalletKeyring $keyring, BotWalletSigner $signer, LiveBroker $live, LivePairs $pairs): int
    {
        if (! $wallets->enabled()) {
            $this->info('กระเป๋าบอทยังไม่เปิดใช้ (AIBOT_BOT_WALLET_ENABLED=false)');

            return self::SUCCESS;
        }

        if (! $signer->isAvailable()) {
            $this->error('เซ็นได้เฉพาะฝั่ง CLI — รอบนี้ข้าม');

            return self::FAILURE;
        }

        $this->wallets = $wallets;
        $this->keyring = $keyring;
        $this->signer = $signer;
        $this->live = $live;
        $this->pairs = $pairs;

        $this->requeueStaleSigning();
        $this->trackBroadcasting($wallets, $signer);
        $this->recheckUnseenFailures($wallets, $signer);
        $this->closeStaleOrders();

        // approve ไม่ถูกหยิบจากคิวตรง ๆ — มันเดินตามแถว swap ที่เป็นเจ้าของ (ดู sendSwap)
        $queued = AiBotWalletTransfer::whereIn('direction', [AiBotWalletTransfer::DIRECTION_WITHDRAW, AiBotWalletTransfer::DIRECTION_SWAP])
            ->where('status', AiBotWalletTransfer::STATUS_QUEUED)
            ->orderBy('id')
            ->get()
            ->unique('ai_bot_wallet_id')   // กระเป๋าละหนึ่งรายการต่อรอบ
            ->take(max(1, (int) $this->option('limit')));

        if ($queued->isEmpty()) {
            $this->info('ไม่มีรายการถอนรอส่ง');

            return self::SUCCESS;
        }

        foreach ($queued as $transfer) {
            $transfer->isSwap() ? $this->sendSwap($transfer) : $this->sendOne($transfer, $keyring, $signer);
        }

        return self::SUCCESS;
    }

    /** กระเป๋าเดียวกันมีรายการอื่นกำลังเซ็น/รอใบเสร็จอยู่ไหม — nonce ชุดเดียว ห้ามซ้อน (ถอน · approve · สวอป นับรวมกัน) */
    private function walletBusy(AiBotWalletTransfer $transfer): bool
    {
        return $transfer->wallet->transfers()->outgoing()
            ->whereIn('status', [AiBotWalletTransfer::STATUS_SIGNING, AiBotWalletTransfer::STATUS_BROADCASTING])
            ->where('id', '!=', $transfer->id)
            ->exists();
    }

    private function sendOne(AiBotWalletTransfer $transfer, BotWalletKeyring $keyring, BotWalletSigner $signer): void
    {
        $wallet = $transfer->wallet;

        // กระเป๋าเดียวกันต้องไม่มีรายการที่กำลังส่งอยู่ (กันเคสรอบก่อนยังตาม receipt ไม่จบ)
        if ($this->walletBusy($transfer)) {
            $this->line("  #{$transfer->id} รอ — กระเป๋านี้มีรายการกำลังส่งอยู่");

            return;
        }

        // ปลายทางต้องเป็นเจ้าของเสมอ — ตรวจซ้ำตอนจะส่งจริง ไม่เชื่อค่าที่บันทึกไว้อย่างเดียว
        if (strcasecmp($transfer->to_address, $wallet->owner_address) !== 0) {
            $this->markFailed($transfer, AiBotWalletTransfer::STATUS_QUEUED, AiBotWalletTransfer::FAIL_OWNER_MISMATCH, 'to_address != owner_address');

            return;
        }

        $this->signAndSend(
            $transfer,
            fn (string $privateKey) => $signer->sign(
                $privateKey,
                $wallet->address,
                $transfer->to_address,
                (string) $transfer->amount_wei,
                $transfer->token_address,
            ),
            fn (string $code) => $code === 'estimate_failed' ? AiBotWalletTransfer::FAIL_INSUFFICIENT : AiBotWalletTransfer::FAIL_SIGN,
        );
    }

    /**
     * ไม้เงินจริงของบอท — ตัดสินใหม่ทั้งหมดจากเชน "ตอนจะเซ็น" ไม่ใช่ตอนที่บอทตัดสินใจ.
     *
     * แถวคำสั่งบอกแค่เจตนา (ฝั่ง · จำนวน · ราคาอ้างอิง) ส่วนเหรียญ router และผู้รับมาจาก config
     * และกระเป๋าเองเสมอ — แถวที่ถูกแก้ในฐานข้อมูลพาเงินไปที่อื่นไม่ได้
     *
     * ลำดับ: ตรวจคำสั่ง → หาเส้นทางที่ได้มากสุด → เทียบราคากับราคาตลาดที่บอทใช้ตัดสินใจ
     *        → สิทธิ์ไม่พอ = ส่ง approve เท่าจำนวนไม้นี้ แล้วรอรอบหน้า → เซ็นสวอปพร้อม minOut
     * ทุกด่านที่ไม่ผ่านปิดคำสั่ง "ก่อนเซ็น" — ไม่เสียค่าแก๊ส และบัญชีของบอทไม่ถูกแตะ
     */
    private function sendSwap(AiBotWalletTransfer $order): void
    {
        $wallet = $order->wallet;
        $queued = AiBotWalletTransfer::STATUS_QUEUED;

        if ($this->walletBusy($order)) {
            $this->line("  #{$order->id} รอ — กระเป๋านี้มีรายการกำลังส่งอยู่");

            return;
        }

        if (! $this->live->operational() || ! $wallet->isActive()) {
            $this->markFailed($order, $queued, AiBotWalletTransfer::FAIL_TRADING_OFF, 'live execution off or wallet locked');

            return;
        }

        if ($order->expires_at?->isPast()) {
            $this->markFailed($order, $queued, AiBotWalletTransfer::FAIL_EXPIRED, 'expired before signing');

            return;
        }

        $side = (string) $order->metaValue('side');
        $pair = $this->pairs->resolve((string) $order->metaValue('pair'));
        $amountIn = (string) $order->amount_wei;

        if (! $pair || ! in_array($side, [LiveBroker::SIDE_BUY, LiveBroker::SIDE_SELL], true) || preg_match('/^[1-9][0-9]*$/', $amountIn) !== 1) {
            $this->markFailed($order, $queued, AiBotWalletTransfer::FAIL_PAIR, 'pair/side/amount not valid');

            return;
        }

        [$tokenIn, $tokenOut, $decimalsIn, $decimalsOut] = $side === LiveBroker::SIDE_BUY
            ? [$pair['quote_address'], $pair['base_address'], $pair['quote_decimals'], $pair['base_decimals']]
            : [$pair['base_address'], $pair['quote_address'], $pair['base_decimals'], $pair['quote_decimals']];

        // เหรียญและผู้รับในแถวต้องตรงกับที่คำนวณจาก config — ไม่ตรง = แถวถูกแก้ ไม่เซ็น
        if (strtolower((string) $order->token_address) !== $tokenIn
            || strtolower((string) $order->metaValue('token_out')) !== $tokenOut
            || strcasecmp((string) $order->to_address, (string) $wallet->address) !== 0) {
            $this->markFailed($order, $queued, AiBotWalletTransfer::FAIL_PAIR, 'order tokens/recipient do not match config');

            return;
        }

        // approve ของคำสั่งนี้ยังค้างคิวอยู่ (โปรเซสตายก่อนหยิบ / ถูกคืนเข้าคิว) — เดินต่อจากตรงนั้น
        $approve = AiBotWalletTransfer::where('parent_id', $order->id)
            ->where('direction', AiBotWalletTransfer::DIRECTION_APPROVE)
            ->where('status', $queued)
            ->oldest('id')
            ->first();

        if ($approve) {
            $this->sendApprove($approve, $order);

            return;
        }

        $pin = $order->metaValue('router_pin');
        $plan = $this->signer->planSwap($wallet->address, $tokenIn, $tokenOut, $amountIn, is_string($pin) ? $pin : null);

        if (! ($plan['ok'] ?? false)) {
            // อ่านเชนไม่ได้ ≠ คำสั่งผิด — คงไว้ในคิว รอบหน้าลองใหม่จนกว่าจะหมดอายุ
            $this->warn("  #{$order->id} สำรวจเส้นทางไม่ได้ (".($plan['code'] ?? 'error').') — ลองใหม่รอบหน้า');

            return;
        }

        if ((int) ($plan['decimalsIn'] ?? -1) !== $decimalsIn || (int) ($plan['decimalsOut'] ?? -1) !== $decimalsOut) {
            $this->markFailed($order, $queued, AiBotWalletTransfer::FAIL_PAIR, 'token decimals on chain differ from config');

            return;
        }

        $balanceIn = $this->digits($plan['balanceIn'] ?? null);

        if (bccomp($balanceIn, $amountIn, 0) < 0) {
            // ซื้อ: USDT ไม่พอ = ไม่ลดขนาดไม้เอง · ขาย: ขายเท่าที่มีจริง (บัญชีตัดตามที่ขายได้ ดู LiveBroker::settle)
            if ($side === LiveBroker::SIDE_BUY || bccomp($balanceIn, '0', 0) <= 0) {
                $this->markFailed($order, $queued, AiBotWalletTransfer::FAIL_INSUFFICIENT, "balance {$balanceIn} < amount {$amountIn}");

                return;
            }

            $amountIn = $balanceIn;
            $plan = $this->signer->planSwap($wallet->address, $tokenIn, $tokenOut, $amountIn, is_string($pin) ? $pin : null);

            if (! ($plan['ok'] ?? false)) {
                $this->warn("  #{$order->id} สำรวจเส้นทางไม่ได้ — ลองใหม่รอบหน้า");

                return;
            }
        }

        // ค่าแก๊สต้องพอสำหรับ approve + สวอป — ส่งธุรกรรมที่โหนดปฏิเสธเพราะแก๊สไม่พอ = กระเป๋าค้างรอผลเป็นครึ่งชั่วโมง
        $gasPrice = $this->digits($plan['gasPrice'] ?? null);
        $gasNeeded = bcmul($gasPrice === '0' ? '3000000000' : $gasPrice, (string) self::GAS_UNITS_RESERVED, 0);

        if (bccomp($this->digits($plan['nativeBalance'] ?? null), $gasNeeded, 0) < 0) {
            $this->markFailed($order, $queued, AiBotWalletTransfer::FAIL_INSUFFICIENT, 'native balance below gas needed');

            return;
        }

        $best = $plan['best'] ?? null;
        $kind = is_array($best) ? (string) ($best['kind'] ?? '') : '';
        $router = $this->signer->router($kind);
        $quoteOut = is_array($best) ? $this->digits($best['amountOut'] ?? null) : '0';

        if ($router === null || bccomp($quoteOut, '0', 0) <= 0) {
            $this->markFailed($order, $queued, AiBotWalletTransfer::FAIL_NO_ROUTE, 'no route with liquidity');

            return;
        }

        // ── ด่านราคา: ของที่จะได้จริงจาก DEX เทียบกับราคาตลาดที่บอทใช้ตัดสินใจ ──────────────
        $refPrice = (float) $order->metaValue('ref_price', 0);

        /*
         * ราคาอ้างอิงในแถวคือราคาตอนบอทตัดสินใจ (นานได้ถึงอายุของคำสั่ง) และเป็นค่าที่แถวที่ถูกแก้
         * ตั้งเองได้ — เทียบกับราคาตลาด "ตอนนี้" อีกชั้น: ห่างเกินเพดาน = ไม่เซ็น (ตลาดวิ่งไปแล้ว
         * หรือราคาอ้างอิงถูกบิดให้ด่าน DEX ข้างล่างผ่าน) · ดึงราคาตลาดไม่ได้ = ข้ามด่านนี้ ไม่ขวางการปิดไม้
         */
        $market = $this->marketPrice($pair['pair']);
        $maxDrift = max(0, (int) config('aibot.live.max_reference_drift_bps', 300));

        if ($refPrice > 0 && $market !== null && abs($market / $refPrice - 1) * 10000 > $maxDrift) {
            $this->markFailed($order, $queued, AiBotWalletTransfer::FAIL_PRICE_MOVED, sprintf('reference %.8f vs market %.8f beyond %d bps', $refPrice, $market, $maxDrift));

            return;
        }
        $in = (float) bcdiv($amountIn, bcpow('10', (string) $decimalsIn, 0), 18);
        $out = (float) bcdiv($quoteOut, bcpow('10', (string) $decimalsOut, 0), 18);
        $expected = $refPrice > 0 ? ($side === LiveBroker::SIDE_BUY ? $in / $refPrice : $in * $refPrice) : 0.0;

        if ($expected <= 0 || $out <= 0) {
            $this->markFailed($order, $queued, AiBotWalletTransfer::FAIL_ORDER_SIGN, 'no reference price on order');

            return;
        }

        $deviationBps = (1 - $out / $expected) * 10000;
        // เพดานในแถวใช้ได้ไม่เกินเพดานที่ผ่อนที่สุดของระบบ — แถวที่ถูกแก้ตั้งเพดานกว้างเองไม่ได้
        $maxDeviation = min(
            max(0, (int) $order->metaValue('max_deviation_bps', 0)),
            max(0, (int) config('aibot.live.manual_exit_max_deviation_bps', 500)),
        );

        if ($deviationBps > $maxDeviation) {
            $this->markFailed($order, $queued, AiBotWalletTransfer::FAIL_PRICE_MOVED, sprintf('deviation %.1f bps > max %d bps', $deviationBps, $maxDeviation));

            return;
        }

        $slippage = min(500, max(0, (int) $order->metaValue('slippage_bps', 50)));
        $minOut = bcdiv(bcmul($quoteOut, (string) (10000 - $slippage), 0), '10000', 0);

        if (bccomp($minOut, '0', 0) <= 0) {
            $this->markFailed($order, $queued, AiBotWalletTransfer::FAIL_NO_ROUTE, 'min out rounds to zero');

            return;
        }

        // ── สิทธิ์ที่ให้ router ไว้ไม่พอ = approve เท่าจำนวนไม้นี้ก่อน แล้วรอรอบหน้า ────────────
        if (bccomp($this->digits($plan['allowance'][$kind] ?? null), $amountIn, 0) < 0) {
            $attempts = AiBotWalletTransfer::where('parent_id', $order->id)->where('direction', AiBotWalletTransfer::DIRECTION_APPROVE)->count();

            if ($attempts >= max(1, (int) config('aibot.live.max_approve_attempts', 2))) {
                $this->markFailed($order, $queued, AiBotWalletTransfer::FAIL_APPROVE, "approve failed {$attempts} times");

                return;
            }

            // ตรึงชนิด router — รอบหน้าเส้นทางที่ดีที่สุดอาจสลับ V2↔V3 แล้วต้อง approve ใหม่ไม่รู้จบ
            $this->patchMeta($order, ['router_pin' => $kind]);

            $approve = $wallet->transfers()->create([
                'ai_bot_config_id' => $order->ai_bot_config_id,
                'parent_id' => $order->id,
                'owner_address' => $wallet->owner_address,
                'direction' => AiBotWalletTransfer::DIRECTION_APPROVE,
                'asset' => $order->asset,
                'token_address' => $tokenIn,
                'amount' => bcdiv($amountIn, bcpow('10', (string) $decimalsIn, 0), 18),
                'amount_wei' => $amountIn,
                'to_address' => $router,
                'status' => $queued,
                'meta' => ['router' => $kind, 'gas_price' => $gasPrice],
            ]);

            $this->sendApprove($approve, $order);

            return;
        }

        $deadline = now()->timestamp + max(60, (int) config('aibot.live.deadline_seconds', 300));
        $route = ['kind' => $kind, 'tokens' => array_values((array) ($best['tokens'] ?? [])), 'fees' => array_values((array) ($best['fees'] ?? []))];
        $bnbQuote = $this->digits($plan['bnbQuote'] ?? null);

        $this->signAndSend(
            $order,
            fn (string $privateKey) => $this->signer->signSwap($privateKey, $wallet->address, $tokenIn, $tokenOut, $amountIn, $minOut, $deadline, $route),
            fn (string $code) => $code === 'estimate_failed' ? AiBotWalletTransfer::FAIL_SIMULATION : AiBotWalletTransfer::FAIL_ORDER_SIGN,
            [
                'route' => $route,
                'amount_in_wei' => $amountIn,
                'quote_out_wei' => $quoteOut,
                'min_out_wei' => $minOut,
                'decimals_in' => $decimalsIn,
                'decimals_out' => $decimalsOut,
                'deviation_bps' => round($deviationBps, 1),
                'deadline' => $deadline,
                'gas_price' => $gasPrice,
                // ราคา BNB เป็นดอลลาร์ตอนเซ็น (1 WBNB → stablecoin) — ใช้ตีค่าแก๊สของไม้นี้ลงบัญชี
                'bnb_usd' => (float) bcdiv($bnbQuote, bcpow('10', (string) $pair['quote_decimals'], 0), 6),
            ],
        );
    }

    /** ราคาตลาดล่าสุดของคู่ (null = ดึงไม่ได้) — แหล่งเดียวกับที่บอทใช้ตัดสินใจ แต่ถามใหม่ตอนจะเซ็น */
    private function marketPrice(string $pair): ?float
    {
        try {
            $price = (float) (app(MarketDataService::class)->getTokenPrice($pair)['price'] ?? 0);

            return $price > 0 ? $price : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** ค่าแก๊สที่ต้องมีในกระเป๋าก่อนเริ่มไม้หนึ่ง (หน่วยแก๊ส) — approve (~50k) + สวอปสองทอด (~350k) เผื่อแล้ว */
    public const GAS_UNITS_RESERVED = 600000;

    /**
     * approve ของคำสั่งสวอป — ผู้รับสิทธิ์เป็นได้แค่ router ของ PancakeSwap จาก config และจำนวนเท่าไม้นั้น.
     */
    private function sendApprove(AiBotWalletTransfer $approve, AiBotWalletTransfer $order): void
    {
        $wallet = $approve->wallet;

        if ($this->walletBusy($approve)) {
            return;
        }

        $router = $this->signer->router((string) $approve->metaValue('router'));

        if ($router === null
            || strcasecmp((string) $approve->to_address, $router) !== 0
            || strcasecmp((string) $approve->token_address, (string) $order->token_address) !== 0
            || $order->status !== AiBotWalletTransfer::STATUS_QUEUED) {
            $this->markFailed($approve, AiBotWalletTransfer::STATUS_QUEUED, AiBotWalletTransfer::FAIL_PAIR, 'approve does not match its order');

            return;
        }

        $this->signAndSend(
            $approve,
            fn (string $privateKey) => $this->signer->signApprove($privateKey, $wallet->address, (string) $approve->token_address, $router, (string) $approve->amount_wei),
            fn (string $code) => AiBotWalletTransfer::FAIL_ORDER_SIGN,
        );
    }

    /**
     * หยิบรายการ → ถอดกุญแจ → เซ็น → บันทึก hash + raw → ส่ง (ลำดับเดียวกันทุกชนิดรายการ ห้ามสลับ).
     *
     * @param  callable(string): array  $sign  รับกุญแจ คืนผลของตัวเซ็น
     * @param  callable(string): string  $failureCode  รหัสจากตัวเซ็น → รหัสเหตุผลที่ล้มของรายการ
     * @param  array  $meta  ข้อมูลที่บันทึกพร้อม hash (เส้นทาง · ใบเสนอราคา)
     */
    private function signAndSend(AiBotWalletTransfer $transfer, callable $sign, callable $failureCode, array $meta = []): void
    {
        $wallet = $transfer->wallet;
        $signer = $this->signer;

        // หยิบรายการแบบ compare-and-set — ยกเลิกไปแล้ว/อีกรอบหยิบไปก่อน = ไม่แตะกุญแจเลย
        if (! $this->transition($transfer, AiBotWalletTransfer::STATUS_QUEUED, [
            'status' => AiBotWalletTransfer::STATUS_SIGNING,
            'signing_at' => now(),
        ])) {
            $this->line("  #{$transfer->id} ข้าม — สถานะเปลี่ยนไปแล้ว (ยกเลิก หรือรอบอื่นหยิบไปก่อน)");

            return;
        }

        try {
            $privateKey = $this->keyring->open($wallet);
        } catch (\Throwable $e) {
            // ข้อความจากตัวถอดกุญแจ (เช่น "The MAC is invalid") ไปอยู่ใน log เท่านั้น ไม่ถึงผู้ใช้
            $this->markFailed($transfer, AiBotWalletTransfer::STATUS_SIGNING, AiBotWalletTransfer::FAIL_KEY_UNREADABLE, $e->getMessage());

            return;
        }

        $signed = $sign($privateKey);
        unset($privateKey);

        if (! ($signed['ok'] ?? false) || empty($signed['txHash']) || empty($signed['raw'])) {
            // ยังไม่มีอะไรออกจากเครื่อง — ล้มได้อย่างปลอดภัย เงินยังอยู่ในกระเป๋าบอท
            $this->markFailed($transfer, AiBotWalletTransfer::STATUS_SIGNING, $failureCode((string) ($signed['code'] ?? '')), ($signed['code'] ?? 'error').': '.($signed['error'] ?? ''));

            return;
        }

        // ⚠️ บันทึก hash + raw ก่อนส่ง — ห้ามสลับลำดับ
        // compare-and-set: ระหว่างเซ็นมีคนปิดรายการ/อีกรอบคืนเข้าคิว = ทิ้งธุรกรรมที่เซ็นไว้ (ยังไม่ส่ง nonce ไม่ถูกใช้)
        $recorded = $this->transition($transfer, AiBotWalletTransfer::STATUS_SIGNING, [
            'status' => AiBotWalletTransfer::STATUS_BROADCASTING,
            'tx_hash' => strtolower((string) $signed['txHash']),
            'nonce' => $signed['nonce'] ?? null,
            'raw_tx' => (string) $signed['raw'],
            'broadcast_at' => now(),
        ] + ($meta === [] ? [] : ['meta' => json_encode(array_merge($transfer->meta ?? [], $meta))]));

        if (! $recorded) {
            Log::warning('bot-wallet: รายการเปลี่ยนสถานะระหว่างเซ็น — ทิ้งธุรกรรมที่เซ็นไว้ ไม่ส่ง', ['transfer_id' => $transfer->id]);
            $this->warn("  #{$transfer->id} สถานะเปลี่ยนระหว่างเซ็น — ไม่ส่ง");

            return;
        }

        $result = $signer->send((string) $signed['raw']);

        if (! ($result['ok'] ?? false)) {
            /*
             * ส่งไม่ผ่าน ≠ ไม่ได้โอน — โหนดอาจรับไปแล้วแต่ตอบไม่ทัน (หมดเวลา 120 วินาที / เน็ตหลุด)
             * เดิมตีว่า failed ทันที รายการหลุดจากโควตาวันและล็อกต่อกระเป๋า ทั้งที่ธุรกรรมลงบล็อกได้
             * → คงสถานะรอผล ให้ trackBroadcasting ตัดสินจากใบเสร็จ/ส่ง raw เดิมซ้ำ
             */
            Log::warning('bot-wallet: ส่งขึ้นเชนไม่ผ่าน — คงสถานะรอผล รอบหน้าตรวจใบเสร็จ/ส่งซ้ำ', [
                'transfer_id' => $transfer->id,
                'code' => $result['code'] ?? null,
                'error' => $result['error'] ?? null,
            ]);
            $this->warn("  #{$transfer->id} ส่งไม่ผ่าน (".($result['code'] ?? 'error').') — รอตรวจ receipt รอบหน้า');

            return;
        }

        Log::info('bot-wallet: ส่งรายการถอนขึ้นเชนแล้ว', [
            'transfer_id' => $transfer->id,
            'wallet' => $wallet->address,
            'asset' => $transfer->asset,
            'tx_hash' => $signed['txHash'],
        ]);

        $this->info("  #{$transfer->id} ส่งแล้ว {$transfer->amount} {$transfer->asset} → {$signed['txHash']}");
    }

    /**
     * "กำลังเซ็น" ค้างโดยยังไม่มี hash = โปรเซสตายระหว่างถอดกุญแจ/เซ็น.
     *
     * ปลอดภัยที่จะคืนเข้าคิว เพราะ hash ถูกบันทึกก่อนส่งเสมอ — ไม่มี hash = ไม่เคยส่ง
     * เงื่อนไข tx_hash IS NULL อยู่ใน UPDATE ด้วย ไม่ใช่แค่ตอนเลือก
     */
    private function requeueStaleSigning(): void
    {
        $cutoff = now()->subMinutes(max(1, (int) config('aibot.bot_wallet.signing_stale_minutes', 10)));

        $stale = AiBotWalletTransfer::outgoing()
            ->where('status', AiBotWalletTransfer::STATUS_SIGNING)
            ->whereNull('tx_hash')
            ->where(fn ($q) => $q->where('signing_at', '<', $cutoff)
                ->orWhere(fn ($q) => $q->whereNull('signing_at')->where('updated_at', '<', $cutoff)))
            ->get();

        foreach ($stale as $transfer) {
            $done = AiBotWalletTransfer::whereKey($transfer->id)
                ->where('status', AiBotWalletTransfer::STATUS_SIGNING)
                ->whereNull('tx_hash')
                ->update(['status' => AiBotWalletTransfer::STATUS_QUEUED, 'signing_at' => null]);

            if ($done === 1) {
                Log::warning('bot-wallet: รายการค้างสถานะกำลังเซ็น (ยังไม่มี hash) — คืนเข้าคิว', ['transfer_id' => $transfer->id]);
                $this->warn("  #{$transfer->id} ค้างกำลังเซ็น — คืนเข้าคิว");
            }
        }
    }

    private function trackBroadcasting(BotWalletService $wallets, BotWalletSigner $signer): void
    {
        $pending = AiBotWalletTransfer::where('status', AiBotWalletTransfer::STATUS_BROADCASTING)
            ->whereNotNull('tx_hash')
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        $need = max(1, (int) config('aibot.bot_wallet.confirmations', 3));
        $stuckMinutes = max(1, (int) config('aibot.bot_wallet.stuck_after_minutes', 30));
        $head = $signer->blockNumber();

        foreach ($pending as $transfer) {
            $hash = (string) $transfer->tx_hash;
            $receipt = $signer->receipt($hash);

            if ($receipt !== null) {
                $this->settle($wallets, $transfer, $receipt, $head, $need);

                continue;
            }

            $since = $transfer->broadcast_at ?? $transfer->updated_at;
            $ageSeconds = $since ? (int) abs(now()->diffInSeconds($since)) : PHP_INT_MAX;

            // เพิ่งส่ง — ยังไม่ลงบล็อกเป็นเรื่องปกติ ไม่ต้องถามเชนเพิ่ม
            if ($ageSeconds < self::REBROADCAST_AFTER_SECONDS) {
                continue;
            }

            $known = $signer->transactionKnown($hash);
            if ($known === null) {
                continue;   // อ่านไม่ได้ ≠ หาย — รอ
            }

            if ($known) {
                // อยู่ใน mempool แต่ไม่ลงบล็อกนานผิดปกติ (แก๊สต่ำ) — ยังลงได้อยู่ ห้ามตัดสินเอง แจ้งทีมงานครั้งเดียวต่อชั่วโมง
                if ($ageSeconds >= $stuckMinutes * 180 && Cache::add("bot-wallet:stuck-warned:{$transfer->id}", 1, 3600)) {
                    Log::warning('bot-wallet: ธุรกรรมค้างใน mempool นานผิดปกติ — ตรวจด้วย aibot:wallet-transfer-resolve', ['transfer_id' => $transfer->id, 'tx_hash' => $hash]);
                }

                continue;
            }

            // เชนไม่รู้จัก tx — nonce ของเราถูกใช้ไปแล้วหรือยัง
            $latest = $signer->nonceOf((string) $transfer->wallet->address, 'latest');
            if ($latest === null) {
                continue;
            }

            $stuck = $ageSeconds >= $stuckMinutes * 60;

            if ($transfer->nonce !== null && $latest > $transfer->nonce) {
                // nonce ถูกใช้แล้ว — อาจเป็นของเราเองที่เพิ่งลงบล็อก (RPC ตามไม่ทัน) ถามใบเสร็จอีกรอบก่อนตัดสิน
                $again = $signer->receipt($hash);
                if ($again !== null) {
                    $this->settle($wallets, $transfer, $again, $head, $need);
                } elseif ($stuck) {
                    $this->markFailed(
                        $transfer,
                        AiBotWalletTransfer::STATUS_BROADCASTING,
                        AiBotWalletTransfer::FAIL_NONCE_CONSUMED,
                        "nonce {$transfer->nonce} ถูกใช้โดยธุรกรรมอื่น (latest {$latest})"
                    );
                }

                continue;
            }

            if ($stuck) {
                // ไม่ลงบล็อก และ nonce ยังว่าง — รายการใหม่จะได้ nonce เดิม (ลงได้ตัวเดียว) จึงปิดได้ไม่เสี่ยงจ่ายซ้ำ
                $this->markFailed(
                    $transfer,
                    AiBotWalletTransfer::STATUS_BROADCASTING,
                    AiBotWalletTransfer::FAIL_DROPPED,
                    "ไม่เห็นบนเชนหลัง {$stuckMinutes} นาที (nonce {$transfer->nonce} ยังว่าง)"
                );

                continue;
            }

            if (! $transfer->raw_tx) {
                continue;   // แถวก่อนมี raw — ส่งซ้ำไม่ได้ รอจนครบเวลาแล้วปิดเป็น dropped
            }

            // ส่ง raw เดิมซ้ำ — nonce เดิม ลงบล็อกได้ครั้งเดียว ไม่มีทางกลายเป็นสองรายการ
            $sent = $signer->send((string) $transfer->raw_tx);
            Log::info('bot-wallet: ส่ง raw เดิมซ้ำ', ['transfer_id' => $transfer->id, 'ok' => $sent['ok'] ?? false, 'code' => $sent['code'] ?? null]);
        }
    }

    /** ตัดสินจากใบเสร็จ — revert = ล้ม · ครบยืนยัน = สำเร็จ · ยังไม่ครบ = รอ */
    private function settle(BotWalletService $wallets, AiBotWalletTransfer $transfer, array $receipt, ?int $head, int $need): void
    {
        $block = isset($receipt['blockNumber']) ? (int) hexdec((string) $receipt['blockNumber']) : null;

        // ค่าแก๊สที่จ่ายจริงของธุรกรรมฝั่งเทรด — เก็บทั้งที่สำเร็จและที่ revert (ไม้ที่ revert ก็เสียแก๊ส)
        $gas = $transfer->direction === AiBotWalletTransfer::DIRECTION_WITHDRAW
            ? []
            : ['gas_bnb' => LiveBroker::gasBnb($receipt, (string) $transfer->metaValue('gas_price', '0'))];

        if (($receipt['status'] ?? '0x0') !== '0x1') {
            $transfer->forceFill(['block_number' => $block])->save();

            if ($gas !== []) {
                $this->patchMeta($transfer, $gas);
            }

            $this->markFailed($transfer, AiBotWalletTransfer::STATUS_BROADCASTING, AiBotWalletTransfer::FAIL_REVERTED, 'receipt status 0x0');

            if ($transfer->isSwap()) {
                $this->refreshQuietly($wallets, $transfer);
            }

            return;
        }

        $confirmations = ($head !== null && $block !== null) ? max(0, $head - $block + 1) : 0;

        if ($confirmations < $need) {
            $transfer->update(['block_number' => $block, 'confirmations' => $confirmations]);

            return;
        }

        $confirmed = [
            'status' => AiBotWalletTransfer::STATUS_CONFIRMED,
            'block_number' => $block,
            'confirmations' => $confirmations,
            'confirmed_at' => now(),
        ] + ($gas === [] ? [] : ['meta' => json_encode(array_merge($transfer->meta ?? [], $gas))]);

        if ($this->closeConfirmed($transfer, AiBotWalletTransfer::STATUS_BROADCASTING, $confirmed, $receipt)) {
            $this->refreshQuietly($wallets, $transfer);
            $this->info("  #{$transfer->id} สำเร็จ (บล็อก {$block})");
        }
    }

    /**
     * ปิดรายการเป็นสำเร็จ — ไม้ของบอทลงบัญชีใน transaction เดียวกับการเปลี่ยนสถานะ.
     *
     * ชื่อไม่ใช่ confirm() — Command ของ Laravel มี confirm() สาธารณะอยู่แล้ว ชนกันแล้วบูตไม่ขึ้น
     *
     * ลงบัญชีไม่ได้ (ใบเสร็จอ่านไม่ออก) = ย้อนทั้งก้อน รายการยังค้างรอผล กระเป๋าใบนั้นหยุดส่งคำสั่งใหม่
     * จนกว่าจะแก้ — ดีกว่าปิดคำสั่งไปทั้งที่บัญชีของบอทไม่รู้ว่าได้เหรียญมาแล้ว
     */
    private function closeConfirmed(AiBotWalletTransfer $transfer, string $from, array $attributes, array $receipt): bool
    {
        if (! $transfer->isSwap()) {
            return $this->transition($transfer, $from, $attributes);
        }

        try {
            return DB::transaction(function () use ($transfer, $from, $attributes, $receipt) {
                if (! $this->transition($transfer, $from, $attributes)) {
                    return false;
                }

                $this->live->settle($transfer, $receipt);

                return true;
            });
        } catch (\Throwable $e) {
            $transfer->refresh();
            Log::error('aibot-live: ธุรกรรมยืนยันแล้วแต่ลงบัญชีไม้ไม่สำเร็จ — คงสถานะรอผล รอบหน้าลองใหม่', [
                'transfer_id' => $transfer->id,
                'tx_hash' => $transfer->tx_hash,
                'error' => $e->getMessage(),
            ]);
            $this->error("  #{$transfer->id} ยืนยันแล้วแต่ลงบัญชีไม่สำเร็จ — ลองใหม่รอบหน้า");

            return false;
        }
    }

    /** อ่านยอดใหม่หลังรายการจบ — อ่านไม่ได้ไม่ใช่เหตุให้รอบนี้ล้ม */
    private function refreshQuietly(BotWalletService $wallets, AiBotWalletTransfer $transfer): void
    {
        try {
            $wallets->refreshBalances($transfer->wallet);
        } catch (\Throwable $e) {
            Log::warning('bot-wallet: อ่านยอดหลังรายการจบไม่ได้', ['transfer_id' => $transfer->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * คำสั่งเทรดที่ค้างคิวจนเลยอายุ และ approve ที่คำสั่งเจ้าของจบไปแล้ว — ปิดโดยไม่แตะกุญแจ.
     *
     * สัญญาณของบอทมีอายุ: คำสั่งที่รอคิวเกินไม่กี่นาที (เชนล่ม / กระเป๋าติดรายการอื่น) ถูกตัดสินจากราคา
     * ที่ไม่ใช่ราคาตอนนี้แล้ว บอทจะคิดใหม่เองในรอบถัดไป
     */
    private function closeStaleOrders(): void
    {
        $expired = AiBotWalletTransfer::swaps()
            ->where('status', AiBotWalletTransfer::STATUS_QUEUED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $order) {
            $this->markFailed($order, AiBotWalletTransfer::STATUS_QUEUED, AiBotWalletTransfer::FAIL_EXPIRED, 'expired in queue');
        }

        $orphans = AiBotWalletTransfer::where('direction', AiBotWalletTransfer::DIRECTION_APPROVE)
            ->where('status', AiBotWalletTransfer::STATUS_QUEUED)
            ->get();

        foreach ($orphans as $approve) {
            $parentQueued = AiBotWalletTransfer::whereKey($approve->parent_id)
                ->where('status', AiBotWalletTransfer::STATUS_QUEUED)
                ->exists();

            if (! $parentQueued) {
                $this->transition($approve, AiBotWalletTransfer::STATUS_QUEUED, ['status' => AiBotWalletTransfer::STATUS_CANCELLED]);
            }
        }
    }

    /** เขียนค่าเพิ่มลง meta ของรายการ (ไม่เปลี่ยนสถานะ) */
    private function patchMeta(AiBotWalletTransfer $transfer, array $values): void
    {
        $transfer->refresh();
        $transfer->mergeMeta($values)->save();
    }

    /** สตริงตัวเลขล้วนจากผลของสคริปต์ — อย่างอื่นนับเป็น 0 (ไม่ส่งของแปลกเข้า bcmath) */
    private function digits(mixed $value): string
    {
        return is_string($value) && preg_match('/^[0-9]+$/', $value) === 1 ? ltrim($value, '0') ?: '0' : '0';
    }

    /**
     * รายการที่ตัดสินว่า "ไม่ได้โอน" จากการไม่เห็นบนเชน (ไม่ใช่จากใบเสร็จ) — ตามใบเสร็จต่ออีกพักหนึ่ง.
     *
     * ธุรกรรมที่โหนดเราไม่รู้จักอาจยังลอยอยู่ในโหนดอื่นแล้วลงบล็อกทีหลัง ถ้าไม่แก้กลับ
     * ประวัติจะบอกว่าไม่ได้โอนทั้งที่เงินถึงเจ้าของแล้ว และโควตารายวันนับขาด
     */
    private function recheckUnseenFailures(BotWalletService $wallets, BotWalletSigner $signer): void
    {
        $rows = AiBotWalletTransfer::outgoing()
            ->where('status', AiBotWalletTransfer::STATUS_FAILED)
            ->whereIn('failure_code', AiBotWalletTransfer::UNSEEN_FAILURES)
            ->whereNotNull('tx_hash')
            ->where('updated_at', '>=', now()->subHours(max(1, (int) config('aibot.bot_wallet.recheck_failed_hours', 24))))
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $need = max(1, (int) config('aibot.bot_wallet.confirmations', 3));
        $head = $signer->blockNumber();

        foreach ($rows as $transfer) {
            $receipt = $signer->receipt((string) $transfer->tx_hash);
            if ($receipt === null || ($receipt['status'] ?? '0x0') !== '0x1') {
                continue;
            }

            $block = isset($receipt['blockNumber']) ? (int) hexdec((string) $receipt['blockNumber']) : null;
            $confirmations = ($head !== null && $block !== null) ? max(0, $head - $block + 1) : 0;
            if ($confirmations < $need) {
                continue;
            }

            // ไม้ของบอทที่โผล่มาลงบล็อกทีหลังต้องลงบัญชีด้วย — ไม่งั้นกระเป๋ามีเหรียญที่บัญชีของบอทไม่รู้จัก
            if ($this->closeConfirmed($transfer, AiBotWalletTransfer::STATUS_FAILED, [
                'status' => AiBotWalletTransfer::STATUS_CONFIRMED,
                'block_number' => $block,
                'confirmations' => $confirmations,
                'confirmed_at' => now(),
                'failure_code' => null,
                'failure_reason' => null,
            ], $receipt)) {
                Log::warning('bot-wallet: รายการที่ตัดสินว่าไม่ได้โอน ลงบล็อกทีหลัง — แก้เป็นสำเร็จ', ['transfer_id' => $transfer->id, 'block' => $block]);
                $this->refreshQuietly($wallets, $transfer);
                $this->warn("  #{$transfer->id} ลงบล็อกทีหลัง — แก้เป็นสำเร็จ (บล็อก {$block})");
            }
        }
    }

    /** compare-and-set: เปลี่ยนสถานะเฉพาะเมื่อยังเป็นสถานะเดิม — คืน true ถ้าเราเป็นคนเปลี่ยน */
    private function transition(AiBotWalletTransfer $transfer, string $from, array $attributes): bool
    {
        $done = AiBotWalletTransfer::whereKey($transfer->id)->where('status', $from)->update($attributes) === 1;

        if ($done) {
            $transfer->refresh();
        }

        return $done;
    }

    /**
     * ชื่อไม่ใช่ fail() — Command ของ Laravel 11 มี fail() สาธารณะอยู่แล้ว ชนกันแล้วบูตไม่ขึ้น.
     *
     * failure_reason = ข้อความที่ผู้ใช้อ่านได้ตามรหัส · รายละเอียดดิบ ($detail) ลง log เท่านั้น
     */
    private function markFailed(AiBotWalletTransfer $transfer, string $from, string $code, string $detail = ''): void
    {
        $message = AiBotWalletTransfer::FAILURE_MESSAGES[$code] ?? AiBotWalletTransfer::FAILURE_MESSAGES[AiBotWalletTransfer::FAIL_SIGN];

        $done = $this->transition($transfer, $from, [
            'status' => AiBotWalletTransfer::STATUS_FAILED,
            'failure_code' => $code,
            'failure_reason' => $message,
        ]);

        if (! $done) {
            return;   // สถานะเปลี่ยนไปแล้ว (อีกรอบ/แอดมินจัดการ) — ไม่ทับ
        }

        Log::warning('bot-wallet: รายการล้มเหลว', ['transfer_id' => $transfer->id, 'direction' => $transfer->direction, 'code' => $code, 'detail' => mb_substr($detail, 0, 500)]);
        $this->error("  #{$transfer->id} {$message}");

        /*
         * คำสั่งปิดไม้ที่ด่านขาดทุนต่อวันสั่งไว้ (แล้วพักบอท) ไม่ลงบล็อก = ยังถือของอยู่กับบอทที่ไม่เดิน
         * ปลุกบอทกลับมาให้รอบถัดไปประเมินใหม่และสั่งปิดอีกครั้ง — เฉพาะบอทที่ยังถูกพักอยู่
         * (ผู้ใช้กดหยุดเองระหว่างนั้น = stopped ไม่ถูกแตะ)
         */
        if ($transfer->isSwap() && $transfer->metaValue('resume_bot_on_failure') === true) {
            $resumed = AiBotConfig::whereKey($transfer->ai_bot_config_id)->where('status', 'paused')->update(['status' => 'running']);

            if ($resumed === 1) {
                Log::warning('aibot-live: คำสั่งปิดไม้ไม่ลงบล็อก — ปลุกบอทกลับมาสั่งปิดใหม่', ['transfer_id' => $transfer->id, 'bot' => $transfer->ai_bot_config_id]);
            }
        }
    }
}
