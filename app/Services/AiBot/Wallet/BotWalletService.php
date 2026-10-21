<?php

namespace App\Services\AiBot\Wallet;

use App\Models\AiBotWallet;
use App\Models\AiBotWalletTransfer;
use App\Services\Web3BalanceService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TPIX TRADE — กระเป๋าบอท: สร้าง อ่านยอด และคิวถอนกลับหาเจ้าของ.
 *
 * กฎเหล็กสามข้อที่โค้ดนี้บังคับ ไม่ใช่แค่หน้าจอ:
 *  1. ถอนได้ปลายทางเดียวคือ owner_address — ไม่มีพารามิเตอร์ปลายทางให้ส่งมาเลย
 *  2. มีรายการถอนค้างได้ทีละหนึ่งรายการต่อกระเป๋า — nonce ของกระเป๋าเดียวกันชนกันไม่ได้
 *  3. เว็บทำได้แค่ "ขอถอน" (queued) — ตัวเซ็นจริงคือ aibot:wallet-transfers ฝั่ง CLI
 *
 * Developed by Xman Studio.
 */
class BotWalletService
{
    public const ERR_DISABLED = 'BOT_WALLET_DISABLED';

    public const ERR_NOT_FOUND = 'BOT_WALLET_NOT_FOUND';

    public const ERR_LOCKED = 'BOT_WALLET_LOCKED';

    public const ERR_ASSET = 'BOT_WALLET_ASSET_UNKNOWN';

    public const ERR_AMOUNT = 'BOT_WALLET_AMOUNT_INVALID';

    public const ERR_BALANCE = 'BOT_WALLET_INSUFFICIENT';

    public const ERR_GAS = 'BOT_WALLET_NO_GAS';

    public const ERR_IN_FLIGHT = 'BOT_WALLET_TRANSFER_IN_FLIGHT';

    public const ERR_DAILY_CAP = 'BOT_WALLET_DAILY_CAP';

    public const ERR_NOT_CANCELLABLE = 'BOT_WALLET_NOT_CANCELLABLE';

    public function __construct(
        private readonly BotWalletKeyring $keyring,
        private readonly Web3BalanceService $balances,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('aibot.bot_wallet.enabled', false);
    }

    public function chainId(): int
    {
        return (int) config('aibot.bot_wallet.chain_id', 56);
    }

    /** @return array<string, array{type: string, address?: string, decimals: int, min_withdraw: float}> */
    public function assets(): array
    {
        return (array) config('aibot.bot_wallet.assets', []);
    }

    public function find(string $owner): ?AiBotWallet
    {
        return AiBotWallet::forOwner($owner)->first();
    }

    /**
     * กระเป๋าของเจ้าของคนนี้ — สร้างให้ถ้ายังไม่มี (หนึ่งใบต่อเจ้าของ).
     *
     * กุญแจไม่เคยออกจากเมธอดนี้ในรูปที่อ่านได้: สร้าง → ห่อ → บันทึก → ทิ้ง
     */
    public function ensure(string $owner): AiBotWallet
    {
        $owner = strtolower($owner);

        if ($existing = $this->find($owner)) {
            return $existing;
        }

        if (! $this->enabled() || ! $this->keyring->available()) {
            throw new RuntimeException(self::ERR_DISABLED);
        }

        $pair = $this->keyring->generate();
        $sealed = $this->keyring->seal($pair['private_key'], $owner);
        unset($pair['private_key']);

        return DB::transaction(fn () => AiBotWallet::firstOrCreate(
            ['owner_address' => $owner],
            [
                'chain_id' => $this->chainId(),
                'address' => strtolower($pair['address']),
                'key_ciphertext' => $sealed,
                'key_version' => BotWalletKeyring::KEY_VERSION,
                'status' => AiBotWallet::STATUS_ACTIVE,
            ],
        ));
    }

    /** อ่านยอดทุกสินทรัพย์จากเชนแล้วบันทึกไว้ (Web3BalanceService แคชให้ 15 วิอยู่แล้ว) */
    public function refreshBalances(AiBotWallet $wallet): AiBotWallet
    {
        $balances = [];

        foreach ($this->assets() as $symbol => $asset) {
            $balances[$symbol] = ($asset['type'] ?? 'erc20') === 'native'
                ? (float) $this->balances->getNativeBalance($wallet->address, $wallet->chain_id)
                : (float) $this->balances->getTokenBalance($wallet->address, (string) $asset['address'], $wallet->chain_id, (int) ($asset['decimals'] ?? 18));
        }

        $wallet->forceFill(['balances' => $balances, 'balances_at' => now()])->save();

        return $wallet;
    }

    /**
     * ขอถอนกลับกระเป๋าของเจ้าของ — ตรวจทุกกฎแล้วเข้าคิว ยังไม่แตะกุญแจ.
     */
    public function requestWithdraw(string $owner, string $asset, float $amount, ?string $ip = null): AiBotWalletTransfer
    {
        $owner = strtolower($owner);
        $symbol = strtoupper(trim($asset));
        $wallet = $this->find($owner) ?? throw new RuntimeException(self::ERR_NOT_FOUND);

        if (! $this->enabled()) {
            throw new RuntimeException(self::ERR_DISABLED);
        }

        if (! $wallet->isActive()) {
            throw new RuntimeException(self::ERR_LOCKED);
        }

        $spec = $this->assets()[$symbol] ?? throw new RuntimeException(self::ERR_ASSET);
        $amount = round($amount, 8);

        if ($amount <= 0 || $amount < (float) ($spec['min_withdraw'] ?? 0)) {
            throw new RuntimeException(self::ERR_AMOUNT);
        }

        // เช็คเร็วก่อนยิง RPC — ไม่มีผลต่อความถูกต้อง ด่านจริงอยู่ใต้ล็อกข้างล่าง
        if ($wallet->transfers()->withdrawals()->inFlight()->exists()) {
            throw new RuntimeException(self::ERR_IN_FLIGHT);
        }

        // อ่านยอดจากเชน "นอก" transaction — RPC ช้าได้ ห้ามถือล็อกแถวไว้ระหว่างรอเชน
        $this->refreshBalances($wallet);
        $balances = $wallet->balances ?? [];

        /*
         * ⚠️ ด่านทั้งหมดต้องอยู่ใต้ล็อกแถวกระเป๋าเดียวกับการสร้างรายการ
         *
         * เดิมเช็ค "มีรายการค้างไหม / ยอดพอไหม / เกินเพดานวันไหม" แล้วค่อยสร้างแยกกัน
         * สองคำขอพร้อมกัน (สองแท็บ / ยิง API ซ้อน) ผ่านด่านทั้งคู่ → ได้สองรายการที่ต่างคน
         * ต่างคิดว่ายอดเต็มเป็นของตัวเอง ตัวที่สองไป revert บนเชน (เสียแก๊ส) และเพดานรายวันถูกข้าม
         * ล็อกแถว ai_bot_wallets = คำขอที่สองรอจนตัวแรกบันทึกเสร็จ แล้วเห็นรายการค้างของตัวแรก
         */
        return DB::transaction(function () use ($wallet, $owner, $symbol, $spec, $amount, $balances, $ip) {
            $locked = AiBotWallet::whereKey($wallet->id)->lockForUpdate()->first();

            if (! $locked || ! $locked->isActive()) {
                throw new RuntimeException(self::ERR_LOCKED);
            }

            // กระเป๋าเดียวกันมีรายการค้างได้ทีละหนึ่ง — สองรายการแย่ง nonce กันแล้วพังทั้งคู่
            if ($locked->transfers()->withdrawals()->inFlight()->exists()) {
                throw new RuntimeException(self::ERR_IN_FLIGHT);
            }

            $balanceOf = fn (string $s) => (float) ($balances[strtoupper($s)] ?? 0);

            if ($balanceOf($symbol) + 1e-12 < $amount) {
                throw new RuntimeException(self::ERR_BALANCE);
            }

            $gasReserve = (float) config('aibot.bot_wallet.gas_reserve_bnb', 0.002);
            $nativeSymbol = $this->nativeSymbol();
            $nativeAfter = $balanceOf($nativeSymbol) - ($symbol === $nativeSymbol ? $amount : 0.0);

            if ($nativeAfter + 1e-12 < $gasReserve) {
                throw new RuntimeException(self::ERR_GAS);
            }

            $cap = $this->dailyCap($symbol);
            $today = (float) $locked->transfers()
                ->withdrawals()
                ->where('asset', $symbol)
                ->where('created_at', '>=', now()->startOfDay())
                ->whereIn('status', array_merge(AiBotWalletTransfer::IN_FLIGHT, [AiBotWalletTransfer::STATUS_CONFIRMED]))
                ->sum('amount');

            if ($cap > 0 && $today + $amount > $cap + 1e-9) {
                throw new RuntimeException(self::ERR_DAILY_CAP);
            }

            return $locked->transfers()->create([
                'owner_address' => $owner,
                'direction' => AiBotWalletTransfer::DIRECTION_WITHDRAW,
                'asset' => $symbol,
                'token_address' => ($spec['type'] ?? 'erc20') === 'native' ? null : strtolower((string) $spec['address']),
                'amount' => $amount,
                'amount_wei' => self::toWei($amount, (int) ($spec['decimals'] ?? 18)),
                // ปลายทางเดียวที่เป็นไปได้ — เจ้าของที่ยืนยันแล้วเท่านั้น
                'to_address' => $owner,
                'status' => AiBotWalletTransfer::STATUS_QUEUED,
                'requested_ip' => $ip,
            ]);
        });
    }

    /**
     * เพดานถอนต่อวันของสินทรัพย์หนึ่ง (หน่วยของสินทรัพย์นั้น) — 0 = ไม่จำกัด.
     *
     * แต่ละสินทรัพย์ตั้งของตัวเองได้ (`assets.<SYMBOL>.daily_cap`) — ตัวเลขเดียวใช้ทุกเหรียญไม่มีความหมาย
     * 5000 USDT กับ 5000 BNB ต่างกันหลายร้อยเท่า ไม่ตั้ง = ใช้ค่ากลาง withdraw_daily_cap (เพดานเดิมของ stablecoin)
     */
    public function dailyCap(string $symbol): float
    {
        $spec = $this->assets()[strtoupper($symbol)] ?? [];

        return (float) ($spec['daily_cap'] ?? config('aibot.bot_wallet.withdraw_daily_cap', 5000));
    }

    public function cancelWithdraw(string $owner, int $id): AiBotWalletTransfer
    {
        $transfer = AiBotWalletTransfer::where('owner_address', strtolower($owner))
            ->where('id', $id)
            ->first() ?? throw new RuntimeException(self::ERR_NOT_FOUND);

        if (! $transfer->isCancellable()) {
            throw new RuntimeException(self::ERR_NOT_CANCELLABLE);
        }

        /*
         * compare-and-set — ตัวส่งอาจเพิ่งหยิบรายการนี้ไปเซ็นระหว่างที่เราอ่าน
         * เขียนทับเป็น cancelled ตรง ๆ = หน้าเว็บบอกยกเลิกแล้วทั้งที่ธุรกรรมกำลังจะออก
         */
        $cancelled = AiBotWalletTransfer::whereKey($transfer->id)
            ->where('status', AiBotWalletTransfer::STATUS_QUEUED)
            ->update(['status' => AiBotWalletTransfer::STATUS_CANCELLED]);

        if ($cancelled !== 1) {
            throw new RuntimeException(self::ERR_NOT_CANCELLABLE);
        }

        return $transfer->refresh();
    }

    public function nativeSymbol(): string
    {
        foreach ($this->assets() as $symbol => $asset) {
            if (($asset['type'] ?? '') === 'native') {
                return $symbol;
            }
        }

        return 'BNB';
    }

    public function present(AiBotWallet $wallet): array
    {
        $explorer = rtrim((string) config('chains.chains.'.$wallet->chain_id.'.explorer', ''), '/');
        $assets = [];

        foreach ($this->assets() as $symbol => $spec) {
            $assets[] = [
                'symbol' => $symbol,
                'type' => $spec['type'] ?? 'erc20',
                'address' => $spec['address'] ?? null,
                'decimals' => (int) ($spec['decimals'] ?? 18),
                'min_withdraw' => (float) ($spec['min_withdraw'] ?? 0),
                'daily_cap' => $this->dailyCap($symbol),
                'balance' => $wallet->balanceOf($symbol),
            ];
        }

        return [
            'address' => $wallet->address,
            'chain_id' => $wallet->chain_id,
            'chain_name' => (string) config('chains.chains.'.$wallet->chain_id.'.shortName', ''),
            'explorer_url' => $explorer !== '' ? "{$explorer}/address/{$wallet->address}" : null,
            'status' => $wallet->status,
            'balances' => $wallet->balances ?? [],
            'balances_at' => $wallet->balances_at?->toIso8601String(),
            'assets' => $assets,
            'native_symbol' => $this->nativeSymbol(),
            'gas_reserve' => (float) config('aibot.bot_wallet.gas_reserve_bnb', 0.002),
            // ค่ากลางเดิม (แอปรุ่นเก่าอ่านช่องนี้) — เพดานจริงของแต่ละเหรียญอยู่ที่ assets[].daily_cap
            'withdraw_daily_cap' => (float) config('aibot.bot_wallet.withdraw_daily_cap', 5000),
            'has_pending_withdraw' => $wallet->transfers()->withdrawals()->inFlight()->exists(),
            'created_at' => $wallet->created_at?->toIso8601String(),
        ];
    }

    public function presentTransfer(AiBotWalletTransfer $t): array
    {
        $explorer = rtrim((string) config('chains.chains.'.$this->chainId().'.explorer', ''), '/');

        return [
            'id' => $t->id,
            'direction' => $t->direction,
            'asset' => $t->asset,
            'amount' => (float) $t->amount,
            'to_address' => $t->to_address,
            'status' => $t->status,
            'tx_hash' => $t->tx_hash,
            'tx_url' => $t->tx_hash && $explorer !== '' ? "{$explorer}/tx/{$t->tx_hash}" : null,
            'confirmations' => $t->confirmations,
            // รหัสให้หน้าเว็บ/แอปแปลเอง + ข้อความไทยสำรอง — ไม่มีข้อความดิบจาก RPC/ระบบเข้ารหัส
            'failure_code' => $t->failure_code,
            'failure_reason' => $t->failureMessage(),
            'cancellable' => $t->isCancellable(),
            'created_at' => $t->created_at?->toIso8601String(),
            'confirmed_at' => $t->confirmed_at?->toIso8601String(),
        ];
    }

    /**
     * จำนวน (ทศนิยมไม่เกิน 8 ตำแหน่ง) → หน่วยเล็กสุดของเชนเป็นสตริงตัวเลข.
     *
     * ทำเป็นสตริงล้วนโดยไม่ผ่าน float ซ้ำ — 18 ทศนิยมเกินความละเอียดของ double
     * (ทศนิยม 8 ตำแหน่งก็ละเอียดกว่าที่ใครจะถอนจริงอยู่แล้ว)
     */
    public static function toWei(float $amount, int $decimals): string
    {
        $fixed = number_format($amount, 8, '.', '');
        [$whole, $fraction] = array_pad(explode('.', $fixed, 2), 2, '');

        $fraction = substr(str_pad($fraction, $decimals, '0'), 0, $decimals);
        $wei = ltrim($whole.$fraction, '0');

        return $wei === '' ? '0' : $wei;
    }
}
