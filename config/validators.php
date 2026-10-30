<?php

/*
 * TPIX TRADE — validator ที่ปิดบล็อก IBFT (ไม่ใช่ใบสมัครหน้าเว็บ ดู masternode.php)
 *
 * seal_monitor: validators:seal-monitor ทุกนาที (ดู App\Services\Validator\SealMonitor)
 *   window        — หน้าต่างสถิติ (บล็อก) 300 ≈ 10 นาทีที่ 2 วินาที/บล็อก
 *   silent_blocks — ไม่เซ็นรับรองเลยต่อเนื่องกี่บล็อกถึงยกคาดแดง 150 ≈ 5 นาที
 *   max_per_run   — อ่านย้อนได้มากสุดต่อรอบ (เชนออก ~30 บล็อก/นาที)
 */
return [
    'seal_monitor' => [
        'window' => (int) env('VALIDATOR_SEAL_WINDOW', 300),
        'silent_blocks' => (int) env('VALIDATOR_SILENT_BLOCKS', 150),
        'max_per_run' => (int) env('VALIDATOR_SEAL_MAX_PER_RUN', 60),
    ],
];
