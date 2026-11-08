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
        // Saklar utama. Walau true, AI baru aktif bila ada API key untuk salah satu provider (lihat AiAvailability).
        'enabled' => (bool) env('AI_ENABLED', true),
        // Urutan provider. Provider berikutnya dipakai bila semua key provider sebelumnya gagal/limit.
        'providers' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_PROVIDERS', 'gemini'))))),
        'timeout' => (int) env('AI_TIMEOUT_SECONDS', 20),
        'gemini' => [
            'keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('GEMINI_API_KEYS', ''))))),
            'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
            'key_cooldown' => (int) env('GEMINI_KEY_COOLDOWN_SECONDS', 60),
        ],
        // Provider cadangan yang kompatibel dengan format OpenAI (OpenAI, OpenRouter, Groq, model lokal, dan lainnya).
        'openai' => [
            'base_url' => rtrim((string) env('AI_OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
            'key' => env('AI_OPENAI_API_KEY'),
            'model' => env('AI_OPENAI_MODEL', 'gpt-4o-mini'),
        ],
        'extraction' => [
            // Draf jadwal hanya dibuat bila AI yakin chat itu permintaan jadwal sebesar nilai ini atau lebih (0-1).
            'min_confidence' => (float) env('AI_MIN_CONFIDENCE', 0.6),
            // Jumlah pesan terakhir dalam percakapan yang dibaca AI sebagai konteks.
            'context_messages' => (int) env('AI_CONTEXT_MESSAGES', 8),
            // Pengaman biaya: batas panggilan AI per percakapan per jam.
            'max_calls_per_hour' => (int) env('AI_MAX_CALLS_PER_CONVERSATION_HOUR', 20),
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
