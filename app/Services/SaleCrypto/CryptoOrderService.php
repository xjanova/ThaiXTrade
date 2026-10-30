<?php

namespace App\Services\SaleCrypto;

use App\Exceptions\PurchaseException;
use App\Models\SaleCryptoOrder;
use App\Models\SaleCryptoPayment;
use App\Models\SalePhase;
use App\Services\TokenSaleService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * สร้างคำสั่งซื้อ TPIX ด้วย USDT — ล็อกราคา + ออก "ยอดเฉพาะ" ที่ใช้จับคู่เงินเข้ากับคำสั่งซื้อ.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ทำไมจับคู่ด้วยยอดเฉพาะ (ไม่ใช่ tx_hash ที่ผู้ซื้อยื่น และไม่ใช่ที่อยู่รับเงินต่อคำสั่งซื้อ)
 * ═══════════════════════════════════════════════════════════════════════════
 * - เซิร์ฟเวอร์เป็นคนออกยอด ผู้ซื้อแค่โอนตาม → ไม่มีข้อมูลจากฝั่งผู้ซื้อที่ต้องเชื่อ
 *   เงินเข้ากระเป๋ารับเงินยอดนี้เป๊ะ = คำสั่งซื้อนี้ ไม่ว่าจะโอนจากกระเป๋าไหนหรือกระดานเทรด
 * - เหรียญที่ได้ส่งไปกระเป๋าที่ยืนยันเจ้าของแล้วตอนสั่งเสมอ คนอื่นโอนยอดนี้มาแทนก็แค่จ่ายให้เจ้าของคำสั่งซื้อ
 *   — ไม่มีทางขโมยเหรียญด้วยวิธีนี้
 * - ที่อยู่รับเงินแยกต่อคำสั่งซื้อต้องเก็บคีย์ HD บนเซิร์ฟเวอร์ + เติมแก๊สกวาดเงินทุกเชนทุกรายการ
 *   (เพิ่มความเสี่ยงคีย์หลุดและต้นทุน) — ยอดเฉพาะให้ผลเดียวกันโดยเงินเข้ากระเป๋าเย็นของเจ้าของตรง ๆ
 *
 * เศษท้ายเป็นหน่วย 0.000001 USDT (1–9999) → เพิ่มจากค่าเหรียญไม่ถึง 1 เซ็นต์ ไม่นับเป็นค่าเหรียญ
 * unique ของ amount_lock (เชน:เหรียญ:กระเป๋า:ยอด) กันคำสั่งซื้อสองใบได้ยอดเดียวกันในระดับฐานข้อมูล
 *
 * ไม่จองโควตาตอนสั่ง (เหตุผลเดียวกับทางโอนเงิน: กดสั่งรัว ๆ ปิดรอบขายได้ฟรี) — ตรวจโควตาซ้ำตอนเงินเข้า
 * และโควตาหมดระหว่างรอ = needs_review ให้ทีมงานคืนเงิน ไม่ใช่ออกเหรียญเกิน
 */
class CryptoOrderService
{
    private const TAG_MAX = 9999;

    public function __construct(
        private SaleCryptoConfig $config,
        private TokenSaleService $sales,
    ) {}

    /**
     * @throws PurchaseException ข้อความปลอดภัยพอที่จะแสดงให้ผู้ซื้ออ่านตรง ๆ
     */
    public function createOrder(string $walletAddress, int $phaseId, float $amountUsd, int $chainId): SaleCryptoOrder
    {
        if (! $this->config->installed()) {
            throw new PurchaseException('Crypto payments are not available right now.');
        }

        $wallet = strtolower($walletAddress);
        $cents = (int) round($amountUsd * 100);
        $usd = $cents / 100;

        // ด่านเดียวกับบัตรทุกข้อ (เฟสเปิด · ช่องทางที่รอบขายประกาศรับ · whitelist · ขั้นต่ำ/เพดาน · โควตา)
        // ไม่มีค่าบริการ — ราคาเป็น USD และ USDT นับ 1:1 แบบเดียวกับสะพาน
        $check = $this->sales->assertPurchasable($wallet, $phaseId, 'USD', $usd, SaleCryptoConfig::CHANNEL);

        $phase = SalePhase::with('tokenSale')->findOrFail($phaseId);
        $sale = $phase->tokenSale;

        if ($this->config->receiveAddressProblem($sale) !== null) {
            throw new PurchaseException('Crypto payments are not available right now.');
        }

        $chain = $this->config->openChains($sale)[$chainId] ?? null;
        if ($chain === null) {
            throw new PurchaseException('This network is not accepting payments right now. Please choose another network or pay by card.');
        }

        $payTo = $this->config->receiveAddress($sale);

        // กดซ้ำ / รีเฟรชหน้า → ได้คำสั่งซื้อเดิม (ยอดเฉพาะเดิม) ไม่ใช่ใบใหม่ที่ทำให้ผู้ซื้อสับสนว่าต้องโอนยอดไหน
        $existing = SaleCryptoOrder::where('wallet_address', $wallet)
            ->where('status', SaleCryptoOrder::STATUS_AWAITING)
            ->where('sale_phase_id', $phase->id)
            ->where('chain_id', $chainId)
            ->where('amount_usd', number_format($usd, 2, '.', ''))
            ->where('expires_at', '>', now()->addMinutes(5))
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $open = SaleCryptoOrder::where('wallet_address', $wallet)
            ->where('status', SaleCryptoOrder::STATUS_AWAITING)
            ->where('expires_at', '>', now())
            ->count();

        if ($open >= (int) config('sale_crypto.max_open_orders_per_wallet', 3)) {
            throw new PurchaseException('You already have unpaid crypto orders. Please pay or wait for them to expire first.');
        }

        $decimals = $chain['decimals'];
        $baseRaw = bcmul((string) $cents, bcpow('10', (string) ($decimals - 2), 0), 0);
        $microRaw = bcpow('10', (string) ($decimals - 6), 0);

        for ($attempt = 0; $attempt < 25; $attempt++) {
            $tag = random_int(1, self::TAG_MAX);
            $payRaw = bcadd($baseRaw, bcmul((string) $tag, $microRaw, 0), 0);

            try {
                $order = SaleCryptoOrder::create([
                    'reference' => $this->reference(),
                    'token_sale_id' => $sale->id,
                    'sale_phase_id' => $phase->id,
                    'wallet_address' => $wallet,
                    'chain_id' => $chainId,
                    'token_address' => $chain['token'],
                    'token_symbol' => $chain['symbol'],
                    'token_decimals' => $decimals,
                    'pay_to_address' => $payTo,
                    'amount_usd' => $usd,
                    'tpix_amount' => $check['tpix_amount'],
                    'price_per_tpix' => (float) $phase->price_usd,
                    'pay_amount_raw' => $payRaw,
                    'tag_micro' => $tag,
                    'amount_lock' => SaleCryptoOrder::lockKey($chainId, $chain['token'], $payTo, $payRaw),
                    'status' => SaleCryptoOrder::STATUS_AWAITING,
                    'expires_at' => now()->addMinutes(max(5, (int) config('sale_crypto.order_ttl_minutes', 30))),
                ]);
            } catch (UniqueConstraintViolationException) {
                // ยอดนี้ (หรือรหัสอ้างอิง) ถูกจองอยู่ — สุ่มใหม่
                continue;
            }

            Log::info('token-sale: สร้างคำสั่งซื้อคริปโต', [
                'reference' => $order->reference,
                'wallet' => $wallet,
                'chain' => $chainId,
                'amount_usd' => $usd,
                'pay_raw' => $payRaw,
            ]);

            return $order;
        }

        throw new PurchaseException('Unable to create the order right now. Please try again.');
    }

    /** คำสั่งซื้อของกระเป๋านี้ — คนอื่นเปิดดูไม่ได้แม้จะรู้ uuid */
    public function findForWallet(string $uuid, string $walletAddress): ?SaleCryptoOrder
    {
        return SaleCryptoOrder::where('uuid', $uuid)->where('wallet_address', strtolower($walletAddress))->first();
    }

    /**
     * หมดเวลา → expired (ยอดเฉพาะยังจองไว้ให้จับคู่การโอนช้าได้) · พ้นช่วงโอนช้า → ปล่อยยอดคืน.
     *
     * @return array{expired: int, released: int}
     */
    public function sweep(): array
    {
        $expired = SaleCryptoOrder::where('status', SaleCryptoOrder::STATUS_AWAITING)
            ->where('expires_at', '<', now())
            ->update(['status' => SaleCryptoOrder::STATUS_EXPIRED, 'updated_at' => now()]);

        $released = SaleCryptoOrder::whereNotNull('amount_lock')
            ->where('expires_at', '<', now()->subHours(max(1, (int) config('sale_crypto.late_window_hours', 72))))
            ->update(['amount_lock' => null, 'updated_at' => now()]);

        return ['expired' => $expired, 'released' => $released];
    }

    /** ข้อมูลที่หน้าซื้อใช้แสดงวิธีจ่าย — ไม่มีอะไรลับ */
    public function present(SaleCryptoOrder $order): array
    {
        $chain = $this->config->chain($order->chain_id);
        $order->loadMissing(['payments', 'saleTransaction']);

        return [
            'id' => $order->uuid,
            'reference' => $order->reference,
            'status' => $order->status,
            'chain_id' => $order->chain_id,
            'chain_name' => $chain['name'] ?? (string) $order->chain_id,
            'chain_short' => $chain['short'] ?? '',
            'native_symbol' => $chain['native'] ?? '',
            'explorer' => $chain['explorer'] ?? '',
            'token_address' => $order->token_address,
            'token_symbol' => $order->token_symbol,
            'token_decimals' => $order->token_decimals,
            'pay_to_address' => $order->pay_to_address,
            'pay_amount' => $order->payAmountDisplay(),
            'pay_amount_raw' => $order->pay_amount_raw,
            'amount_usd' => (float) $order->amount_usd,
            'tpix_amount' => (float) $order->tpix_amount,
            'price_per_tpix' => (float) $order->price_per_tpix,
            'expires_at' => $order->expires_at->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'transaction_id' => $order->saleTransaction?->uuid,
            'payments' => $order->payments->map(fn (SaleCryptoPayment $p) => [
                'tx_hash' => $p->tx_hash,
                'amount' => $p->amountDisplay(),
                'token_symbol' => $p->token_symbol,
                'status' => $p->status,
                'review_reason' => $p->review_reason,
            ])->values()->all(),
        ];
    }

    private function reference(): string
    {
        // ตัด I/O/0/1 ออก — ผู้ซื้ออ่านให้ทีมงานฟังทางแชทได้ไม่สับสน
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = 'TPX-';

        for ($i = 0; $i < 7; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }
}
