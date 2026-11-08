<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/** Dipanggil workflow n8n terjadwal. n8n hanya pemicu: semua keputusan dan pengiriman ada di Laravel. */
class N8nNotificationController extends Controller
{
    /** Batas waktu kunci. Putaran yang macet tidak boleh menahan putaran berikutnya lebih lama dari ini. */
    private const LOCK_SECONDS = 120;

    public function run(NotificationDispatcher $dispatcher): JsonResponse
    {
        // Dua pemicu yang tumpang tindih (mis. putaran lambat) tidak boleh berjalan bersamaan.
        $lock = Cache::lock('notifications:run', self::LOCK_SECONDS);
        if (! $lock->get()) {
            return response()->json(['skipped' => 'Putaran sebelumnya masih berjalan.']);
        }

        try {
            return response()->json($dispatcher->run()->toArray($dispatcher->failedRecently()));
        } finally {
            $lock->release();
        }
    }
}
