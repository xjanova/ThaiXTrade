<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * TPIX TRADE — การรายงานรีวิวหนึ่งครั้ง (กระเป๋าหนึ่งใบต่อรีวิวหนึ่งอัน).
 *
 * การรายงานไม่ซ่อนรีวิวเอง — มาสเตอร์ที่มีหลายกระเป๋าจะรุมรายงานรีวิวที่ติให้หายไปได้
 * ทีมงานเป็นคนตัดสิน (คำสั่ง aibot:copy-review)
 *
 * Developed by Xman Studio.
 */
class AiCopyReviewReport extends Model
{
    protected $fillable = ['ai_copy_review_id', 'reporter_wallet', 'reason'];
}
