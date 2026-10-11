<?php

namespace App\Services\Bridge\Wtpix;

use App\Models\SystemAlert;
use App\Models\WtpixBridgeSnapshot;
use App\Models\WtpixBridgeTransfer;
use App\Services\Bridge\Abi;
use App\Support\Wei;

/**
 * กระทบยอดสะพาน TPIX ⇄ wTPIX — wTPIX ทุกเหรียญบน BSC ต้องมี TPIX จริงในคลังหนุน.
 *
 *   คลังสุทธิ = TPIX ใน TPIXBridgeVault − ยอดที่ค้างปล่อยให้คนที่เผา wTPIX ไปแล้ว
 *   ขาด       = wTPIX.totalSupply() − คลังสุทธิ   (ติดลบ = เกิน ซึ่งปกติเพราะค่าธรรมเนียมค้างในคลัง)
 *
 * หยุดทันทีรอบแรก (ไม่รอรอบสอง):
 *   - minter.totalMinted มากกว่ายอดที่ระบบสั่ง mint          → คีย์ relayer หลุด (mint ผ่าน minter เอง)
 *   - wTPIX.totalSupply มากกว่า minter.totalMinted           → มี minter อื่นที่ mint ข้ามเพดานรายวัน
 *   - vault.totalReleased มากกว่ายอดที่ระบบสั่งปล่อย           → คีย์ relayer หลุด (ปล่อย TPIX เอง)
 * ขาดเกิน tolerance สองรอบติด → หยุด ("สองรอบ" เพราะยอดสองเชนอ่านไม่พร้อมกัน ธุรกรรมที่ลงบล็อก
 * ระหว่างอ่านทำให้คลาดได้ชั่วคราว แต่ wTPIX ที่ไม่มีเงินหนุนจะค้างอยู่ทุกรอบ)
 *
 * อ่านเชนไหนไม่ได้ = incomplete ไม่ตัดสินอะไร — ใครก็ทำให้ RPC สาธารณะช้าได้
 * ถ้านั่นหยุดสะพานได้ ก็กลายเป็นช่องให้ปิดบริการเรา
 */
class WtpixBridgeReconciler
{
    public function __construct(
        private WtpixBridgeConfig $config,
        private WtpixBridgeRelayer $relayer,
        private WtpixBridgeGuard $guard,
    ) {}

    /** วัดอย่างเดียว ไม่สั่งอะไร (หน้าหลังบ้านเรียกได้) */
    public function measure(): array
    {
        $vault = $this->config->vaultAddress();
        $wtpix = $this->config->wtpixAddress();
        $minter = $this->config->minterAddress();
        $tpix = $this->config->tpixRpc();
        $bsc = $this->config->bscRpc();

        // ลำดับตั้งใจ: supply ก่อน → คลัง → ภาระในฐานข้อมูลทีหลังสุด (เผาระหว่างอ่านทำให้ดูเกิน ไม่ใช่ดูขาด)
        $supply = $wtpix ? $bsc->callUint($wtpix, 'totalSupply()') : null;
        $minterTotal = $minter ? $bsc->callUint($minter, 'totalMinted()') : null;
        $minterPaused = $minter ? $bsc->callBool($minter, 'paused()') : null;
        $minterIsMinter = $wtpix && $minter ? $bsc->callBool($wtpix, 'minters(address)', Abi::encAddress($minter)) : null;

        $vaultBalance = $vault ? $tpix->nativeBalance($vault) : null;
        $vaultReleased = $vault ? $tpix->callUint($vault, 'totalReleased()') : null;
        $vaultLocked = $vault ? $tpix->callUint($vault, 'totalLocked()') : null;
        $vaultPaused = $vault ? $tpix->callBool($vault, 'paused()') : null;

        $complete = $supply !== null && $vaultBalance !== null && $minterTotal !== null && $vaultReleased !== null;

        // ภาระค้างปล่อย — รายการ "เผาแล้ว ยังไม่ได้ TPIX" จาก wTPIX ตัวที่ตั้งไว้ตอนนี้เท่านั้น
        // released_elsewhere ออกจากคลังไปแล้ว (โดยใครก็ไม่รู้) ไม่นับซ้ำ — ตัวเช็ค unexplained จับเรื่องนั้นแทน
        $owed = '0';
        $owedRows = WtpixBridgeTransfer::query()
            ->where('direction', WtpixBridgeTransfer::TO_TPIX)
            ->where('source_chain_id', $this->config->bscChainId())
            ->where('source_contract', (string) $wtpix)
            ->whereIn('status', WtpixBridgeTransfer::OWED)
            ->where(fn ($q) => $q->whereNull('review_reason')->orWhere('review_reason', '!=', 'released_elsewhere'))
            ->get(['id', 'status', 'payout_wei', 'deposit_id', 'dest_contract']);

        foreach ($owedRows as $row) {
            // ส่งแล้วแต่ยังไม่ได้ตามผล — ถ้าลงบล็อกไปแล้ว TPIX ออกจากคลังแล้ว ห้ามนับเป็นภาระซ้ำ (ไม่งั้นขาดปลอม)
            if ($row->status === WtpixBridgeTransfer::STATUS_SENDING) {
                $done = $this->relayer->processedOnChain($tpix, $row->dest_contract ?: $vault, $row->deposit_id);
                if ($done === null) {
                    $complete = false;

                    continue;
                }
                if ($done) {
                    continue;
                }
            }

            $owed = bcadd($owed, (string) $row->payout_wei, 0);
        }

        $backing = $vaultBalance !== null ? bcsub($vaultBalance, $owed, 0) : '0';
        $deficit = $supply !== null && $vaultBalance !== null ? bcsub($supply, $backing, 0) : '0';

        $ourMinted = $this->sum(WtpixBridgeTransfer::query()
            ->where('direction', WtpixBridgeTransfer::TO_BSC)
            ->whereIn('status', [WtpixBridgeTransfer::STATUS_SENDING, WtpixBridgeTransfer::STATUS_COMPLETED])
            ->where('dest_contract', (string) $minter)
            ->pluck('payout_wei')->all());
        $ourReleased = $this->sum(WtpixBridgeTransfer::query()
            ->where('direction', WtpixBridgeTransfer::TO_TPIX)
            ->whereIn('status', [WtpixBridgeTransfer::STATUS_SENDING, WtpixBridgeTransfer::STATUS_COMPLETED])
            ->where('dest_contract', (string) $vault)
            ->pluck('payout_wei')->all());

        $positive = fn (?string $a, string $b) => $a !== null && bccomp($a, $b, 0) > 0 ? bcsub($a, $b, 0) : '0';
        $unexplainedMint = $positive($minterTotal, $ourMinted);
        $unexplainedRelease = $positive($vaultReleased, $ourReleased);
        $foreignSupply = $minterTotal !== null ? $positive($supply, $minterTotal) : '0';

        $status = match (true) {
            ! $complete => WtpixBridgeSnapshot::STATUS_INCOMPLETE,
            bccomp($deficit, $this->config->reconcileToleranceWei(), 0) > 0 => WtpixBridgeSnapshot::STATUS_DEFICIT,
            default => WtpixBridgeSnapshot::STATUS_OK,
        };

        $tpixExplorer = $this->config->explorer($this->config->tpixChainId());
        $bscExplorer = $this->config->explorer($this->config->bscChainId());

        return [
            'status' => $status,
            'vault_wei' => $vaultBalance,
            'supply_wei' => $supply,
            'owed_wei' => $owed,
            'backing_wei' => $backing,
            'deficit_wei' => $deficit,
            'unexplained_mint_wei' => $unexplainedMint,
            'unexplained_release_wei' => $unexplainedRelease,
            'foreign_supply_wei' => $foreignSupply,
            'details' => [
                'vault_address' => $vault,
                'wtpix_address' => $wtpix,
                'minter_address' => $minter,
                'bsc_chain_id' => $this->config->bscChainId(),
                'vault_total_locked_wei' => $vaultLocked,
                'vault_total_released_wei' => $vaultReleased,
                'minter_total_minted_wei' => $minterTotal,
                'our_minted_wei' => $ourMinted,
                'our_released_wei' => $ourReleased,
                'vault_paused' => $vaultPaused,
                'minter_paused' => $minterPaused,
                'minter_is_minter' => $minterIsMinter,
                'vault_url' => $vault && $tpixExplorer !== '' ? "{$tpixExplorer}/address/{$vault}" : null,
                'token_url' => $wtpix && $bscExplorer !== '' ? "{$bscExplorer}/token/{$wtpix}" : null,
            ],
        ];
    }

    /** หนึ่งรอบ: วัด → ตัดสิน → (ถ้าต้อง) หยุด → เก็บผลให้หน้า proof of reserves */
    public function run(): WtpixBridgeSnapshot
    {
        $previous = WtpixBridgeSnapshot::latest('id')->first();
        $m = $this->measure();
        $notes = [];
        $action = null;
        $fmt = fn (string $wei) => Wei::format($wei, 18);

        $severe = [];
        if (bccomp($m['unexplained_mint_wei'], '0', 0) > 0) {
            $severe[] = ['unexplained_mint', 'minter.totalMinted มากกว่ายอดที่ระบบสั่ง mint '.$fmt($m['unexplained_mint_wei']).' wTPIX — คีย์ relayer อาจหลุด'];
        }
        if (bccomp($m['foreign_supply_wei'], '0', 0) > 0) {
            $severe[] = ['foreign_mint', 'wTPIX supply มากกว่ายอดที่ WTPIXBridgeMinter เคย mint '.$fmt($m['foreign_supply_wei']).' — มี minter อื่น mint'];
        }
        if (bccomp($m['unexplained_release_wei'], '0', 0) > 0) {
            $severe[] = ['unexplained_release', 'vault.totalReleased มากกว่ายอดที่ระบบสั่งปล่อย '.$fmt($m['unexplained_release_wei']).' TPIX — คีย์ relayer อาจหลุด'];
        }

        if ($severe !== []) {
            $notes = array_column($severe, 1);
            $action = $this->guard->halt($severe[0][0], implode(' · ', $notes), $this->alertContext($m));
        } elseif ($m['status'] === WtpixBridgeSnapshot::STATUS_DEFICIT) {
            $notes[] = 'wTPIX supply เกิน TPIX ในคลังสุทธิ '.$fmt($m['deficit_wei']).' (เกิน tolerance)';

            if ($previous && $previous->status === WtpixBridgeSnapshot::STATUS_DEFICIT) {
                $action = $this->guard->halt('reserve_deficit', end($notes), $this->alertContext($m));
            } else {
                $action = 'deficit_warning';
                SystemAlert::raise(
                    WtpixBridgeGuard::ALERT_NODE,
                    'wtpix_bridge_reserve_warning',
                    'warning',
                    'สะพาน TPIX⇄wTPIX: wTPIX supply เกินคลังในรอบล่าสุด — ถ้ารอบหน้ายังขาดระบบจะหยุดเอง',
                    ['deficit_wei' => $m['deficit_wei']]
                );
            }
        } elseif ($m['status'] === WtpixBridgeSnapshot::STATUS_OK) {
            SystemAlert::resolveKeys(WtpixBridgeGuard::ALERT_NODE, ['wtpix_bridge_reserve_warning']);
        }

        if ($m['status'] === WtpixBridgeSnapshot::STATUS_INCOMPLETE) {
            $notes[] = 'อ่านยอดไม่ครบทั้งสองเชน — ไม่ตัดสินเรื่องเงินหนุนรอบนี้';
        }

        if (($m['details']['minter_is_minter'] ?? null) === false && $this->config->minterAddress() !== null) {
            $notes[] = 'wTPIX.minters(WTPIXBridgeMinter) = false — mint ไม่ได้';
        }

        return WtpixBridgeSnapshot::create([
            'status' => $m['status'],
            'vault_wei' => $m['vault_wei'],
            'supply_wei' => $m['supply_wei'],
            'owed_wei' => $m['owed_wei'],
            'backing_wei' => $m['backing_wei'],
            'deficit_wei' => $m['deficit_wei'],
            'unexplained_mint_wei' => $m['unexplained_mint_wei'],
            'unexplained_release_wei' => $m['unexplained_release_wei'],
            'foreign_supply_wei' => $m['foreign_supply_wei'],
            'details' => $m['details'],
            'action' => $action !== null ? mb_substr($action, 0, 191) : null,
            'note' => $notes === [] ? null : implode(' · ', $notes),
        ]);
    }

    private function alertContext(array $m): array
    {
        return [
            'supply_wei' => $m['supply_wei'],
            'vault_wei' => $m['vault_wei'],
            'owed_wei' => $m['owed_wei'],
            'deficit_wei' => $m['deficit_wei'],
            'unexplained_mint_wei' => $m['unexplained_mint_wei'],
            'unexplained_release_wei' => $m['unexplained_release_wei'],
            'foreign_supply_wei' => $m['foreign_supply_wei'],
        ];
    }

    private function sum(array $values): string
    {
        return array_reduce($values, fn ($carry, $v) => bcadd($carry, (string) $v, 0), '0');
    }
}
