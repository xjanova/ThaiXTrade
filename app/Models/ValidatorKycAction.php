<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ValidatorKycAction — submit/approve/reject บนสัญญา ValidatorKYC ที่เซิร์ฟเวอร์ตรวจกับเชนแล้ว.
 *
 * แถวเกิดหลัง tx สำเร็จบนเชนเท่านั้น (ดู ValidatorKycAdminController::confirm)
 * ไม่ใช่สิ่งที่เบราว์เซอร์ "บอกว่า" ทำ
 *
 * @property int $id
 * @property string $applicant
 * @property string $action
 * @property string $tx_hash
 * @property string $signer
 * @property string|null $kyc_hash
 * @property int|null $kyc_submission_id
 * @property string|null $note
 * @property string $chain_status
 * @property int|null $admin_user_id
 * @property int|null $block_number
 */
class ValidatorKycAction extends Model
{
    protected $fillable = [
        'applicant',
        'action',
        'tx_hash',
        'signer',
        'kyc_hash',
        'kyc_submission_id',
        'note',
        'chain_status',
        'admin_user_id',
        'block_number',
    ];

    protected function casts(): array
    {
        return [
            'block_number' => 'integer',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function kycSubmission(): BelongsTo
    {
        return $this->belongsTo(KycSubmission::class);
    }
}
