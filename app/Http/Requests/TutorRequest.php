<?php

namespace App\Http\Requests;

use App\Enums\DegreeLevel;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TutorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => PhoneNumber::normalize($this->input('phone'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tutor = $this->route('tutor');

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^62\d{8,13}$/', Rule::unique('tutors', 'phone')->ignore($tutor?->id)],
            // Tutor hanya S2 atau S3, sesuai kualifikasi pembimbing.
            'highest_degree' => ['required', Rule::in([DegreeLevel::S2->value, DegreeLevel::S3->value])],
            'max_weekly_sessions' => ['required', 'integer', 'between:1,60'],
            'is_active' => ['required', 'boolean'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'expertise_field_ids' => ['required', 'array', 'min:1'],
            'expertise_field_ids.*' => ['integer', 'exists:expertise_fields,id'],
            'availabilities' => ['present', 'array', 'max:40'],
            'availabilities.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'availabilities.*.start_time' => ['required', 'date_format:H:i'],
            'availabilities.*.end_time' => ['required', 'date_format:H:i'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Nomor WhatsApp tidak valid. Contoh: 0812 3456 7890.',
            'phone.unique' => 'Nomor ini sudah terdaftar untuk tutor lain.',
            'expertise_field_ids.required' => 'Pilih minimal satu bidang keahlian.',
            'expertise_field_ids.min' => 'Pilih minimal satu bidang keahlian.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                // Jam tersedia: selesai harus setelah mulai, dan tidak boleh saling tumpang tindih di hari yang sama.
                $byDay = collect($this->input('availabilities', []))->groupBy('day_of_week');
                foreach ($byDay as $slots) {
                    $sorted = $slots->sortBy('start_time')->values();
                    foreach ($sorted as $i => $slot) {
                        if ($slot['end_time'] <= $slot['start_time']) {
                            $validator->errors()->add('availabilities', 'Jam selesai harus setelah jam mulai.');

                            return;
                        }
                        if ($i > 0 && $slot['start_time'] < $sorted[$i - 1]['end_time']) {
                            $validator->errors()->add('availabilities', 'Jam tersedia pada hari yang sama tidak boleh tumpang tindih.');

                            return;
                        }
                    }
                }
            },
        ];
    }
}
