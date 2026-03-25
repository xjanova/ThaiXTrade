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
    ],

    // TPIX Chain — blockchain config
    'tpix_chain' => [
        'rpc_url' => env('TPIX_RPC_URL', 'https://rpc.tpix.online'),
        'chain_id' => env('TPIX_CHAIN_ID', 4289),
        'master_wallet' => env('TPIX_MASTER_WALLET', ''),
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
    ],
];
