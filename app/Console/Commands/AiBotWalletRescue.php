<?php

namespace App\Console\Commands;

use App\Models\AiBotConfig;
use App\Models\AiBotPosition;
use App\Models\AiBotWalletTransfer;
use App\Services\AiBot\Wallet\BotWalletService;
use App\Services\Web3BalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — ทางออกฉุกเฉินของกระเป๋าบอท: ส่งเหรียญที่บอทถืออยู่ "กลับหาเจ้าของ" โดยไม่ต้องขาย.
 *
 * ปกติเงินออกจากไม้ได้สองทาง: บอทขายเอง หรือเจ้าของกดปิดไม้ — ทั้งคู่ต้องสวอปบน DEX
 * ถ้าสวอปไม่ได้เลย (พูลหมดสภาพคล่อง · ราคาเบี่ยงเกินเพดานตลอด · คู่ถูกถอดจากรายการ) เหรียญจะค้าง
 * ในกระเป๋าที่ผู้ใช้ไม่มีกุญแจ และหน้าเว็บถอนได้แค่ USDT/BNB — คำสั่งนี้คือทางที่ทีมงานคืนเหรียญนั้น
 *
 * ปลายทางคือกระเป๋าเจ้าของเท่านั้น (ไม่มีอาร์กิวเมนต์ปลายทาง) และเดินผ่านคิวถอนตัวเดิม —
 * ตัวส่งตรวจซ้ำว่า to_address == owner_address ก่อนเซ็นเสมอ
 *
 * ไม้ที่ถือเหรียญนั้นในบัญชีของบอทถูกปิดไปด้วย (ไม่บันทึกกำไรขาดทุน — ไม่ได้ขาย) ไม่งั้นบอทจะพยายาม
 * ขายเหรียญที่ไม่อยู่ในกระเป๋าแล้วทุกรอบ
 *
 * Developed by Xman Studio.
 */
class AiBotWalletRescue extends Command
{
    protected $signature = 'aibot:wallet-rescue
        {owner : กระเป๋าเจ้าของ (0x…)}
        {token : สัญลักษณ์เหรียญฝั่งซ้ายของคู่ เช่น BTC, ETH, BNB (=WBNB)}
        {--confirm : สร้างรายการโอนจริง (ไม่ใส่ = แสดงให้ดูอย่างเดียว)}';

    protected $description = 'ส่งเหรียญที่ค้างในกระเป๋าบอทกลับหาเจ้าของ (ใช้เมื่อขายคืนเป็น USDT ไม่ได้)';

    public function handle(BotWalletService $wallets, Web3BalanceService $balances): int
    {
        $owner = strtolower((string) $this->argument('owner'));
        $symbol = strtoupper((string) $this->argument('token'));
        $wallet = $wallets->find($owner);

        if (! $wallet) {
            $this->error('ไม่พบกระเป๋าบอทของเจ้าของคนนี้');

            return self::FAILURE;
        }

        // ที่อยู่สัญญามาจากทะเบียนเหรียญของระบบเท่านั้น — ไม่รับที่อยู่จากบรรทัดคำสั่ง
        $token = (array) config("bsc_trade_tokens.tokens.{$symbol}", []);
        $native = (bool) ($token['native'] ?? false);
        $address = strtolower($native ? (string) config('aibot.live.contracts.wbnb', '') : (string) ($token['address'] ?? ''));
        $decimals = (int) ($token['decimals'] ?? 18);
        $quote = strtolower((string) config('aibot.bot_wallet.assets.'.config('aibot.live.quote_asset', 'USDT').'.address', ''));

        if (preg_match('/^0x[a-f0-9]{40}$/', $address) !== 1 || $address === $quote) {
            $this->error('ไม่รู้จักเหรียญนี้ (หรือเป็นเหรียญที่ถอนได้จากหน้าเว็บอยู่แล้ว)');

            return self::FAILURE;
        }

        if ($wallet->transfers()->outgoing()->inFlight()->exists()) {
            $this->error('กระเป๋านี้มีรายการค้างอยู่ — รอให้จบก่อน');

            return self::FAILURE;
        }

        $balance = (float) $balances->getTokenBalance($wallet->address, $address, $wallet->chain_id, $decimals);
        // ปัดลง 8 ตำแหน่ง — ไม่ขอโอนมากกว่าที่มี (เศษที่เหลือเล็กกว่าที่ใครจะใช้ได้)
        $amount = floor($balance * 1e8) / 1e8;

        $botIds = AiBotConfig::forWallet($owner)->pluck('id');
        $positions = AiBotPosition::whereIn('ai_bot_config_id', $botIds)
            ->where('mode', 'live')
            ->where('pair', "{$symbol}/".strtoupper((string) config('aibot.live.quote_asset', 'USDT')))
            ->get();

        $this->table(['ช่อง', 'ค่า'], [
            ['กระเป๋าบอท', $wallet->address],
            ['ปลายทาง (เจ้าของ)', $wallet->owner_address],
            ['เหรียญ', ($native ? 'W' : '').$symbol.' '.$address],
            ['ยอดในกระเป๋า', (string) $balance],
            ['จะโอน', (string) $amount],
            ['ไม้ในบัญชีที่จะถูกปิด', (string) $positions->count()],
        ]);

        if ($amount <= 0) {
            $this->error('ไม่มีเหรียญนี้ในกระเป๋าบอท (หรืออ่านยอดจากเชนไม่ได้)');

            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->info('ยังไม่ได้ทำอะไร — ใส่ --confirm เพื่อสร้างรายการโอน');

            return self::SUCCESS;
        }

        $transfer = DB::transaction(function () use ($wallet, $native, $symbol, $address, $amount, $decimals, $positions) {
            $transfer = $wallet->transfers()->create([
                'owner_address' => $wallet->owner_address,
                'direction' => AiBotWalletTransfer::DIRECTION_WITHDRAW,
                'asset' => ($native ? 'W' : '').$symbol,
                'token_address' => $address,
                'amount' => $amount,
                'amount_wei' => BotWalletService::toWei($amount, $decimals),
                'to_address' => $wallet->owner_address,
                'status' => AiBotWalletTransfer::STATUS_QUEUED,
                'meta' => ['rescue' => true, 'closed_positions' => $positions->pluck('id')->all()],
            ]);

            foreach ($positions as $position) {
                $position->delete();
            }

            return $transfer;
        });

        Log::warning('bot-wallet: ทีมงานสั่งคืนเหรียญที่ค้างในกระเป๋าบอทให้เจ้าของ', [
            'transfer_id' => $transfer->id,
            'owner' => $wallet->owner_address,
            'token' => $address,
            'amount' => $amount,
            'closed_positions' => $positions->map(fn (AiBotPosition $p) => [
                'bot' => $p->ai_bot_config_id, 'quantity' => (string) $p->quantity, 'cost_basis' => (string) $p->cost_basis,
            ])->all(),
        ]);

        $this->info("สร้างรายการโอน #{$transfer->id} แล้ว — aibot:wallet-transfers จะเซ็นและส่งในรอบถัดไป");

        return self::SUCCESS;
    }
}
