<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;

/**
 * Aturan isian jam tersedia tutor. Dipakai form profil tutor (admin/director) dan halaman jam tersedia milik tutor,
 * supaya kedua jalur menerima data dengan aturan yang sama.
 */
trait ValidatesAvailabilities
{
    /** @return array<string, mixed> */
    protected function availabilityRules(): array
    {
        return [
            'availabilities' => ['present', 'array', 'max:40'],
            'availabilities.*.day_of_week' => ['required', 'integer', 'between:1,7'],
            'availabilities.*.start_time' => ['required', 'date_format:H:i'],
            'availabilities.*.end_time' => ['required', 'date_format:H:i'],
        ];
    }

    /** Selesai harus setelah mulai, dan jam pada hari yang sama tidak boleh saling tumpang tindih. */
    protected function checkAvailabilityOverlap(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

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
    }
}
