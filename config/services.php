<?php

/**
 * TPIX TRADE - Third-party Services Configuration.
 */

return [
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'groq' => [
        'api_key' => env('GROQ_API_KEY', ''),
        // ⚠️ Llama ถูก Groq ถอดออกหมดแล้ว (~18 ส.ค. 2026) — ค่าปริยายเดิมทำให้
        //    ผู้ช่วย AI ตอบ 404 เงียบๆ ทุกครั้ง รายชื่อที่ใช้ได้อยู่ใน config/ai_text.php
        'default_model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
    ],

    /*
     * OpenAI — คีย์ปกติมาจากหลังบ้าน (site_settings กลุ่ม ai) ซึ่งดึงมาจากพูล
     * ของ Thaiprompt ด้วย `php artisan ai:pull-pool-key` · ตรงนี้เป็นทางสำรอง
     */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY', ''),
    ],

    // Turnstile config จัดการผ่าน SiteSetting (DB) ไม่ใช้ .env แล้ว

    // Stripe — ระบบชำระเงินสำหรับ ICO Token Sale
    'stripe' => [
        'key' => env('STRIPE_KEY', ''),
        'secret' => env('STRIPE_SECRET', ''),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
    ],

    // Resend — ระบบส่งอีเมล (production)
    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    // GitHub — สำหรับดึง release/APK (repo เป็น private ได้)
    'github' => [
        'owner' => env('GITHUB_OWNER', 'xjanova'),
        'repo' => env('GITHUB_REPO', 'ThaiXTrade'),

        // repo เชน (สาธารณะ) — ที่มาของ TPIX Wallet APK
        'chain_repo' => env('GITHUB_CHAIN_REPO', 'TPIX-Coin'),

        // repo โปรแกรมมาสเตอร์โหนด (ไพรเวท) — แยกออกจาก repo เชนแล้ว
        // เป็นไพรเวทได้เพราะเซิร์ฟเวอร์เป็นคนถือ token ไปดึงไฟล์แทนผู้ใช้
        // ห้ามให้ตัวแอปยิง GitHub ตรงๆ เด็ดขาด (ต้องฝัง token ในไฟล์ .exe ที่แจก)
        'masternode_repo' => env('GITHUB_MASTERNODE_REPO', 'TPIX-Masternode'),

        'token' => env('GITHUB_TOKEN'),
        'deploy_secret' => env('GITHUB_DEPLOY_SECRET'),
    ],

    // TPIX Chain — blockchain config
    'tpix_chain' => [
        'rpc_url' => env('TPIX_RPC_URL', 'https://rpc.tpix.online'),
        'chain_id' => env('TPIX_CHAIN_ID', 4289),
        'master_wallet' => env('TPIX_MASTER_WALLET', ''),
    ],

    // Infra Alerts — watchdog เซิร์ฟเวอร์เชนยิง heartbeat/เหตุวิกฤตเข้าคาดแดงหลังบ้าน
    // token ต้องตรงกับ TPIX_ALERT_TOKEN ใน /etc/tpix-watchdog.env บนเครื่องเชน
    /*
    | ให้สคริปต์ deploy ลงทะเบียนที่อยู่สัญญาเข้ามาเองหลัง deploy เสร็จ
    | ยังไม่ตั้ง = ปิดระบบ (503) ไม่ใช่เปิดโล่ง
    | สร้าง token: php artisan tinker --execute="echo bin2hex(random_bytes(32));"
    */
    'contract_registry' => [
        'token' => env('CONTRACT_REGISTRY_TOKEN', ''),
    ],

    'infra_alerts' => [
        'token' => env('TPIX_INFRA_ALERT_TOKEN', ''),
        'stale_minutes' => env('TPIX_INFRA_STALE_MINUTES', 3),
    ],

    // Bridge — cross-chain TPIX Chain ↔ BSC
    'bridge' => [
        'treasury_address' => env('BRIDGE_TREASURY_ADDRESS', ''),
        'signer_private_key' => env('BRIDGE_SIGNER_PRIVATE_KEY', ''),
        'wtpix_bsc_address' => env('WTPIX_BSC_ADDRESS', ''),
    ],

    // Social Login — OAuth providers (keys จัดการผ่าน SiteSetting ได้ด้วย)
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('APP_URL').'/auth/google/callback',
    ],
    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('APP_URL').'/auth/facebook/callback',
    ],
    'line' => [
        'client_id' => env('LINE_CHANNEL_ID'),
        'client_secret' => env('LINE_CHANNEL_SECRET'),
        'redirect' => env('APP_URL').'/auth/line/callback',
    ],

    // Image Generation — Cloudflare Worker
    'image_gen' => [
        'url' => env('IMAGE_GEN_URL', 'https://tpix-image-gen.xjanovax.workers.dev/'),
        'key' => env('IMAGE_GEN_API_KEY'),
    ],

    // BSC (BNB Smart Chain) — สำหรับ verify transactions
    'bsc' => [
        'rpc_url' => env('BSC_RPC_URL', 'https://bsc-dataseed.binance.org'),

        /*
         * จำนวนบล็อกที่ต้องรอก่อนถือว่าการชำระเงินนิ่งพอ
         *
         * BSC จบบล็อกเร็วแต่ reorg สั้นๆ เกิดได้ — ถ้ายืนยันที่ 0 confirmation
         * แล้วบล็อกถูกม้วนกลับ เท่ากับออกเหรียญให้โดยที่เงินไม่เคยเข้าจริง
         */
        'min_confirmations' => (int) env('BSC_MIN_CONFIRMATIONS', 15),

        /*
         * อายุสูงสุดของธุรกรรมที่ยอมรับ (นาที)
         *
         * กันการเอา tx เก่าตอนราคาถูกมายื่นตอนราคาแพง — เราตีราคา ณ เวลาที่ยื่น
         * ถ้าไม่จำกัดอายุ ส่วนต่างราคาทั้งหมดจะตกเป็นกำไรของผู้ยื่น
         */
        'max_tx_age_minutes' => (int) env('BSC_MAX_TX_AGE_MINUTES', 60),

        /*
         * เหรียญที่รับชำระได้ — ต้องระบุ contract address ตัวจริงเสมอ
         *
         * ⚠️ ห้ามรับ ERC-20 โดยดูแค่ event Transfer เพราะใครก็ deploy เหรียญ
         *    ตั้งชื่อ USDT แล้วโอนเข้ากระเป๋าขายได้ด้วยต้นทุนหลักสิบบาท
         *    ต้องเทียบ address ของสัญญาที่ปล่อย event กับรายการนี้เท่านั้น
         *
         * decimals ต้องตรงกับสัญญาจริง — USDT/BUSD บน BSC ใช้ 18 (ต่างจาก
         * USDT บน Ethereum/Tron ที่ใช้ 6) การใช้ค่าผิดทำให้ยอดเพี้ยน 10^12 เท่า
         */
        'payment_tokens' => [
            'USDT' => [
                'address' => env('BSC_USDT_ADDRESS', '0x55d398326f99059fF775485246999027B3197955'),
                'decimals' => 18,
            ],
            'BUSD' => [
                'address' => env('BSC_BUSD_ADDRESS', '0xe9e7CEA3DedcA5984780Bafc599bD69ADd087D56'),
                'decimals' => 18,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | ระบบรายงานบั๊กกลางของ xman studio
    |--------------------------------------------------------------------------
    | ทุกแอปในบ้านส่งเข้าที่เดียวกัน (มีหน้าแอดมิน สถิติ อ่านผ่าน GET สาธารณะได้)
    | แอปมือถือยิงตรง · เว็บยิงผ่าน relay ของเรา (กัน CORS) · หลังบ้าน TPIX อ่านมาแสดง
    | ⚠️ WAF ฝั่งนั้นตอบ 403 ให้ User-Agent ตั้งต้นของไลบรารี — ต้องตั้ง UA เองเสมอ
    */
    'bug_reports' => [
        'endpoint' => env('BUG_REPORTS_ENDPOINT', 'https://xman4289.com/api/v1/bug-reports'),
        'admin_url' => env('BUG_REPORTS_ADMIN_URL', 'https://xman4289.com/admin/bug-reports'),
        'user_agent' => 'TPIX-Relay/1.0 (+https://tpix.online)',
    ],
];
