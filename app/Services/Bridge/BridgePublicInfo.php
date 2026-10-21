<?php

namespace App\Services\Bridge;

use App\Models\BridgeDeposit;
use App\Models\BridgeReserveSnapshot;
use App\Models\BridgeWithdrawal;
use App\Support\Wei;
use Illuminate\Support\Facades\Cache;

/**
 * ข้อมูลสาธารณะของสะพาน — หน้า /bridge, /bridge/reserves และ API อ่านจากที่นี่ที่เดียว.
 *
 * ทุกอย่างอ่านอย่างเดียว ไม่มีอะไรในนี้เขียนฐานข้อมูลหรือเซ็นธุรกรรม
 * ค่าที่ต้องยิง RPC ถูกแคชสั้น ๆ — คนเปิดหน้าพร้อมกันร้อยคนต้องไม่กลายเป็น RPC ร้อยครั้ง
 */
class BridgePublicInfo
{
    public function __construct(private BridgeConfig $config) {}

    /** สะพานเปิดให้ฝาก/ถอนจากหน้าเว็บไหม — ต้องติดตั้ง + สวิตช์เปิด + มีสัญญาครบ */
    public function open(): bool
    {
        return $this->config->operational()
            && $this->config->usdtTpixAddress() !== null
            && $this->config->minterAddress() !== null;
    }

    public function info(): array
    {
        return Cache::remember('bridge:info:public', 30, function () {
            $chains = [];

            foreach ($this->config->chains() as $chainId => $chain) {
                $chains[] = [
                    'chain_id' => $chainId,
                    'name' => $chain['name'],
                    'short' => $chain['short'] ?? $chain['name'],
                    'native' => $chain['native'] ?? 'ETH',
                    'enabled' => $this->config->chainEnabled($chainId),
                    'usdt_address' => $chain['usdt']['address'],
                    'usdt_decimals' => (int) $chain['usdt']['decimals'],
                    'usdt_symbol' => $chain['usdt']['symbol'] ?? 'USDT',
                    'explorer' => $this->config->explorer($chainId),
                    'withdraw_fee' => $this->config->withdrawFee($chainId),
                    'eta_minutes' => (int) ($chain['eta_minutes'] ?? 10),
                ];
            }

            $caps = $this->minterCaps();
            $onchainMax = $caps['max_per_tx'] !== null ? Wei::toWei((string) $caps['max_per_tx'], 6) : null;

            return [
                'enabled' => $this->open(),
                'installed' => $this->config->installed(),
                // รหัสกลาง ๆ เท่านั้น (maintenance / safety_hold) — ข้อความเต็มมีชื่อแอดมินและบันทึกเหตุการณ์
                'paused_reason' => $this->config->publicPausedCode(),
                'vault_address' => $this->config->vaultAddress(),
                // ฝากต่อครั้งได้สูงสุดเท่านี้จริง = min(วงเงินของเว็บ, maxPerTx บนสัญญา) — เกินนี้ mint ไม่ได้เลย
                'deposit_max_per_tx' => Wei::format($this->config->effectiveMaxDepositUnits($onchainMax), 6),
                'tpix' => [
                    'chain_id' => $this->config->tpixChainId(),
                    'usdt_tpix' => $this->config->usdtTpixAddress(),
                    'usdt_decimals' => 6,
                    'explorer' => $this->config->explorer($this->config->tpixChainId()),
                ],
                'minter_address' => $this->config->minterAddress(),
                'minter' => $caps,
                'chains' => $chains,
                'limits' => $this->config->limits(),
                'trade_url' => '/trade?pair=TPIX-USDT',
            ];
        });
    }

    /** เพดานบนสัญญา (อ่านสด แคช 60 วิ) — null ทุกช่องถ้ายังไม่ deploy หรืออ่านไม่ได้ */
    public function minterCaps(): array
    {
        $minter = $this->config->minterAddress();
        $empty = ['max_per_tx' => null, 'daily_cap' => null, 'remaining_today' => null, 'paused' => null];

        if ($minter === null) {
            return $empty;
        }

        return Cache::remember('bridge:minter:caps:'.$minter, 60, function () use ($minter) {
            $rpc = $this->config->tpixRpc();
            $fmt = fn (?string $v) => $v === null ? null : Wei::format($v, 6);

            return [
                'max_per_tx' => $fmt($rpc->callUint($minter, 'maxPerTx()')),
                'daily_cap' => $fmt($rpc->callUint($minter, 'dailyCap()')),
                'remaining_today' => $fmt($rpc->callUint($minter, 'remainingToday()')),
                'paused' => $rpc->callBool($minter, 'paused()'),
            ];
        });
    }

    /**
     * สถานะเงินฝากของธุรกรรมหนึ่ง — จากฐานข้อมูลถ้าสแกนเจอแล้ว ไม่งั้นถามเชนสด (ยังไม่ final).
     */
    public function depositStatus(int $chainId, string $txHash): array
    {
        $txHash = strtolower($txHash);
        $records = BridgeDeposit::where('source_chain_id', $chainId)->where('tx_hash', $txHash)->orderBy('log_index')->get();
        $explorer = $this->config->explorer($chainId);
        $tpixExplorer = $this->config->explorer($this->config->tpixChainId());

        $base = [
            'chain_id' => $chainId,
            'tx_hash' => $txHash,
            'source_url' => $explorer !== '' ? "{$explorer}/tx/{$txHash}" : null,
        ];

        if ($records->isNotEmpty()) {
            $deposits = $records->map(fn (BridgeDeposit $d) => $d->toPublicArray() + [
                'mint_url' => $d->mint_tx_hash && in_array($d->status, [BridgeDeposit::STATUS_MINTING, BridgeDeposit::STATUS_MINTED], true)
                    ? "{$tpixExplorer}/tx/{$d->mint_tx_hash}" : null,
            ])->all();

            return $base + ['state' => $this->depositState($records->pluck('status')->all()), 'deposits' => $deposits];
        }

        // เชนที่ปิดอยู่ไม่ถูกสแกน — เดิมตอบ "final แล้ว รอเข้าคิว ~1 นาที" ตลอดไปทั้งที่ไม่มีวันถูก mint
        // ไม่ยิง RPC ด้วย (ไม่มีอะไรให้รอ และกันใช้ endpoint นี้ยิง RPC ของเชนที่ไม่ได้ใช้)
        if (! $this->config->chainEnabled($chainId)) {
            // เคยเปิดแล้วถูกหยุดชั่วคราว = เคอร์เซอร์ค้างอยู่ เปิดใหม่เมื่อไหร่ตัวสแกนไล่ต่อจากจุดเดิมและบันทึกให้เอง
            // ไม่เคยเปิดเลย = เปิดทีหลังก็เริ่มที่หัวปัจจุบัน รายการนี้จะไม่ถูกสแกน ต้องขอคืนเงินจาก vault ด้วยมือ
            $paused = $this->config->cursor("deposit_{$chainId}") !== null;

            return $base + ['state' => $paused ? 'chain_paused' : 'chain_disabled', 'deposits' => [], 'refund' => ! $paused];
        }

        return $base + Cache::remember("bridge:live:deposit:{$chainId}:{$txHash}", 15, fn () => $this->liveDeposit($chainId, $txHash));
    }

    /** สถานะรวมของหลาย log ในธุรกรรมเดียว — ตัวที่ยังไม่จบมีน้ำหนักกว่า */
    private function depositState(array $statuses): string
    {
        foreach ([
            BridgeDeposit::STATUS_NEEDS_REVIEW => 'review',
            BridgeDeposit::STATUS_CONFIRMED => 'queued',
            BridgeDeposit::STATUS_MINTING => 'minting',
            BridgeDeposit::STATUS_REJECTED => 'rejected',
            BridgeDeposit::STATUS_MINTED => 'minted',
        ] as $status => $state) {
            if (in_array($status, $statuses, true)) {
                return $state;
            }
        }

        return 'ignored';
    }

    private function liveDeposit(int $chainId, string $txHash): array
    {
        $chain = $this->config->chain($chainId);
        $rpc = $this->config->rpc($chainId);

        if (! $chain || ! $rpc) {
            return ['state' => 'unsupported_chain', 'deposits' => []];
        }

        $receipt = $rpc->receipt($txHash);

        if ($receipt === null) {
            $known = $rpc->knowsTransaction($txHash);

            return ['state' => $known === true ? 'pending' : ($known === null ? 'unknown' : 'not_found'), 'deposits' => []];
        }

        if (strtolower((string) ($receipt['status'] ?? '')) !== '0x1') {
            return ['state' => 'failed', 'deposits' => []];
        }

        $token = strtolower((string) $chain['usdt']['address']);
        $decimals = (int) $chain['usdt']['decimals'];
        $vault = $this->config->vaultAddress();
        $amount = '0';

        foreach ((array) ($receipt['logs'] ?? []) as $log) {
            $topics = array_map('strtolower', (array) ($log['topics'] ?? []));
            if (strtolower((string) ($log['address'] ?? '')) === $token
                && ($topics[0] ?? null) === Abi::topic(Abi::TRANSFER_EVENT)
                && Abi::topicAddress($topics[2] ?? null) === $vault) {
                $amount = bcadd($amount, Abi::toUnits6(Wei::hexToInt((string) ($log['data'] ?? '0x0')), $decimals)['units'], 0);
            }
        }

        if (bccomp($amount, '0', 0) === 0) {
            return ['state' => 'not_a_deposit', 'deposits' => []];
        }

        $block = (int) Wei::hexToInt((string) ($receipt['blockNumber'] ?? '0x0'));
        $latest = $rpc->blockNumber();
        $safe = $rpc->safeHead((string) ($chain['finality'] ?? 'confirmations'), (int) ($chain['min_confirmations'] ?? 12), (int) ($chain['fallback_confirmations'] ?? 64));

        return [
            'state' => 'confirming',
            'deposits' => [],
            'amount' => Wei::format($amount, 6),
            'block_number' => $block,
            'confirmations' => $latest !== null ? max(0, $latest - $block + 1) : null,
            // ถึงหัวที่ปลอดภัยแล้ว = รอตัวสแกนรอบถัดไป (ไม่เกิน ~1 นาที)
            'final' => $safe !== null && $block <= $safe,
            'eta_minutes' => (int) ($chain['eta_minutes'] ?? 10),
        ];
    }

    public function withdrawalStatus(string $txHash): array
    {
        $txHash = strtolower($txHash);
        $tpixExplorer = $this->config->explorer($this->config->tpixChainId());
        $records = BridgeWithdrawal::where('tx_hash', $txHash)->orderBy('log_index')->get();

        $base = ['tx_hash' => $txHash, 'burn_url' => "{$tpixExplorer}/tx/{$txHash}"];

        if ($records->isNotEmpty()) {
            $rows = $records->map(function (BridgeWithdrawal $w) {
                $explorer = $w->target_chain_id ? $this->config->explorer((int) $w->target_chain_id) : '';

                return $w->toPublicArray() + [
                    'payout_url' => $w->payout_tx_hash && $explorer !== '' && in_array($w->status, [BridgeWithdrawal::STATUS_SENDING, BridgeWithdrawal::STATUS_PAID], true)
                        ? "{$explorer}/tx/{$w->payout_tx_hash}" : null,
                ];
            })->all();

            $statuses = $records->pluck('status')->all();
            $state = match (true) {
                in_array(BridgeWithdrawal::STATUS_NEEDS_REVIEW, $statuses, true) => 'review',
                in_array(BridgeWithdrawal::STATUS_AWAITING_LIQUIDITY, $statuses, true) => 'awaiting_liquidity',
                in_array(BridgeWithdrawal::STATUS_PENDING, $statuses, true) => 'queued',
                in_array(BridgeWithdrawal::STATUS_SENDING, $statuses, true) => 'sending',
                in_array(BridgeWithdrawal::STATUS_REJECTED, $statuses, true) => 'rejected',
                in_array(BridgeWithdrawal::STATUS_IGNORED, $statuses, true) => 'below_fee',
                default => 'paid',
            };

            return $base + ['state' => $state, 'withdrawals' => $rows];
        }

        return $base + Cache::remember("bridge:live:burn:{$txHash}", 10, function () use ($txHash) {
            $rpc = $this->config->tpixRpc();
            $receipt = $rpc->receipt($txHash);

            if ($receipt === null) {
                $known = $rpc->knowsTransaction($txHash);

                return ['state' => $known === true ? 'pending' : ($known === null ? 'unknown' : 'not_found'), 'withdrawals' => []];
            }

            if (strtolower((string) ($receipt['status'] ?? '')) !== '0x1') {
                return ['state' => 'failed', 'withdrawals' => []];
            }

            $token = $this->config->usdtTpixAddress();
            foreach ((array) ($receipt['logs'] ?? []) as $log) {
                if ($token && strtolower((string) ($log['address'] ?? '')) === $token
                    && strtolower((string) ($log['topics'][0] ?? '')) === Abi::topic(Abi::BURNED_EVENT)) {
                    return ['state' => 'confirming', 'withdrawals' => []];
                }
            }

            return ['state' => 'not_a_withdrawal', 'withdrawals' => []];
        });
    }

    public function history(string $wallet): array
    {
        return [
            // ต่ำกว่าขั้นต่ำต้องเห็นในประวัติด้วย (เงินเข้า vault แล้ว ผู้ใช้ต้องรู้ว่าต้องติดต่อขอคืน)
            // ส่วน ignored อื่น (โอนภายใน / ฝุ่น) ไม่ใช่เงินฝากของผู้ใช้ ไม่โชว์
            'deposits' => BridgeDeposit::forWallet($wallet)
                ->where(fn ($q) => $q->where('status', '!=', BridgeDeposit::STATUS_IGNORED)->orWhere('review_reason', 'below_minimum'))
                ->latest('id')->limit(25)->get()
                ->map(fn (BridgeDeposit $d) => $d->toPublicArray())->all(),
            'withdrawals' => BridgeWithdrawal::forWallet($wallet)
                ->latest('id')->limit(25)->get()
                ->map(fn (BridgeWithdrawal $w) => $w->toPublicArray())->all(),
        ];
    }

    public function reserves(): array
    {
        $snapshot = BridgeReserveSnapshot::latest('id')->first();
        $info = $this->info();

        return [
            'snapshot' => $snapshot?->toPublicArray(),
            'vault_address' => $info['vault_address'],
            'payout_address' => $this->config->signerAddress('payout'),
            'minter_address' => $info['minter_address'],
            'minter' => $info['minter'],
            'tpix' => $info['tpix'],
            'chains' => $info['chains'],
            'limits' => $info['limits'],
            'enabled' => $info['enabled'],
        ];
    }
}
