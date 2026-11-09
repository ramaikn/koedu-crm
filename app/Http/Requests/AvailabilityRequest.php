<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAvailabilities;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** Isian halaman "Jam tersedia" milik tutor. Hak akses dicek di controller lewat TutorPolicy::updateAvailability. */
class AvailabilityRequest extends FormRequest
{
    use ValidatesAvailabilities;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->availabilityRules();
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->checkAvailabilityOverlap($validator)];
    }
}
