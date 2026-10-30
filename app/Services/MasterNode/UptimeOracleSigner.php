<?php

namespace App\Services\MasterNode;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * UptimeOracleSigner — เซ็น/ส่ง updateUptimeBatch ด้วยกุญแจ uptime oracle ผ่าน ethers.
 *
 * เหตุผลที่ผ่าน Node เหมือน HotWalletSigner: keystore ใช้ scrypt N=131072 ซึ่ง PHP
 * บนเซิร์ฟเวอร์ถอดเองไม่ได้ในเวลาที่รับได้ และ RLP + EIP-155 เขียนเองผิดหนึ่งไบต์ก็พัง
 *
 * ⚠️ CLI เท่านั้น — php-fpm ปิด proc_open · ความลับส่งผ่าน env ไม่ใช่ argv
 *
 * Developed by Xman Studio
 */
class UptimeOracleSigner
{
    public function configured(): bool
    {
        return (string) config('masternode.uptime.oracle.keystore_path') !== ''
            && (string) config('masternode.uptime.oracle.passphrase') !== ''
            && preg_match('/^0x[a-fA-F0-9]{40}$/', (string) config('masternode.uptime.oracle.address')) === 1;
    }

    /** ที่อยู่ที่ตั้งไว้ใน .env (lowercase) — ตรงกับ keystore หรือไม่ สคริปต์ตรวจตอนเซ็น */
    public function expectedAddress(): ?string
    {
        $addr = strtolower((string) config('masternode.uptime.oracle.address'));

        return preg_match('/^0x[a-f0-9]{40}$/', $addr) ? $addr : null;
    }

    /**
     * ถอด keystore แล้วบอกที่อยู่ — ใช้ตอนตั้งค่า/ตรวจก่อนเจ้าของเรียก setUptimeOracle.
     *
     * @return array{ok:bool, address?:string, error?:string, code?:string}
     */
    public function address(): array
    {
        return $this->run(['TPIX_ACTION' => 'address'] + $this->keystoreEnv(), 'address');
    }

    /**
     * @return array{ok:bool, txHash?:string, raw?:string, nonce?:int, error?:string, code?:string}
     */
    public function sign(string $contract, string $calldata): array
    {
        return $this->run([
            'TPIX_ACTION' => 'sign',
            'TPIX_TO' => $contract,
            'TPIX_DATA' => $calldata,
        ] + $this->keystoreEnv(), 'sign');
    }

    /**
     * @return array{ok:bool, txHash?:string, alreadyKnown?:bool, error?:string, code?:string}
     */
    public function send(string $rawTx): array
    {
        return $this->run(['TPIX_ACTION' => 'send', 'TPIX_RAW_TX' => $rawTx], 'send');
    }

    private function keystoreEnv(): array
    {
        return [
            'TPIX_KEYSTORE_PATH' => (string) config('masternode.uptime.oracle.keystore_path'),
            'TPIX_KEYSTORE_PASS' => (string) config('masternode.uptime.oracle.passphrase'),
            'TPIX_EXPECT_ADDRESS' => (string) config('masternode.uptime.oracle.address'),
        ];
    }

    private function run(array $env, string $label): array
    {
        if (PHP_SAPI !== 'cli' || ! function_exists('proc_open')) {
            return ['ok' => false, 'code' => 'not_cli', 'error' => 'เซ็นได้เฉพาะฝั่ง CLI เท่านั้น'];
        }

        // .cjs เพราะ package.json ตั้ง "type": "module" — .js ใช้ require() ไม่ได้
        $script = base_path('scripts/blockchain/uptime-oracle.cjs');
        if (! is_readable($script)) {
            return ['ok' => false, 'code' => 'script_missing', 'error' => "ไม่พบสคริปต์ {$script}"];
        }

        $env += [
            'TPIX_RPC_URL' => (string) config('masternode.registry.rpc_url'),
            'TPIX_CHAIN_ID' => (string) config('masternode.registry.chain_id', 4289),
            'TPIX_USER_AGENT' => 'TPIX-UptimeOracle/1.0 (+https://tpix.online)',
        ];

        try {
            $result = Process::timeout(120)
                ->env($env)
                ->run(sprintf('%s %s', escapeshellarg((string) config('blockchain.node_path', 'node')), escapeshellarg($script)));
        } catch (\Throwable $e) {
            Log::error('uptime-oracle: เรียกสคริปต์เซ็นไม่สำเร็จ', ['step' => $label, 'error' => $e->getMessage()]);

            return ['ok' => false, 'code' => 'process_failed', 'error' => $e->getMessage()];
        }

        $decoded = json_decode(trim($result->output()), true);
        if (! is_array($decoded)) {
            // ห้าม log stdout ดิบ — log แค่ความยาวกับ exit code
            Log::error('uptime-oracle: สคริปต์คืนผลที่อ่านไม่ออก', [
                'step' => $label,
                'exit_code' => $result->exitCode(),
                'stdout_length' => strlen($result->output()),
                'stderr' => mb_substr(trim($result->errorOutput()), 0, 500),
            ]);

            return ['ok' => false, 'code' => 'bad_output', 'error' => 'สคริปต์เซ็นคืนผลที่อ่านไม่ออก'];
        }

        return $decoded;
    }
}
