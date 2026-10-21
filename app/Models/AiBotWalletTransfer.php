<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TPIX TRADE — รายการโอนของกระเป๋าบอท (คิวถอนกลับหาเจ้าของ + ฝากที่ตรวจพบ).
 *
 * สถานะเดินทางเดียว: queued → signing → broadcasting → confirmed | failed
 * ยกเลิกได้เฉพาะตอนยัง queued — พ้นจากนั้นกุญแจถูกใช้แล้ว ต้องรอผลจากเชนเท่านั้น
 *
 * ⚠️ ทุกการเปลี่ยนสถานะของตัวส่งเป็น compare-and-set (UPDATE … WHERE status = เดิม)
 *    สองรอบของ cron ที่ซ้อนกันจึงหยิบรายการเดียวกันไปเซ็นสองครั้งไม่ได้
 *
 * failure_reason เก็บ "ข้อความที่ผู้ใช้อ่านได้" ตามรหัสใน failure_code เท่านั้น
 * รายละเอียดดิบ (ข้อความจาก RPC / ตัวถอดกุญแจ) ไปอยู่ใน log — ห้ามไหลถึงหน้าเว็บ
 *
 * Developed by Xman Studio.
 */
class AiBotWalletTransfer extends Model
{
    public const DIRECTION_WITHDRAW = 'withdraw';

    public const DIRECTION_DEPOSIT = 'deposit';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SIGNING = 'signing';

    public const STATUS_BROADCASTING = 'broadcasting';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /** สถานะที่ยัง "กินโควตา/ล็อกกระเป๋า" อยู่ — ห้ามมีสองรายการพร้อมกัน */
    public const IN_FLIGHT = [self::STATUS_QUEUED, self::STATUS_SIGNING, self::STATUS_BROADCASTING];

    // ── รหัสเหตุผลที่ล้ม (หน้าเว็บแปลเองตาม aiTrade.botWallet.failure.<code>) ────────

    public const FAIL_OWNER_MISMATCH = 'owner_mismatch';

    public const FAIL_KEY_UNREADABLE = 'key_unreadable';

    public const FAIL_INSUFFICIENT = 'insufficient_funds';

    public const FAIL_SIGN = 'sign_failed';

    public const FAIL_REVERTED = 'reverted';

    public const FAIL_NONCE_CONSUMED = 'nonce_consumed';

    public const FAIL_DROPPED = 'dropped';

    public const FAIL_RESOLVED_BY_ADMIN = 'resolved_by_admin';

    /**
     * ข้อความภาษาไทยที่เก็บลง failure_reason (หลังบ้าน/แอปรุ่นเก่าที่ยังไม่แปลจากรหัสอ่านได้).
     *
     * ทุกข้อบอกให้ชัดว่า "เงินออกไปหรือยัง" — คำถามแรกที่ผู้ใช้ถามเสมอเมื่อเห็นคำว่าล้มเหลว
     */
    public const FAILURE_MESSAGES = [
        self::FAIL_OWNER_MISMATCH => 'ปลายทางไม่ใช่กระเป๋าของเจ้าของ — ไม่ได้ส่งธุรกรรม เงินยังอยู่ในกระเป๋าบอท',
        self::FAIL_KEY_UNREADABLE => 'ระบบเปิดกุญแจของกระเป๋าบอทไม่ได้ — ไม่ได้ส่งธุรกรรม เงินยังอยู่ ทีมงานได้รับแจ้งแล้ว',
        self::FAIL_INSUFFICIENT => 'ยอดไม่พอหรือ BNB ค่าแก๊สไม่พอ — ไม่ได้ส่งธุรกรรม เงินยังอยู่ในกระเป๋าบอท',
        self::FAIL_SIGN => 'เตรียมธุรกรรมไม่สำเร็จ — ไม่ได้ส่งธุรกรรม เงินยังอยู่ในกระเป๋าบอท ลองขอถอนใหม่ได้',
        self::FAIL_REVERTED => 'เชนปฏิเสธธุรกรรม — ไม่ได้โอน (เสียเฉพาะค่าแก๊ส)',
        self::FAIL_NONCE_CONSUMED => 'ธุรกรรมนี้ถูกแทนที่ด้วยธุรกรรมอื่นของกระเป๋าบอท — รายการนี้ไม่ได้โอน',
        self::FAIL_DROPPED => 'ธุรกรรมหายจากเครือข่ายโดยไม่ลงบล็อก — ไม่ได้โอน เงินยังอยู่ในกระเป๋าบอท ลองขอถอนใหม่ได้',
        self::FAIL_RESOLVED_BY_ADMIN => 'ทีมงานปิดรายการนี้หลังตรวจบนเชนแล้วว่าไม่ได้โอน',
    ];

    /**
     * รหัสที่ "ตัดสินว่าไม่ได้โอน" จากการไม่เห็นธุรกรรม ไม่ใช่จากใบเสร็จ —
     * ตัวส่งยังตามใบเสร็จของรายการเหล่านี้ต่ออีกพักหนึ่ง เผื่อโหนดอื่นเอาไปลงบล็อกทีหลัง.
     */
    public const UNSEEN_FAILURES = [self::FAIL_NONCE_CONSUMED, self::FAIL_DROPPED, self::FAIL_RESOLVED_BY_ADMIN];

    protected $fillable = [
        'ai_bot_wallet_id', 'owner_address', 'direction', 'asset', 'token_address',
        'amount', 'amount_wei', 'to_address', 'status', 'tx_hash', 'nonce', 'raw_tx',
        'block_number', 'confirmations', 'failure_reason', 'failure_code', 'requested_ip',
        'signing_at', 'broadcast_at', 'confirmed_at',
    ];

    /** raw tx ยาวและไม่จำเป็นต่อหน้าจอ — ไม่ส่งออกไปกับ JSON */
    protected $hidden = ['raw_tx'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:18',
            'nonce' => 'integer',
            'block_number' => 'integer',
            'confirmations' => 'integer',
            'signing_at' => 'datetime',
            'broadcast_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(AiBotWallet::class, 'ai_bot_wallet_id');
    }

    public function scopeInFlight(Builder $query): Builder
    {
        return $query->whereIn('status', self::IN_FLIGHT);
    }

    public function scopeWithdrawals(Builder $query): Builder
    {
        return $query->where('direction', self::DIRECTION_WITHDRAW);
    }

    public function isCancellable(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    /**
     * ข้อความที่ผู้ใช้เห็น — จากรหัสเท่านั้น.
     *
     * แถวเก่าก่อนมีรหัสเก็บเป็น "หัวข้อ: รายละเอียดดิบ" — ตัดส่วนหลัง ':' ทิ้ง
     * (ส่วนนั้นคือข้อความจาก RPC/ตัวถอดกุญแจที่ไม่ควรถึงหน้าเว็บตั้งแต่แรก)
     */
    public function failureMessage(): ?string
    {
        if ($this->failure_code !== null) {
            return self::FAILURE_MESSAGES[$this->failure_code] ?? self::FAILURE_MESSAGES[self::FAIL_SIGN];
        }

        if ($this->failure_reason === null || $this->failure_reason === '') {
            return null;
        }

        return trim(explode(':', $this->failure_reason, 2)[0]);
    }
}
