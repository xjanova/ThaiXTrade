<?php

namespace App\Console\Commands;

use App\Services\AiBot\Live\LivePairs;
use App\Services\AiBot\Wallet\BotWalletKeyring;
use App\Services\AiBot\Wallet\BotWalletRemoteSigner;
use Illuminate\Console\Command;

/**
 * TPIX TRADE — พิมพ์ค่าตั้ง (ที่ไม่ใช่ความลับ) สำหรับเครื่องตัวเซ็นของกระเป๋าบอท จาก config ของเว็บ.
 *
 * ตัวเซ็นถือรายการเหรียญ/สัญญาของตัวเองโดยตั้งใจ (เครื่องเว็บเพิ่มรายการผ่านคำขอไม่ได้) จึงต้องตั้งสองที่
 * ถ้าพิมพ์เองแล้วไม่ตรงกัน อาการคือคำสั่งของบอทล้มด้วย policy_token ทั้งที่เว็บบอกว่าคู่นั้นเทรดได้
 * คำสั่งนี้พิมพ์บรรทัดให้คัดลอกไปใส่ .env ของตัวเซ็น และรายงานสถานะการเชื่อมต่อ
 *
 * ไม่พิมพ์ความลับใด ๆ — คีย์ห่อไม่อยู่บนเครื่องนี้ และ secret ของ HMAC แสดงแค่ว่าตั้งแล้วหรือยัง
 *
 * Developed by Xman Studio.
 */
class AiBotSignerEnv extends Command
{
    protected $signature = 'aibot:signer-env';

    protected $description = 'พิมพ์ค่าตั้งเหรียญ/สัญญาสำหรับเครื่องตัวเซ็นกระเป๋าบอท และตรวจการเชื่อมต่อ';

    public function handle(LivePairs $pairs, BotWalletKeyring $keyring, BotWalletRemoteSigner $remote): int
    {
        $contracts = (array) config('aibot.live.contracts', []);
        $quote = null;
        $tokens = [];

        foreach ($pairs->supported() as $pair) {
            $resolved = $pairs->resolve($pair);

            if ($resolved === null) {
                continue;
            }

            $quote ??= $resolved;
            $tokens[$resolved['base_address']] = $resolved['base_symbol'];
        }

        if ($quote === null || $tokens === []) {
            $this->error('ไม่มีคู่เหรียญที่บอทเทรดเงินจริงได้ใน config (aibot.live.bases / bsc_trade_tokens)');

            return self::FAILURE;
        }

        $maxEntryWei = bcmul(
            number_format((float) config('aibot.live.max_order_usd', 500), 6, '.', ''),
            bcpow('10', (string) $quote['quote_decimals'], 0),
            0,
        );

        $this->line('# ── คัดลอกบรรทัดด้านล่างไปใส่ .env ของเครื่องตัวเซ็น (ไม่มีความลับ) ──');
        $this->line('# เหรียญที่เทรดได้: '.implode(', ', array_values($tokens)));
        $this->line('SIGNER_CHAIN_ID='.(int) config('aibot.bot_wallet.chain_id', 56));
        $this->line('SIGNER_WBNB='.($contracts['wbnb'] ?? ''));
        $this->line('SIGNER_V2_ROUTER='.($contracts['v2_router'] ?? ''));
        $this->line('SIGNER_V3_ROUTER='.($contracts['v3_router'] ?? ''));
        $this->line('SIGNER_V3_QUOTER='.($contracts['v3_quoter'] ?? ''));
        $this->line('SIGNER_MULTICALL3='.($contracts['multicall3'] ?? ''));
        $this->line('SIGNER_QUOTE_TOKEN='.$quote['quote_address']);
        $this->line('SIGNER_TRADE_TOKENS='.implode(',', array_keys($tokens)));
        $this->line('SIGNER_MAX_ENTRY_QUOTE_WEI='.$maxEntryWei);
        $this->newLine();

        $this->line('# ── สถานะฝั่งเครื่องเว็บ ──');
        $this->line('ที่เก็บกุญแจ (AIBOT_BOT_WALLET_SIGNER): '.$keyring->driver());
        $this->line('ตั้ง URL + secret ของตัวเซ็นครบ: '.($remote->configured() ? 'ใช่' : 'ยัง'));
        $this->line('ตัวเซ็นตอบ /health: '.($remote->healthy() ? 'ใช่' : 'ไม่ตอบ'));

        if ((string) config('aibot.bot_wallet.encryption_key') !== '' && app()->environment('production')) {
            $this->warn('⚠️ พบ AIBOT_BOT_WALLET_KEY ใน .env ของเครื่องเว็บ production — คีย์ห่อไม่ควรอยู่บนเครื่องนี้ ให้ลบออก');
        }

        return self::SUCCESS;
    }
}
