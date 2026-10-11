<?php

namespace App\Services\Bridge\Wtpix;

use App\Models\SystemAlert;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeSigner;
use App\Services\Bridge\EvmRpc;
use App\Support\Wei;
use Illuminate\Support\Facades\Log;

/**
 * เบรกฉุกเฉินของสะพาน TPIX ⇄ wTPIX — ทุกจุดที่เจอเหตุร้ายแรงเรียกที่นี่ที่เดียว.
 *
 * halt(): ปิดสวิตช์หลังบ้านเสมอ → guardian สั่ง pause() ทั้ง WTPIXBridgeMinter (BSC) และ
 *         TPIXBridgeVault (4289) → ยกเหตุวิกฤตขึ้นหลังบ้าน
 *
 * guardian ปลด pause ไม่ได้ (เฉพาะเจ้าของ) — คีย์ guardian หลุดก็ทำได้แค่หยุดสะพาน ไม่ทำให้เงินหาย
 * จึงสั่ง pause ได้ทันทีโดยไม่ต้องรอคนยืนยัน
 *
 * guardian ยังไม่ตั้ง = ทำได้แค่ปิดสวิตช์ + แจ้งเตือน (สะพานบนเว็บหยุด แต่สัญญายังเปิด)
 * pause บน BSC ต้องใช้ BNB — กระเป๋า guardian ไม่มี BNB = pause ฝั่ง BSC ไม่ผ่าน (บันทึกไว้ในผล)
 */
class WtpixBridgeGuard
{
    public const ALERT_NODE = 'bridge';

    public function __construct(
        private WtpixBridgeConfig $config,
        private BridgeSigner $signer,
    ) {}

    /**
     * @return string ผลการกระทำคั่นด้วย , (เก็บลง snapshot/บันทึก)
     */
    public function halt(string $reason, string $note, array $context = []): string
    {
        $this->config->setSiteSwitch(false, mb_substr("หยุดอัตโนมัติ ({$reason}): {$note}", 0, 900));

        $actions = ['site_disabled'];
        $autoPause = (bool) config('wtpix_bridge.reconcile.auto_pause', true);

        if (! $autoPause) {
            $actions[] = 'auto_pause_off';
        } elseif (! $this->config->signerReady('guardian')) {
            $actions[] = 'no_guardian';
        } elseif (! $this->signer->isAvailable()) {
            $actions[] = 'not_cli';
        } else {
            $bsc = $this->config->bsc();
            $maxGwei = (string) ($bsc['max_gas_price_gwei'] ?? '');

            $actions[] = $this->pauseOne('minter', $this->config->bscRpc(), $this->config->minterAddress(), [
                'gas_multiplier_bps' => (int) ($bsc['gas_price_multiplier_bps'] ?? 12000),
                // เหตุฉุกเฉินยอมจ่ายแก๊สแพงกว่าปกติสองเท่า — ช้ากว่าคนร้ายหนึ่งบล็อกก็เสียหายเพิ่ม
                'max_gas_price_wei' => $maxGwei !== '' ? bcmul(Wei::toWei($maxGwei, 9), '2', 0) : null,
            ]);
            $actions[] = $this->pauseOne('vault', $this->config->tpixRpc(), $this->config->vaultAddress(), ['gas_price_wei' => '0']);
        }

        $message = "สะพาน TPIX⇄wTPIX ถูกหยุดอัตโนมัติ: {$note} — ".implode(', ', $actions);
        Log::critical('wtpix-bridge: '.$message, ['reason' => $reason] + $context);
        SystemAlert::raise(self::ALERT_NODE, 'wtpix_bridge_'.$reason, 'critical', $message, $context + ['reason' => $reason]);

        return implode(',', $actions);
    }

    /** pause() สัญญาหนึ่งตัวด้วยกุญแจ guardian — อ่าน paused() ก่อน (pause ซ้ำ revert ตามพฤติกรรม OZ) */
    private function pauseOne(string $label, EvmRpc $rpc, ?string $contract, array $options): string
    {
        if ($contract === null) {
            return "{$label}_not_configured";
        }

        if ($rpc->callBool($contract, 'paused()') === true) {
            return "{$label}_already_paused";
        }

        $signed = $this->signer->signWithKey($this->config->signerKey('guardian'), $rpc, $contract, Abi::selector('pause()'), $options);
        if (! ($signed['ok'] ?? false)) {
            return "{$label}_pause_failed:".($signed['code'] ?? 'error');
        }

        $sent = $this->signer->send($rpc, (string) $signed['raw']);
        Log::critical("wtpix-bridge: guardian สั่ง pause {$label}", ['tx' => $signed['txHash'] ?? null, 'sent' => $sent['ok'] ?? false]);

        return ($sent['ok'] ?? false) ? "{$label}_paused" : "{$label}_pause_broadcast_failed";
    }
}
