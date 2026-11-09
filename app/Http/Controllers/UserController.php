<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\UserRequest;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Akun login (director, admin, tutor). Hanya director. Akun tidak dihapus, cukup dinonaktifkan,
 * supaya riwayat (pembayaran dicatat oleh, jadwal dibuat oleh) tetap utuh.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
            'active' => ['nullable', 'in:1,0'],
        ]);

        $users = User::query()
            ->with('tutor:id,user_id,name')
            ->when(! empty($filters['q']), function ($q) use ($filters) {
                $term = '%'.mb_strtolower($filters['q']).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$term])->orWhereRaw('lower(email) like ?', [$term]));
            })
            ->when(! empty($filters['role']), fn ($q) => $q->where('role', $filters['role']))
            ->when(isset($filters['active']), fn ($q) => $q->where('is_active', $filters['active'] === '1'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role->value,
                'role_label' => $u->role->label(),
                'tutor_name' => $u->tutor?->name,
                'is_active' => $u->is_active,
            ]);

        return Inertia::render('users/index', [
            'users' => $users,
            'filters' => ['q' => $filters['q'] ?? '', 'role' => $filters['role'] ?? '', 'active' => $filters['active'] ?? ''],
            'roles' => UserRole::options(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('users/form', ['user' => null, ...$this->formOptions(null)]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $user = DB::transaction(function () use ($request) {
            $user = new User($request->safe()->only(['name', 'email', 'role', 'phone', 'is_active', 'password']));
            // Akun dibuat director, jadi email dianggap sudah benar. Tidak ada alur verifikasi email.
            $user->forceFill(['email_verified_at' => now()])->save();
            $this->linkTutor($user, $request->selectedTutor());

            return $user;
        });
        Log::info('Akun dibuat', ['user_id' => $user->id, 'role' => $user->role->value, 'by' => $request->user()->id]);

        return to_route('users.index')->with('success', 'Akun dibuat. Sampaikan email dan password kepada pemiliknya.');
    }

    public function edit(User $user): Response
    {
        Gate::authorize('update', $user);

        $user->load('tutor:id,user_id');

        return Inertia::render('users/form', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'phone' => $user->phone,
                'is_active' => $user->is_active,
                'tutor_id' => $user->tutor?->id,
            ],
            ...$this->formOptions($user),
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);
        if (! $request->boolean('is_active') && $user->is_active) {
            Gate::authorize('deactivate', $user);
        }

        DB::transaction(function () use ($request, $user) {
            $data = $request->safe()->only(['name', 'email', 'role', 'phone', 'is_active']);
            if (filled($request->validated('password'))) {
                $data['password'] = $request->validated('password');
            }
            $user->update($data);
            $this->linkTutor($user, $request->selectedTutor());
        });
        Log::info('Akun diubah', [
            'user_id' => $user->id,
            'role' => $user->role->value,
            'is_active' => $user->is_active,
            'password_changed' => filled($request->validated('password')),
            'by' => $request->user()->id,
        ]);

        return to_route('users.index')->with('success', 'Akun diperbarui.');
    }

    /** Satu akun paling banyak satu profil tutor. Profil lama dilepas bila diganti atau role bukan tutor lagi. */
    private function linkTutor(User $user, ?Tutor $tutor): void
    {
        Tutor::where('user_id', $user->id)->when($tutor, fn ($q) => $q->whereKeyNot($tutor->id))->update(['user_id' => null]);
        $tutor?->update(['user_id' => $user->id]);
    }

    /** @return array<string, mixed> */
    private function formOptions(?User $user): array
    {
        return [
            'roles' => UserRole::options(),
            // Profil tutor yang bisa dipilih: belum punya akun, atau sudah tertaut ke akun ini.
            'tutors' => Tutor::query()
                ->where(fn ($q) => $q->whereNull('user_id')->when($user, fn ($q) => $q->orWhere('user_id', $user->id)))
                ->orderBy('name')
                ->get(['id', 'name', 'phone']),
            'is_self' => $user !== null && $user->is(request()->user()),
        ];
    }
}
