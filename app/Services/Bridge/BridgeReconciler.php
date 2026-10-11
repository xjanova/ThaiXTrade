<?php

namespace App\Services\Bridge;

use App\Models\BridgeDeposit;
use App\Models\BridgeReserveSnapshot;
use App\Models\BridgeWithdrawal;
use App\Models\SystemAlert;
use Illuminate\Support\Facades\Log;

/**
 * กระทบยอดสะพาน — supply ของ USDT_TPIX ต้องไม่เกินเงินหนุนจริง.
 *
 *   เงินหนุนสุทธิ = USDT ใน vault ทุกเชน + USDT ในกระเป๋าจ่ายทุกเชน − ยอดที่ค้างจ่ายให้คนที่ถอนแล้ว
 *   ขาด (deficit) = supply − เงินหนุนสุทธิ
 *
 * ขาดเกิน tolerance สองรอบติด → ปิดสวิตช์หลังบ้าน + guardian สั่ง pause minter บนเชน + คาดแดงหลังบ้าน
 * ("สองรอบ" เพราะยอดหลายเชนอ่านไม่พร้อมกัน ธุรกรรมที่ลงบล็อกระหว่างอ่านทำให้คลาดได้ชั่วคราว)
 *
 * mint ที่ไม่มีรายการในระบบ (minter.totalMinted มากกว่ายอดที่เราสั่ง) = คีย์ relayer หลุด
 * → หยุดทันทีรอบแรก ไม่รอรอบที่สอง
 *
 * อ่านเชนไม่ครบ = สถานะ incomplete ไม่ตัดสินอะไร (ห้ามสั่งหยุดเพราะ RPC ล่ม — ใครก็ทำให้ RPC
 * สาธารณะช้าได้ ถ้านั่นหยุดสะพานได้ก็กลายเป็นช่องโจมตีให้ปิดบริการเรา)
 */
class BridgeReconciler
{
    public const ALERT_NODE = 'bridge';

    public function __construct(
        private BridgeConfig $config,
        private BridgeSigner $signer,
    ) {}

    /**
     * คำนวณสถานะเงินหนุน — อ่านอย่างเดียว ไม่สั่งอะไร (หน้าหลังบ้านเรียกได้).
     */
    public function measure(): array
    {
        $tpix = $this->config->tpixRpc();
        $usdt = $this->config->usdtTpixAddress();
        $minter = $this->config->minterAddress();
        $payoutWallet = $this->config->signerAddress('payout');
        $vault = $this->config->vaultAddress();

        // ลำดับการอ่านตั้งใจ: supply ก่อน → ยอดเชนต่าง ๆ → ภาระค้างจ่ายในฐานข้อมูลทีหลังสุด
        $supply = $usdt ? $tpix->callUint($usdt, 'totalSupply()') : null;
        $minterTotal = $minter ? $tpix->callUint($minter, 'totalMinted()') : null;
        $minterPaused = $minter ? $tpix->callBool($minter, 'paused()') : null;

        $vaultSum = '0';
        $payoutSum = '0';
        $complete = $supply !== null;
        $rows = [];

        foreach ($this->config->chains() as $chainId => $chain) {
            $rpc = $this->config->rpc($chainId);
            $token = (string) $chain['usdt']['address'];
            $decimals = (int) $chain['usdt']['decimals'];
            $explorer = $this->config->explorer($chainId);

            $vaultRaw = $rpc?->tokenBalance($token, $vault);
            $payoutRaw = $payoutWallet ? $rpc?->tokenBalance($token, $payoutWallet) : '0';
            $payoutNative = $payoutWallet ? $rpc?->nativeBalance($payoutWallet) : null;
            $readable = $vaultRaw !== null && $payoutRaw !== null;

            $vaultUnits = $vaultRaw !== null ? Abi::toUnits6($vaultRaw, $decimals)['units'] : null;
            $payoutUnits = $payoutRaw !== null ? Abi::toUnits6($payoutRaw, $decimals)['units'] : null;

            if ($readable) {
                $vaultSum = bcadd($vaultSum, $vaultUnits, 0);
                $payoutSum = bcadd($payoutSum, $payoutUnits, 0);
            } else {
                $complete = false;
            }

            $rows[] = [
                'chain_id' => $chainId,
                'name' => $chain['name'],
                'short' => $chain['short'] ?? $chain['name'],
                'enabled' => $this->config->chainEnabled($chainId),
                'token' => $token,
                'symbol' => $chain['usdt']['symbol'] ?? 'USDT',
                'decimals' => $decimals,
                'readable' => $readable,
                'vault_units' => $vaultUnits,
                'payout_units' => $payoutUnits,
                'payout_native_wei' => $payoutNative,
                'native' => $chain['native'] ?? 'ETH',
                'vault_url' => $explorer !== '' ? "{$explorer}/token/{$token}?a={$vault}" : null,
                'payout_url' => $explorer !== '' && $payoutWallet ? "{$explorer}/token/{$token}?a={$payoutWallet}" : null,
            ];
        }

        $owed = $this->sum(BridgeWithdrawal::whereIn('status', BridgeWithdrawal::OWED)->pluck('payout_units')->all());
        $minted = $this->sum(BridgeDeposit::whereIn('status', [BridgeDeposit::STATUS_MINTED, BridgeDeposit::STATUS_MINTING])->pluck('mint_units')->all());

        $backing = bcsub(bcadd($vaultSum, $payoutSum, 0), $owed, 0);
        $deficit = $supply !== null ? bcsub($supply, $backing, 0) : '0';

        // mint ผ่าน minter ที่ระบบไม่ได้สั่ง — นับรวม minting ด้วย (ลงบล็อกแล้วแต่เรายังไม่ได้ตามผล)
        $unexplained = $minterTotal !== null && bccomp($minterTotal, $minted, 0) > 0 ? bcsub($minterTotal, $minted, 0) : '0';

        $status = match (true) {
            ! $complete => BridgeReserveSnapshot::STATUS_INCOMPLETE,
            bccomp($deficit, $this->config->reconcileToleranceUnits(), 0) > 0 => BridgeReserveSnapshot::STATUS_DEFICIT,
            default => BridgeReserveSnapshot::STATUS_OK,
        };

        return [
            'status' => $status,
            'supply_units' => $supply,
            'vault_units' => $vaultSum,
            'payout_units' => $payoutSum,
            'owed_units' => $owed,
            'backing_units' => $backing,
            'deficit_units' => $deficit,
            'unexplained_mint_units' => $unexplained,
            'minter_total_units' => $minterTotal,
            'minter_paused' => $minterPaused,
            'chains' => $rows,
        ];
    }

    /**
     * หนึ่งรอบ: วัด → ตัดสิน → (ถ้าต้อง) สั่งหยุด → เก็บผลไว้ให้หน้า proof of reserves.
     */
    public function run(): BridgeReserveSnapshot
    {
        $previous = BridgeReserveSnapshot::latest('id')->first();
        $m = $this->measure();
        $action = null;
        $notes = [];

        $autoPause = (bool) config('bridge.reconcile.auto_pause', true);

        if (bccomp($m['unexplained_mint_units'], '0', 0) > 0) {
            $notes[] = 'minter.totalMinted มากกว่ายอดที่ระบบสั่ง mint '.$m['unexplained_mint_units'].' หน่วย — คีย์ relayer อาจหลุด';
            $action = $autoPause ? $this->halt('unexplained_mint', end($notes), $m) : 'alert_only';
        } elseif ($m['status'] === BridgeReserveSnapshot::STATUS_DEFICIT) {
            $notes[] = "supply เกินเงินหนุน {$m['deficit_units']} หน่วย (เกิน tolerance)";

            if ($previous && $previous->status === BridgeReserveSnapshot::STATUS_DEFICIT) {
                $action = $autoPause ? $this->halt('reserve_deficit', end($notes), $m) : 'alert_only';
            } else {
                // รอบแรก — เตือนก่อน รอบหน้ายังขาดค่อยหยุด
                $action = 'deficit_warning';
                SystemAlert::raise(
                    self::ALERT_NODE,
                    'bridge_reserve_warning',
                    'warning',
                    'สะพาน USDT: supply เกินเงินหนุนในรอบล่าสุด — ถ้ารอบหน้ายังขาดระบบจะหยุดเอง',
                    ['deficit_units' => $m['deficit_units']]
                );
            }
        } elseif ($m['status'] === BridgeReserveSnapshot::STATUS_OK) {
            SystemAlert::resolveKeys(self::ALERT_NODE, ['bridge_reserve_warning']);
        }

        if ($m['status'] === BridgeReserveSnapshot::STATUS_INCOMPLETE) {
            $notes[] = 'อ่านยอดไม่ครบทุกเชน — ไม่ตัดสินรอบนี้';
        }

        return BridgeReserveSnapshot::create([
            'status' => $m['status'],
            'supply_units' => $m['supply_units'],
            'vault_units' => $m['vault_units'],
            'payout_units' => $m['payout_units'],
            'owed_units' => $m['owed_units'],
            'backing_units' => $m['backing_units'],
            'deficit_units' => $m['deficit_units'],
            'unexplained_mint_units' => $m['unexplained_mint_units'],
            'chains' => $m['chains'],
            'action' => $action,
            'note' => $notes === [] ? null : implode(' · ', $notes),
        ]);
    }

    /**
     * หยุดสะพาน: ปิดสวิตช์หลังบ้านเสมอ + guardian pause minter บนเชน (ถ้าตั้งกุญแจไว้) + คาดแดง.
     */
    private function halt(string $reason, string $note, array $m): string
    {
        $this->config->setSiteSwitch(false, "หยุดอัตโนมัติ ({$reason}): {$note}");

        $actions = ['site_disabled'];
        $minter = $this->config->minterAddress();

        if ($minter && $m['minter_paused'] === false && $this->config->signerReady('guardian') && $this->signer->isAvailable()) {
            $rpc = $this->config->tpixRpc();
            $signed = $this->signer->sign('guardian', $rpc, $minter, Abi::selector('pause()'), ['gas_multiplier_bps' => 10000]);

            if ($signed['ok'] ?? false) {
                $sent = $this->signer->send($rpc, (string) $signed['raw']);
                $actions[] = ($sent['ok'] ?? false) ? 'minter_paused' : 'minter_pause_broadcast_failed';
                Log::critical('bridge: guardian สั่ง pause minter แล้ว', ['tx' => $signed['txHash'] ?? null, 'reason' => $reason]);
            } else {
                $actions[] = 'minter_pause_sign_failed';
            }
        } elseif ($m['minter_paused'] === true) {
            $actions[] = 'minter_already_paused';
        } else {
            $actions[] = 'no_guardian';
        }

        $message = "สะพาน USDT ถูกหยุดอัตโนมัติ: {$note} — ".implode(', ', $actions);
        Log::critical('bridge: '.$message, [
            'supply' => $m['supply_units'],
            'backing' => $m['backing_units'],
            'deficit' => $m['deficit_units'],
            'unexplained' => $m['unexplained_mint_units'],
        ]);

        SystemAlert::raise(self::ALERT_NODE, $reason === 'unexplained_mint' ? 'bridge_unexplained_mint' : 'bridge_reserve_deficit', 'critical', $message, [
            'supply_units' => $m['supply_units'],
            'backing_units' => $m['backing_units'],
            'deficit_units' => $m['deficit_units'],
            'unexplained_mint_units' => $m['unexplained_mint_units'],
        ]);

        return implode(',', $actions);
    }

    private function sum(array $values): string
    {
        return array_reduce($values, fn ($carry, $v) => bcadd($carry, (string) $v, 0), '0');
    }
}
