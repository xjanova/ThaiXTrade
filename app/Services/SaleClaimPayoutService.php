<?php

namespace App\Services;

use App\Models\SaleTransaction;
use App\Models\TreasuryPayout;
use App\Support\Wei;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * คิวจ่ายของ /token-sale/claim และการกู้ยอดของแถวที่จบโดยเงินไม่ออก.
 *
 * claimed_amount แปลว่า "เข้าคิวจ่ายแล้ว" ไม่ใช่ "จ่ายแล้วบนเชน" แถวที่ถูกปฏิเสธหรือ revert
 * จึงทำให้เหรียญค้าง: ยอดยังกินสิทธิ์ claim อยู่ แต่ไม่มีอะไรจะจ่ายออก
 *
 * ทุกเส้นทางที่แตะยอดต้องถือล็อกแถว sale_transactions และเปลี่ยน claimed_amount
 * ในทรานแซกชันเดียวกับแถวจ่าย — แถวจ่ายของออเดอร์เดียวกันต้องไม่จ่ายเหรียญก้อนเดียวกันสองรอบ
 */
class SaleClaimPayoutService
{
    /** คำนำหน้า idempotency key ต่อด้วย uuid ของออเดอร์ แล้วตามด้วยลำดับ claim หรือ -retry-id ของแถวที่แทน */
    private const KEY_PREFIX = 'sale-claim-';

    public function __construct(private readonly TreasuryService $treasury) {}

    /**
     * เข้าคิวจ่ายของ claim หนึ่งครั้ง — ผู้เรียกต้องถือล็อกแถวนี้และบวก claimed_amount ไว้แล้ว.
     *
     * คีย์ใช้ลำดับของแถว ไม่ใช้ยอดสะสม: ยอดที่คืนสิทธิ์ทำให้ยอดสะสมวนกลับมาซ้ำได้
     * ถ้าใช้ยอดสะสม claim ใหม่จะได้แถวเก่าที่ตายแล้วคืนมาแทน = บวก claimed_amount แต่ไม่มีแถวจ่าย
     * คีย์ชนจึงต้องล้มทั้งทรานแซกชัน (create) ไม่ใช่คืนแถวเดิมเงียบ ๆ (firstOrCreate)
     */
    public function queueClaim(SaleTransaction $tx, string $amountWei): TreasuryPayout
    {
        $key = self::KEY_PREFIX.$tx->uuid.'-'.($this->payouts($tx)->count() + 1);

        return $this->createPayout($tx, $key, $amountWei, 'claim vesting จากรายการซื้อ '.$tx->uuid);
    }

    /**
     * กู้ยอดของแถวที่จบโดยเงินไม่ออก — ได้แถวแทนแถวละหนึ่งแถว ยังต้องรออนุมัติที่หน้าคลังตามปกติ.
     *
     * ยอดยังอยู่ใน claimed_amount จึงไม่บวกซ้ำ และผู้ซื้อ claim ก้อนเดิมซ้ำไม่ได้ระหว่างรอ
     * ยกเว้นส่วนที่เกินวงเงินต่อครั้งของปลายทาง: ส่งยอดเดิมก็ตก validatePayout เหมือนเดิม
     * จึงเข้าคิวเต็มวงเงิน แล้วคืนส่วนเกินเป็นสิทธิ์ให้ผู้ซื้อแบ่ง claim เอง
     *
     * @return Collection<int, TreasuryPayout> แถวที่สร้างใหม่ — ว่าง = ไม่มีอะไรให้กู้
     */
    public function requeueDeadPayouts(SaleTransaction $tx, ?int $requestedBy = null): Collection
    {
        return DB::transaction(function () use ($tx, $requestedBy) {
            // ล็อกแถวเดียวกับ /token-sale/claim — ทุกทางหักสิทธิ์จากยอดเดียวกัน ต้องเข้าทีละทาง
            $locked = SaleTransaction::whereKey($tx->id)->lockForUpdate()->firstOrFail();

            if (! $this->isPayableOrder($locked)) {
                return new Collection();
            }

            return $this->deadPayouts($locked)
                ->map(fn (TreasuryPayout $dead) => $this->replace($locked, $dead, $requestedBy));
        });
    }

    /**
     * ยอดที่จะเข้าคิวและยอดที่จะคืนสิทธิ์ถ้าแอดมินกดตอนนี้ — null = ไม่มีอะไรให้กด.
     *
     * หน้าแอดมินใช้แสดงปุ่มและบอกยอดก่อนกดเท่านั้น การกู้จริงตัดสินใหม่ใต้ล็อกใน requeueDeadPayouts
     *
     * @return array{amount_tpix: string, returned_tpix: ?string}|null
     */
    public function requeueOffer(SaleTransaction $tx): ?array
    {
        $dead = $this->isPayableOrder($tx) ? $this->deadPayouts($tx) : new Collection();

        if ($dead->isEmpty()) {
            return null;
        }

        $amountWei = Wei::sum($dead->map(fn (TreasuryPayout $p) => $this->replacementWei($tx, $p))->all());
        $returnedWei = bcsub(Wei::sum($dead->pluck('amount_wei')->all()), $amountWei, 0);

        return [
            'amount_tpix' => Wei::format($amountWei),
            'returned_tpix' => bccomp($returnedWei, '0', 0) > 0 ? Wei::format($returnedWei) : null,
        ];
    }

    /** route กู้ยอดรับ id ใดก็ได้ — ออเดอร์ที่คืนเงินไปแล้วถ้าผ่านด่านนี้ ผู้ซื้อจะได้ทั้งเงินคืนและเหรียญ */
    private function isPayableOrder(SaleTransaction $tx): bool
    {
        return $tx->status === 'confirmed';
    }

    private function replace(SaleTransaction $tx, TreasuryPayout $dead, ?int $requestedBy): TreasuryPayout
    {
        $amountWei = $this->replacementWei($tx, $dead);
        $returnedWei = bcsub((string) $dead->amount_wei, $amountWei, 0);
        $memo = "เข้าคิวใหม่แทนรายการจ่าย #{$dead->id} ของรายการซื้อ {$tx->uuid}";

        if (bccomp($returnedWei, '0', 0) > 0) {
            $returned = Wei::format($returnedWei);
            $tx->update(['claimed_amount' => bcsub((string) $tx->claimed_amount, $returned, 18)]);
            $memo .= " · คืนสิทธิ์ claim {$returned} TPIX ส่วนที่เกินวงเงินต่อครั้ง";
        }

        return $this->createPayout($tx, $this->replacementKey($tx, $dead), $amountWei, $memo, $requestedBy);
    }

    /**
     * แถวที่พิสูจน์ได้ว่าเงินไม่ออกและยังไม่มีแถวแทน.
     *
     * แถวเก่าใช้คีย์ sale-claim-{uuid}-{ยอดสะสม} จึงจับด้วยคำนำหน้าเท่านั้น
     * ต้องกรอง origin ด้วยเพราะแอดมินกรอก idempotency_key เองได้ และแถวแทนได้ยกเว้น whitelist
     *
     * @return Collection<int, TreasuryPayout>
     */
    private function deadPayouts(SaleTransaction $tx): Collection
    {
        $payouts = $this->payouts($tx)
            ->where('origin', TreasuryPayout::ORIGIN_SALE_CLAIM)
            ->orderBy('id')
            ->get();

        $keys = $payouts->pluck('idempotency_key')->flip();

        return $payouts->filter(fn (TreasuryPayout $p) => $p->endedWithoutPayment()
            && ! $keys->has($this->replacementKey($tx, $p)))->values();
    }

    /** ยอดของแถวแทน — ไม่เกินวงเงินต่อครั้งของปลายทางนี้ ไม่งั้นได้แถวที่ตกกฎเดิมอีกรอบ */
    private function replacementWei(SaleTransaction $tx, TreasuryPayout $dead): string
    {
        $amountWei = (string) $dead->amount_wei;
        $maxPerTxWei = $this->treasury->maxPerTxWei($tx->wallet_address);

        return bccomp($maxPerTxWei, '0', 0) > 0 && bccomp($amountWei, $maxPerTxWei, 0) > 0
            ? $maxPerTxWei
            : $amountWei;
    }

    /** ผูกกับ id ของแถวตาย — unique index จึงกันแถวแทนซ้ำได้แม้ล็อกพลาด */
    private function replacementKey(SaleTransaction $tx, TreasuryPayout $dead): string
    {
        return self::KEY_PREFIX.$tx->uuid.'-retry-'.$dead->id;
    }

    /** @return Builder<TreasuryPayout> */
    private function payouts(SaleTransaction $tx): Builder
    {
        return TreasuryPayout::where('idempotency_key', 'like', self::KEY_PREFIX.$tx->uuid.'-%');
    }

    private function createPayout(SaleTransaction $tx, string $key, string $amountWei, string $memo, ?int $requestedBy = null): TreasuryPayout
    {
        return TreasuryPayout::create([
            'idempotency_key' => $key,
            'to_address' => $tx->wallet_address,
            'amount_wei' => $amountWei,
            'purpose' => 'token_sale',
            // ระบบสร้างจาก claim จริง — ยกเว้น whitelist ได้เพราะปลายทางผูกกับรายการซื้อ
            'origin' => TreasuryPayout::ORIGIN_SALE_CLAIM,
            'memo' => $memo,
            'status' => TreasuryPayout::STATUS_PENDING,
            'requested_by' => $requestedBy,
        ]);
    }
}
