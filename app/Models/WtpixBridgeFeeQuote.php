<?php

namespace App\Models;

use App\Support\Wei;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ค่าธรรมเนียมขาออก (TPIX → BSC) ที่ประกาศหนึ่งครั้ง — ดู WtpixBridgeFeeQuoter.
 *
 * @property int $id
 * @property string $fee_wei
 * @property string|null $raw_fee_wei
 * @property string|null $gas_price_wei
 * @property int $gas_units
 * @property string|null $bnb_usd
 * @property string|null $tpix_usd
 * @property int $markup_bps
 * @property string $source
 * @property string|null $note
 * @property Carbon|null $created_at
 */
class WtpixBridgeFeeQuote extends Model
{
    public const SOURCE_MARKET = 'market';

    public const SOURCE_CAPPED = 'capped';

    public const SOURCE_FALLBACK = 'fallback';

    protected $fillable = [
        'fee_wei', 'raw_fee_wei', 'gas_price_wei', 'gas_units', 'bnb_usd', 'tpix_usd', 'markup_bps', 'source', 'note',
    ];

    protected function casts(): array
    {
        return ['gas_units' => 'integer', 'markup_bps' => 'integer'];
    }

    public function toPublicArray(): array
    {
        return [
            'fee' => Wei::format((string) $this->fee_wei, 18),
            'raw_fee' => $this->raw_fee_wei === null ? null : Wei::format((string) $this->raw_fee_wei, 18),
            'gas_gwei' => $this->gas_price_wei === null ? null : Wei::format((string) $this->gas_price_wei, 9),
            'gas_units' => $this->gas_units,
            'bnb_usd' => $this->bnb_usd,
            'tpix_usd' => $this->tpix_usd,
            'markup_bps' => $this->markup_bps,
            'source' => $this->source,
            'updated_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
