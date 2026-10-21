<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * คำสั่งซื้อ TPIX ด้วย USDT — ราคาและ "ยอดเฉพาะ" ที่ล็อกไว้ให้ผู้ซื้อโอนเข้ามา.
 *
 * วงจรสถานะ:
 *   awaiting_payment → paid          (ตัวสแกนเห็นยอดเป๊ะภายในเวลา แล้วออก SaleTransaction)
 *   awaiting_payment → expired       (หมดเวลาโดยไม่มีเงินเข้า — ยอดเฉพาะยังจองไว้จนพ้น late_window)
 *   awaiting_payment → needs_review  (เงินเข้าแต่ออกเหรียญเองไม่ได้: ยอดไม่ตรง / มาช้า / โควตาหมด)
 *
 * @property int $id
 * @property string $uuid
 * @property string $reference
 * @property int $token_sale_id
 * @property int $sale_phase_id
 * @property string $wallet_address
 * @property int $chain_id
 * @property string $token_address
 * @property string $token_symbol
 * @property int $token_decimals
 * @property string $pay_to_address
 * @property string $amount_usd
 * @property string $tpix_amount
 * @property string $price_per_tpix
 * @property string $pay_amount_raw
 * @property int $tag_micro
 * @property string|null $amount_lock
 * @property string $status
 * @property Carbon $expires_at
 * @property Carbon|null $paid_at
 * @property int|null $sale_transaction_id
 * @property array|null $metadata
 */
class SaleCryptoOrder extends Model
{
    public const STATUS_AWAITING = 'awaiting_payment';

    public const STATUS_PAID = 'paid';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    protected $table = 'sale_crypto_orders';

    protected $fillable = [
        'uuid', 'reference', 'token_sale_id', 'sale_phase_id', 'wallet_address',
        'chain_id', 'token_address', 'token_symbol', 'token_decimals', 'pay_to_address',
        'amount_usd', 'tpix_amount', 'price_per_tpix', 'pay_amount_raw', 'tag_micro',
        'amount_lock', 'status', 'expires_at', 'paid_at', 'sale_transaction_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'chain_id' => 'integer',
            'token_decimals' => 'integer',
            'tag_micro' => 'integer',
            'amount_usd' => 'decimal:2',
            'tpix_amount' => 'decimal:18',
            'price_per_tpix' => 'decimal:8',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SaleCryptoOrder $order) {
            if (empty($order->uuid)) {
                $order->uuid = (string) Str::uuid();
            }
        });
    }

    /** ที่อยู่ทุกช่องเก็บพิมพ์เล็กเสมอ — ตัวจับคู่เทียบตรง ๆ กับที่อยู่จาก log */
    public function setWalletAddressAttribute(?string $value): void
    {
        $this->attributes['wallet_address'] = $value !== null ? strtolower($value) : null;
    }

    public function setPayToAddressAttribute(?string $value): void
    {
        $this->attributes['pay_to_address'] = $value !== null ? strtolower($value) : null;
    }

    public function setTokenAddressAttribute(?string $value): void
    {
        $this->attributes['token_address'] = $value !== null ? strtolower($value) : null;
    }

    public static function lockKey(int $chainId, string $token, string $payTo, string $amountRaw): string
    {
        return $chainId.':'.strtolower($token).':'.strtolower($payTo).':'.$amountRaw;
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(SalePhase::class, 'sale_phase_id');
    }

    public function tokenSale(): BelongsTo
    {
        return $this->belongsTo(TokenSale::class);
    }

    public function saleTransaction(): BelongsTo
    {
        return $this->belongsTo(SaleTransaction::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SaleCryptoPayment::class, 'order_id');
    }

    /** ยอดที่ต้องโอนเป็นทศนิยม (สตริง ไม่ผ่าน float) — 6 ตำแหน่งพอเสมอเพราะเศษท้ายละเอียดแค่ 0.000001 */
    public function payAmountDisplay(): string
    {
        return self::formatRaw($this->pay_amount_raw, $this->token_decimals);
    }

    public static function formatRaw(string $raw, int $decimals, int $places = 6): string
    {
        $scaled = bcdiv($raw, bcpow('10', (string) $decimals, 0), $places);

        return rtrim(rtrim($scaled, '0'), '.') ?: '0';
    }
}
