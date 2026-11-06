<?php

namespace App\Models;

use App\Support\Wei;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * หน้าขายหนึ่งรายการ = หนึ่ง launch ของ LaunchpadFactory บนเชน 4289.
 *
 * ทุกตัวเลข (ราคา/เพดาน/เวลา/ที่อยู่) อ่านจากสัญญาตอนลงทะเบียน (`launchpad:register`) — ไม่มีช่องให้พิมพ์เอง
 * ราคาและเพดานจริงอยู่บนสัญญา แถวนี้เป็นแค่สำเนาไว้แสดงผล ส่วนการซื้อจริงจำลองบนเชนทุกครั้งก่อนเซ็น
 *
 * สถานะ: pending (รอแอดมินดู) → approved (ขึ้นหน้าเว็บ + รับคำสั่งซื้อ) | rejected | hidden (ซ่อนภายหลัง)
 *
 * @property int $id
 * @property string $sale_address
 * @property string $feeder_address
 * @property string $token_address
 * @property string $token_symbol
 * @property string $token_name
 * @property int $token_decimals
 * @property string $rate_numerator
 * @property string $rate_denominator
 * @property string $status
 * @property bool $accepts_bsc
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon $lp_unlock_at
 */
class LaunchpadSale extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_HIDDEN = 'hidden';

    protected $fillable = [
        'chain_id', 'factory_address', 'launch_id', 'sale_address', 'feeder_address', 'vesting_address',
        'token_address', 'token_name', 'token_symbol', 'token_decimals', 'quote_address',
        'rate_numerator', 'rate_denominator', 'sale_cap', 'min_feed', 'pool_share_bps',
        'starts_at', 'ends_at', 'lp_unlock_at', 'creator_address', 'accepts_bsc',
        'status', 'status_note', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'chain_id' => 'integer',
            'launch_id' => 'integer',
            'token_decimals' => 'integer',
            'pool_share_bps' => 'integer',
            'accepts_bsc' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'lp_unlock_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(LaunchpadOrder::class);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /** อยู่ในช่วงขายตามเวลาที่จดไว้ (สัญญาเป็นตัวตัดสินจริงตอนซื้อ) */
    public function inWindow(?Carbon $at = null): bool
    {
        $at ??= now();

        return $at->greaterThanOrEqualTo($this->starts_at) && $at->lessThan($this->ends_at);
    }

    /** เหรียญที่ได้ (หน่วยเล็กสุด) ต่อ USDT_TPIX (หน่วย 6 ทศนิยม) — สูตรเดียวกับ FixedPriceSale.quote() */
    public function quoteUnits(string $payUnits): string
    {
        if (bccomp($this->rate_denominator, '0', 0) <= 0) {
            return '0';
        }

        return bcdiv(bcmul($payUnits, $this->rate_numerator, 0), $this->rate_denominator, 0);
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'chain_id' => $this->chain_id,
            'launch_id' => $this->launch_id,
            'sale_address' => $this->sale_address,
            'feeder_address' => $this->feeder_address,
            'vesting_address' => $this->vesting_address,
            'token' => [
                'address' => $this->token_address,
                'name' => $this->token_name,
                'symbol' => $this->token_symbol,
                'decimals' => $this->token_decimals,
            ],
            // ราคา 1 เหรียญเป็น USDT (ทศนิยมยาว — แสดงผลเท่านั้น)
            'price_usdt' => $this->priceUsdt(),
            'tokens_per_usdt' => Wei::format($this->quoteUnits('1000000'), $this->token_decimals),
            'sale_cap' => Wei::format((string) $this->sale_cap, $this->token_decimals),
            'pool_share_bps' => $this->pool_share_bps,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'lp_unlock_at' => $this->lp_unlock_at?->toIso8601String(),
            'creator_address' => $this->creator_address,
            'accepts_bsc' => $this->accepts_bsc,
            'status' => $this->status,
        ];
    }

    /** ราคา 1 เหรียญ (USDT) = denominator ÷ numerator ปรับทศนิยม (6 ของ USDT, decimals ของเหรียญ) */
    public function priceUsdt(): string
    {
        if (bccomp($this->rate_numerator, '0', 0) <= 0) {
            return '0';
        }

        // pay(6) × num ÷ den = tokens(dec) → ราคาต่อเหรียญเต็ม = den × 10^dec ÷ (num × 10^6)
        $scale = 18;
        $price = bcdiv(
            bcmul($this->rate_denominator, bcpow('10', (string) $this->token_decimals, 0), 0),
            bcmul($this->rate_numerator, '1000000', 0),
            $scale,
        );

        return rtrim(rtrim($price, '0'), '.') ?: '0';
    }
}
