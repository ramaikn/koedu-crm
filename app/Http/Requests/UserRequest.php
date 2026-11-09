<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Tutor;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/** Form akun pengguna oleh director. Hak akses dicek di UserController lewat UserPolicy. */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = ['email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : $this->input('email')];
        if (is_string($this->input('phone')) && trim($this->input('phone')) !== '') {
            $merge['phone'] = PhoneNumber::normalize($this->input('phone'));
        }
        // Profil tutor hanya relevan untuk role tutor.
        if ($this->input('role') !== UserRole::Tutor->value) {
            $merge['tutor_id'] = null;
        }
        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var User|null $target */
        $target = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target?->id)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'phone' => ['nullable', 'string', 'regex:/^62\d{8,13}$/'],
            'is_active' => ['required', 'boolean'],
            // Wajib saat membuat akun. Saat mengubah, kosong berarti password tidak diganti.
            'password' => [$target ? 'nullable' : 'required', 'string', Password::defaults()],
            'tutor_id' => [
                Rule::requiredIf($this->input('role') === UserRole::Tutor->value),
                'nullable',
                'integer',
                // Profil yang belum punya akun, atau yang memang sudah tertaut ke akun ini.
                Rule::exists('tutors', 'id')->where(fn ($q) => $q->whereNull('user_id')->when($target, fn ($q) => $q->orWhere('user_id', $target->id))),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.unique' => 'Email ini sudah dipakai akun lain.',
            'phone.regex' => 'Nomor WhatsApp tidak valid. Contoh: 0812 3456 7890.',
            'tutor_id.required' => 'Pilih profil tutor untuk akun ini.',
            'tutor_id.exists' => 'Profil tutor ini sudah tertaut ke akun lain.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                /** @var User|null $target */
                $target = $this->route('user');
                if (! $target || $target->isNot($this->user())) {
                    return;
                }

                // Director tidak boleh mengunci dirinya sendiri: role dan status akunnya sendiri tetap.
                if ($this->input('role') !== $target->role->value) {
                    $validator->errors()->add('role', 'Anda tidak bisa mengubah role akun Anda sendiri.');
                }
                if (! $this->boolean('is_active')) {
                    $validator->errors()->add('is_active', 'Anda tidak bisa menonaktifkan akun Anda sendiri.');
                }
            },
        ];
    }

    /** Profil tutor yang dipilih, atau null untuk role selain tutor. */
    public function selectedTutor(): ?Tutor
    {
        $id = $this->validated('tutor_id');

        return $id ? Tutor::find($id) : null;
    }
}
