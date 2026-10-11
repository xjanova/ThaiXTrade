<?php

/*
|--------------------------------------------------------------------------
| Queue — แก้เฉพาะ retry_after ของ connection ที่ใช้จริง
|--------------------------------------------------------------------------
|
| ไฟล์นี้ override แค่ 'connections.database' กับ 'connections.redis'
| ส่วนที่เหลือ Laravel เอาจากค่า default ของ framework มารวมให้เอง
| (LoadConfiguration ผสาน 'connections' ทีละชื่อ)
|
| ⚠️ ทำไมต้องยก retry_after:
|    ค่า default ของ framework คือ 90 วินาที แต่ DeployTokenJob รันนานได้ถึง
|    timeout ของมัน (180 วินาที — ส่งธุรกรรมแล้วรอ receipt) ถ้า retry_after
|    น้อยกว่า timeout คิวจะคิดว่างานตายแล้วหยิบไปรันซ้ำทั้งที่ตัวแรกยังทำอยู่
|    = ส่งธุรกรรมสร้างเหรียญซ้ำ ได้สัญญาซ้ำหลายตัว (เหรียญ supply เต็มทุกตัว)
|
|    กติกา: retry_after ต้องมากกว่า timeout ของงานที่ยาวที่สุดในคิวเสมอ
|    ถ้าตั้ง REDIS_QUEUE_RETRY_AFTER / DB_QUEUE_RETRY_AFTER ใน .env ไว้ ค่านั้นชนะ
|    — ต้องไม่ต่ำกว่า 200
|
*/

return [

    'connections' => [

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 360),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 360),
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

];
