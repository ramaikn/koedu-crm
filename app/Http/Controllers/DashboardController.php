<?php

namespace App\Http\Controllers;

use App\Enums\ConsultationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Enrollment;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\ScheduleDraft;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ringkasan harian. Staf kantor melihat gambaran operasional; tutor melihat jadwalnya sendiri.
 * Grafik dan laporan director dibuat terpisah.
 */
class DashboardController extends Controller
{
    private const LIST_LIMIT = 6;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $tz = config('app.timezone');
        $now = CarbonImmutable::now($tz);

        $sessions = Consultation::query()
            ->with(['tutor:id,name', 'participants.client:id,name'])
            ->where('status', ConsultationStatus::Scheduled)
            ->when($user->isTutor(), fn ($q) => $q->where('tutor_id', $user->tutor?->id ?? 0))
            ->where('ends_at', '>=', $now);

        $today = (clone $sessions)->where('starts_at', '<', $now->endOfDay())->orderBy('starts_at')->get();
        $upcoming = (clone $sessions)->where('starts_at', '>', $now->endOfDay())->where('starts_at', '<=', $now->addDays(7)->endOfDay())
            ->orderBy('starts_at')->limit(self::LIST_LIMIT)->get();

        $props = [
            'today' => $today->map($this->session(...))->values(),
            'upcoming' => $upcoming->map($this->session(...))->values(),
            'role' => $user->role->value,
        ];

        if ($user->isStaff()) {
            $expiring = Enrollment::query()
                ->usableOn()
                ->where('ends_on', '<=', $now->addDays(7)->toDateString())
                ->with(['client:id,name', 'package:id,name'])
                ->orderBy('ends_on')->limit(self::LIST_LIMIT)->get();

            $props += [
                'stats' => [
                    'sessions_today' => $today->count(),
                    'unpaid_enrollments' => Enrollment::where('status', EnrollmentStatus::Active)->where('payment_status', PaymentStatus::Unpaid)->count(),
                    'unread_conversations' => WaConversation::where('unread_count', '>', 0)->count(),
                    'pending_drafts' => ScheduleDraft::pending()->count(),
                    'failed_messages' => WaMessage::where('direction', MessageDirection::Outbound)->where('status', MessageStatus::Failed)
                        ->where('created_at', '>=', now()->subDay())->count(),
                    'new_clients_7d' => Client::where('created_at', '>=', $now->subDays(7))->count(),
                ],
                'expiring' => $expiring->map(fn (Enrollment $e) => [
                    'id' => $e->id,
                    'client_id' => $e->client_id,
                    'client' => $e->client->name,
                    'package' => $e->package->name,
                    'ends_on' => $e->ends_on->toDateString(),
                ])->values(),
            ];
        }

        return Inertia::render('dashboard', $props);
    }

    /** @return array<string, mixed> */
    private function session(Consultation $c): array
    {
        return [
            'id' => $c->id,
            'title' => $c->title,
            'starts_at' => $c->starts_at->toIso8601String(),
            'ends_at' => $c->ends_at->toIso8601String(),
            'tutor' => $c->tutor->name,
            'clients' => $c->participants->map(fn ($p) => $p->client->name)->values(),
        ];
    }
}
