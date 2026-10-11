<?php

namespace App\Models;

use App\Support\Wei;
use Illuminate\Database\Eloquent\Model;

/**
 * ผลกระทบยอดหนึ่งรอบของสะพาน TPIX ⇄ wTPIX — TPIX ในคลังเทียบ wTPIX supply บน BSC.
 *
 * แยกจาก bridge_reserve_snapshots ของสะพาน USDT เพราะตัวกระทบยอดของ USDT ตัดสิน "ขาดสองรอบติด"
 * จากแถวล่าสุดของตารางตัวเอง — ถ้าแถวของสองสะพานปนกัน รอบของสะพานหนึ่งจะไปลบล้างอีกสะพาน
 */
class WtpixBridgeSnapshot extends Model
{
    public const STATUS_OK = 'ok';

    public const STATUS_DEFICIT = 'deficit';

    public const STATUS_INCOMPLETE = 'incomplete';

    protected $fillable = [
        'status', 'vault_wei', 'supply_wei', 'owed_wei', 'backing_wei', 'deficit_wei',
        'unexplained_mint_wei', 'unexplained_release_wei', 'foreign_supply_wei', 'details', 'action', 'note',
    ];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function toPublicArray(): array
    {
        $fmt = fn ($v) => $v === null ? null : Wei::format((string) $v, 18);

        return [
            'status' => $this->status,
            'vault' => $fmt($this->vault_wei),
            'supply' => $fmt($this->supply_wei),
            'owed' => $fmt($this->owed_wei),
            'backing' => $fmt($this->backing_wei),
            'deficit' => $fmt($this->deficit_wei),
            'details' => [
                'vault_url' => $this->details['vault_url'] ?? null,
                'token_url' => $this->details['token_url'] ?? null,
                'vault_paused' => $this->details['vault_paused'] ?? null,
                'minter_paused' => $this->details['minter_paused'] ?? null,
            ],
            'checked_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
