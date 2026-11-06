<?php

namespace App\Services\Launchpad;

use App\Models\BridgeDeposit;
use App\Models\LaunchpadOrder;
use App\Models\LaunchpadSale;
use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\EvmRpc;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * จับคู่เงินฝากสะพาน USDT กับคำสั่งซื้อ Launchpad — ตัวสแกนของสะพานเรียกก่อนบันทึกเงินฝากทุกรายการ.
 *
 * จับคู่เมื่อครบทุกข้อเท่านั้น (ข้อใดไม่ครบ = เงินฝากปกติ mint ให้ผู้ฝาก ไม่มีเงินหาย):
 *   - Launchpad เปิด · มีกุญแจตัวแทนขาย · เชนต้นทางอยู่ในรายการที่รับ
 *   - ด่านของสะพานให้ผ่าน (confirmed) — ยอดที่ต้องให้คนดูไม่ถูกจับคู่ (อนุมัติแล้ว mint ให้ผู้ฝากเหมือนเดิม)
 *   - มีคำสั่งรอเงินของกระเป๋าผู้ฝากเอง (ลายเซ็นของเขา) เชนเดียวกัน ยอดเป๊ะเท่ากัน หน้าขายยังอนุมัติอยู่
 *   - เวลาของบล็อกที่ฝากอยู่ในช่วงของคำสั่ง (สร้างคำสั่ง − 60 วิ … หมดเวลา) — ยอดเก่าที่บังเอิญเท่ากันไม่ถูกดึงไปซื้อ
 */
class LaunchpadDepositMatcher
{
    /** สถานะที่ยังรับเงินได้ (ยกเลิกเอง = ไม่รับแล้ว เงินที่มาทีหลังเป็นเงินฝากปกติ) */
    public const MATCHABLE = [LaunchpadOrder::STATUS_AWAITING, LaunchpadOrder::STATUS_EXPIRED];

    public function __construct(private BridgeConfig $config) {}

    public function agentAddress(): ?string
    {
        $address = $this->config->signerAddress('launchpad_agent');

        return $address ? strtolower($address) : null;
    }

    /** เปิดรับคำสั่งซื้อจากเชนนี้ได้ไหม (ยังไม่ดูหน้าขาย) */
    public function acceptsChain(int $chainId): bool
    {
        return (bool) config('launchpad.enabled')
            && in_array($chainId, (array) config('launchpad.source_chains', []), true)
            && $this->agentAddress() !== null;
    }

    /**
     * คำสั่งที่ยอดฝากนี้ควรไปเติม.
     *
     * @return array{state: 'none'}|array{state: 'unreadable'}|array{state: 'order', order: LaunchpadOrder}
     */
    public function candidate(int $chainId, string $from, string $units, int $blockNumber, EvmRpc $rpc): array
    {
        if (! $this->acceptsChain($chainId)) {
            return ['state' => 'none'];
        }

        // expired ด้วย: ผู้ซื้อโอนทันเวลาแต่ตัวสแกนตามหลัง (RPC ล่มนาน) — เวลาบล็อกเป็นตัวตัดสิน ไม่ใช่เวลาที่เราเห็น
        $orders = LaunchpadOrder::query()
            ->whereIn('status', self::MATCHABLE)
            ->where('buyer_address', strtolower($from))
            ->where('source_chain_id', $chainId)
            ->where('amount_units', $units)
            ->whereHas('sale', fn ($q) => $q->where('status', LaunchpadSale::STATUS_APPROVED))
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            return ['state' => 'none'];
        }

        // เวลาจริงของบล็อกบนเชนต้นทาง — อ่านไม่ได้ = ยังไม่ตัดสิน (ตัวสแกนไม่ขยับเคอร์เซอร์ รอบหน้าลองใหม่)
        $timestamp = $rpc->blockTimestamp($blockNumber);
        if ($timestamp === null) {
            return ['state' => 'unreadable'];
        }

        $at = Carbon::createFromTimestamp($timestamp);
        $early = max(0, (int) config('launchpad.match_early_seconds', 60));

        foreach ($orders as $order) {
            if ($at->greaterThanOrEqualTo($order->created_at->copy()->subSeconds($early))
                && $at->lessThanOrEqualTo($order->expires_at)) {
                return ['state' => 'order', 'order' => $order];
            }
        }

        return ['state' => 'none'];
    }

    /**
     * บันทึกเงินฝากพร้อมผูกกับคำสั่ง — ในธุรกรรมฐานข้อมูลเดียว (ไม่มีทางได้แค่ครึ่งเดียว).
     *
     * คำสั่งเปลี่ยนสถานะไปแล้วระหว่างนั้น (ผู้ซื้อยกเลิก/หมดเวลาพอดี) = บันทึกเป็นเงินฝากปกติ
     */
    public function recordMatched(LaunchpadOrder $candidate, array $attributes): BridgeDeposit
    {
        $agent = $this->agentAddress();

        return DB::transaction(function () use ($candidate, $attributes, $agent) {
            $order = LaunchpadOrder::whereKey($candidate->id)->lockForUpdate()->first();

            if (! $order || ! in_array($order->status, self::MATCHABLE, true) || $agent === null) {
                return BridgeDeposit::create($attributes);
            }

            $deposit = BridgeDeposit::create($attributes + [
                'mint_to' => $agent,
                'launchpad_order_id' => $order->id,
            ]);

            $order->update([
                'status' => LaunchpadOrder::STATUS_PAID,
                'bridge_deposit_id' => $deposit->id,
                'pay_units' => (string) $deposit->mint_units,
                'paid_at' => now(),
                'settled_at' => null,
                'last_error' => null,
            ]);

            Log::info('launchpad: จับคู่เงินฝากกับคำสั่งซื้อ', [
                'order' => $order->public_id,
                'deposit' => $deposit->id,
                'units' => (string) $deposit->mint_units,
            ]);

            return $deposit;
        });
    }
}
