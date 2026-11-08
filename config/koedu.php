<?php

/*
| Setelan khusus Koedu CRM. Semua nilai rahasia diambil dari .env.
*/

return [

    'whatsapp' => [
        // waha = gateway self-hosted untuk simulasi, cloud_api = WhatsApp Cloud API resmi.
        'driver' => env('WHATSAPP_DRIVER', 'waha'),
        'waha' => [
            'url' => env('WAHA_URL', 'http://waha:3000'),
            'session' => env('WAHA_SESSION', 'default'),
            'api_key' => env('WAHA_API_KEY'),
        ],
        'cloud_api' => [
            'phone_number_id' => env('WA_CLOUD_PHONE_NUMBER_ID'),
            'access_token' => env('WA_CLOUD_ACCESS_TOKEN'),
            'app_secret' => env('WA_CLOUD_APP_SECRET'),
            'verify_token' => env('WA_CLOUD_VERIFY_TOKEN'),
            'api_version' => env('WA_CLOUD_API_VERSION', 'v23.0'),
        ],
    ],

    'ai' => [
        // Urutan provider. Provider berikutnya dipakai bila semua key provider sebelumnya gagal/limit.
        'providers' => array_filter(array_map('trim', explode(',', (string) env('AI_PROVIDERS', 'gemini')))),
        'timeout' => (int) env('AI_TIMEOUT_SECONDS', 20),
        'gemini' => [
            'keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('GEMINI_API_KEYS', ''))))),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'key_cooldown' => (int) env('GEMINI_KEY_COOLDOWN_SECONDS', 60),
        ],
    ],

    'sheets' => [
        'enabled' => (bool) env('GOOGLE_SHEETS_ENABLED', false),
        'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID'),
        'credentials_path' => env('GOOGLE_SHEETS_CREDENTIALS_PATH'),
    ],

    'n8n' => [
        // Token yang dikirim n8n di header X-Koedu-Token saat memanggil API Laravel.
        'api_token' => env('N8N_API_TOKEN'),
    ],

    'quota' => [
        // Apakah client yang tidak hadir tetap dipotong kuotanya. Kebijakan bisnis, bisa diubah lewat .env.
        'deduct_on_absent' => (bool) env('QUOTA_DEDUCT_ON_ABSENT', false),
    ],

    'seed' => [
        // Password akun contoh (director, admin, tutor). Hanya untuk data demo lokal.
        'user_password' => env('SEED_USER_PASSWORD', 'password'),
    ],

];
