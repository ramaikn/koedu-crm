<?php

namespace App\Http\Requests;

use App\Enums\BenefitType;
use App\Enums\DegreeLevel;
use App\Enums\DeliveryMode;
use App\Enums\PackageCategory;
use App\Enums\ScheduleType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $package = $this->route('package');

        return [
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('packages', 'code')->ignore($package?->id)],
            'category' => ['required', Rule::enum(PackageCategory::class)],
            'name' => ['required', 'string', 'max:255'],
            'schedule_type' => ['required', Rule::enum(ScheduleType::class)],
            'delivery' => ['required', Rule::enum(DeliveryMode::class)],
            'degree_level' => ['nullable', Rule::enum(DegreeLevel::class)],
            'duration_days' => ['required', 'integer', 'between:1,730'],
            'price' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'list_price' => ['nullable', 'integer', 'min:0', 'max:1000000000', 'gte:price'],
            'max_participants' => ['nullable', 'integer', 'between:1,100', 'required_if:delivery,group'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
            'benefits' => ['present', 'array', 'max:30'],
            'benefits.*.benefit_type' => ['required', Rule::enum(BenefitType::class), 'distinct'],
            // Kosong = tanpa batas (mis. review esai tanpa batas).
            'benefits.*.quantity' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.regex' => 'Kode hanya boleh huruf, angka, tanda minus, dan garis bawah.',
            'list_price.gte' => 'Harga coret tidak boleh lebih kecil dari harga jual.',
            'max_participants.required_if' => 'Kelas grup wajib punya batas peserta.',
            'benefits.*.benefit_type.distinct' => 'Jenis kuota tidak boleh dobel.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'nama paket',
            'duration_days' => 'masa aktif',
            'price' => 'harga',
            'list_price' => 'harga coret',
            'max_participants' => 'batas peserta',
        ];
    }
}
