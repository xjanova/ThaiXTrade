<?php

namespace App\Models;

use App\Support\Wei;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * สมุดบัญชีคลัง — ทุกการเคลื่อนไหวที่ระบบรู้เห็น.
 *
 * สมุดนี้ **ไม่ใช่แหล่งความจริง** ของยอดเงิน — เชนต่างหากที่เป็น
 * หน้าที่ของมันคือให้ตัวกระทบยอดเอาไปเทียบกับยอดจริงบนเชน
 * ถ้าสองอย่างไม่ตรงกันแปลว่ามีเงินเคลื่อนโดยที่ระบบไม่รู้ ต้องดังทันที
 */
class TreasuryLedger extends Model
{
    use HasFactory;

    public const DIRECTION_DEBIT = 'debit';   // เงินออก
    public const DIRECTION_CREDIT = 'credit'; // เงินเข้า

    protected $table = 'treasury_ledger';

    protected $fillable = [
        'wallet_key',
        'wallet_address',
        'direction',
        'amount_wei',
        'source',
        'payout_id',
        'tx_hash',
        'block_number',
        'note',
        'recorded_by',
    ];

    protected $appends = ['amount_tpix'];

    public function setWalletAddressAttribute(?string $value): void
    {
        $this->attributes['wallet_address'] = strtolower(trim((string) $value));
    }

    public function getAmountTpixAttribute(): string
    {
        return Wei::format((string) ($this->amount_wei ?? '0'));
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(TreasuryPayout::class, 'payout_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'recorded_by');
    }

    /**
     * ยอดสุทธิที่สมุดเชื่อว่ากระเป๋าใบนี้เคลื่อนไป (credit - debit) หน่วย wei.
     *
     * บวกด้วย bcmath ทั้งหมด — ผลรวมของกระเป๋าคลังใบใหญ่อยู่ระดับ 1e27
     * ซึ่ง SUM() ของ MySQL บนคอลัมน์ string จะ cast เป็น double แล้วเพี้ยน
     * จึงต้องดึงออกมาบวกฝั่ง PHP
     */
    public static function netMovementWei(string $walletKey): string
    {
        $rows = static::where('wallet_key', $walletKey)
            ->get(['direction', 'amount_wei']);

        $net = '0';
        foreach ($rows as $row) {
            $net = $row->direction === self::DIRECTION_CREDIT
                ? bcadd($net, (string) $row->amount_wei, 0)
                : bcsub($net, (string) $row->amount_wei, 0);
        }

        return $net;
    }
}
