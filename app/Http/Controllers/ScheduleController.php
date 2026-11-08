<?php

namespace App\Http\Controllers;

use App\Actions\Consultations\CancelConsultation;
use App\Actions\Consultations\CompleteConsultation;
use App\Actions\Consultations\ScheduleConsultation;
use App\Enums\AttendanceStatus;
use App\Enums\BenefitType;
use App\Enums\ConsultationStatus;
use App\Enums\ConsultationType;
use App\Enums\MeetingMode;
use App\Http\Requests\ConsultationRequest;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use App\Models\ScheduleDraft;
use App\Models\Tutor;
use App\Services\Scheduling\ScheduleChecker;
use App\Services\Scheduling\ScheduleInput;
use App\Services\Scheduling\TutorSuggester;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Jadwal konsultasi. Admin membuat dan mengubah, director membaca,
 * tutor melihat dan menyelesaikan sesinya sendiri (akses data diatur ConsultationPolicy).
 */
class ScheduleController extends Controller
{
    private const MAX_RANGE_DAYS = 62;

    private const MAX_ROWS = 300;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Consultation::class);
        $user = $request->user();
        $tz = config('app.timezone');

        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'tutor_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(ConsultationStatus::values())],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $from = CarbonImmutable::parse($filters['from'] ?? 'today', $tz)->startOfDay();
        $to = CarbonImmutable::parse($filters['to'] ?? $from->addDays(6)->toDateString(), $tz)->endOfDay();
        if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
            $to = $from->addDays(self::MAX_RANGE_DAYS)->endOfDay();
        }

        $query = Consultation::query()
            ->with(['tutor:id,name', 'participants.client:id,name'])
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<=', $to)
            ->orderBy('starts_at')
            ->limit(self::MAX_ROWS + 1); // satu baris lebih untuk mengetahui apakah hasilnya terpotong

        // Tutor hanya melihat sesinya sendiri, apa pun filter yang dikirim.
        if ($user->isTutor()) {
            $query->where('tutor_id', $user->tutor?->id ?? 0);
        } elseif (! empty($filters['tutor_id'])) {
            $query->where('tutor_id', $filters['tutor_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $like = '%'.mb_strtolower($filters['q']).'%';
            $query->where(fn ($w) => $w
                ->whereRaw('lower(title) like ?', [$like])
                ->orWhereHas('participants.client', fn ($c) => $c->whereRaw('lower(name) like ?', [$like])));
        }

        $rows = $query->get();
        $truncated = $rows->count() > self::MAX_ROWS;

        return Inertia::render('schedule/index', [
            'sessions' => $rows->take(self::MAX_ROWS)->map(fn (Consultation $c) => [
                'id' => $c->id,
                'day' => $c->starts_at->setTimezone($tz)->toDateString(),
                'starts_at' => $c->starts_at->toIso8601String(),
                'ends_at' => $c->ends_at->toIso8601String(),
                'title' => $c->title,
                'type' => $c->type->value,
                'type_label' => $c->type->label(),
                'status' => ['value' => $c->status->value, 'label' => $c->status->label()],
                'tutor' => ['id' => $c->tutor_id, 'name' => $c->tutor->name],
                'clients' => $c->participants->map(fn ($p) => $p->client->name)->values(),
                'meeting_mode' => $c->meeting_mode->label(),
            ])->values(),
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'tutor_id' => $filters['tutor_id'] ?? '',
                'status' => $filters['status'] ?? '',
                'q' => $filters['q'] ?? '',
            ],
            'statuses' => ConsultationStatus::options(),
            'tutors' => $user->isTutor() ? [] : Tutor::orderBy('name')->get(['id', 'name']),
            'is_tutor' => $user->isTutor(),
            'can_create' => $user->can('create', Consultation::class),
            'truncated' => $truncated,
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        Gate::authorize('create', Consultation::class);

        $draft = null;
        if ($request->integer('draft_id')) {
            $draft = ScheduleDraft::pending()->with('conversation')->find($request->integer('draft_id'));
            if ($draft === null) {
                // Draf sudah diproses admin lain atau tidak ada: buka form kosong saja.
                return to_route('schedule.create')->with('success', 'Draf itu sudah diproses.');
            }
        }

        // Client dari draf: yang sudah terhubung, atau yang baru didaftarkan dengan nomor chat yang sama.
        $preset = $draft
            ? ($draft->client ?? Client::where('phone', $draft->conversation->phone)->first())
            : ($request->integer('client_id') ? Client::find($request->integer('client_id')) : null);

        return Inertia::render('schedule/form', [
            'consultation' => null,
            'preset_clients' => $preset ? [['id' => $preset->id, 'name' => $preset->name, 'phone' => $preset->phone]] : [],
            'draft' => $draft ? $this->draftSummary($draft, $preset) : null,
            'prefill' => $draft ? $this->draftPrefill($draft) : null,
            ...$this->formOptions(),
        ]);
    }

    public function store(ConsultationRequest $request, ScheduleConsultation $schedule): RedirectResponse
    {
        Gate::authorize('create', Consultation::class);

        $consultation = $schedule->handle($request->toInput(), $request->user(), $request->confirmsWarnings());

        return to_route('schedule.show', $consultation)->with('success', 'Jadwal dibuat.');
    }

    public function show(Request $request, Consultation $consultation): Response
    {
        Gate::authorize('view', $consultation);
        $user = $request->user();
        $tz = config('app.timezone');

        $consultation->load(['tutor:id,name,phone', 'participants.client:id,name,phone', 'participants.enrollment.package:id,name', 'participants.enrollment.quotas', 'creator:id,name']);
        $scheduled = $consultation->status === ConsultationStatus::Scheduled;

        return Inertia::render('schedule/show', [
            'consultation' => [
                'id' => $consultation->id,
                'title' => $consultation->title,
                'topic' => $consultation->topic,
                'type' => $consultation->type->label(),
                'starts_at' => $consultation->starts_at->toIso8601String(),
                'ends_at' => $consultation->ends_at->toIso8601String(),
                'status' => ['value' => $consultation->status->value, 'label' => $consultation->status->label()],
                'meeting_mode' => $consultation->meeting_mode->label(),
                'meeting_url' => $consultation->meeting_url,
                'location' => $consultation->location,
                'benefit' => $consultation->benefit_type?->label(),
                'tutor' => ['id' => $consultation->tutor_id, 'name' => $consultation->tutor->name],
                'tutor_notes' => $consultation->tutor_notes,
                'tutor_notified_at' => $consultation->tutor_notified_at?->toIso8601String(),
                'tutor_reminded_at' => $consultation->tutor_reminded_at?->toIso8601String(),
                'cancel_reason' => $consultation->cancel_reason,
                'created_by' => $consultation->creator?->name,
                'capacity' => $consultation->capacity,
            ],
            'participants' => $consultation->participants->map(function (ConsultationParticipant $p) use ($consultation) {
                $quota = $consultation->benefit_type ? $p->enrollment?->quotaFor($consultation->benefit_type) : null;

                return [
                    'id' => $p->id,
                    'client' => ['id' => $p->client_id, 'name' => $p->client->name, 'phone' => $p->client->phone],
                    'attendance' => ['value' => $p->attendance->value, 'label' => $p->attendance->label()],
                    'package' => $p->enrollment?->package->name,
                    'quota_remaining' => $quota?->remaining(),
                    'quota_unlimited' => $quota?->isUnlimited() ?? false,
                    'quota_deducted' => $p->quota_deducted_at !== null,
                    // Status pesan otomatis ke client ini, supaya admin tahu apa yang sudah terkirim.
                    'confirmation_sent_at' => $p->confirmation_sent_at?->toIso8601String(),
                    'reminder_day_sent_at' => $p->reminder_day_sent_at?->toIso8601String(),
                    'reminder_hour_sent_at' => $p->reminder_hour_sent_at?->toIso8601String(),
                ];
            })->values(),
            'can' => [
                'edit' => $scheduled && $user->can('update', $consultation),
                'cancel' => $scheduled && $user->can('cancel', $consultation),
                // Baru bisa diselesaikan setelah sesi dimulai, supaya kuota tidak terpotong untuk sesi yang belum terjadi.
                'complete' => $scheduled && $user->can('complete', $consultation) && $consultation->starts_at->lessThanOrEqualTo(now()),
                'complete_blocked_until_start' => $scheduled && $user->can('complete', $consultation) && $consultation->starts_at->greaterThan(now()),
                'see_clients' => true,
            ],
            'timezone' => $tz,
        ]);
    }

    public function edit(Consultation $consultation): Response
    {
        Gate::authorize('update', $consultation);
        abort_unless($consultation->status === ConsultationStatus::Scheduled, 403, 'Hanya sesi yang masih terjadwal yang bisa diubah.');

        $consultation->load('participants.client:id,name,phone');
        $tz = config('app.timezone');
        $start = $consultation->starts_at->setTimezone($tz);

        return Inertia::render('schedule/form', [
            'consultation' => [
                'id' => $consultation->id,
                'type' => $consultation->type->value,
                'tutor_id' => $consultation->tutor_id,
                'title' => $consultation->title,
                'topic' => $consultation->topic,
                'date' => $start->toDateString(),
                'start_time' => $start->format('H:i'),
                'duration_minutes' => (int) $consultation->starts_at->diffInMinutes($consultation->ends_at),
                'meeting_mode' => $consultation->meeting_mode->value,
                'meeting_url' => $consultation->meeting_url,
                'location' => $consultation->location,
                'capacity' => $consultation->capacity,
                'benefit_type' => $consultation->benefit_type?->value,
            ],
            'preset_clients' => $consultation->participants->map(fn ($p) => ['id' => $p->client_id, 'name' => $p->client->name, 'phone' => $p->client->phone])->values(),
            ...$this->formOptions(),
        ]);
    }

    public function update(ConsultationRequest $request, Consultation $consultation, ScheduleConsultation $schedule): RedirectResponse
    {
        Gate::authorize('update', $consultation);

        $schedule->handle($request->toInput(), $request->user(), $request->confirmsWarnings(), $consultation);

        return to_route('schedule.show', $consultation)->with('success', 'Jadwal diperbarui.');
    }

    public function cancel(Request $request, Consultation $consultation, CancelConsultation $cancel): RedirectResponse
    {
        Gate::authorize('cancel', $consultation);

        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], ['reason.required' => 'Alasan pembatalan wajib diisi.']);
        $cancel->handle($consultation, $data['reason'], $request->user());

        return back()->with('success', 'Sesi dibatalkan.');
    }

    public function complete(Request $request, Consultation $consultation, CompleteConsultation $complete): RedirectResponse
    {
        Gate::authorize('complete', $consultation);

        if ($consultation->starts_at->greaterThan(now())) {
            throw ValidationException::withMessages(['status' => 'Sesi belum dimulai, jadi belum bisa diselesaikan.']);
        }

        $data = $request->validate([
            'attendance' => ['nullable', 'array'],
            'attendance.*' => ['in:'.AttendanceStatus::Present->value.','.AttendanceStatus::Absent->value],
            'tutor_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        // Hanya peserta sesi ini yang diterima. Peserta tanpa isian dianggap hadir.
        $valid = $consultation->participants()->pluck('id')->all();
        $attendance = collect($data['attendance'] ?? [])
            ->filter(fn ($status, $participantId) => in_array((int) $participantId, $valid, true))
            ->mapWithKeys(fn ($status, $participantId) => [(int) $participantId => AttendanceStatus::from($status)])
            ->all();

        $complete->handle($consultation, $attendance, $data['tutor_notes'] ?? null);

        return back()->with('success', 'Sesi diselesaikan.');
    }

    /** Pemeriksaan langsung di form: bentrok, kapasitas, dan peringatan sebelum disimpan. */
    public function check(Request $request, ScheduleChecker $checker): JsonResponse
    {
        Gate::authorize('create', Consultation::class);

        $input = ScheduleInput::fromForm($request->all());
        if ($input === null) {
            return response()->json(['ready' => false, 'errors' => [], 'warnings' => []]);
        }

        $ignore = $request->integer('consultation_id') ?: null;

        return response()->json(['ready' => true, ...$checker->check($input, $ignore)->toArray()]);
    }

    /** Peringkat tutor untuk waktu dan client yang dipilih. */
    public function suggestTutors(Request $request, TutorSuggester $suggester): JsonResponse
    {
        Gate::authorize('create', Consultation::class);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'between:15,480'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'expertise_field_id' => ['nullable', 'integer', 'exists:expertise_fields,id'],
            'consultation_id' => ['nullable', 'integer'],
        ]);

        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', "{$data['date']} {$data['start_time']}", config('app.timezone'));
        $result = $suggester->suggest(
            $data['client_id'] ?? null,
            $data['expertise_field_id'] ?? null,
            $start,
            $start->addMinutes((int) $data['duration_minutes']),
            $data['consultation_id'] ?? null,
        );

        return response()->json([
            'eligible' => array_map(fn ($s) => $s->toArray(), $result['eligible']),
            'blocked' => array_map(fn ($s) => $s->toArray(), $result['blocked']),
        ]);
    }

    /** @return array<string, mixed> */
    private function draftSummary(ScheduleDraft $draft, ?Client $client): array
    {
        return [
            'id' => $draft->id,
            'summary' => $draft->extracted['summary'] ?? '',
            'confidence' => $draft->extracted['confidence'] ?? null,
            'conversation_id' => $draft->conversation_id,
            'client_registered' => $client !== null,
            // Dipakai untuk mendaftarkan client baru langsung dari form bila nomor ini belum terdaftar.
            'register' => [
                'name' => $draft->extracted['client_name'] ?? $draft->conversation->display_name,
                'phone' => $draft->conversation->phone,
                'degree_level' => $draft->extracted['degree_level'] ?? null,
                'study_program' => $draft->extracted['field_of_study'] ?? null,
            ],
        ];
    }

    /**
     * Isian awal form dari rincian yang dibaca AI. Hanya yang terisi; sisanya memakai bawaan form.
     *
     * @return array<string, mixed>
     */
    private function draftPrefill(ScheduleDraft $draft): array
    {
        $x = $draft->extracted;
        $type = $x['session_type'] ?? null;

        return array_filter([
            'type' => $type,
            'benefit_type' => $type === 'group' ? BenefitType::GroupMeeting->value : null,
            'date' => $x['requested_date'] ?? null,
            'start_time' => $x['requested_time'] ?? null,
            'duration_minutes' => $x['duration_minutes'] ?? null,
            'topic' => $x['service_note'] ?? null,
        ], fn ($value) => $value !== null);
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'tutors' => Tutor::active()->with('expertiseFields:id,name')->orderBy('name')->get()->map(fn (Tutor $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'degree' => $t->highest_degree->value,
                'fields' => $t->expertiseFields->pluck('name')->sort()->values(),
            ]),
            'types' => ConsultationType::options(),
            'meeting_modes' => MeetingMode::options(),
            'benefit_types' => collect(BenefitType::sessionBased())->map(fn (BenefitType $t) => ['value' => $t->value, 'label' => $t->label()])->values(),
        ];
    }
}
