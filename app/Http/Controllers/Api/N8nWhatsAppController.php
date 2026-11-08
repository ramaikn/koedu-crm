<?php

namespace App\Http\Controllers\Api;

use App\Actions\WhatsApp\ReceiveWhatsAppMessage;
use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/** Menerima webhook WA yang diteruskan n8n (isi body = payload mentah dari gateway). */
class N8nWhatsAppController extends Controller
{
    public function incoming(Request $request, WhatsAppProvider $provider, ReceiveWhatsAppMessage $receive): JsonResponse
    {
        $messages = $provider->parseWebhook($request->json()->all());

        $stored = 0;
        foreach ($messages as $incoming) {
            if ($receive->handle($incoming) !== null) {
                $stored++;
            }
        }

        Log::info('Webhook WA diproses', ['driver' => $provider->name(), 'diterima' => count($messages), 'disimpan' => $stored]);

        return response()->json(['received' => count($messages), 'stored' => $stored]);
    }
}
