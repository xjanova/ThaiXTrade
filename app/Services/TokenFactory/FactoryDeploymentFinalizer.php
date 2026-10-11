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
     * @return string ผลที่เกิด: deployed | failed | pending | ignored
     */
    public function apply(int $tokenId, array $result, string $source): string
    {
        $token = FactoryToken::find($tokenId);
        if (! $token || $token->status !== 'deploying') {
            return 'ignored';
        }

        // callback บันทึก hash ล้มเหลวระหว่างรัน — บันทึกซ้ำจากผลที่ได้ ก่อนตัดสินอะไรต่อ
        if (! $token->tx_hash && is_array($result['signed'] ?? null) && is_string($result['signed']['txHash'] ?? null)) {
            $this->recordSigned($tokenId, $result['signed']['txHash'], $result['signed']['nonce'] ?? null, $result['signed']['rawTx'] ?? null);
            $token->refresh();
        }

        if (($result['success'] ?? false) === true && ! empty($result['contractAddress'])) {
            return $this->markDeployed($token, $result, $source) ? 'deployed' : 'ignored';
        }

        $hasTx = (bool) $token->tx_hash;

        // รู้แน่ว่าธุรกรรมนั้นไม่มีวันสร้างเหรียญ → ล้มเหลว ส่งใหม่ได้อย่างปลอดภัย
        if ($hasTx && (($result['reverted'] ?? false) || ($result['dropped'] ?? false))) {
            $this->markFailed($tokenId, (string) ($result['error'] ?? 'ธุรกรรมไม่สำเร็จ'), clearTx: true);

            return 'failed';
        }

        // มีธุรกรรมแล้วแต่ยังไม่รู้ผล → ห้ามล้มเหลว ห้ามส่งใหม่ — ให้งานตรวจผลวนถาม
        if ($hasTx) {
            $this->note($tokenId, ['deploy_pending_reason' => $result['error'] ?? 'รอผลธุรกรรม']);

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

    public function markFailed(int $tokenId, string $error, bool $clearTx): void
    {
        $token = FactoryToken::find($tokenId);
        if (! $token || $token->status !== 'deploying') {
            return;
        }

        $metadata = $token->metadata ?? [];
        $metadata['deploy_error'] = mb_substr($error, 0, 1000);
        $metadata['failed_at'] = now()->toIso8601String();

        $changes = ['status' => 'failed', 'deploy_started_at' => null];

        if ($clearTx && $token->tx_hash) {
            // เก็บประวัติไว้ตรวจย้อนหลัง แล้วเคลียร์ให้ retry เซ็นธุรกรรมใหม่ได้
            $metadata['previous_deploy_txs'] = array_values(array_merge(
                $metadata['previous_deploy_txs'] ?? [],
                [['tx_hash' => $token->tx_hash, 'nonce' => $token->deploy_nonce, 'reason' => mb_substr($error, 0, 300), 'at' => now()->toIso8601String()]]
            ));
            $changes += ['tx_hash' => null, 'deploy_nonce' => null, 'deploy_raw_tx' => null];
        } elseif ($token->tx_hash) {
            // มีธุรกรรมค้างอยู่ — ห้ามล้มเหลว (กันเรียกผิดที่)
            return;
        }

        // where status = 'deploying' อีกชั้น เผื่อมีงานอื่นปิดงานไปก่อนระหว่างอ่าน
        FactoryToken::whereKey($tokenId)->where('status', 'deploying')->update(
            array_merge($changes, ['metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE), 'updated_at' => now()])
        );

        Log::error('Token deployment failed', ['token_id' => $tokenId, 'error' => $error, 'cleared_tx' => $clearTx]);
    }

    private function markDeployed(FactoryToken $token, array $result, string $source): bool
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

        $updated = FactoryToken::whereKey($token->id)->where('status', 'deploying')->update([
            'status' => 'deployed',
            'contract_address' => strtolower((string) $result['contractAddress']),
            'tx_hash' => strtolower((string) ($result['txHash'] ?? $token->tx_hash)),
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
            'tx' => $result['txHash'] ?? $token->tx_hash,
        ]);

        // ยืนยันซอร์สบน explorer เป็นงานแยก — ไม่ให้ sleep/HTTP ของ Blockscout กินเวลางาน deploy
        VerifyFactoryTokenOnExplorerJob::dispatch($token->id)->delay(now()->addSeconds(15));

        return true;
    }

    private function note(int $tokenId, array $values): void
    {
        $token = FactoryToken::find($tokenId);
        if (! $token || $token->status !== 'deploying') {
            return;
        }

        FactoryToken::whereKey($tokenId)->where('status', 'deploying')->update([
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
