<?php

namespace App\Services\AiBot\Wallet;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * TPIX TRADE — เซ็น/ส่งธุรกรรมของกระเป๋าบอทผ่าน ethers.js (สคริปต์ bot-wallet-transfer.cjs).
 *
 * แบบเดียวกับ HotWalletSigner: ใช้ได้เฉพาะ CLI, ความลับส่งผ่าน env ไม่ใช่ argv,
 * แยกขั้น "เซ็น" กับ "ส่ง" เพื่อให้ผู้เรียกบันทึก tx hash ก่อนส่งจริงเสมอ
 *
 * ต่างตรงที่กุญแจไม่ได้อยู่ในไฟล์ keystore แต่ถูกถอดจากฐานข้อมูลโดย BotWalletKeyring
 * แล้วส่งเข้ามาเป็น hex — จึงต้องไม่ log อาร์กิวเมนต์ของเมธอดนี้ไม่ว่ากรณีใด
 *
 * Developed by Xman Studio.
 */
class BotWalletSigner
{
    /**
     * @return array{ok: bool, address?: string, nonce?: int, txHash?: string, raw?: string, error?: string, code?: string}
     */
    public function sign(
        string $privateKeyHex,
        string $expectAddress,
        string $to,
        string $valueWei,
        ?string $tokenAddress = null,
        ?int $nonce = null,
    ): array {
        return $this->runNode([
            'BW_ACTION' => 'sign',
            'BW_PRIVATE_KEY' => $privateKeyHex,
            'BW_EXPECT_ADDRESS' => $expectAddress,
            'BW_TO' => $to,
            'BW_VALUE_WEI' => $valueWei,
            'BW_TOKEN' => (string) ($tokenAddress ?? ''),
            'BW_NONCE' => $nonce === null ? '' : (string) $nonce,
        ], 'sign');
    }

    /**
     * @return array{ok: bool, txHash?: string, alreadyKnown?: bool, error?: string, code?: string}
     */
    public function send(string $rawTx): array
    {
        return $this->runNode(['BW_ACTION' => 'send', 'BW_RAW_TX' => $rawTx], 'send');
    }

    public function isAvailable(): bool
    {
        return PHP_SAPI === 'cli' && function_exists('proc_open');
    }

    /** receipt ของธุรกรรม — null = ยังไม่เข้าบล็อกหรืออ่านไม่ได้ */
    public function receipt(string $txHash): ?array
    {
        $result = $this->rpc('eth_getTransactionReceipt', [$txHash]);

        return is_array($result) ? $result : null;
    }

    public function blockNumber(): ?int
    {
        $result = $this->rpc('eth_blockNumber', []);

        return is_string($result) && str_starts_with($result, '0x') ? (int) hexdec($result) : null;
    }

    public function chainId(): int
    {
        return (int) config('aibot.bot_wallet.chain_id', 56);
    }

    public function rpcUrl(): string
    {
        $rpc = config('chains.chains.'.$this->chainId().'.rpc', []);

        return (string) (is_array($rpc) ? ($rpc[0] ?? '') : $rpc);
    }

    private function rpc(string $method, array $params): mixed
    {
        $url = $this->rpcUrl();

        if ($url === '') {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => (string) config('aibot.bot_wallet.rpc_user_agent')])
                ->post($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);

            $data = $response->json();

            if (! $response->successful() || isset($data['error'])) {
                return null;
            }

            return $data['result'] ?? null;
        } catch (\Throwable $e) {
            Log::warning('bot-wallet: rpc ล้มเหลว', ['method' => $method, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function runNode(array $env, string $label): array
    {
        if (! $this->isAvailable()) {
            return ['ok' => false, 'code' => 'not_cli', 'error' => 'เซ็นธุรกรรมได้เฉพาะฝั่ง CLI เท่านั้น'];
        }

        $script = base_path('scripts/blockchain/bot-wallet-transfer.cjs');

        if (! is_readable($script)) {
            return ['ok' => false, 'code' => 'script_missing', 'error' => 'ไม่พบสคริปต์เซ็นของกระเป๋าบอท'];
        }

        $env += [
            'BW_RPC_URL' => $this->rpcUrl(),
            'BW_CHAIN_ID' => (string) $this->chainId(),
            'BW_USER_AGENT' => (string) config('aibot.bot_wallet.rpc_user_agent'),
        ];

        $nodePath = (string) config('blockchain.node_path', 'node');

        try {
            $result = Process::timeout(120)
                ->env($env)
                ->run(sprintf('%s %s', escapeshellarg($nodePath), escapeshellarg($script)));
        } catch (\Throwable $e) {
            Log::error('bot-wallet: เรียกสคริปต์เซ็นไม่สำเร็จ', ['step' => $label, 'error' => $e->getMessage()]);

            return ['ok' => false, 'code' => 'process_failed', 'error' => $e->getMessage()];
        }

        $decoded = json_decode(trim($result->output()), true);

        if (! is_array($decoded)) {
            // ห้าม log stdout ดิบ — log แค่ขนาดกับ exit code
            Log::error('bot-wallet: สคริปต์เซ็นคืนผลที่อ่านไม่ออก', [
                'step' => $label,
                'exit_code' => $result->exitCode(),
                'stdout_length' => strlen(trim($result->output())),
                'stderr' => mb_substr(trim($result->errorOutput()), 0, 300),
            ]);

            return ['ok' => false, 'code' => 'bad_output', 'error' => 'สคริปต์เซ็นคืนผลที่อ่านไม่ออก'];
        }

        if (! ($decoded['ok'] ?? false)) {
            Log::warning('bot-wallet: สคริปต์เซ็นรายงานความล้มเหลว', [
                'step' => $label,
                'code' => $decoded['code'] ?? null,
                'error' => $decoded['error'] ?? null,
            ]);
        }

        return $decoded;
    }
}
