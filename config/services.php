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
        'default_model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
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
];
