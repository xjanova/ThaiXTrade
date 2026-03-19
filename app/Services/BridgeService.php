<?php

namespace App\Services;

use App\Models\BridgeTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * TPIX TRADE — Bridge Service
 * จัดการ cross-chain bridge: TPIX Chain (4289) ↔ BSC (56)
 * lock/mint mechanism — fee 0.1% (min 1 TPIX).
 */
class BridgeService
{
    /**
     * เช็คว่า admin เปิดใช้ bridge หรือไม่.
     */
    public function isEnabled(): bool
    {
        $val = \App\Models\SiteSetting::get('trading', 'bridge_enabled');

        return $val === null || $val === true || $val === '1' || $val === 'true';
    }

    private const FEE_PERCENT = 0.001; // 0.1%

    private const MIN_FEE = 1; // 1 TPIX minimum

    private const MIN_AMOUNT = 10; // 10 TPIX minimum bridge

    private const MAX_AMOUNT = 10000000; // 10M max per tx

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
            'chains' => [
                ['id' => 56, 'name' => 'BSC', 'symbol' => 'WTPIX (BEP-20)'],
                ['id' => 4289, 'name' => 'TPIX Chain', 'symbol' => 'TPIX (Native)'],
            ],
            'estimated_time' => '2-5 minutes',
            'status' => 'active',
        ];
    }

    /**
     * คำนวณ fee.
     */
    public function calculateFee(string $amount): string
    {
        $fee = bcmul($amount, (string) self::FEE_PERCENT, 18);

        return bccomp($fee, (string) self::MIN_FEE, 18) < 0 ? (string) self::MIN_FEE : $fee;
    }

    /**
     * เริ่ม bridge transaction.
     */
    public function initiateBridge(string $wallet, string $amount, string $direction, ?string $txHash = null): BridgeTransaction
    {
        // ตรวจ amount
        if (bccomp($amount, (string) self::MIN_AMOUNT, 18) < 0) {
            throw new \InvalidArgumentException('Minimum bridge amount is '.self::MIN_AMOUNT.' TPIX');
        }

        if (bccomp($amount, (string) self::MAX_AMOUNT, 18) > 0) {
            throw new \InvalidArgumentException('Maximum bridge amount is '.number_format(self::MAX_AMOUNT).' TPIX');
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

    /**
     * ประวัติ bridge ของ wallet.
     */
    public function getHistory(string $wallet, int $limit = 20): Collection
    {
        return BridgeTransaction::byWallet($wallet)
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * สถิติ bridge ทั้งระบบ.
     */
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
