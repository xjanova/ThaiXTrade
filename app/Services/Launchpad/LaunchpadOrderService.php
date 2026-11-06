<?php

namespace App\Services\Launchpad;

use App\Models\LaunchpadOrder;
use App\Models\LaunchpadSale;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\BridgePolicy;
use App\Services\MasterNode\Web3SignatureService;
use App\Support\Wei;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * สร้างคำสั่งซื้อ Launchpad — ผู้ซื้อเซ็นข้อความ (personal_sign) ในกระเป๋าตัวเอง ไม่มีธุรกรรม ไม่เสียค่าแก๊ส.
 *
 * ทำไมต้องเซ็น: คำสั่งซื้อ "ดึง" ยอดฝากครั้งถัดไปของกระเป๋านั้นไปซื้อเหรียญ ถ้าไม่บังคับลายเซ็น
 * ใครก็สร้างคำสั่งให้กระเป๋าคนอื่นได้ แล้วยอดฝากสะพานปกติของเขาจะกลายเป็นการซื้อเหรียญที่เขาไม่ได้สั่ง
 *
 * ข้อความที่เซ็นสร้างจากฝั่งเซิร์ฟเวอร์ทั้งหมด (prepare) แล้วสร้างซ้ำตอนรับ (create) จากค่าเดิม
 * ลายเซ็นหนึ่งใบใช้ได้ครั้งเดียว (message_hash unique) และต้องสดไม่เกิน sign_window_minutes
 */
class LaunchpadOrderService
{
    public function __construct(
        private BridgeConfig $bridge,
        private BridgePolicy $policy,
        private LaunchpadDepositMatcher $matcher,
        private Web3SignatureService $signatures,
    ) {}

    /**
     * ตรวจทุกข้อ + คืนข้อความให้กระเป๋าเซ็น.
     *
     * @return array{message: string, issued_at: string, nonce: string, amount_units: string, pay_units: string, expected_tokens: string, quote: array}
     */
    public function prepare(LaunchpadSale $sale, string $buyer, string $amount, int $chainId): array
    {
        $buyer = $this->normalizeWallet($buyer);
        $units = $this->parseAmount($amount);
        $checked = $this->check($sale, $buyer, $units, $chainId);
        $issuedAt = now()->utc()->startOfSecond();
        $nonce = bin2hex(random_bytes(8));

        return [
            'message' => $this->message($sale, $buyer, $units, $chainId, $issuedAt, $nonce),
            'issued_at' => $issuedAt->toIso8601ZuluString(),
            'nonce' => $nonce,
        ] + $checked;
    }

    /**
     * สร้างคำสั่งจากข้อความที่เซ็นแล้ว.
     */
    public function create(LaunchpadSale $sale, array $input, ?string $clientIp = null): LaunchpadOrder
    {
        $buyer = $this->normalizeWallet((string) ($input['buyer'] ?? ''));
        $units = $this->parseAmount((string) ($input['amount'] ?? ''));
        $chainId = (int) ($input['source_chain_id'] ?? 0);
        $nonce = (string) ($input['nonce'] ?? '');

        if (($input['risk_accepted'] ?? false) !== true) {
            throw new LaunchpadOrderException('RISK_NOT_ACCEPTED', 'ต้องติ๊กยอมรับความเสี่ยงก่อนซื้อ');
        }

        if (! preg_match('/^[0-9a-f]{16}$/', $nonce)) {
            throw new LaunchpadOrderException('INVALID_NONCE', 'ข้อความที่เซ็นไม่ถูกต้อง — กดซื้อใหม่');
        }

        try {
            $issuedAt = Carbon::parse((string) ($input['issued_at'] ?? ''))->utc();
        } catch (\Throwable) {
            throw new LaunchpadOrderException('INVALID_ISSUED_AT', 'ข้อความที่เซ็นไม่ถูกต้อง — กดซื้อใหม่');
        }

        $window = max(1, (int) config('launchpad.sign_window_minutes', 10));
        if ($issuedAt->lessThan(now()->subMinutes($window)) || $issuedAt->greaterThan(now()->addMinute())) {
            throw new LaunchpadOrderException('SIGNATURE_EXPIRED', 'ลายเซ็นหมดอายุแล้ว — กดซื้อใหม่แล้วเซ็นอีกครั้ง');
        }

        $message = $this->message($sale, $buyer, $units, $chainId, $issuedAt, $nonce);
        $signature = (string) ($input['signature'] ?? '');

        if (! $this->signatures->verify($message, $signature, $buyer)) {
            throw new LaunchpadOrderException('INVALID_SIGNATURE', 'ลายเซ็นไม่ตรงกับกระเป๋านี้', 401);
        }

        $checked = $this->check($sale, $buyer, $units, $chainId);
        $hash = '0x'.Abi::keccak($message);
        $ttl = max(5, (int) config('launchpad.order_ttl_minutes', 30));

        try {
            return DB::transaction(function () use ($sale, $buyer, $chainId, $units, $checked, $hash, $signature, $clientIp, $ttl) {
                // ล็อกแถวคำสั่งของกระเป๋านี้ — กดซื้อสองแท็บพร้อมกันต้องได้คำสั่งเดียว
                $open = LaunchpadOrder::where('buyer_address', $buyer)
                    ->where('status', LaunchpadOrder::STATUS_AWAITING)
                    ->lockForUpdate()
                    ->get();

                if ($open->isNotEmpty()) {
                    throw new LaunchpadOrderException('OPEN_ORDER_EXISTS', 'กระเป๋านี้มีคำสั่งที่รอโอนอยู่แล้ว — โอนตามคำสั่งเดิม หรือยกเลิกก่อน', 409, [
                        'order_id' => $open->first()->public_id,
                    ]);
                }

                return LaunchpadOrder::create([
                    'public_id' => (string) Str::uuid(),
                    'launchpad_sale_id' => $sale->id,
                    'buyer_address' => $buyer,
                    'source_chain_id' => $chainId,
                    'amount_units' => $units,
                    'pay_units' => $checked['pay_units'],
                    'expected_tokens' => $checked['expected_tokens'],
                    'status' => LaunchpadOrder::STATUS_AWAITING,
                    'expires_at' => now()->addMinutes($ttl),
                    'risk_accepted_at' => now(),
                    'message_hash' => $hash,
                    'signature' => $signature,
                    'client_ip' => $clientIp ? mb_substr($clientIp, 0, 45) : null,
                ]);
            });
        } catch (QueryException $e) {
            if (LaunchpadOrder::where('message_hash', $hash)->exists()) {
                throw new LaunchpadOrderException('SIGNATURE_USED', 'ลายเซ็นนี้ถูกใช้สร้างคำสั่งไปแล้ว', 409);
            }

            throw $e;
        }
    }

    /**
     * ยกเลิกคำสั่งที่ยังรอโอน — ต้องเซ็นข้อความยกเลิกด้วยกระเป๋าเจ้าของคำสั่ง.
     *
     * ยกเลิกแล้วถ้าเงินมาถึงทีหลัง = เงินฝากสะพานปกติ (ได้ USDT_TPIX) ไม่มีเงินหาย
     */
    public function cancel(LaunchpadOrder $order, string $signature): LaunchpadOrder
    {
        if (! $this->signatures->verify($this->cancelMessage($order), $signature, $order->buyer_address)) {
            throw new LaunchpadOrderException('INVALID_SIGNATURE', 'ลายเซ็นไม่ตรงกับเจ้าของคำสั่ง', 401);
        }

        $changed = LaunchpadOrder::whereKey($order->id)
            ->where('status', LaunchpadOrder::STATUS_AWAITING)
            ->update(['status' => LaunchpadOrder::STATUS_CANCELLED, 'settled_at' => now(), 'updated_at' => now()]);

        if ($changed !== 1) {
            throw new LaunchpadOrderException('ORDER_NOT_CANCELLABLE', 'คำสั่งนี้ได้รับเงินแล้วหรือปิดไปแล้ว — ยกเลิกไม่ได้', 409);
        }

        return $order->fresh();
    }

    public function cancelMessage(LaunchpadOrder $order): string
    {
        return "TPIX Launchpad — cancel order / ยกเลิกคำสั่งซื้อ\n"
            ."Order: {$order->public_id}\n"
            ."Wallet: {$order->buyer_address}";
    }

    /**
     * ทุกด่านก่อนรับคำสั่ง — ผ่านหมดแล้วยอดฝากจะถูกจับคู่จริง (ไม่ผ่านข้อไหน = เงินไปทางสะพานปกติ ผู้ซื้อจะงง).
     *
     * @return array{amount_units: string, pay_units: string, expected_tokens: string, quote: array}
     */
    private function check(LaunchpadSale $sale, string $buyer, string $units, int $chainId): array
    {
        if (! $this->matcher->acceptsChain($chainId)) {
            throw new LaunchpadOrderException('LAUNCHPAD_UNAVAILABLE', 'ยังไม่เปิดรับคำสั่งซื้อจากเชนนี้', 503);
        }

        if (! $sale->isApproved() || ($chainId === 56 && ! $sale->accepts_bsc)) {
            throw new LaunchpadOrderException('SALE_NOT_OPEN', 'หน้าขายนี้ยังไม่เปิดรับคำสั่งซื้อ');
        }

        if (! $sale->inWindow()) {
            throw new LaunchpadOrderException('SALE_NOT_ACTIVE', 'ไม่อยู่ในช่วงเวลาขาย');
        }

        if (! $this->bridge->operational() || ! $this->bridge->chainEnabled($chainId)) {
            throw new LaunchpadOrderException('BRIDGE_PAUSED', 'สะพาน USDT หยุดชั่วคราว — ยังโอนไม่ได้ตอนนี้', 503);
        }

        $min = $this->bridge->limitUnits('min_deposit');
        $max = $this->bridge->effectiveMaxDepositUnits(null);
        if (bccomp($units, $min, 0) < 0 || bccomp($units, $max, 0) > 0) {
            throw new LaunchpadOrderException('AMOUNT_OUT_OF_RANGE', 'จำนวนต้องอยู่ระหว่าง '.Wei::format($min, 6).' – '.Wei::format($max, 6).' USDT ต่อครั้ง', 422, [
                'min' => Wei::format($min, 6),
                'max' => Wei::format($max, 6),
            ]);
        }

        $daily = $this->bridge->limitUnits('max_deposit_per_address_daily');
        if (bccomp(bcadd($this->policy->depositedLast24hUnits($buyer), $units, 0), $daily, 0) > 0) {
            throw new LaunchpadOrderException('DAILY_LIMIT', 'เกินวงเงินฝากต่อกระเป๋าใน 24 ชั่วโมง ('.Wei::format($daily, 6).' USDT)');
        }

        // สะพานส่งยอดจากกระเป๋าสัญญา (multisig/กระดานเทรด) ให้คนดู — ยอดนั้นจะไม่ได้ซื้อเหรียญ บอกก่อนโอน
        $code = $this->bridge->rpc($chainId)?->code($buyer);
        if ($code === null) {
            throw new LaunchpadOrderException('CHAIN_UNAVAILABLE', 'อ่านข้อมูลกระเป๋าจากเชนต้นทางไม่ได้ตอนนี้ — ลองใหม่อีกครั้ง', 503);
        }
        if ($code !== '0x' && ! str_starts_with($code, '0xef0100')) {
            throw new LaunchpadOrderException('CONTRACT_WALLET', 'ต้องโอนจากกระเป๋าส่วนตัว (ไม่ใช่กระเป๋าสัญญา/กระดานเทรด)');
        }

        if (! $this->policy->depositKycPasses($buyer)) {
            throw new LaunchpadOrderException('KYC_REQUIRED', 'ต้องยืนยันตัวตน (KYC) ก่อนโอนผ่านสะพาน');
        }

        $fee = $this->bridge->limitUnits('deposit_fee');
        $pay = bccomp($units, $fee, 0) > 0 ? bcsub($units, $fee, 0) : '0';
        $tokens = $sale->quoteUnits($pay);

        if (bccomp($tokens, '0', 0) <= 0) {
            throw new LaunchpadOrderException('ZERO_OUTPUT', 'จำนวนน้อยเกินไป ได้เหรียญเป็นศูนย์');
        }

        // อ่านจากสัญญาจริง — ขายหมด/ต่ำกว่าขั้นต่ำ/เกินเพดานต่อกระเป๋า ต้องบอกก่อนให้โอน (ไม่งั้นเงินจะถูกคืนเป็น USDT_TPIX)
        $rpc = $this->bridge->tpixRpc();
        $saleAddress = strtolower($sale->sale_address);
        $onchain = [
            'paused' => $rpc->callBool($saleAddress, 'paused()'),
            'remaining' => $rpc->callUint($saleAddress, 'remaining()'),
            'min_purchase' => $rpc->callUint($saleAddress, 'minPurchase()'),
            'max_per_wallet' => $rpc->callUint($saleAddress, 'maxPerWallet()'),
            'purchased' => $rpc->callUint($saleAddress, 'purchased(address)', Abi::encAddress($buyer)),
        ];

        if (in_array(null, $onchain, true)) {
            throw new LaunchpadOrderException('CHAIN_UNAVAILABLE', 'อ่านข้อมูลหน้าขายจากเชนไม่ได้ตอนนี้ — ลองใหม่อีกครั้ง', 503);
        }

        if ($onchain['paused']) {
            throw new LaunchpadOrderException('SALE_PAUSED', 'หน้าขายหยุดชั่วคราว');
        }

        if (bccomp($tokens, $onchain['remaining'], 0) > 0) {
            throw new LaunchpadOrderException('SOLD_OUT', 'เหรียญเหลือไม่พอสำหรับจำนวนนี้', 422, [
                'remaining' => Wei::format($onchain['remaining'], $sale->token_decimals),
            ]);
        }

        if (bccomp($pay, $onchain['min_purchase'], 0) < 0) {
            throw new LaunchpadOrderException('BELOW_MINIMUM', 'ต่ำกว่ายอดซื้อขั้นต่ำ ('.Wei::format($onchain['min_purchase'], 6).' USDT)');
        }

        if (bccomp($onchain['max_per_wallet'], '0', 0) > 0
            && bccomp(bcadd($onchain['purchased'], $tokens, 0), $onchain['max_per_wallet'], 0) > 0) {
            throw new LaunchpadOrderException('WALLET_LIMIT', 'เกินเพดานการซื้อต่อกระเป๋าของหน้าขายนี้');
        }

        return [
            'amount_units' => $units,
            'pay_units' => $pay,
            'expected_tokens' => $tokens,
            'quote' => [
                'amount' => Wei::format($units, 6),
                'fee' => Wei::format(bcsub($units, $pay, 0), 6),
                'tokens' => Wei::format($tokens, $sale->token_decimals),
                'vault' => $this->bridge->vaultAddress(),
                'usdt_contract' => strtolower((string) ($this->bridge->chain($chainId)['usdt']['address'] ?? '')),
                'usdt_decimals' => (int) ($this->bridge->chain($chainId)['usdt']['decimals'] ?? 18),
            ],
        ];
    }

    /** ข้อความที่ผู้ซื้อเซ็น — ทุกค่ามาจากเซิร์ฟเวอร์ ไม่มีข้อความอิสระจากผู้ใช้ */
    public function message(LaunchpadSale $sale, string $buyer, string $units, int $chainId, Carbon $issuedAt, string $nonce): string
    {
        $chainName = (string) ($this->bridge->chain($chainId)['name'] ?? "chain {$chainId}");

        return "TPIX Launchpad — purchase order / คำสั่งซื้อ\n"
            ."Sale: {$sale->token_symbol} ".strtolower($sale->sale_address)."\n"
            .'Pay exactly: '.Wei::format($units, 6)." USDT on {$chainName} (chain {$chainId})\n"
            ."Wallet (pay from + receive to): {$buyer}\n"
            ."Tokens arrive on TPIX Chain (4289) in the same wallet.\n"
            ."I have read the risk warning and accept the risks.\n"
            ."ฉันอ่านคำเตือนความเสี่ยงแล้ว และยอมรับความเสี่ยงเอง\n"
            .'Issued: '.$issuedAt->copy()->utc()->toIso8601ZuluString()."\n"
            ."Nonce: {$nonce}";
    }

    private function normalizeWallet(string $wallet): string
    {
        $wallet = strtolower(trim($wallet));

        if (! preg_match('/^0x[0-9a-f]{40}$/', $wallet) || $wallet === '0x'.str_repeat('0', 40)) {
            throw new LaunchpadOrderException('INVALID_WALLET', 'ที่อยู่กระเป๋าไม่ถูกต้อง');
        }

        return $wallet;
    }

    /** "12.5" → "12500000" (USDT 6 ทศนิยม) — เกิน 6 ทศนิยม/ไม่ใช่ตัวเลข = ไม่รับ (ห้ามปัดให้เอง) */
    private function parseAmount(string $amount): string
    {
        $amount = trim($amount);

        if (! preg_match('/^(\d{1,9})(?:\.(\d{1,6}))?$/', $amount, $m)) {
            throw new LaunchpadOrderException('INVALID_AMOUNT', 'จำนวนไม่ถูกต้อง (ทศนิยมไม่เกิน 6 หลัก)');
        }

        $units = ltrim($m[1].str_pad($m[2] ?? '', 6, '0'), '0');

        return $units === '' ? '0' : $units;
    }
}
