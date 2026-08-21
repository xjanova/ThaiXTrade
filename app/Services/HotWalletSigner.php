<?php

namespace App\Services;

use App\Support\TreasuryWallet;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * HotWalletSigner — เซ็นและส่งธุรกรรมจากกระเป๋าร้อนผ่าน ethers.js.
 *
 * ทำไมต้องผ่าน Node ไม่เซ็นใน PHP เอง:
 * keystore ของกระเป๋าร้อนใช้ scrypt N=131072 ซึ่งเป็นค่าเริ่มต้นของ ethers
 * PHP บนเซิร์ฟเวอร์ไม่มี ext-scrypt และ ext-sodium ก็ไม่เปิด API แบบ raw (N,r,p)
 * ให้เรียก ส่วน scrypt แบบเขียนเองใน PHP ที่ N ระดับนี้ใช้เวลาเป็นนาที
 * ethers ถอดได้ในเสี้ยววินาทีเพราะใช้โค้ดเนทีฟ และยังจัดการ RLP + EIP-155
 * ให้ถูกต้องโดยไม่ต้องเขียนเอง (เขียน RLP ผิดหนึ่งไบต์ = เงินหาย)
 *
 * ⚠️ ใช้ได้เฉพาะฝั่ง CLI (artisan / cron)
 * php-fpm บนเซิร์ฟเวอร์นี้ปิด shell_exec/proc_open ไว้ ถ้าเรียกจาก web request
 * จะล้มเหลวเสมอ — นี่คือเหตุผลที่หน้าเว็บทำได้แค่ "สั่งให้ส่ง" แล้วให้คำสั่ง
 * ตามเวลาเป็นคนเซ็นจริง
 *
 * ความลับส่งผ่าน env ไม่ใช่ argv เพราะ argv โผล่ใน `ps aux`
 */
class HotWalletSigner
{
    /**
     * เซ็นธุรกรรม — ยังไม่ส่งขึ้นเชน.
     *
     * แยกขั้นเซ็นกับขั้นส่งออกจากกันโดยตั้งใจ เพื่อให้บันทึก tx hash ลงฐานข้อมูล
     * **ก่อน** ส่งจริง ถ้าเครื่องดับกลางคันหลังส่งแต่ก่อนบันทึก รอบหน้าจะไม่รู้ว่า
     * เคยส่งไปแล้วและเซ็นใหม่ด้วย nonce ใหม่ = จ่ายเงินซ้ำสองรอบ
     *
     * @return array{ok: bool, address?: string, nonce?: int, txHash?: string, raw?: string, error?: string, code?: string}
     */
    public function sign(string $toAddress, string $amountWei, ?int $nonce = null): array
    {
        return $this->runNode([
            'TPIX_ACTION' => 'sign',
            'TPIX_KEYSTORE_PATH' => (string) config('treasury.hot_wallet.keystore_path'),
            'TPIX_KEYSTORE_PASS' => (string) config('treasury.hot_wallet.passphrase'),
            // ต้องตรงกับกระเป๋าที่ตั้งไว้ในหลังบ้าน ไม่งั้นปฏิเสธการเซ็น (fail-closed)
            'TPIX_EXPECT_ADDRESS' => TreasuryWallet::address(),
            'TPIX_TO' => $toAddress,
            'TPIX_VALUE_WEI' => $amountWei,
            'TPIX_NONCE' => $nonce === null ? '' : (string) $nonce,
        ], 'sign');
    }

    /**
     * ส่ง raw transaction ที่เซ็นไว้แล้วขึ้นเชน.
     *
     * @return array{ok: bool, txHash?: string, alreadyKnown?: bool, error?: string, code?: string}
     */
    public function send(string $rawTx): array
    {
        return $this->runNode([
            'TPIX_ACTION' => 'send',
            'TPIX_RAW_TX' => $rawTx,
        ], 'send');
    }

    /**
     * เรียกใช้งานได้ไหมในบริบทนี้ — ต้องเป็น CLI และ Process ต้องทำงานได้.
     */
    public function isAvailable(): bool
    {
        return PHP_SAPI === 'cli' && function_exists('proc_open');
    }

    private function runNode(array $env, string $label): array
    {
        if (! $this->isAvailable()) {
            return [
                'ok' => false,
                'code' => 'not_cli',
                'error' => 'เซ็นธุรกรรมได้เฉพาะฝั่ง CLI เท่านั้น (php-fpm ปิด proc_open)',
            ];
        }

        // นามสกุล .cjs ไม่ใช่ .js โดยตั้งใจ — package.json ตั้ง "type": "module"
        // ไฟล์ .js จึงถูกมองเป็น ES module แล้ว require() ใช้ไม่ได้
        // (สคริปต์เดิม create-token.js กับ bridge-transfer.js ติดปัญหานี้อยู่)
        $script = base_path('scripts/blockchain/treasury-payout.cjs');

        if (! is_readable($script)) {
            return ['ok' => false, 'code' => 'script_missing', 'error' => "ไม่พบสคริปต์ {$script}"];
        }

        $env += [
            'TPIX_RPC_URL' => (string) config('treasury.rpc_url'),
            'TPIX_CHAIN_ID' => (string) config('blockchain.tpix_chain_id', 4289),
            'TPIX_USER_AGENT' => (string) config('treasury.rpc_user_agent'),
        ];

        $nodePath = (string) config('blockchain.node_path', 'node');

        try {
            $result = Process::timeout(120)
                ->env($env)
                ->run(sprintf('%s %s', escapeshellarg($nodePath), escapeshellarg($script)));
        } catch (\Throwable $e) {
            Log::error('treasury: เรียกสคริปต์เซ็นไม่สำเร็จ', ['step' => $label, 'error' => $e->getMessage()]);

            return ['ok' => false, 'code' => 'process_failed', 'error' => $e->getMessage()];
        }

        $stdout = trim($result->output());
        $decoded = json_decode($stdout, true);

        if (! is_array($decoded)) {
            // ห้าม log stdout ดิบ เผื่อมีอะไรหลุดมา — log แค่ความยาวกับ exit code
            Log::error('treasury: สคริปต์เซ็นคืนผลที่อ่านไม่ออก', [
                'step' => $label,
                'exit_code' => $result->exitCode(),
                'stdout_length' => strlen($stdout),
                'stderr' => mb_substr(trim($result->errorOutput()), 0, 500),
            ]);

            return ['ok' => false, 'code' => 'bad_output', 'error' => 'สคริปต์เซ็นคืนผลที่อ่านไม่ออก'];
        }

        if (! ($decoded['ok'] ?? false)) {
            Log::warning('treasury: สคริปต์เซ็นรายงานความล้มเหลว', [
                'step' => $label,
                'code' => $decoded['code'] ?? null,
                'error' => $decoded['error'] ?? null,
            ]);
        }

        return $decoded;
    }
}
