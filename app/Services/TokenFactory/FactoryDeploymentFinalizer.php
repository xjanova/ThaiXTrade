<?php

namespace App\Services\TokenFactory;

use App\Jobs\CheckFactoryDeployReceiptJob;
use App\Jobs\VerifyFactoryTokenOnExplorerJob;
use App\Models\FactoryToken;
use Illuminate\Support\Facades\Log;

/**
 * ปิดงาน deploy ตามผลที่สคริปต์รายงาน — ใช้ร่วมกันระหว่าง DeployTokenJob กับงานตรวจผล.
 *
 * กติกาที่ห้ามพัง:
 *   - ทุกการเขียนมีเงื่อนไข WHERE status = 'deploying' — แถวที่ deployed/rejected แล้วไม่มีวันถูกเขียนทับ
 *   - แถวที่มี tx_hash แล้ว ห้าม mark failed จนกว่าจะ "รู้แน่" ว่าธุรกรรมนั้นไม่มีวันขึ้นเชน
 *     (revert แล้ว หรือ nonce ถูกใช้ไปแล้ว) — ไม่แน่ใจ = ส่งให้งานตรวจผลวนถามต่อ
 *   - is_verified ไม่ถูกตั้งที่นี่เด็ดขาด — "Verified" เป็นการตัดสินของแอดมิน
 *
 * Developed by Xman Studio
 */
class FactoryDeploymentFinalizer
{
    public function __construct(private readonly TokenFactoryPolicy $policy) {}

    /**
     * @param  string|null  $expectedTx  ธุรกรรมที่ผลนี้เป็นของมัน (null = ยังไม่ได้เซ็นอะไร)
     *                                   ผลของธุรกรรมเก่าห้ามไปปิดงานของธุรกรรมใหม่ — เช่นงานตรวจผล T1
     *                                   ที่รายงาน "dropped" ช้ากว่าที่แอดมิน retry ไปเป็น T2 แล้ว
     * @return string ผลที่เกิด: deployed | failed | pending | ignored
     */
    public function apply(int $tokenId, array $result, string $source, ?string $expectedTx = null): string
    {
        $expectedTx = $expectedTx !== null ? strtolower($expectedTx) : null;

        $token = FactoryToken::find($tokenId);
        if (! $token || $token->status !== 'deploying') {
            return 'ignored';
        }

        // callback บันทึก hash ล้มเหลวระหว่างรัน — บันทึกซ้ำจากผลที่ได้ ก่อนตัดสินอะไรต่อ
        if (! $token->tx_hash && $expectedTx !== null) {
            $signed = is_array($result['signed'] ?? null) ? $result['signed'] : [];
            $this->recordSigned($tokenId, $expectedTx, $signed['nonce'] ?? null, $signed['rawTx'] ?? null);
            $token->refresh();
        }

        $rowTx = $token->tx_hash ? strtolower($token->tx_hash) : null;
        if ($rowTx !== $expectedTx) {
            Log::warning('ผลการ deploy ไม่ตรงกับธุรกรรมปัจจุบันของแถว — ไม่นำมาใช้', [
                'token_id' => $tokenId,
                'source' => $source,
                'result_tx' => $expectedTx,
                'row_tx' => $rowTx,
            ]);

            return 'ignored';
        }

        if (($result['success'] ?? false) === true && ! empty($result['contractAddress'])) {
            return $this->markDeployed($token, $result, $source, $expectedTx) ? 'deployed' : 'ignored';
        }

        // รู้แน่ว่าธุรกรรมนั้นไม่มีวันสร้างเหรียญ → ล้มเหลว ส่งใหม่ได้อย่างปลอดภัย
        if ($expectedTx !== null && (($result['reverted'] ?? false) || ($result['dropped'] ?? false))) {
            $this->markFailed($tokenId, (string) ($result['error'] ?? 'ธุรกรรมไม่สำเร็จ'), clearTx: true, expectedTx: $expectedTx);

            return 'failed';
        }

        // มีธุรกรรมแล้วแต่ยังไม่รู้ผล → ห้ามล้มเหลว ห้ามส่งใหม่ — ให้งานตรวจผลวนถาม
        if ($expectedTx !== null) {
            $this->note($tokenId, ['deploy_pending_reason' => $result['error'] ?? 'รอผลธุรกรรม'], $expectedTx);

            return 'pending';
        }

        // ยังไม่ได้เซ็นอะไรเลย (ล้มก่อนส่ง) → ล้มเหลว แอดมินกด retry ได้
        $this->markFailed($tokenId, (string) ($result['error'] ?? 'deploy ไม่สำเร็จ'), clearTx: false);

        return 'failed';
    }

    /** บันทึกธุรกรรมที่เซ็นแล้ว — ครั้งเดียวต่อแถว (WHERE tx_hash IS NULL) */
    public function recordSigned(int $tokenId, string $txHash, mixed $nonce, ?string $rawTx): bool
    {
        return FactoryToken::whereKey($tokenId)
            ->where('status', 'deploying')
            ->whereNull('tx_hash')
            ->update([
                'tx_hash' => strtolower($txHash),
                'deploy_nonce' => is_numeric($nonce) ? (int) $nonce : null,
                'deploy_raw_tx' => $rawTx,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * @param  bool  $clearTx  true = ธุรกรรม $expectedTx ล้มแน่นอนแล้ว เคลียร์ให้ retry ได้ (ต้องระบุ $expectedTx)
     *                         false = ล้มก่อนเซ็น (แถวต้องไม่มี tx_hash)
     */
    public function markFailed(int $tokenId, string $error, bool $clearTx, ?string $expectedTx = null): void
    {
        $token = FactoryToken::find($tokenId);
        if (! $token || $token->status !== 'deploying') {
            return;
        }

        $expectedTx = $expectedTx !== null ? strtolower($expectedTx) : null;
        $rowTx = $token->tx_hash ? strtolower($token->tx_hash) : null;

        if ($clearTx ? ($expectedTx === null || $rowTx !== $expectedTx) : $rowTx !== null) {
            // ไม่ตรงเงื่อนไข = มีธุรกรรมอื่นค้างอยู่ — ห้ามล้มเหลว (กันผลเก่าเคลียร์ธุรกรรมใหม่)
            return;
        }

        $metadata = $token->metadata ?? [];
        $metadata['deploy_error'] = mb_substr($error, 0, 1000);
        $metadata['failed_at'] = now()->toIso8601String();

        $changes = ['status' => 'failed', 'deploy_started_at' => null];

        if ($clearTx) {
            // เก็บประวัติไว้ตรวจย้อนหลัง แล้วเคลียร์ให้ retry เซ็นธุรกรรมใหม่ได้
            $metadata['previous_deploy_txs'] = array_values(array_merge(
                $metadata['previous_deploy_txs'] ?? [],
                [['tx_hash' => $rowTx, 'nonce' => $token->deploy_nonce, 'reason' => mb_substr($error, 0, 300), 'at' => now()->toIso8601String()]]
            ));
            $changes += ['tx_hash' => null, 'deploy_nonce' => null, 'deploy_raw_tx' => null];
        }

        // where status/tx_hash อีกชั้น เผื่อมีงานอื่นเปลี่ยนแถวระหว่างอ่าน
        $query = FactoryToken::whereKey($tokenId)->where('status', 'deploying');
        $clearTx ? $query->where('tx_hash', $rowTx) : $query->whereNull('tx_hash');

        $query->update(array_merge($changes, [
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]));

        Log::error('Token deployment failed', ['token_id' => $tokenId, 'error' => $error, 'cleared_tx' => $clearTx ? $rowTx : null]);
    }

    private function markDeployed(FactoryToken $token, array $result, string $source, ?string $expectedTx): bool
    {
        $metadata = array_merge($token->metadata ?? [], [
            'block_number' => $result['blockNumber'] ?? null,
            'deployed_at' => now()->toIso8601String(),
            'factory_version' => $result['factoryVersion'] ?? null,
            'deployed_via' => $source,
            'already_existed' => (bool) ($result['alreadyExisted'] ?? false),
            // ออฟชั่นที่ผู้ใช้เลือกไว้แต่สัญญายังทำให้ไม่ได้ — ให้หน้าเว็บแจ้งเจ้าของเหรียญ
            'unsupported_options' => $result['unsupportedOptions'] ?? [],
        ]);
        unset($metadata['deploy_error'], $metadata['deploy_pending_reason']);

        $query = FactoryToken::whereKey($token->id)->where('status', 'deploying');
        $expectedTx === null ? $query->whereNull('tx_hash') : $query->where('tx_hash', $expectedTx);

        $updated = $query->update([
            'status' => 'deployed',
            'contract_address' => strtolower((string) $result['contractAddress']),
            'tx_hash' => strtolower((string) ($result['txHash'] ?? $expectedTx)),
            'deploy_raw_tx' => null,
            // ลิสต์ = ขึ้นหน้า Explore + เปิดคู่เทรดใน dex:sync — ค่าตั้งต้นให้แอดมินตัดสินเอง
            'is_listed' => (bool) $this->policy->effective()['auto_list_on_deploy'],
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        if ($updated !== 1) {
            return false;
        }

        Log::info("Token {$token->symbol} deployed", [
            'token_id' => $token->id,
            'contract' => $result['contractAddress'],
            'tx' => $result['txHash'] ?? $expectedTx,
        ]);

        // ยืนยันซอร์สบน explorer เป็นงานแยก — ไม่ให้ sleep/HTTP ของ Blockscout กินเวลางาน deploy
        VerifyFactoryTokenOnExplorerJob::dispatch($token->id)->delay(now()->addSeconds(15));

        return true;
    }

    private function note(int $tokenId, array $values, string $expectedTx): void
    {
        $token = FactoryToken::find($tokenId);
        if (! $token || $token->status !== 'deploying') {
            return;
        }

        FactoryToken::whereKey($tokenId)
            ->where('status', 'deploying')
            ->where('tx_hash', $expectedTx)
            ->update([
                'metadata' => json_encode(array_merge($token->metadata ?? [], $values), JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }

    /** ส่งต่อให้งานตรวจผล (ไม่ส่งธุรกรรมใหม่) */
    public function scheduleReceiptCheck(int $tokenId, int $delaySeconds = 30): void
    {
        CheckFactoryDeployReceiptJob::dispatch($tokenId)->delay(now()->addSeconds($delaySeconds));
    }
}
