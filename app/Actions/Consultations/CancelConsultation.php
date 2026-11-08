<?php

namespace App\Actions\Consultations;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Membatalkan sesi yang masih terjadwal. Kuota tidak pernah terpotong untuk sesi yang belum selesai. */
final class CancelConsultation
{
    public function handle(Consultation $consultation, string $reason, User $by): Consultation
    {
        return DB::transaction(function () use ($consultation, $reason, $by) {
            $consultation = Consultation::lockForUpdate()->findOrFail($consultation->id);

            if ($consultation->status !== ConsultationStatus::Scheduled) {
                throw ValidationException::withMessages(['status' => 'Hanya sesi yang masih terjadwal yang bisa dibatalkan.']);
            }

            $consultation->update([
                'status' => ConsultationStatus::Cancelled,
                'cancel_reason' => $reason,
                // Salinan di Google Sheets perlu disegarkan.
                'sheet_synced_at' => null,
            ]);

            Log::info('Sesi dibatalkan', ['consultation_id' => $consultation->id, 'by' => $by->id]);

            return $consultation;
        });
    }
}
