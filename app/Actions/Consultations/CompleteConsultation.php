<?php

namespace App\Actions\Consultations;

use App\Enums\AttendanceStatus;
use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use App\Models\EnrollmentQuota;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Menandai sesi selesai: isi absensi, simpan catatan tutor, lalu potong kuota paket peserta.
 * Aman dipanggil ulang: kuota peserta yang sudah dipotong (quota_deducted_at terisi) tidak dipotong lagi.
 */
final class CompleteConsultation
{
    /**
     * @param  array<int, AttendanceStatus>  $attendance  participant_id => status. Peserta yang tidak disebut dianggap hadir.
     */
    public function handle(Consultation $consultation, array $attendance = [], ?string $tutorNotes = null): Consultation
    {
        return DB::transaction(function () use ($consultation, $attendance, $tutorNotes) {
            // Kunci baris supaya dua klik "Selesai" bersamaan tidak memotong kuota dua kali.
            $consultation = Consultation::query()->lockForUpdate()->findOrFail($consultation->id);

            if ($consultation->status === ConsultationStatus::Cancelled) {
                throw ValidationException::withMessages(['status' => 'Sesi yang dibatalkan tidak bisa diselesaikan.']);
            }

            $participants = $consultation->participants()->with('enrollment.quotas')->get();

            foreach ($participants as $participant) {
                $participant->attendance = $attendance[$participant->id] ?? AttendanceStatus::Present;
                $this->deductQuota($consultation, $participant);
                $participant->save();
            }

            $consultation->status = ConsultationStatus::Completed;
            $consultation->tutor_notes = $tutorNotes ?? $consultation->tutor_notes;
            $consultation->save();

            Log::info('Sesi diselesaikan', [
                'consultation_id' => $consultation->id,
                'participants' => $participants->count(),
            ]);

            return $consultation->load('participants');
        });
    }

    private function deductQuota(Consultation $consultation, ConsultationParticipant $participant): void
    {
        $shouldDeduct = $participant->attendance === AttendanceStatus::Present
            || ($participant->attendance === AttendanceStatus::Absent && config('koedu.quota.deduct_on_absent'));

        if (! $shouldDeduct || $participant->quota_deducted_at !== null || $consultation->benefit_type === null) {
            return;
        }

        /** @var EnrollmentQuota|null $quota */
        $quota = $participant->enrollment?->quotaFor($consultation->benefit_type);

        if ($quota === null) {
            // Sesi tanpa paket terkait (mis. sesi gratis). Tidak ada kuota yang dipotong.
            return;
        }

        if (! $quota->hasRemaining()) {
            // Sesi tetap dicatat selesai. Kuota habis ditandai di log untuk ditindaklanjuti admin.
            Log::warning('Kuota habis saat sesi diselesaikan', [
                'consultation_id' => $consultation->id,
                'enrollment_id' => $participant->enrollment_id,
                'benefit_type' => $consultation->benefit_type->value,
            ]);

            return;
        }

        $quota->increment('used');
        $participant->quota_deducted_at = now();
    }
}
