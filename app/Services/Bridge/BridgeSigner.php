<?php

namespace App\Services\Bridge;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * BridgeSigner — เซ็นและส่งธุรกรรมของสะพานผ่าน ethers.js (scripts/blockchain/bridge-signer.cjs).
 *
 * แบบเดียวกับ HotWalletSigner ของชั้นคลัง:
 *   - คีย์อยู่ในไฟล์ keystore V3 นอก public_html · passphrase อยู่ใน .env · PHP ไม่เคยถอดรหัสเอง
 *   - ความลับส่งเข้าโปรเซสลูกผ่าน env ไม่ใช่ argv (argv โผล่ใน `ps aux`)
 *   - ใช้ได้เฉพาะ CLI — php-fpm บนเซิร์ฟเวอร์นี้ปิด proc_open
 *   - แยก "เซ็น" กับ "ส่ง" เพื่อให้ผู้เรียกบันทึก tx hash + raw ลงฐานข้อมูล **ก่อน** ส่งเสมอ
 *     (เครื่องดับหลังส่งแต่ก่อนบันทึก = รอบหน้าไม่รู้ว่าเคยส่ง แล้วเซ็นใหม่ด้วย nonce ใหม่ = จ่ายซ้ำ)
 *
 * บทบาท: relayer (mint บนเชน 4289) · payout (จ่าย USDT บนเชนปลายทาง) · guardian (pause minter)
 */
class BridgeSigner
{
    public function __construct(private BridgeConfig $config) {}

    public function isAvailable(): bool
    {
        return PHP_SAPI === 'cli' && function_exists('proc_open');
    }

    /**
     * เซ็นการเรียกสัญญา — ยังไม่ส่งขึ้นเชน.
     *
     * @param  array{gas_price_wei?: string, gas_multiplier_bps?: int, max_gas_price_wei?: string|null, gas_limit?: string|null, nonce?: int|null}  $options
     * @return array{ok: bool, address?: string, nonce?: int, txHash?: string, raw?: string, gasLimit?: string, gasPrice?: string, error?: string, code?: string}
     */
    public function sign(string $role, EvmRpc $rpc, string $to, string $data, array $options = []): array
    {
        return $this->signWithKey([
            'label' => $role,
            'keystore' => $this->config->keystorePath($role),
            'passphrase' => config("bridge.signers.{$role}.passphrase"),
            'address' => $this->config->signerAddress($role),
        ], $rpc, $to, $data, $options);
    }

    /**
     * เซ็นด้วยกุญแจที่ผู้เรียกระบุเอง — สะพาน wTPIX ใช้ keystore ชุดของตัวเอง (WTPIX_BRIDGE_*)
     * แต่ใช้สคริปต์และวินัย "เซ็นก่อน บันทึก แล้วค่อยส่ง" ชุดเดียวกับสะพาน USDT.
     *
     * @param  array{label: string, keystore: string, passphrase: ?string, address: ?string}  $key
     */
    public function signWithKey(array $key, EvmRpc $rpc, string $to, string $data, array $options = []): array
    {
        $role = (string) ($key['label'] ?? 'signer');
        $expect = $key['address'] ?? null;
        $passphrase = (string) ($key['passphrase'] ?? '');

        if ($expect === null || $passphrase === '' || trim((string) ($key['keystore'] ?? '')) === '') {
            return ['ok' => false, 'code' => 'signer_not_configured', 'error' => "ยังไม่ได้ตั้ง keystore/passphrase ของ {$role}"];
        }

        return $this->runNode([
            'BRIDGE_ACTION' => 'sign',
            'BRIDGE_KEYSTORE_PATH' => (string) $key['keystore'],
            'BRIDGE_KEYSTORE_PASS' => $passphrase,
            'BRIDGE_EXPECT_ADDRESS' => $expect,
            'BRIDGE_TO' => $to,
            'BRIDGE_DATA' => $data,
            'BRIDGE_NONCE' => isset($options['nonce']) ? (string) $options['nonce'] : '',
            'BRIDGE_GAS_PRICE_WEI' => (string) ($options['gas_price_wei'] ?? ''),
            'BRIDGE_GAS_MULTIPLIER_BPS' => (string) ($options['gas_multiplier_bps'] ?? 10000),
            'BRIDGE_MAX_GAS_PRICE_WEI' => (string) ($options['max_gas_price_wei'] ?? ''),
            'BRIDGE_GAS_LIMIT' => (string) ($options['gas_limit'] ?? ''),
        ], $rpc, "sign:{$role}");
    }

    /**
     * ส่ง raw ที่เซ็นไว้แล้วขึ้นเชน (ส่งซ้ำได้ปลอดภัย — nonce เดิม เชนรับได้ครั้งเดียว).
     *
     * @return array{ok: bool, txHash?: string, alreadyKnown?: bool, error?: string, code?: string}
     */
    public function send(EvmRpc $rpc, string $rawTx): array
    {
        return $this->runNode([
            'BRIDGE_ACTION' => 'send',
            'BRIDGE_RAW_TX' => $rawTx,
        ], $rpc, 'send');
    }

    private function runNode(array $env, EvmRpc $rpc, string $label): array
    {
        if (! $this->isAvailable()) {
            return ['ok' => false, 'code' => 'not_cli', 'error' => 'เซ็นธุรกรรมได้เฉพาะฝั่ง CLI เท่านั้น (php-fpm ปิด proc_open)'];
        }

        // .cjs เพราะ package.json ตั้ง "type": "module" — .js จะถูกมองเป็น ES module แล้ว require() ใช้ไม่ได้
        $script = base_path('scripts/blockchain/bridge-signer.cjs');

        if (! is_readable($script)) {
            return ['ok' => false, 'code' => 'script_missing', 'error' => 'ไม่พบสคริปต์เซ็นของสะพาน'];
        }

        $env += [
            'BRIDGE_RPC_URLS' => implode(',', $rpc->urls()),
            'BRIDGE_CHAIN_ID' => (string) $rpc->chainId,
            'BRIDGE_USER_AGENT' => $this->config->userAgent(),
        ];

        $nodePath = (string) config('blockchain.node_path', 'node');

        try {
            $result = Process::timeout(120)
                ->env($env)
                ->run(sprintf('%s %s', escapeshellarg($nodePath), escapeshellarg($script)));
        } catch (\Throwable $e) {
            Log::error('bridge: เรียกสคริปต์เซ็นไม่สำเร็จ', ['step' => $label, 'error' => mb_substr($e->getMessage(), 0, 200)]);

            return ['ok' => false, 'code' => 'process_failed', 'error' => 'เรียกสคริปต์เซ็นไม่สำเร็จ'];
        }

        $stdout = trim($result->output());
        $decoded = json_decode($stdout, true);

        if (! is_array($decoded)) {
            // ห้าม log stdout ดิบ เผื่อมีอะไรหลุดมา — log แค่ความยาวกับ exit code
            Log::error('bridge: สคริปต์เซ็นคืนผลที่อ่านไม่ออก', [
                'step' => $label,
                'exit_code' => $result->exitCode(),
                'stdout_length' => strlen($stdout),
                'stderr' => mb_substr(trim($result->errorOutput()), 0, 300),
            ]);

            return ['ok' => false, 'code' => 'bad_output', 'error' => 'สคริปต์เซ็นคืนผลที่อ่านไม่ออก'];
        }

        if (! ($decoded['ok'] ?? false)) {
            Log::warning('bridge: สคริปต์เซ็นรายงานความล้มเหลว', [
                'step' => $label,
                'chain' => $rpc->chainId,
                'code' => $decoded['code'] ?? null,
                'error' => mb_substr((string) ($decoded['error'] ?? ''), 0, 300),
            ]);
        }

        return $decoded;
    }
}
