<?php

namespace App\Http\Requests;

use App\Enums\BenefitType;
use App\Enums\ConsultationType;
use App\Enums\MeetingMode;
use App\Services\Scheduling\ScheduleInput;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Form buat dan ubah sesi. Otorisasi dicek di controller lewat policy. */
class ConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ConsultationType::class)],
            'tutor_id' => ['required', 'integer', 'exists:tutors,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'topic' => ['nullable', 'string', 'max:2000'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'between:15,480'],
            'meeting_mode' => ['required', Rule::enum(MeetingMode::class)],
            'meeting_url' => ['nullable', 'url', 'max:500'],
            'location' => ['nullable', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'between:1,100'],
            'benefit_type' => ['nullable', Rule::in(array_map(fn (BenefitType $t) => $t->value, BenefitType::sessionBased()))],
            'client_ids' => ['required', 'array', 'min:1'],
            'client_ids.*' => ['integer', 'exists:clients,id'],
            'confirm_warnings' => ['nullable', 'boolean'],
            // Diisi bila jadwal dibuat dari draf AI di inbox. Draf ditandai disetujui saat jadwal tersimpan.
            'schedule_draft_id' => ['nullable', 'integer', 'exists:schedule_drafts,id'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'tutor_id' => 'tutor',
            'date' => 'tanggal',
            'start_time' => 'jam mulai',
            'duration_minutes' => 'durasi',
            'client_ids' => 'client',
            'meeting_url' => 'link meeting',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // Sesi baru harus di masa depan. Mengubah sesi lama (mis. memperbaiki catatan) tidak dibatasi.
                if ($this->isMethod('POST') && ! $validator->errors()->hasAny(['date', 'start_time'])) {
                    $start = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$this->input('date')} {$this->input('start_time')}", config('app.timezone'));
                    if ($start !== false && $start->isPast()) {
                        $validator->errors()->add('date', 'Waktu sesi harus di masa depan.');
                    }
                }
            },
        ];
    }

    public function toInput(): ScheduleInput
    {
        // Data sudah lolos validasi, jadi fromForm tidak akan mengembalikan null.
        return ScheduleInput::fromForm($this->validated());
    }

    public function confirmsWarnings(): bool
    {
        return $this->boolean('confirm_warnings');
    }
}
