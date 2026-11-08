<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sheets\SheetsAvailability;
use App\Services\Sheets\SheetSyncDispatcher;
use Illuminate\Http\JsonResponse;

/** Dipanggil workflow n8n untuk pencocokan penuh Google Sheets (rekonsiliasi malam). */
class N8nSheetsController extends Controller
{
    public function sync(SheetSyncDispatcher $dispatcher): JsonResponse
    {
        return response()->json([
            'enabled' => SheetsAvailability::enabled(),
            'queued' => $dispatcher->all(),
        ]);
    }
}
