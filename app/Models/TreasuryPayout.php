<?php

namespace App\Models;

use App\Services\TokenFactory\TokenFactoryRefunds;
use App\Support\Wei;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * รายการจ่ายเงินจากกระเป๋าร้อน.
 *
 * สถานะเดินทางเดียว: pending -> approved -> broadcasting -> confirmed
 * แตกออกได้ที่ rejected (คนปฏิเสธ) และ failed (เชนไม่รับ / เซ็นไม่ผ่าน)
 *
 * failed ต้องมีหลักฐานว่าเงินไม่ได้ออกเสมอ: ยังไม่เคยมี tx_hash หรือใบเสร็จบอกว่า revert
 * แถวที่มี hash แล้วแต่ส่งไม่ยืนยันผลคงเป็น broadcasting จนกว่าเชนจะตอบ (ดู tpix:treasury-payouts)
 *
 * `amount_wei` เป็น string เสมอ ห้ามแปลงเป็น int/float ที่ไหนก็ตาม
 */
class TreasuryPayout extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_BROADCASTING = 'broadcasting';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    /** แอดมินกรอกเองในหน้าคลัง — ต้องผ่าน whitelist เสมอ ไม่ว่าจะเลือก purpose อะไร */
    public const ORIGIN_ADMIN = 'admin';

    /** ระบบสร้างจาก /token-sale/claim — ปลายทางผูกกับกระเป๋าของรายการซื้อที่ยืนยันแล้ว */
    public const ORIGIN_SALE_CLAIM = 'sale_claim';

    /** ระบบสร้างตอนแอดมินยืนยันออเดอร์โอนธนาคาร — ปลายทางคือกระเป๋าที่ผู้ซื้อผูกไว้กับออเดอร์ */
    public const ORIGIN_SALE_BANK = 'sale_bank';

    /** ระบบสร้างตอนคืนค่าธรรมเนียมสร้างเหรียญ — ปลายทางคือกระเป๋าที่โอนค่าธรรมเนียมเข้ามา */
    public const ORIGIN_FACTORY_REFUND = 'factory_refund';

    /**
     * origin ที่ยกเว้น whitelist ได้ — ปลายทางมาจากรายการที่ระบบตรวจแล้ว ไม่ได้มาจากช่องกรอก
     * ห้ามเพิ่ม ORIGIN_ADMIN ที่นี่: รหัสแอดมินหลุดหนึ่งครั้ง = โอนไปที่ไหนก็ได้.
     */
    public const WHITELIST_EXEMPT_ORIGINS = [
        self::ORIGIN_SALE_CLAIM,
        self::ORIGIN_SALE_BANK,
        self::ORIGIN_FACTORY_REFUND,
    ];

    /**
     * คีย์ที่จองไว้ให้แถวที่ระบบสร้าง — รายการกรอกมือที่ใช้คีย์เหล่านี้จะชน unique index ตอน claim หรือกู้ยอด
     * ไม่สนตัวพิมพ์เพราะ collation ของ MySQL ก็ไม่สน.
     */
    public const SYSTEM_KEY_PATTERN = '/^(sale-claim-|sale-bank-|token_factory_refund:)/i';

    /** สถานะที่ถือว่า "ยังกินวงเงินอยู่" ตอนคำนวณยอดใช้ไปต่อวัน */
    public const COUNTS_TOWARD_LIMIT = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_BROADCASTING,
        self::STATUS_CONFIRMED,
    ];

    protected $fillable = [
        'idempotency_key',
        'to_address',
        'amount_wei',
        'purpose',
        'origin',
        'memo',
        'status',
        'tx_hash',
        'raw_tx',
        'nonce',
        'from_address',
        'claimed_at',
        'claim_token',
        'block_number',
        'confirmations',
        'failure_reason',
        'last_error',
        'hot_balance_at_approval_wei',
        'requested_by',
        'approved_by',
        'approved_at',
        'broadcast_at',
        'confirmed_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'broadcast_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'claimed_at' => 'datetime',
        'confirmations' => 'integer',
        'nonce' => 'integer',
    ];

    /** ธุรกรรมที่เซ็นแล้วกับตัวจองแถวไม่ต้องไหลไปหน้าจอ — ใช้แค่ฝั่ง CLI */
    protected $hidden = ['raw_tx', 'claim_token'];

    protected $appends = ['amount_tpix'];

    /**
     * คืนค่าธรรมเนียมสร้างเหรียญจ่ายผ่านคิวนี้ — ผลของคิว (สำเร็จ/ล้มเหลว) ต้องไหลกลับไปที่แถวเหรียญ
     * ไม่ว่าสถานะจะเปลี่ยนจากที่ไหน (tpix:treasury-payouts หรือแอดมินปฏิเสธในหน้าคลัง).
     */
    protected static function booted(): void
    {
        static::updated(function (self $payout) {
            if ($payout->wasChanged('status') && $payout->purpose === TokenFactoryRefunds::PAYOUT_PURPOSE) {
                app(TokenFactoryRefunds::class)->syncFromPayout($payout);
            }
        });
    }

    public function setToAddressAttribute(?string $value): void
    {
        $this->attributes['to_address'] = strtolower(trim((string) $value));
    }

    /** ยอดในรูปอ่านง่าย — คำนวณด้วย bcmath ไม่แตะ float */
    public function getAmountTpixAttribute(): string
    {
        return Wei::format((string) ($this->amount_wei ?? '0'));
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /** รายการที่ยังเปลี่ยนแปลงได้ — ใช้กันการอนุมัติซ้ำ */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** ยกเว้น whitelist ได้ไหม — ดูจากผู้สร้างรายการ (origin) ไม่ใช่ purpose */
    public function isWhitelistExempt(): bool
    {
        return in_array($this->origin, self::WHITELIST_EXEMPT_ORIGINS, true);
    }

    /**
     * จบแล้วโดยพิสูจน์ได้ว่าเงินไม่ได้ออก — สร้างรายการจ่ายใหม่แทนได้อย่างปลอดภัย.
     *
     * failed ที่มี tx_hash แต่ไม่มี block_number คือแถวแบบเก่าที่ตั้ง failed ตอนส่งไม่ยืนยันผล
     * ธุรกรรมนั้นอาจลงบล็อกไปแล้ว — ห้ามนับว่าจบ ไม่งั้นจ่ายซ้ำ
     */
    public function endedWithoutPayment(): bool
    {
        if (! in_array($this->status, [self::STATUS_FAILED, self::STATUS_REJECTED], true)) {
            return false;
        }

        return $this->tx_hash === null || $this->block_number !== null;
    }
}
