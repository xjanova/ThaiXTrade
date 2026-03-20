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

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY', ''),
        'secret_key' => env('TURNSTILE_SECRET_KEY', ''),
    ],

    // Stripe — ระบบชำระเงินสำหรับ ICO Token Sale
    'stripe' => [
        'key' => env('STRIPE_KEY', ''),
        'secret' => env('STRIPE_SECRET', ''),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
    ],

    // TPIX Chain — blockchain config
    'tpix_chain' => [
        'rpc_url' => env('TPIX_RPC_URL', 'https://rpc.tpix.online'),
        'chain_id' => env('TPIX_CHAIN_ID', 4289),
        'master_wallet' => env('TPIX_MASTER_WALLET', ''),
    ],
];
