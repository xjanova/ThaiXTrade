<?php

namespace App\Console\Commands;

use App\Models\BridgeDeposit;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\BridgeRelayer;
use App\Services\Bridge\BridgeScanner;
use App\Support\Wei;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * บันทึกว่าเงินฝากรายการนี้ถูกคืนด้วยมือแล้ว.
 *
 * เงินฝากของเชนที่ปิดอยู่ไม่ถูกสแกน หน้าเว็บบอกให้ขอคืนจาก vault — ถ้าไม่มีแถวบันทึก เปิดเชนทีหลังโดยตั้ง
 * start block ก่อนรายการนั้น ตัวสแกนจะเห็นเป็นเงินฝากใหม่แล้ว mint = จ่ายซ้ำ
 * แถว rejected ที่นี่ใช้ unique (เชน, tx, log) กันไว้ถาวร
 */
class BridgeRecordManualRefund extends Command
{
    protected $signature = 'bridge:record-manual-refund
                            {chain : chain id ของเงินฝาก}
                            {tx : tx hash ของเงินฝาก}
                            {refund_tx : tx hash ที่คืนเงิน}
                            {--log-index= : ระบุเมื่อธุรกรรมเดียวโอนเข้า vault หลายรายการ}';

    protected $description = 'บันทึกเงินฝากที่คืนด้วยมือแล้ว เพื่อไม่ให้ถูก mint ซ้ำเมื่อเปิดเชนทีหลัง';

    public function handle(BridgeConfig $config, BridgeScanner $scanner, BridgeRelayer $relayer): int
    {
        $chainId = (int) $this->argument('chain');
        $txHash = strtolower((string) $this->argument('tx'));
        $refundTx = strtolower((string) $this->argument('refund_tx'));
        $chain = $config->chain($chainId);
        $rpc = $config->rpc($chainId);

        if (! $chain || ! $rpc) {
            return $this->refuse("ไม่รู้จักเชน {$chainId}");
        }

        if (! preg_match('/^0x[0-9a-f]{64}$/', $txHash) || ! preg_match('/^0x[0-9a-f]{64}$/', $refundTx)) {
            return $this->refuse('รูปแบบ tx hash ไม่ถูกต้อง');
        }

        $token = strtolower((string) ($chain['usdt']['address'] ?? ''));
        $decimals = (int) ($chain['usdt']['decimals'] ?? 6);
        $receipt = $rpc->receiptAny($txHash)['receipt'];

        if ($receipt === null) {
            return $this->refuse('อ่านธุรกรรมจากเชนต้นทางไม่ได้ — ลองใหม่อีกครั้ง');
        }

        $logs = $this->vaultLogs($config, $receipt, $token);
        if ($this->option('log-index') !== null) {
            $logs = array_values(array_filter($logs, fn ($log) => (int) Wei::hexToInt((string) ($log['logIndex'] ?? '0x0')) === (int) $this->option('log-index')));
        }

        if (count($logs) !== 1) {
            return $this->refuse($logs === []
                ? 'ธุรกรรมนี้ไม่มีการโอน USDT เข้า vault'
                : 'ธุรกรรมนี้โอนเข้า vault หลายรายการ — ระบุ --log-index');
        }

        $log = $logs[0];
        $logIndex = (int) Wei::hexToInt((string) ($log['logIndex'] ?? '0x0'));
        $existing = fn () => BridgeDeposit::where('source_chain_id', $chainId)->where('tx_hash', $txHash)->where('log_index', $logIndex);

        if ($reason = $this->blocked($existing()->first())) {
            return $this->refuse($reason);
        }

        $processed = $relayer->processedOnChain($config->tpixRpc(), Abi::depositId($chainId, $txHash, $logIndex));
        if ($processed !== false) {
            return $this->refuse($processed === null ? 'อ่านสถานะจากเชน TPIX ไม่ได้ — ลองใหม่อีกครั้ง' : 'depositId นี้ถูก mint บนเชนแล้ว');
        }

        $this->line('เงินฝาก '.Wei::format(Wei::hexToInt((string) ($log['data'] ?? '0x0')), $decimals)." USDT · tx {$txHash} · log {$logIndex}");
        $this->line("คืนด้วย tx {$refundTx}");

        if (! $this->confirm('บันทึกว่าคืนเงินแล้ว รายการนี้จะไม่ถูก mint อีกเลย ยืนยัน?')) {
            $this->warn('ยกเลิก ไม่ได้บันทึกอะไร');

            return self::FAILURE;
        }

        // ธุรกรรมเดียวกัน — แถวใหม่ต้องไม่โผล่ให้ relayer เห็นเป็น confirmed ก่อนถูกปิด
        $failure = DB::transaction(function () use ($scanner, $chainId, $token, $decimals, $rpc, $log, $existing, $refundTx) {
            if ($scanner->recordDeposit($chainId, $token, $decimals, $rpc, $log) === null) {
                return 'อ่านข้อมูลผู้ส่งจากเชนต้นทางไม่ได้ — ลองใหม่อีกครั้ง';
            }

            $row = $existing()->lockForUpdate()->first();
            if (! $row) {
                return 'ยอดนี้เล็กเกินกว่าที่ระบบบันทึก ไม่มีอะไรให้ mint';
            }

            if ($reason = $this->blocked($row)) {
                return $reason;
            }

            $row->update([
                'status' => BridgeDeposit::STATUS_REJECTED,
                'review_reason' => 'manual_refund',
                'reviewed_at' => now(),
                'last_error' => null,
                'review_note' => trim(($row->review_note ? $row->review_note."\n" : '')
                    .now()->toDateTimeString()." คืนเงินด้วยมือแล้ว tx {$refundTx} (bridge:record-manual-refund)"),
            ]);

            return null;
        });

        if ($failure !== null) {
            return $this->refuse($failure);
        }

        $this->info('บันทึกแล้ว รายการนี้จะไม่ถูก mint');

        return self::SUCCESS;
    }

    /** @return array<int, array> Transfer ของ USDT ที่เข้า vault */
    private function vaultLogs(BridgeConfig $config, array $receipt, string $token): array
    {
        return array_values(array_filter((array) ($receipt['logs'] ?? []), function ($log) use ($config, $token) {
            $topics = array_map('strtolower', (array) ($log['topics'] ?? []));

            return strtolower((string) ($log['address'] ?? '')) === $token
                && ($topics[0] ?? null) === Abi::topic(Abi::TRANSFER_EVENT)
                && Abi::topicAddress($topics[2] ?? null) === $config->vaultAddress();
        }));
    }

    private function blocked(?BridgeDeposit $row): ?string
    {
        return match (true) {
            $row === null => null,
            $row->status === BridgeDeposit::STATUS_MINTED => 'รายการนี้ mint สำเร็จแล้ว',
            $row->status === BridgeDeposit::STATUS_REJECTED => 'รายการนี้ปิดไปแล้ว',
            $row->mint_tx_hash !== null => "รายการนี้มีธุรกรรม mint ค้างอยู่ ({$row->mint_tx_hash})",
            default => null,
        };
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
