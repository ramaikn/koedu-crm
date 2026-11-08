<?php

namespace App\Http\Controllers;

use App\Enums\DraftStatus;
use App\Models\ScheduleDraft;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Draf jadwal dari AI. Persetujuan terjadi saat jadwal dibuat (ScheduleConsultation); di sini hanya penolakan. */
class ScheduleDraftController extends Controller
{
    public function reject(Request $request, ScheduleDraft $draft): RedirectResponse
    {
        Gate::authorize('review', $draft);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        DB::transaction(function () use ($draft, $data, $request) {
            $draft = ScheduleDraft::lockForUpdate()->findOrFail($draft->id);
            if ($draft->status !== DraftStatus::Pending) {
                throw ValidationException::withMessages(['draft' => 'Draf ini sudah diproses.']);
            }

            $draft->update([
                'status' => DraftStatus::Rejected,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'reject_reason' => $data['reason'] ?? null,
            ]);
            Log::info('Draf jadwal ditolak', ['draft_id' => $draft->id, 'by' => $request->user()->id]);
        });

        return back()->with('success', 'Draf jadwal ditolak.');
    }
}
