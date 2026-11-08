<?php

namespace App\Http\Requests;

use App\Enums\ClientStatus;
use App\Enums\DegreeLevel;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Nomor diseragamkan dulu (081.. / +62.. / 62..) supaya pengecekan duplikat akurat.
        if (is_string($this->input('phone'))) {
            $this->merge(['phone' => PhoneNumber::normalize($this->input('phone'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $client = $this->route('client');

        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^62\d{8,13}$/', Rule::unique('clients', 'phone')->ignore($client?->id)],
            'email' => ['nullable', 'email', 'max:255'],
            'university' => ['nullable', 'string', 'max:255'],
            'study_program' => ['nullable', 'string', 'max:255'],
            'expertise_field_id' => ['nullable', 'integer', 'exists:expertise_fields,id'],
            'degree_level' => ['nullable', Rule::enum(DegreeLevel::class)],
            'status' => ['required', Rule::enum(ClientStatus::class)],
            'source' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Nomor WhatsApp tidak valid. Contoh: 0812 3456 7890.',
            'phone.unique' => 'Nomor ini sudah terdaftar untuk client lain.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'phone' => 'nomor WhatsApp',
            'university' => 'universitas',
            'study_program' => 'jurusan',
            'expertise_field_id' => 'bidang',
            'degree_level' => 'jenjang',
            'notes' => 'catatan',
        ];
    }
}
