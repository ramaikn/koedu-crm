<?php

namespace App\Http\Controllers;

use App\Enums\ConsultationStatus;
use App\Http\Requests\TutorRequest;
use App\Models\Consultation;
use App\Models\ExpertiseField;
use App\Models\Tutor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/** Profil tutor: bidang keahlian, jenjang, batas sesi, dan jam tersedia. Dikelola admin dan director. */
class TutorController extends Controller
{
    private const DAYS = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Tutor::class);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'field' => ['nullable', 'integer'],
            'degree' => ['nullable', 'in:S2,S3'],
            'active' => ['nullable', 'in:1,0'],
        ]);

        $weekStart = now()->startOfWeek();

        $tutors = Tutor::query()
            ->with('expertiseFields:id,name')
            ->withCount(['consultations as weekly_load' => fn ($q) => $q
                ->where('status', '!=', ConsultationStatus::Cancelled)
                ->where('starts_at', '>=', $weekStart)
                ->where('starts_at', '<', $weekStart->copy()->addWeek())])
            ->when(! empty($filters['q']), fn ($q) => $q->whereRaw('lower(name) like ?', ['%'.mb_strtolower($filters['q']).'%']))
            ->when(! empty($filters['field']), fn ($q) => $q->whereHas('expertiseFields', fn ($f) => $f->where('expertise_fields.id', $filters['field'])))
            ->when(! empty($filters['degree']), fn ($q) => $q->where('highest_degree', $filters['degree']))
            ->when(isset($filters['active']), fn ($q) => $q->where('is_active', $filters['active'] === '1'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Tutor $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'degree' => $t->highest_degree->value,
                'fields' => $t->expertiseFields->pluck('name')->sort()->values(),
                'weekly_load' => $t->weekly_load,
                'max_weekly_sessions' => $t->max_weekly_sessions,
                'is_active' => $t->is_active,
            ]);

        return Inertia::render('tutors/index', [
            'tutors' => $tutors,
            'filters' => ['q' => $filters['q'] ?? '', 'field' => $filters['field'] ?? '', 'degree' => $filters['degree'] ?? '', 'active' => $filters['active'] ?? ''],
            'fields' => ExpertiseField::orderBy('name')->get(['id', 'name']),
            'can_create' => $request->user()->can('create', Tutor::class),
        ]);
    }

    public function show(Request $request, Tutor $tutor): Response
    {
        Gate::authorize('view', $tutor);

        $tutor->load(['expertiseFields', 'availabilities']);

        $upcoming = Consultation::where('tutor_id', $tutor->id)
            ->where('status', ConsultationStatus::Scheduled)
            ->where('starts_at', '>=', now())
            ->with('participants.client:id,name')
            ->orderBy('starts_at')
            ->limit(10)
            ->get();

        return Inertia::render('tutors/show', [
            'tutor' => [
                'id' => $tutor->id,
                'name' => $tutor->name,
                'phone' => $tutor->phone,
                'degree' => $tutor->highest_degree->value,
                'max_weekly_sessions' => $tutor->max_weekly_sessions,
                'is_active' => $tutor->is_active,
                'bio' => $tutor->bio,
                'fields' => $tutor->expertiseFields->map(fn ($f) => ['name' => $f->name, 'group' => $f->group])->sortBy('name')->values(),
                'availability' => collect(self::DAYS)->map(fn ($name, $day) => [
                    'day' => $name,
                    'slots' => $tutor->availabilities->where('day_of_week', $day)->sortBy('start_time')
                        ->map(fn ($s) => substr($s->start_time, 0, 5).' - '.substr($s->end_time, 0, 5))->values(),
                ])->values(),
            ],
            'upcoming' => $upcoming->map(fn (Consultation $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'starts_at' => $c->starts_at->toIso8601String(),
                'clients' => $c->participants->map(fn ($p) => $p->client->name)->values(),
            ]),
            'can_update' => $request->user()->can('update', $tutor),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Tutor::class);

        return Inertia::render('tutors/form', ['tutor' => null, ...$this->formOptions()]);
    }

    public function store(TutorRequest $request): RedirectResponse
    {
        Gate::authorize('create', Tutor::class);

        $tutor = DB::transaction(function () use ($request) {
            $tutor = Tutor::create($request->safe()->only(['name', 'phone', 'highest_degree', 'max_weekly_sessions', 'is_active', 'bio']));
            $this->syncRelations($tutor, $request);

            return $tutor;
        });
        Log::info('Tutor dibuat', ['tutor_id' => $tutor->id, 'by' => $request->user()->id]);

        return to_route('tutors.show', $tutor)->with('success', 'Tutor ditambahkan.');
    }

    public function edit(Tutor $tutor): Response
    {
        Gate::authorize('update', $tutor);

        $tutor->load(['expertiseFields:id', 'availabilities']);

        return Inertia::render('tutors/form', [
            'tutor' => [
                'id' => $tutor->id,
                'name' => $tutor->name,
                'phone' => $tutor->phone,
                'highest_degree' => $tutor->highest_degree->value,
                'max_weekly_sessions' => $tutor->max_weekly_sessions,
                'is_active' => $tutor->is_active,
                'bio' => $tutor->bio,
                'expertise_field_ids' => $tutor->expertiseFields->pluck('id')->values(),
                'availabilities' => $tutor->availabilities->sortBy(['day_of_week', 'start_time'])->map(fn ($s) => [
                    'day_of_week' => $s->day_of_week,
                    'start_time' => substr($s->start_time, 0, 5),
                    'end_time' => substr($s->end_time, 0, 5),
                ])->values(),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(TutorRequest $request, Tutor $tutor): RedirectResponse
    {
        Gate::authorize('update', $tutor);

        DB::transaction(function () use ($request, $tutor) {
            $tutor->update($request->safe()->only(['name', 'phone', 'highest_degree', 'max_weekly_sessions', 'is_active', 'bio']));
            $this->syncRelations($tutor, $request);
        });

        return to_route('tutors.show', $tutor)->with('success', 'Profil tutor diperbarui.');
    }

    private function syncRelations(Tutor $tutor, TutorRequest $request): void
    {
        $tutor->expertiseFields()->sync($request->validated('expertise_field_ids'));

        // Jam tersedia diganti seluruhnya sesuai isi form.
        $tutor->availabilities()->delete();
        $tutor->availabilities()->createMany(collect($request->validated('availabilities'))->map(fn ($s) => [
            'day_of_week' => $s['day_of_week'],
            'start_time' => $s['start_time'],
            'end_time' => $s['end_time'],
        ])->all());
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'fields' => ExpertiseField::orderBy('group')->orderBy('name')->get(['id', 'name', 'group']),
            'days' => collect(self::DAYS)->map(fn ($name, $day) => ['value' => $day, 'label' => $name])->values(),
        ];
    }
}
