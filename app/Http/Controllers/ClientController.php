<?php

namespace App\Http\Controllers;

use App\Enums\ClientStatus;
use App\Enums\DegreeLevel;
use App\Http\Requests\ClientRequest;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Enrollment;
use App\Models\ExpertiseField;
use App\Models\Package;
use App\Models\Tutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/** Data client. Admin mengelola, director membaca, tutor hanya melihat client yang ia bimbing. */
class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Client::class);
        $user = $request->user();

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:'.implode(',', ClientStatus::values())],
        ]);

        $query = Client::query()
            ->with('expertiseField:id,name')
            ->withCount(['enrollments as active_packages' => fn ($q) => $q->usableOn()])
            ->orderBy('name');

        if ($user->isTutor()) {
            $user->tutor ? $query->handledByTutor($user->tutor) : $query->whereRaw('1 = 0');
        }
        if (! empty($filters['q'])) {
            $query->matching($filters['q']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return Inertia::render('clients/index', [
            'clients' => $query->paginate(20)->withQueryString()->through(fn (Client $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'phone' => $c->phone,
                'university' => $c->university,
                'study_program' => $c->study_program,
                'degree_level' => $c->degree_level?->value,
                'status' => ['value' => $c->status->value, 'label' => $c->status->label()],
                'active_packages' => $c->active_packages,
            ]),
            'filters' => ['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? ''],
            'statuses' => ClientStatus::options(),
            'can_create' => $user->can('create', Client::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Client::class);

        return Inertia::render('clients/form', ['client' => null, ...$this->formOptions()]);
    }

    public function store(ClientRequest $request): RedirectResponse
    {
        Gate::authorize('create', Client::class);

        $client = Client::create([...$request->validated(), 'created_by' => $request->user()->id]);
        Log::info('Client dibuat', ['client_id' => $client->id, 'by' => $request->user()->id]);

        return to_route('clients.show', $client)->with('success', 'Client berhasil ditambahkan.');
    }

    public function show(Request $request, Client $client): Response
    {
        Gate::authorize('view', $client);
        $user = $request->user();
        // Harga dan status bayar hanya untuk staf kantor. Tutor tidak perlu melihat data keuangan.
        $sawFinance = $user->isStaff();

        $enrollments = $client->enrollments()
            ->with(['package:id,name,category', 'primaryTutor:id,name', 'quotas'])
            ->when($user->isTutor(), fn ($q) => $q->where('primary_tutor_id', $user->tutor?->id))
            ->orderByDesc('starts_on')->orderByDesc('id')
            ->get();

        $sessions = Consultation::query()
            ->whereHas('participants', fn ($q) => $q->where('client_id', $client->id))
            ->when($user->isTutor(), fn ($q) => $q->where('tutor_id', $user->tutor?->id))
            ->with('tutor:id,name')
            ->orderByDesc('starts_at')
            ->limit(15)
            ->get();

        return Inertia::render('clients/show', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
                'email' => $client->email,
                'university' => $client->university,
                'study_program' => $client->study_program,
                'expertise_field' => $client->expertiseField?->name,
                'degree_level' => $client->degree_level?->value,
                'status' => ['value' => $client->status->value, 'label' => $client->status->label()],
                'source' => $client->source,
                'notes' => $client->notes,
                'created_at' => $client->created_at->toIso8601String(),
            ],
            'enrollments' => $enrollments->map(fn (Enrollment $e) => [
                'id' => $e->id,
                'package' => $e->package->name,
                'category' => $e->package->category->label(),
                'status' => ['value' => $e->status->value, 'label' => $e->status->label()],
                'starts_on' => $e->starts_on->toDateString(),
                'ends_on' => $e->ends_on->toDateString(),
                'tutor' => $e->primaryTutor?->name,
                'tutor_id' => $e->primary_tutor_id,
                'notes' => $e->notes,
                'price' => $sawFinance ? $e->price : null,
                'payment' => $sawFinance ? [
                    'status' => $e->payment_status->value,
                    'label' => $e->payment_status->label(),
                    'paid_at' => $e->paid_at?->toIso8601String(),
                    'method' => $e->payment_method,
                    'has_proof' => $e->payment_proof_path !== null,
                ] : null,
                'quotas' => $e->quotas->map(fn ($q) => [
                    'id' => $q->id,
                    'label' => $q->benefit_type->label(),
                    'session_based' => $q->benefit_type->isSessionBased(),
                    'day_based' => $q->benefit_type->isDayBased(),
                    'total' => $q->total,
                    'used' => $q->used,
                    'remaining' => $q->remaining(),
                ])->values(),
            ])->values(),
            'sessions' => $sessions->map(fn (Consultation $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'tutor' => $c->tutor->name,
                'starts_at' => $c->starts_at->toIso8601String(),
                'status' => ['value' => $c->status->value, 'label' => $c->status->label()],
            ])->values(),
            'conversation_id' => $user->isStaff() ? $client->conversation?->id : null,
            'packages' => $user->can('create', Enrollment::class)
                ? Package::active()->orderBy('category')->orderBy('name')->get(['id', 'name', 'category', 'price', 'duration_days'])
                    ->map(fn (Package $p) => ['id' => $p->id, 'name' => $p->name, 'category' => $p->category->label(), 'price' => $p->price, 'duration_days' => $p->duration_days])
                : [],
            'tutors' => $user->can('create', Enrollment::class)
                ? Tutor::active()->orderBy('name')->get(['id', 'name'])->map(fn (Tutor $t) => ['id' => $t->id, 'name' => $t->name])
                : [],
            'can' => [
                'update' => $user->can('update', $client),
                'delete' => $user->can('delete', $client),
                'enroll' => $user->can('create', Enrollment::class),
                'schedule' => $user->can('create', Consultation::class),
                'manage_payment' => $sawFinance && $user->isAdmin(),
                'see_finance' => $sawFinance,
            ],
        ]);
    }

    public function edit(Client $client): Response
    {
        Gate::authorize('update', $client);

        return Inertia::render('clients/form', [
            'client' => $client->only(['id', 'name', 'phone', 'email', 'university', 'study_program', 'expertise_field_id', 'source', 'notes'])
                + ['degree_level' => $client->degree_level?->value, 'status' => $client->status->value],
            ...$this->formOptions(),
        ]);
    }

    public function update(ClientRequest $request, Client $client): RedirectResponse
    {
        Gate::authorize('update', $client);

        $client->update($request->validated());

        return to_route('clients.show', $client)->with('success', 'Data client diperbarui.');
    }

    /** Client dihapus lunak: riwayat sesi dan pembayaran tetap utuh. */
    public function destroy(Client $client): RedirectResponse
    {
        Gate::authorize('delete', $client);

        $client->delete();
        Log::info('Client dihapus', ['client_id' => $client->id]);

        return to_route('clients.index')->with('success', 'Client dihapus.');
    }

    /** Pencarian cepat untuk pemilih client di form jadwal. */
    public function search(Request $request): JsonResponse
    {
        Gate::authorize('create', Consultation::class);

        $q = trim((string) $request->query('q', ''));

        $clients = Client::query()
            ->when($q !== '', fn ($query) => $query->matching($q))
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'phone']);

        return response()->json($clients);
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'fields' => ExpertiseField::orderBy('group')->orderBy('name')->get(['id', 'name', 'group']),
            'degrees' => DegreeLevel::options(),
            'statuses' => ClientStatus::options(),
        ];
    }
}
