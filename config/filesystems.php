<?php

/*
 * TPIX TRADE — ที่เก็บไฟล์.
 *
 * ไฟล์นี้เพิ่งมีเพราะต้องการ disk `kyc` โดยเฉพาะ ที่เหลือคัดมาจากค่าเริ่มต้น
 * ของ Laravel 11 ตามเดิมทุกตัว (local / public / s3 / links) — อย่าแก้ให้ต่าง
 * เพราะโค้ดเดิม 17 จุดเรียก disk('public') อยู่
 *
 * Developed by Xman Studio.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * เอกสารยืนยันตัวตน — บัตรประชาชน หน้าคน หลักฐานที่อยู่
         *
         * ⚠️ กฎเหล็กสามข้อของ disk นี้:
         *
         *   1. root ต้องอยู่นอก storage/app/public เสมอ
         *      storage/app/public มี symlink ไปโผล่ที่เว็บ (ดู `links` ข้างล่าง)
         *      วางบัตรประชาชนไว้ตรงนั้น = ใครเดา URL ถูกก็เปิดดูได้โดยไม่ต้องล็อกอิน
         *
         *   2. ห้ามใส่คีย์ `url` — ไม่มี URL สาธารณะให้ใช้โดยตั้งใจ
         *      ทางเดียวที่จะอ่านไฟล์ได้คือผ่าน KycDocumentController ที่ตรวจสิทธิ์
         *      แล้วบันทึกลง kyc_document_views ว่าใครเปิดดู
         *
         *   3. visibility ต้องเป็น private
         *      บนโฮสต์ที่ deploy ด้วย user เดียวกับ web server สิทธิ์ไฟล์คือ
         *      ด่านสุดท้ายถ้าใครตั้ง alias พลาดจนโฟลเดอร์นี้โผล่ออกเว็บ
         *
         * `throw` = true ต่างจาก disk อื่นโดยตั้งใจ: เขียนไฟล์ไม่สำเร็จแล้วเงียบ
         * จะได้ใบคำขอที่ไม่มีเอกสารแนบ ทีมงานตรวจไม่ได้ ผู้ใช้ก็ไม่รู้ว่าต้องส่งใหม่
         */
        'kyc' => [
            'driver' => 'local',
            'root' => storage_path('app/kyc'),
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | ⚠️ ห้ามเพิ่ม kyc ลงในนี้ไม่ว่ากรณีใด — นั่นคือการเปิดบัตรประชาชนออกเว็บ
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],
];
