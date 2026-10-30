<?php

use App\Models\SaleTransaction;
use App\Models\TreasuryPayout;
use App\Support\Wei;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * รายการจ่ายจากออเดอร์โอนธนาคารที่สร้างก่อนแก้ C6 ไม่เคยถูกหักสิทธิ์ claim (ออดิท 2026-10-29)
 *
 * แถวที่ยังไม่จบ (รวม failed ที่อาจโอนไปแล้ว) ต้องนับเข้า claimed_amount ไม่งั้น /token-sale/claim
 * จ่ายยอดเดียวกันซ้ำได้ ส่วนแถวที่จบโดยเงินไม่ออกไม่เคยกินสิทธิ์ใคร จึงไม่แตะ
 *
 * initial_payout_wei เก็บยอดของแถวแรก ถ้าแถวนั้นจบโดยเงินไม่ออกทีหลัง กดจ่ายใหม่จะได้ยอดเดิมโดยไม่หักซ้ำ
 *
 * ออเดอร์ที่มีหลายแถวคือเหรียญถูกตั้งจ่ายซ้ำจริง: หักทุกแถว (ไม่เกินยอดซื้อ) เพื่อปิดทาง claim
 * แล้วเตือนไว้ ไม่ปฏิเสธแถวให้เองเพราะแถวไหนควรอยู่เป็นการตัดสินของคน
 * ยอดของแถวที่ปฏิเสธทีหลังไม่คืนเอง คนที่ปฏิเสธต้องหักออกจาก claimed_amount ด้วยมือ
 * ที่ไม่ทำทางคืนอัตโนมัติเพราะแถวซ้ำแบบนี้เกิดใหม่ไม่ได้แล้ว (คีย์ใหม่ผูกกับออเดอร์)
 * โค้ดลด claimed_amount ในทางจ่ายเงินที่ไม่มีวันได้ใช้จึงเสี่ยงกว่าประโยชน์ที่ได้
 *
 * prod ยังไม่มีผู้ซื้อ จึงไม่มีแถวให้ทำตอนนี้ แต่ต้องปลอดภัยถ้ามีแถวขึ้นมาก่อน deploy
 */
return new class() extends Migration
{
    private const KEY_PREFIX = 'sale-bank-';

    private const UUID_LENGTH = 36;

    public function up(): void
    {
        TreasuryPayout::where('idempotency_key', 'like', self::KEY_PREFIX.'%')
            ->orderBy('id')
            ->get()
            ->reject(fn (TreasuryPayout $payout) => $payout->endedWithoutPayment())
            ->groupBy(fn (TreasuryPayout $payout) => substr($payout->idempotency_key, strlen(self::KEY_PREFIX), self::UUID_LENGTH))
            ->each(fn (Collection $payouts, string $uuid) => DB::transaction(fn () => $this->countAsClaimed($uuid, $payouts)));
    }

    public function down(): void
    {
        // ไม่ย้อน — แยกไม่ได้แล้วว่า claimed_amount ส่วนไหนมาจาก migration นี้ และคืนสิทธิ์ผิด = จ่ายซ้ำ
    }

    private function countAsClaimed(string $uuid, Collection $payouts): void
    {
        $tx = SaleTransaction::withTrashed()->where('uuid', $uuid)->lockForUpdate()->first();

        if (! $tx) {
            return;
        }

        $queued = Wei::format(Wei::sum($payouts->pluck('amount_wei')->all()));
        $claimed = bcadd((string) $tx->claimed_amount, $queued, 18);
        $purchased = (string) $tx->tpix_amount;

        $tx->update([
            'claimed_amount' => bccomp($claimed, $purchased, 18) > 0 ? $purchased : $claimed,
            'metadata' => array_merge($tx->metadata ?? [], ['initial_payout_wei' => (string) $payouts->first()->amount_wei]),
        ]);

        if ($payouts->count() > 1) {
            Log::warning('token-sale: ออเดอร์โอนมีรายการจ่ายครั้งแรกซ้ำ — ปฏิเสธแถวที่เกิน แล้วหักยอดแถวนั้นออกจาก claimed_amount ด้วยมือ', [
                'uuid' => $uuid,
                'payout_ids' => $payouts->pluck('id')->all(),
            ]);
        }
    }
};
