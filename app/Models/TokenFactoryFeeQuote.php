<?php

namespace App\Models;

use App\Support\Wei;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ใบเสนอราคาค่าธรรมเนียมสร้างเหรียญ.
 *
 * ล็อกยอดไว้ช่วงหนึ่ง (ค่าตั้งต้น 30 นาที) ผูกกับกระเป๋าผู้สร้าง และลายนิ้วมือของ
 * "สิ่งที่คิดเงิน" (ประเภท + ตัวเลือก) — ใช้ได้ครั้งเดียว
 *
 * @property string $id
 * @property string $creator_address
 * @property int $chain_id
 * @property string $purpose
 * @property string $token_type
 * @property string $params_hash
 * @property string $amount
 * @property string $amount_wei
 * @property string|null $fee_wallet
 * @property array|null $breakdown
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property int|null $factory_token_id
 * @property Carbon $created_at
 */
class TokenFactoryFeeQuote extends Model
{
    use HasUuids;

    /** ค่าสร้างเหรียญ */
    public const PURPOSE_CREATE = 'create';

    /** ค่ายืนยันซอร์สโค้ดบน explorer ที่ซื้อหลังเหรียญ deploy แล้ว (factory_token_id ผูกตั้งแต่ออกใบ) */
    public const PURPOSE_SOURCE_VERIFY = 'source_verify';

    protected $table = 'token_factory_fee_quotes';

    protected $attributes = [
        'purpose' => self::PURPOSE_CREATE,
    ];

    protected $fillable = [
        'creator_address',
        'chain_id',
        'purpose',
        'token_type',
        'params_hash',
        'amount',
        'amount_wei',
        'fee_wallet',
        'breakdown',
        'expires_at',
        'used_at',
        'factory_token_id',
    ];

    protected $casts = [
        'breakdown' => 'array',
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'chain_id' => 'integer',
    ];

    public function isFree(): bool
    {
        return bccomp((string) $this->amount_wei, '0', 0) <= 0;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function toApiArray(): array
    {
        return [
            'quote_id' => $this->id,
            // แสดงจาก wei เสมอ — ทศนิยมจากคอลัมน์ decimal ต่างกันตามฐานข้อมูล ('50' vs '50.000…')
            'amount' => Wei::format((string) $this->amount_wei),
            'amount_wei' => (string) $this->amount_wei,
            'currency' => 'TPIX',
            'chain_id' => $this->chain_id,
            'fee_wallet' => $this->fee_wallet,
            'breakdown' => $this->breakdown ?? [],
            'is_free' => $this->isFree(),
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }
}
