<?php

namespace App\Services\Stabilizer;

use App\Services\ContractRegistry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * เซ็น/ส่งธุรกรรมของ Stability Reserve ผ่าน ethers.js (scripts/blockchain/stabilizer-sign.cjs).
 *
 * แบบเดียวกับ HotWalletSigner ของคลัง:
 *   - ใช้ได้เฉพาะ CLI (php-fpm บนเซิร์ฟเวอร์ปิด proc_open)
 *   - ความลับ (path + passphrase ของ keystore) ส่งผ่าน env ไม่ใช่ argv เพราะ argv โผล่ใน `ps aux`
 *   - ไม่ log stdout ดิบไม่ว่ากรณีใด
 *
 * สคริปต์ตรวจซ้ำอีกชั้นว่า: ปลายทางอยู่ในรายชื่อที่อนุญาต (router + USDT_TPIX),
 * ฟังก์ชันเป็นหนึ่งใน approve/swap ที่กำหนด, ผู้รับเงินจากสวอปคือกระเป๋าตัวเอง,
 * และ keystore ถอดออกมาได้ที่อยู่ตรงกับที่ประกาศ — ไม่ตรงข้อใดข้อหนึ่ง = ไม่เซ็น
 *
 * Developed by Xman Studio
 */
class NodeStabilizerSigner implements StabilizerTxSigner
{
    public function __construct(
        private StabilizerSettings $settings,
        private ContractRegistry $registry,
    ) {}

    public function isAvailable(): bool
    {
        return PHP_SAPI === 'cli' && function_exists('proc_open');
    }

    public function sign(string $to, string $data, string $valueWei, int $gasLimit, int $nonce): array
    {
        $dex = $this->registry->dexConfig();

        return $this->runNode([
            'STB_ACTION' => 'sign',
            'STB_KEYSTORE_PATH' => (string) config('stabilizer.wallet.keystore_path'),
            'STB_KEYSTORE_PASS' => (string) config('stabilizer.wallet.passphrase'),
            'STB_EXPECT_ADDRESS' => (string) $this->settings->walletAddress(),
            'STB_ALLOWED_TO' => implode(',', array_filter([$dex['ROUTER'] ?? null, $dex['USDT'] ?? null])),
            'STB_TO' => $to,
            'STB_DATA' => $data,
            'STB_VALUE_WEI' => $valueWei,
            'STB_GAS_LIMIT' => (string) $gasLimit,
            'STB_NONCE' => (string) $nonce,
        ], 'sign');
    }

    public function send(string $rawTx): array
    {
        return $this->runNode(['STB_ACTION' => 'send', 'STB_RAW_TX' => $rawTx], 'send');
    }

    private function runNode(array $env, string $label): array
    {
        if (! $this->isAvailable()) {
            return ['ok' => false, 'code' => 'not_cli', 'error' => 'signing is only possible from the CLI scheduler'];
        }

        // .cjs ไม่ใช่ .js — package.json ตั้ง "type": "module" ไฟล์ .js จึง require() ไม่ได้
        $script = base_path('scripts/blockchain/stabilizer-sign.cjs');
        if (! is_readable($script)) {
            return ['ok' => false, 'code' => 'script_missing', 'error' => 'signing script not found'];
        }

        $env += [
            'STB_RPC_URL' => (string) config('blockchain.tpix_rpc_url', 'https://rpc1.tpix.online'),
            'STB_CHAIN_ID' => (string) config('blockchain.tpix_chain_id', 4289),
            'STB_USER_AGENT' => (string) config('stabilizer.rpc_user_agent'),
        ];

        try {
            $result = Process::timeout(120)
                ->env($env)
                ->run(sprintf('%s %s', escapeshellarg((string) config('blockchain.node_path', 'node')), escapeshellarg($script)));
        } catch (\Throwable $e) {
            Log::error('stabilizer: signing script could not start', ['step' => $label, 'error' => $e->getMessage()]);

            return ['ok' => false, 'code' => 'process_failed', 'error' => 'signing process failed to start'];
        }

        $stdout = trim($result->output());
        $decoded = json_decode($stdout, true);

        if (! is_array($decoded)) {
            // ห้าม log stdout ดิบ — log แค่ความยาวกับ exit code
            Log::error('stabilizer: signing script returned unreadable output', [
                'step' => $label,
                'exit_code' => $result->exitCode(),
                'stdout_length' => strlen($stdout),
                'stderr' => mb_substr(trim($result->errorOutput()), 0, 300),
            ]);

            return ['ok' => false, 'code' => 'bad_output', 'error' => 'signing script returned unreadable output'];
        }

        if (! ($decoded['ok'] ?? false)) {
            Log::warning('stabilizer: signing script reported failure', [
                'step' => $label,
                'code' => $decoded['code'] ?? null,
                'error' => $decoded['error'] ?? null,
            ]);
        }

        return $decoded;
    }
}
