<?php

namespace App\Actions\Consultations;

use App\Enums\ConsultationStatus;
use App\Models\Consultation;
use App\Models\Tutor;
use App\Models\User;
use App\Services\Scheduling\EnrollmentResolver;
use App\Services\Scheduling\ScheduleChecker;
use App\Services\Scheduling\ScheduleInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Membuat atau mengubah sesi konsultasi setelah lolos pemeriksaan jadwal.
 * Dipakai form admin, dan nanti juga persetujuan draf dari AI.
 */
final class ScheduleConsultation
{
    public function __construct(
        private readonly ScheduleChecker $checker,
        private readonly EnrollmentResolver $enrollments,
    ) {}

    /**
     * @throws ValidationException bila jadwal bermasalah (key "schedule") atau peringatan belum dikonfirmasi (key "confirm_warnings")
     */
    public function handle(ScheduleInput $input, User $by, bool $confirmWarnings = false, ?Consultation $existing = null): Consultation
    {
        return DB::transaction(function () use ($input, $by, $confirmWarnings, $existing) {
            // Kunci baris tutor selama pemeriksaan dan penyimpanan. Dua admin yang menjadwalkan
            // tutor yang sama di jam yang sama akan antre, sehingga yang kedua melihat bentrok.
            Tutor::whereKey($input->tutorId)->lockForUpdate()->first();

            if ($existing !== null) {
                $existing = Consultation::lockForUpdate()->findOrFail($existing->id);
                if ($existing->status !== ConsultationStatus::Scheduled) {
                    throw ValidationException::withMessages(['schedule' => 'Hanya sesi yang masih terjadwal yang bisa diubah.']);
                }
            }

            $result = $this->checker->check($input, $existing?->id);
            if (! $result->passes()) {
                throw ValidationException::withMessages(['schedule' => implode("\n", $result->errors)]);
            }
            if ($result->warnings !== [] && ! $confirmWarnings) {
                throw ValidationException::withMessages(['confirm_warnings' => 'Ada peringatan yang perlu dikonfirmasi dulu.']);
            }

            $consultation = $existing === null ? $this->create($input, $by) : $this->update($existing, $input);
            $this->syncParticipants($consultation, $input);

            Log::info($existing === null ? 'Sesi dibuat' : 'Sesi diubah', [
                'consultation_id' => $consultation->id,
                'tutor_id' => $consultation->tutor_id,
                'by' => $by->id,
                'warnings' => count($result->warnings),
            ]);

            return $consultation->load('participants');
        });
    }

    private function create(ScheduleInput $input, User $by): Consultation
    {
        return Consultation::create([
            ...$this->attributes($input),
            'status' => ConsultationStatus::Scheduled,
            'created_by' => $by->id,
        ]);
    }

    private function update(Consultation $consultation, ScheduleInput $input): Consultation
    {
        $needsRenotify = ! $consultation->starts_at->equalTo($input->startsAt) || $consultation->tutor_id !== $input->tutorId;

        $consultation->fill($this->attributes($input));
        // Salinan di Google Sheets selalu perlu disegarkan setelah perubahan apa pun.
        $consultation->sheet_synced_at = null;

        if ($needsRenotify) {
            // Waktu atau tutor berubah: tutor dan client harus diberi tahu ulang, dan pengingat dihitung ulang.
            $consultation->tutor_notified_at = null;
            $consultation->tutor_reminded_at = null;
            $consultation->participants()->update([
                'confirmation_sent_at' => null,
                'reminder_day_sent_at' => null,
                'reminder_hour_sent_at' => null,
            ]);
        }

        $consultation->save();

        return $consultation;
    }

    /** @return array<string, mixed> */
    private function attributes(ScheduleInput $input): array
    {
        return [
            'type' => $input->type,
            'tutor_id' => $input->tutorId,
            'title' => $input->title,
            'topic' => $input->topic,
            'starts_at' => $input->startsAt,
            'ends_at' => $input->endsAt,
            'meeting_mode' => $input->meetingMode,
            'meeting_url' => $input->meetingUrl,
            'location' => $input->location,
            'capacity' => $input->capacity,
            'benefit_type' => $input->benefitType,
            'schedule_draft_id' => $input->scheduleDraftId,
        ];
    }

    /** Peserta yang tidak lagi dipilih dihapus, yang baru ditambah, yang tetap dibiarkan (absensi tidak hilang). */
    private function syncParticipants(Consultation $consultation, ScheduleInput $input): void
    {
        $wanted = $input->uniqueClientIds();

        $consultation->participants()->whereNotIn('client_id', $wanted)->delete();
        $existing = $consultation->participants()->get()->keyBy('client_id');

        foreach ($wanted as $clientId) {
            $participant = $existing->get($clientId);
            $enrollmentId = $participant?->enrollment_id
                ?? ($input->benefitType ? $this->enrollments->usableFor($clientId, $input->benefitType, $input->startsAt)?->id : null);

            if ($participant === null) {
                $consultation->participants()->create(['client_id' => $clientId, 'enrollment_id' => $enrollmentId]);
            } elseif ($participant->enrollment_id === null && $enrollmentId !== null) {
                $participant->update(['enrollment_id' => $enrollmentId]);
            }
        }
    }
}
