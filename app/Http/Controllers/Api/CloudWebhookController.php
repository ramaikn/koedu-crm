<?php

namespace App\Http\Controllers\Api;

use App\Actions\WhatsApp\ReceiveWhatsAppMessage;
use App\Http\Controllers\Controller;
use App\Services\WhatsApp\CloudApiDriver;
use App\Services\WhatsApp\WhatsAppProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook langsung dari Meta (WhatsApp Cloud API). Hanya aktif bila WHATSAPP_DRIVER=cloud_api.
 * Meta memanggil URL ini tanpa token n8n, jadi keamanannya lewat verify token dan tanda tangan HMAC.
 */
class CloudWebhookController extends Controller
{
    /** Verifikasi saat webhook didaftarkan di dashboard Meta. */
    public function verify(Request $request, WhatsAppProvider $provider): Response
    {
        abort_unless($provider instanceof CloudApiDriver, 404);

        $challenge = $provider->verifyChallenge(
            $request->query('hub_mode'),
            $request->query('hub_verify_token'),
            $request->query('hub_challenge'),
        );

        abort_if($challenge === null, 403);

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, WhatsAppProvider $provider, ReceiveWhatsAppMessage $receive): JsonResponse
    {
        abort_unless($provider instanceof CloudApiDriver, 404);

        if (! $provider->isValidSignature($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            Log::warning('Webhook Cloud API ditolak: tanda tangan tidak valid');
            abort(403);
        }

        $messages = $provider->parseWebhook($request->json()->all());
        foreach ($messages as $incoming) {
            $receive->handle($incoming);
        }

        return response()->json(['received' => count($messages)]);
    }
}
