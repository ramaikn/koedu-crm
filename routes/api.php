<?php

use App\Http\Controllers\Api\CloudWebhookController;
use App\Http\Controllers\Api\N8nWhatsAppController;
use App\Http\Middleware\VerifyN8nToken;
use Illuminate\Support\Facades\Route;

// Endpoint untuk n8n. Dilindungi token di header X-Koedu-Token.
Route::middleware(VerifyN8nToken::class)->prefix('n8n')->group(function () {
    Route::post('whatsapp/incoming', [N8nWhatsAppController::class, 'incoming']);
});

// Webhook langsung dari Meta bila memakai WhatsApp Cloud API resmi (WHATSAPP_DRIVER=cloud_api).
Route::get('webhooks/whatsapp', [CloudWebhookController::class, 'verify']);
Route::post('webhooks/whatsapp', [CloudWebhookController::class, 'receive']);
