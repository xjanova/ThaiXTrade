<?php

/*
 * TPIX TRADE — ผู้ให้บริการงานข้อความด้วย AI (ผู้ช่วยตอบคำถาม · วิเคราะห์ · เขียนข่าว).
 *
 * ⚠️ รายชื่อโมเดลต้องอยู่ที่นี่ ห้ามฝังในโค้ด
 *
 *    เดิม `GroqService` ฝัง `llama-3.3-70b-versatile` ไว้เป็นค่าปริยาย และ
 *    `getModels()` คืนรายการ Llama ทั้งชุด — พอ Groq ถอดโมเดลออกหมดเมื่อ ~18 ส.ค. 2026
 *    ผู้ช่วย AI ทั้งเว็บก็ตายเงียบ และแอดมินเปิดหน้าตั้งค่าเห็นแต่ตัวเลือกที่ใช้ไม่ได้
 *    เลือกอันไหนก็พังเหมือนกันหมด กว่าจะรู้ต้องไปไล่อ่าน storage/logs
 *
 *    ชื่อโมเดลเป็นค่าที่ **เน่าได้เองโดยไม่มีใครแตะ** ผู้ให้บริการถอดเมื่อไหร่ก็ได้
 *    อยู่ใน config = แก้แล้ว deploy ได้ทันที ไม่ต้องรอแก้โค้ด
 *
 * ลำดับที่ระบบลอง: OpenAI → Groq (ดู AiTextService::PROVIDERS)
 *
 * Developed by Xman Studio.
 */

return [
    'timeout' => 60,

    'providers' => [
        /*
         * OpenAI — คีย์มาจากพูลของ Thaiprompt (ดู `php artisan ai:pull-pool-key`)
         *
         * ⚠️ คีย์ในพูลอยู่ OpenAI org เดียวกันทั้งหมด → **บิลรวมกัน** กับบอทดูดวง
         *    ที่ใช้อยู่ ~20M tokens/เดือน เพิ่มการใช้ที่นี่คือเพิ่มบิลก้อนเดียวกัน
         */
        'openai' => [
            'endpoint' => 'https://api.openai.com/v1/chat/completions',
            'key_setting' => 'openai_api_key',        // site_settings กลุ่ม ai
            'key_config' => 'services.openai.api_key', // ตกไป .env
            'model_setting' => 'openai_default_model',
            'default_model' => 'gpt-4o-mini',
            'models' => [
                'gpt-4o-mini' => 'GPT-4o mini (เร็ว ถูก)',
                'gpt-4o' => 'GPT-4o',
                'gpt-4.1-mini' => 'GPT-4.1 mini',
                'gpt-4.1' => 'GPT-4.1',
            ],
        ],

        /*
         * Groq — สำรอง endpoint เข้ากันได้กับ OpenAI อยู่แล้ว
         *
         * โมเดล Llama ถูกถอดออกหมดแล้ว รายการนี้คือโมเดลแชตที่เหลือจริงเมื่อ 13 พ.ย. 2026
         * (ยิง /openai/v1/models ตรวจก่อนใส่ ไม่ได้คัดจากความจำ)
         *
         * retired_models = ชื่อที่ Groq ถอดไปแล้วแต่ยังอาจค้างอยู่ในหลังบ้าน (ai.groq_default_model)
         * หรือถูกส่งมาจากหน้าจอรุ่นเก่า — เจอเมื่อไหร่ถือว่า "ไม่ได้ตั้ง" แล้วใช้ default_model แทน
         * โมเดลไหนถูกถอดเพิ่ม ให้ย้ายจาก models มาไว้ที่นี่ ไม่ใช่ลบทิ้งเฉยๆ
         */
        'groq' => [
            'endpoint' => 'https://api.groq.com/openai/v1/chat/completions',
            'key_setting' => 'groq_api_key',
            'key_config' => 'services.groq.api_key',
            'model_setting' => 'groq_default_model',
            'default_model' => 'openai/gpt-oss-120b',
            'models' => [
                'openai/gpt-oss-120b' => 'GPT-OSS 120B (Groq)',
                'openai/gpt-oss-20b' => 'GPT-OSS 20B (Groq)',
                'qwen/qwen3.8-27b' => 'Qwen 3.8 27B (Groq)',
            ],
            'retired_models' => [
                'llama-3.3-70b-versatile',
                'llama-3.1-8b-instant',
                'mixtral-8x7b-32768',
                'gemma2-9b-it',
                'qwen/qwen3.6-27b',
                'groq/compound',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | งบรายวันของผู้ช่วย AI บนหน้าเว็บ (POST /api/v1/chatbot)
    |--------------------------------------------------------------------------
    | เส้นนี้เปิดสาธารณะ ไม่ต้องล็อกอิน และทุกข้อความคือการเรียกโมเดลด้วยคีย์ของเรา
    | throttle ของเส้น (30 ข้อความ/นาที ต่อ IP) กันการยิงรัวจาก IP เดียว แต่ไม่ได้จำกัดยอดรวม:
    | IP เดียวส่งได้ 43,200 ข้อความ/วัน และคนที่หมุน IP ได้ไม่มีเพดานเลย
    |
    | นับเป็นวันตามเวลาไทย · ครบแล้วผู้ช่วยตอบว่า "วันนี้ครบโควตาแล้ว" โดยไม่เรียกโมเดล
    | ตั้งเป็น 0 = ไม่จำกัด (ไม่แนะนำ) · บอท Discord มีงบของตัวเองใน config/discord.php
    */
    'chatbot' => [
        'daily_cap' => (int) env('CHATBOT_DAILY_CAP', 2000),              // ทั้งเว็บ
        'daily_cap_per_ip' => (int) env('CHATBOT_DAILY_CAP_PER_IP', 200), // ต่อ IP
    ],

    /*
    |--------------------------------------------------------------------------
    | สะพานไปยังพูลคีย์ของ Thaiprompt
    |--------------------------------------------------------------------------
    | ทั้งสองแอปอยู่เครื่องเดียวกัน แต่ **APP_KEY คนละใบ** — คีย์ในพูลถูกเข้ารหัส
    | ด้วย APP_KEY ของ Thaiprompt ก๊อป ciphertext มาตรงๆ จึงถอดไม่ออก
    | ต้องถอดฝั่งโน้นแล้วเข้ารหัสใหม่ฝั่งนี้ (ดู ai:pull-pool-key)
    */
    'pool' => [
        'path' => env('THAIPROMPT_PATH', '/home/admin/domains/main.thaiprompt.online/public_html'),
        'connection' => env('THAIPROMPT_DB_CONNECTION', 'thaiprompt_pool'),
        'table' => 'ai_api_keys',
    ],
];
