<?php

namespace App\Services\Bridge\Wtpix;

use App\Models\WtpixBridgeSnapshot;
use App\Models\WtpixBridgeTransfer;
use App\Services\Bridge\Abi;
use App\Support\Wei;
use Illuminate\Support\Facades\Cache;

/**
 * ข้อมูลสาธารณะของสะพาน TPIX ⇄ wTPIX — หน้า /bridge (asset TPIX), /bridge/reserves และ API อ่านจากที่นี่.
 *
 * อ่านอย่างเดียวทั้งหมด ค่าที่ต้องยิง RPC แคชสั้น ๆ (คนเปิดหน้าร้อยคนต้องไม่เป็น RPC ร้อยครั้ง)
 */
class WtpixBridgePublicInfo
{
    public function __construct(
        private WtpixBridgeConfig $config,
        private WtpixBridgeFeeQuoter $fees,
    ) {}

    public function info(): array
    {
        return Cache::remember(WtpixBridgeConfig::PUBLIC_CACHE_KEY, 30, function () {
            $caps = $this->caps();
            $configured = $this->config->vaultAddress() !== null && $this->config->wtpixAddress() !== null
                && $this->config->minterAddress() !== null && ! $this->config->vaultRegistryMismatch();

            // คลังถูก pause = lock() revert — บอกว่าปิดดีกว่าให้ผู้ใช้กดแล้วเจอ error ในกระเป๋า
            $open = $this->config->operational() && $configured && ($caps['vault']['paused'] ?? null) !== true;

            return [
                'asset' => 'tpix',
                'enabled' => $open,
                'installed' => $this->config->installed(),
                'paused_reason' => $this->config->siteSwitchOn() ? null : $this->config->switchReason(),
                // โหมดทดสอบ — หน้าเว็บเปิดปุ่มเฉพาะกระเป๋าที่ sha256(ที่อยู่ตัวเล็ก) ตรง · ไม่ประกาศที่อยู่จริง
                'test_mode' => $this->config->testMode(),
                'test_wallet_hashes' => array_map(fn ($a) => hash('sha256', $a), $this->config->testWallets()),
                'tpix' => [
                    'chain_id' => $this->config->tpixChainId(),
                    'name' => (string) config('wtpix_bridge.tpix.name', 'TPIX Chain'),
                    'vault_address' => $this->config->vaultAddress(),
                    'explorer' => $this->config->explorer($this->config->tpixChainId()),
                    'rpc' => (string) config('wtpix_bridge.tpix.public_rpc', 'https://rpc1.tpix.online'),
                    'eta_minutes' => (int) config('wtpix_bridge.tpix.eta_minutes', 3),
                ],
                'bsc' => [
                    'chain_id' => $this->config->bscChainId(),
                    'name' => $this->config->bscName(),
                    'wtpix_address' => $this->config->wtpixAddress(),
                    'minter_address' => $this->config->minterAddress(),
                    'explorer' => $this->config->explorer($this->config->bscChainId()),
                    'rpc' => (string) config('wtpix_bridge.bsc.public_rpc', ''),
                    'eta_minutes' => (int) config('wtpix_bridge.bsc.eta_minutes', 2),
                ],
                'token' => [
                    'symbol' => 'wTPIX',
                    'decimals' => 18,
                    'logo' => (string) config('wtpix_bridge.token_logo'),
                ],
                // lock_fee = ราคาที่ประกาศอยู่ตอนนี้ (ตัวที่ถูกเก็บจริง) ไม่ใช่ขั้นต่ำในค่าตั้ง
                'limits' => ['lock_fee' => Wei::format($this->fees->current()['fee_wei'], 18)] + $this->config->limits(),
                'fee_quote' => $this->feeQuote(),
                'vault' => $caps['vault'],
                'minter' => $caps['minter'],
            ];
        });
    }

    /** ที่มาของค่าธรรมเนียมขาออก — หน้าเว็บโชว์ให้ผู้ใช้ตรวจได้ว่าคิดจากอะไร */
    private function feeQuote(): array
    {
        $current = $this->fees->current();
        $quote = $current['fresh'] ? $current['quote']?->toPublicArray() : null;

        return [
            'fee' => Wei::format($current['fee_wei'], 18),
            'floor' => Wei::format($this->fees->floorWei(), 18),
            'ceiling' => Wei::format($this->fees->ceilingWei(), 18),
            'markup_pct' => $this->config->feeMarkupPct(),
            'grace_minutes' => $this->config->feeSetting('grace_minutes', 15),
            // ไม่มีราคาสด = กำลังเก็บเพดาน
            'source' => $quote['source'] ?? 'fallback',
            'gas_gwei' => $quote['gas_gwei'] ?? null,
            'bnb_usd' => $quote['bnb_usd'] ?? null,
            'tpix_usd' => $quote['tpix_usd'] ?? null,
            'updated_at' => $quote['updated_at'] ?? null,
        ];
    }

    /** เพดานบนสัญญาทั้งสองฝั่ง (อ่านสด แคช 60 วิ) — null ทุกช่องถ้ายังไม่ตั้งหรืออ่านไม่ได้ */
    public function caps(): array
    {
        $vault = $this->config->vaultAddress();
        $minter = $this->config->minterAddress();
        $fmt = fn (?string $v) => $v === null ? null : Wei::format($v, 18);

        $vaultCaps = ['min_lock' => null, 'max_lock' => null, 'max_per_tx' => null, 'daily_cap' => null, 'remaining_today' => null, 'paused' => null];
        $minterCaps = ['max_per_tx' => null, 'daily_cap' => null, 'remaining_today' => null, 'paused' => null];

        if ($vault !== null) {
            $vaultCaps = Cache::remember('wtpix-bridge:vault:caps:'.$vault, 60, function () use ($vault, $fmt) {
                $rpc = $this->config->tpixRpc();

                return [
                    'min_lock' => $fmt($rpc->callUint($vault, 'minLock()')),
                    'max_lock' => $fmt($rpc->callUint($vault, 'maxLock()')),
                    'max_per_tx' => $fmt($rpc->callUint($vault, 'maxPerTx()')),
                    'daily_cap' => $fmt($rpc->callUint($vault, 'dailyCap()')),
                    'remaining_today' => $fmt($rpc->callUint($vault, 'remainingToday()')),
                    'paused' => $rpc->callBool($vault, 'paused()'),
                ];
            });
        }

        if ($minter !== null) {
            $minterCaps = Cache::remember('wtpix-bridge:minter:caps:'.$this->config->bscChainId().':'.$minter, 60, function () use ($minter, $fmt) {
                $rpc = $this->config->bscRpc();

                return [
                    'max_per_tx' => $fmt($rpc->callUint($minter, 'maxPerTx()')),
                    'daily_cap' => $fmt($rpc->callUint($minter, 'dailyCap()')),
                    'remaining_today' => $fmt($rpc->callUint($minter, 'remainingToday()')),
                    'paused' => $rpc->callBool($minter, 'paused()'),
                ];
            });
        }

        return ['vault' => $vaultCaps, 'minter' => $minterCaps];
    }

    /** chain id นี้เป็นต้นทางของสะพานนี้ไหม (4289 = ล็อก · BSC = เผา) */
    public function supportsChain(int $chainId): bool
    {
        return $chainId === $this->config->tpixChainId() || $chainId === $this->config->bscChainId();
    }

    /**
     * สถานะของธุรกรรมต้นทางหนึ่ง — จากฐานข้อมูลถ้าสแกนเจอแล้ว ไม่งั้นถามเชนสด.
     */
    public function status(int $chainId, string $txHash): array
    {
        $txHash = strtolower($txHash);
        $direction = $chainId === $this->config->tpixChainId() ? WtpixBridgeTransfer::TO_BSC : WtpixBridgeTransfer::TO_TPIX;
        $explorer = $this->config->explorer($chainId);

        $base = [
            'asset' => 'tpix',
            'direction' => $direction,
            'chain_id' => $chainId,
            'tx_hash' => $txHash,
            'source_url' => $explorer !== '' ? "{$explorer}/tx/{$txHash}" : null,
        ];

        $records = WtpixBridgeTransfer::where('source_chain_id', $chainId)->where('tx_hash', $txHash)->orderBy('log_index')->get();

        if ($records->isNotEmpty()) {
            $rows = $records->map(fn (WtpixBridgeTransfer $t) => $this->publicRow($t))->all();

            return $base + ['state' => $this->combinedState($records->all()), 'transfers' => $rows];
        }

        return $base + Cache::remember("wtpix-bridge:live:{$chainId}:{$txHash}", 15, fn () => $this->live($chainId, $direction, $txHash));
    }

    public function publicRow(WtpixBridgeTransfer $t): array
    {
        $destExplorer = $t->dest_chain_id ? $this->config->explorer((int) $t->dest_chain_id) : '';
        $row = $t->toPublicArray();

        return $row + [
            'dest_url' => $row['dest_tx_hash'] && $destExplorer !== '' ? "{$destExplorer}/tx/{$row['dest_tx_hash']}" : null,
        ];
    }

    /** สถานะรวมของหลาย log ในธุรกรรมเดียว — ตัวที่ยังไม่จบมีน้ำหนักกว่า */
    private function combinedState(array $records): string
    {
        $states = array_map(fn (WtpixBridgeTransfer $t) => $t->publicState(), $records);

        foreach (['review', 'awaiting_liquidity', 'queued', 'minting', 'releasing', 'rejected', 'minted', 'released', 'below_fee'] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return 'ignored';
    }

    private function live(int $chainId, string $direction, string $txHash): array
    {
        $rpc = $this->config->rpc($chainId);
        if (! $rpc) {
            return ['state' => 'unsupported_chain', 'transfers' => []];
        }

        $receipt = $rpc->receipt($txHash);
        if ($receipt === null) {
            $known = $rpc->knowsTransaction($txHash);

            return ['state' => $known === true ? 'pending' : ($known === null ? 'unknown' : 'not_found'), 'transfers' => []];
        }

        if (strtolower((string) ($receipt['status'] ?? '')) !== '0x1') {
            return ['state' => 'failed', 'transfers' => []];
        }

        $toBsc = $direction === WtpixBridgeTransfer::TO_BSC;
        $contract = $toBsc ? $this->config->vaultAddress() : $this->config->wtpixAddress();
        $amount = '0';

        foreach ((array) ($receipt['logs'] ?? []) as $log) {
            $topics = array_map('strtolower', (array) ($log['topics'] ?? []));
            if ($contract === null || strtolower((string) ($log['address'] ?? '')) !== $contract) {
                continue;
            }

            $match = $toBsc
                ? ($topics[0] ?? null) === Abi::topic(WtpixAbi::LOCKED_EVENT)
                : ($topics[0] ?? null) === Abi::topic(Abi::TRANSFER_EVENT) && ($topics[2] ?? null) === WtpixAbi::zeroTopic();

            if ($match) {
                $amount = bcadd($amount, Abi::decUint((string) ($log['data'] ?? '')) ?? '0', 0);
            }
        }

        if (bccomp($amount, '0', 0) === 0) {
            return ['state' => $toBsc ? 'not_a_lock' : 'not_a_burn', 'transfers' => []];
        }

        $block = (int) Wei::hexToInt((string) ($receipt['blockNumber'] ?? '0x0'));
        $latest = $rpc->blockNumber();

        if ($toBsc) {
            $safe = $latest !== null ? $latest - $this->config->tpixConfirmations() : null;
            $eta = (int) config('wtpix_bridge.bsc.eta_minutes', 2);
        } else {
            $bsc = $this->config->bsc();
            $safe = $rpc->safeHead((string) ($bsc['finality'] ?? 'finalized'), (int) ($bsc['min_confirmations'] ?? 15), (int) ($bsc['fallback_confirmations'] ?? 30));
            $eta = (int) config('wtpix_bridge.tpix.eta_minutes', 3);
        }

        return [
            'state' => 'confirming',
            'transfers' => [],
            'amount' => Wei::format($amount, 18),
            'block_number' => $block,
            'confirmations' => $latest !== null ? max(0, $latest - $block + 1) : null,
            // ถึงหัวที่ปลอดภัยแล้ว = รอตัวสแกนรอบถัดไป (ไม่เกิน ~1 นาที)
            'final' => $safe !== null && $block <= $safe,
            'eta_minutes' => $eta,
        ];
    }

    public function history(string $wallet): array
    {
        return [
            'transfers' => WtpixBridgeTransfer::forWallet($wallet)
                ->where(fn ($q) => $q->where('status', '!=', WtpixBridgeTransfer::STATUS_IGNORED)->orWhere('review_reason', '!=', 'dust'))
                ->latest('id')->limit(25)->get()
                ->map(fn (WtpixBridgeTransfer $t) => $this->publicRow($t))->all(),
        ];
    }

    public function reserves(): array
    {
        $snapshot = WtpixBridgeSnapshot::latest('id')->first();
        $info = $this->info();

        return [
            'asset' => 'tpix',
            'snapshot' => $snapshot?->toPublicArray(),
            'tpix' => $info['tpix'],
            'bsc' => $info['bsc'],
            'vault' => $info['vault'],
            'minter' => $info['minter'],
            'limits' => $info['limits'],
            'enabled' => $info['enabled'],
        ];
    }
}
