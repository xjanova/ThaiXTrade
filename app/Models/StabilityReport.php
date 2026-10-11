<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * รายงานรายวันของ TPIX Stability Reserve — ตัวเลขจริงเสมอ + บทสรุปจาก AI (ถ้ามี).
 *
 * Developed by Xman Studio
 */
class StabilityReport extends Model
{
    protected $fillable = [
        'report_date', 'stats', 'facts_th', 'facts_en',
        'summary_th', 'summary_en', 'ai_provider', 'ai_model', 'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date:Y-m-d',
            'stats' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
