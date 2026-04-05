<?php

namespace App\Services;

use App\Models\BridgeTransaction;
use App\Models\SiteSetting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * TPIX TRADE — Bridge Service (Production)
 * จัดการ cross-chain bridge: TPIX Chain (4289) ↔ BSC (56)
 *
 * Flow:
 * BSC → TPIX: User burn wTPIX on BSC → backend verify → send native TPIX from treasury
 * TPIX → BSC: User send TPIX to treasury → backend verify → mint wTPIX on BSC
 *
 * ไม่ต้องเขียน smart contract ใหม่ — ใช้ wTPIX.burn() + wTPIX.mint() ที่มีอยู่
 * Backend เป็น relay: verify source tx → execute target transfer
 *
 * Developed by Xman Studio
 */
class BridgeService
{
    private const FEE_PERCENT = 0.001; // 0.1%

    private const MIN_FEE = 1; // 1 TPIX minimum

    private const MIN_AMOUNT = 10; // 10 TPIX minimum bridge

    private const MAX_AMOUNT = 10000000; // 10M max per tx

    // ERC20 Transfer event topic (for detecting burn = Transfer to 0x0)
    private const TRANSFER_EVENT_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    private const ZERO_ADDRESS_PADDED = '0x0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * เช็คว่า admin เปิดใช้ bridge หรือไม่.
     */
    public function isEnabled(): bool
    {
        $val = SiteSetting::get('trading', 'bridge_enabled');

        return $val === null || $val === true || $val === '1' || $val === 'true';
    }

    /**
     * ข้อมูล bridge สำหรับ frontend.
     */
    public function getInfo(): array
    {
        return [
            'fee_percent' => self::FEE_PERCENT * 100,
            'min_fee' => self::MIN_FEE,
            'min_amount' => self::MIN_AMOUNT,
            'max_amount' => self::MAX_AMOUNT,
            'treasury_address' => config('services.bridge.treasury_address', ''),
            'wtpix_bsc_address' => config('services.bridge.wtpix_bsc_address', ''),
            'chains' => [
                ['id' => 56, 'name' => 'BSC', 'symbol' => 'wTPIX (BEP-20)'],
                ['id' => 4289, 'name' => 'TPIX Chain', 'symbol' => 'TPIX (Native)'],
            ],
            'estimated_time' => '2-5 minutes',
            'enabled' => $this->isEnabled(),
        ];
    }

    /**
     * คำนวณ fee (bcmath precision).
     */
    public function calculateFee(string $amount): string
    {
        $fee = bcmul($amount, (string) self::FEE_PERCENT, 18);

        return bccomp($fee, (string) self::MIN_FEE, 18) < 0 ? (string) self::MIN_FEE : $fee;
    }

    /**
     * เริ่ม bridge transaction + validate.
     */
    public function initiateBridge(string $wallet, string $amount, string $direction, ?string $txHash = null): BridgeTransaction
    {
        if (bccomp($amount, (string) self::MIN_AMOUNT, 18) < 0) {
            throw new \InvalidArgumentException('Minimum bridge amount is '.self::MIN_AMOUNT.' TPIX');
        }

        if (bccomp($amount, (string) self::MAX_AMOUNT, 18) > 0) {
            throw new \InvalidArgumentException('Maximum bridge amount is '.number_format(self::MAX_AMOUNT).' TPIX');
        }

        // ป้องกัน duplicate tx_hash
        if ($txHash && BridgeTransaction::where('source_tx_hash', $txHash)->exists()) {
            throw new \InvalidArgumentException('This transaction has already been submitted');
        }

        $fee = $this->calculateFee($amount);
        $chains = $direction === 'bsc_to_tpix'
            ? ['source' => 56, 'target' => 4289]
            : ['source' => 4289, 'target' => 56];

        return BridgeTransaction::create([
            'wallet_address' => strtolower($wallet),
            'direction' => $direction,
            'amount' => $amount,
            'fee' => $fee,
            'source_chain_id' => $chains['source'],
            'target_chain_id' => $chains['target'],
            'source_tx_hash' => $txHash,
            'status' => $txHash ? 'processing' : 'pending',
        ]);
    }

    // =========================================================================
    // ON-CHAIN VERIFICATION (ตรวจสอบ tx จริงบน blockchain)
    // =========================================================================

    /**
     * Verify source transaction on-chain.
     *
     * BSC→TPIX: ตรวจว่า user burn wTPIX จริง (Transfer event ไปที่ 0x0)
     * TPIX→BSC: ตรวจว่า user ส่ง native TPIX ไปที่ treasury จริง
     *
     * Pattern จาก TokenSaleService::verifyBscTransaction()
     */
    public function verifySourceTransaction(BridgeTransaction $tx): array
    {
        $result = ['verified' => false, 'reason' => 'not_checked'];

        if (! $tx->source_tx_hash) {
            $result['reason'] = 'no_tx_hash';

            return $result;
        }

        $rpcUrl = $this->getRpcUrl($tx->source_chain_id);
        if (! $rpcUrl) {
            $result['reason'] = 'no_rpc_configured';

            return $result;
        }

        try {
            // Step 1: eth_getTransactionReceipt — ตรวจว่า tx สำเร็จ
            $receiptResponse = Http::timeout(15)->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'eth_getTransactionReceipt',
                'params' => [$tx->source_tx_hash],
            ]);

            if (! $receiptResponse->successful()) {
                $result['reason'] = 'rpc_request_failed';

                return $result;
            }

            $receiptData = $receiptResponse->json();
            if (isset($receiptData['error'])) {
                $result['reason'] = 'rpc_error: '.($receiptData['error']['message'] ?? 'unknown');

                return $result;
            }

            $receipt = $receiptData['result'] ?? null;
            if (! $receipt) {
                $result['reason'] = 'tx_not_found_or_pending';

                return $result;
            }

            if (($receipt['status'] ?? '') !== '0x1') {
                $result['reason'] = 'tx_reverted';

                return $result;
            }

            // Step 2: eth_getTransactionByHash — ดึง from/to/value
            $txResponse = Http::timeout(15)->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'eth_getTransactionByHash',
                'params' => [$tx->source_tx_hash],
            ]);

            $txDetail = $txResponse->json('result');
            if (! $txDetail) {
                $result['reason'] = 'tx_detail_not_found';

                return $result;
            }

            $from = strtolower($txDetail['from'] ?? '');
            $result['from'] = $from;

            // ตรวจว่า sender = wallet ที่อ้าง
            if ($from !== strtolower($tx->wallet_address)) {
                $result['reason'] = 'from_address_mismatch';

                return $result;
            }

            // Verify ตาม direction
            if ($tx->direction === 'bsc_to_tpix') {
                return $this->verifyBscBurn($tx, $receipt, $result);
            } else {
                return $this->verifyTpixTransfer($tx, $txDetail, $result);
            }
        } catch (\Exception $e) {
            Log::warning('Bridge verification failed', [
                'bridge_id' => $tx->id,
                'error' => $e->getMessage(),
            ]);
            $result['reason'] = 'verification_exception';

            return $result;
        }
    }

    /**
     * BSC→TPIX: ตรวจว่า user burn wTPIX จริง
     * ดู Transfer event ที่ to = 0x0 (burn)
     */
    private function verifyBscBurn(BridgeTransaction $tx, array $receipt, array $result): array
    {
        $wtpixAddress = strtolower(config('services.bridge.wtpix_bsc_address', ''));
        if (empty($wtpixAddress)) {
            $result['reason'] = 'wtpix_address_not_configured';

            return $result;
        }

        // หา Transfer event log ที่ to = 0x0 (burn) จาก wTPIX contract
        foreach ($receipt['logs'] ?? [] as $log) {
            if (strtolower($log['address'] ?? '') !== $wtpixAddress) {
                continue;
            }

            $topics = $log['topics'] ?? [];
            if (count($topics) < 3 || $topics[0] !== self::TRANSFER_EVENT_TOPIC) {
                continue;
            }

            // Transfer(from, to, amount) — to = 0x0 = burn
            if ($topics[2] !== self::ZERO_ADDRESS_PADDED) {
                continue;
            }

            // from = padded user address
            $logFrom = '0x'.substr($topics[1], -40);
            if (strtolower($logFrom) !== strtolower($tx->wallet_address)) {
                continue;
            }

            // amount จาก data field
            $burnAmountHex = $log['data'] ?? '0x0';
            $burnAmountWei = gmp_strval(gmp_init($burnAmountHex, 16));
            $burnAmount = bcdiv($burnAmountWei, '1000000000000000000', 18);

            // ตรวจว่า burn amount ตรงกับที่อ้าง (tolerance 1%)
            $expectedAmount = (string) $tx->amount;
            $diff = bcsub($burnAmount, $expectedAmount, 18);
            if (bccomp($diff, '0', 18) < 0) {
                $diff = bcsub($expectedAmount, $burnAmount, 18);
            }
            $tolerance = bcmul($expectedAmount, '0.01', 18);

            if (bccomp($diff, $tolerance, 18) > 0) {
                $result['reason'] = 'burn_amount_mismatch';
                $result['expected'] = $expectedAmount;
                $result['actual'] = $burnAmount;

                return $result;
            }

            $result['verified'] = true;
            $result['reason'] = 'burn_verified';
            $result['burn_amount'] = $burnAmount;

            return $result;
        }

        $result['reason'] = 'burn_event_not_found';

        return $result;
    }

    /**
     * TPIX→BSC: ตรวจว่า user ส่ง native TPIX ไปที่ treasury จริง
     */
    private function verifyTpixTransfer(BridgeTransaction $tx, array $txDetail, array $result): array
    {
        $treasuryAddress = strtolower(config('services.bridge.treasury_address', ''));
        if (empty($treasuryAddress)) {
            $result['reason'] = 'treasury_address_not_configured';

            return $result;
        }

        // ตรวจว่า to = treasury
        $to = strtolower($txDetail['to'] ?? '');
        if ($to !== $treasuryAddress) {
            $result['reason'] = 'recipient_not_treasury';
            $result['expected'] = $treasuryAddress;
            $result['actual'] = $to;

            return $result;
        }

        // ตรวจ value
        $valueWei = gmp_strval(gmp_init($txDetail['value'] ?? '0x0', 16));
        $value = bcdiv($valueWei, '1000000000000000000', 18);

        $expectedAmount = (string) $tx->amount;
        $diff = bcsub($value, $expectedAmount, 18);
        if (bccomp($diff, '0', 18) < 0) {
            $diff = bcsub($expectedAmount, $value, 18);
        }
        $tolerance = bcmul($expectedAmount, '0.01', 18);

        if (bccomp($diff, $tolerance, 18) > 0) {
            $result['reason'] = 'transfer_amount_mismatch';
            $result['expected'] = $expectedAmount;
            $result['actual'] = $value;

            return $result;
        }

        $result['verified'] = true;
        $result['reason'] = 'transfer_verified';
        $result['transfer_amount'] = $value;

        return $result;
    }

    // =========================================================================
    // TARGET CHAIN EXECUTION (ส่งเหรียญฝั่งปลายทาง)
    // =========================================================================

    /**
     * Execute target transfer.
     *
     * BSC→TPIX: ส่ง native TPIX จาก treasury → user (TPIX Chain)
     * TPIX→BSC: mint wTPIX ให้ user (BSC)
     *
     * เรียก Node.js script bridge-transfer.js ผ่าน Process::run()
     * Pattern จาก Web3DeploymentService::deployToken()
     */
    public function executeTargetTransfer(BridgeTransaction $tx): array
    {
        $receiveAmount = bcsub((string) $tx->amount, (string) $tx->fee, 18);

        // เลือก action ตาม direction
        $action = $tx->direction === 'bsc_to_tpix' ? 'send_native_tpix' : 'mint_wtpix';

        $params = json_encode([
            'action' => $action,
            'to' => $tx->wallet_address,
            'amount' => $receiveAmount,
        ]);

        $scriptPath = base_path('scripts/blockchain/bridge-transfer.js');

        $env = [
            'BRIDGE_SIGNER_PRIVATE_KEY' => config('services.bridge.signer_private_key'),
            'TPIX_RPC_URL' => config('chains.chains.4289.rpc.0', 'https://rpc.tpix.online'),
            'BSC_RPC_URL' => config('chains.chains.56.rpc.0', 'https://bsc-dataseed.binance.org'),
            'WTPIX_BSC_ADDRESS' => config('services.bridge.wtpix_bsc_address'),
        ];

        $command = "node {$scriptPath} ".escapeshellarg($params);

        $process = Process::timeout(120)->env($env)->run($command);

        if (! $process->successful()) {
            $stderr = $process->errorOutput();
            Log::error('Bridge transfer script failed', [
                'bridge_id' => $tx->id,
                'stderr' => $stderr,
            ]);

            // ลอง parse JSON error จาก stdout
            $output = json_decode($process->output(), true);

            return [
                'success' => false,
                'error' => $output['error'] ?? $stderr ?: 'Script execution failed',
            ];
        }

        $output = json_decode($process->output(), true);
        if (! $output || ! ($output['success'] ?? false)) {
            return [
                'success' => false,
                'error' => $output['error'] ?? 'Invalid script output',
            ];
        }

        return $output;
    }

    // =========================================================================
    // ORCHESTRATION (ดำเนินการ bridge ทั้งหมด)
    // =========================================================================

    /**
     * Process bridge transaction: verify → execute → complete.
     * เรียกจาก ProcessBridgeJob
     */
    public function processBridgeTransaction(BridgeTransaction $tx): void
    {
        // Guard: ต้องมี tx_hash และ status ต้องเป็น processing
        if (! $tx->source_tx_hash || ! in_array($tx->status, ['processing', 'pending'])) {
            return;
        }

        $tx->update(['status' => 'processing', 'retry_count' => $tx->retry_count + 1]);

        // Step 1: Verify source transaction on-chain
        $verification = $this->verifySourceTransaction($tx);

        if (! $verification['verified']) {
            Log::warning('Bridge source verification failed', [
                'bridge_id' => $tx->id,
                'reason' => $verification['reason'],
            ]);

            // ถ้า tx ยังไม่ confirmed → ปล่อยให้ retry
            if (in_array($verification['reason'], ['tx_not_found_or_pending', 'rpc_request_failed', 'verification_exception'])) {
                return; // Job จะ retry ตาม backoff
            }

            // ถ้า verify fail ถาวร → mark failed
            $tx->update([
                'status' => 'failed',
                'error_message' => 'Verification failed: '.$verification['reason'],
            ]);

            return;
        }

        $tx->update(['verified_at' => now()]);

        // Step 2: Execute target transfer
        $execution = $this->executeTargetTransfer($tx);

        if (! ($execution['success'] ?? false)) {
            Log::error('Bridge target execution failed', [
                'bridge_id' => $tx->id,
                'error' => $execution['error'] ?? 'unknown',
            ]);

            // ถ้า execution fail → ปล่อยให้ retry (อาจเป็นปัญหา RPC ชั่วคราว)
            $tx->update(['error_message' => $execution['error'] ?? 'Execution failed']);

            throw new \RuntimeException('Bridge execution failed: '.($execution['error'] ?? 'unknown'));
        }

        // Step 3: Mark completed
        $tx->update([
            'status' => 'completed',
            'target_tx_hash' => $execution['tx_hash'] ?? null,
            'completed_at' => now(),
            'error_message' => null,
        ]);

        Log::info('Bridge completed', [
            'bridge_id' => $tx->id,
            'direction' => $tx->direction,
            'amount' => $tx->amount,
            'target_tx' => $execution['tx_hash'] ?? null,
        ]);

        Cache::forget('bridge:stats');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function getRpcUrl(int $chainId): ?string
    {
        $chain = config("chains.chains.{$chainId}");
        if (! $chain || empty($chain['rpc'])) {
            return null;
        }

        return $chain['rpc'][0];
    }

    public function getHistory(string $wallet, int $limit = 20): Collection
    {
        return BridgeTransaction::byWallet($wallet)->latest()->limit($limit)->get();
    }

    public function getStats(): array
    {
        return Cache::remember('bridge:stats', 60, function () {
            return [
                'total_transactions' => BridgeTransaction::count(),
                'completed' => BridgeTransaction::completed()->count(),
                'pending' => BridgeTransaction::pending()->count(),
                'total_volume' => (float) BridgeTransaction::completed()->sum('amount'),
                'total_fees' => (float) BridgeTransaction::completed()->sum('fee'),
                'bsc_to_tpix' => BridgeTransaction::completed()->bscToTpix()->count(),
                'tpix_to_bsc' => BridgeTransaction::completed()->tpixToBsc()->count(),
            ];
        });
    }
}
