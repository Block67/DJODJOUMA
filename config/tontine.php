<?php

return [
    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN') ?: throw new \Exception('TELEGRAM_BOT_TOKEN is not set in .env'),
        'webhook_url' => env('TELEGRAM_WEBHOOK_URL') ?: throw new \Exception('TELEGRAM_WEBHOOK_URL is not set in .env'),
    ],
    'btcpay' => [
        'api_key' => env('BTCPAY_API_KEY') ?: throw new \Exception('BTCPAY_API_KEY is not set in .env'),
        'store_id' => env('BTCPAY_STORE_ID') ?: throw new \Exception('BTCPAY_STORE_ID is not set in .env'),
        'server_url' => env('BTCPAY_SERVER_URL') ?: throw new \Exception('BTCPAY_SERVER_URL is not set in .env'),
        'webhook_secret' => env('BTCPAY_WEBHOOK_SECRET') ?: throw new \Exception('BTCPAY_WEBHOOK_SECRET is not set in .env'),
    ],
    'exchange' => [
        'default_currency' => 'XOF',
        'default_rate' => env('FCFA_TO_BTC_RATE') ?: throw new \Exception('FCFA_TO_BTC_RATE is not set in .env'),
    ],
];
