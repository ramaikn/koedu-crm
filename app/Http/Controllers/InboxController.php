<?php

namespace App\Http\Controllers;

use App\Actions\WhatsApp\SendWhatsAppMessage;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\ScheduleDraft;
use App\Models\WaConversation;
use App\Models\WaMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Inbox WhatsApp: admin membaca dan membalas, director hanya membaca. */
class InboxController extends Controller
{
    private const MESSAGE_LIMIT = 200;

    public function index(): Response
    {
        Gate::authorize('viewAny', WaConversation::class);

        return Inertia::render('inbox/index', $this->props(null));
    }

    public function show(Request $request, WaConversation $conversation): Response
    {
        Gate::authorize('view', $conversation);

        // Hanya admin yang menandai dibaca. Director yang sekadar memantau tidak menghapus penanda milik tim admin.
        if ($request->user()->isAdmin() && $conversation->unread_count > 0) {
            $conversation->update(['unread_count' => 0]);
        }

        return Inertia::render('inbox/index', $this->props($conversation));
    }

    public function reply(Request $request, WaConversation $conversation, SendWhatsAppMessage $send): RedirectResponse
    {
        Gate::authorize('reply', $conversation);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $send->reply($conversation, trim($data['body']), $request->user());

        return back();
    }

    /** Kirim ulang pesan keluar yang gagal. Percobaan otomatis sudah habis, jadi keputusan ada di admin. */
    public function retry(WaMessage $message): RedirectResponse
    {
        Gate::authorize('reply', $message->conversation);

        if ($message->direction !== MessageDirection::Outbound || $message->status !== MessageStatus::Failed) {
            throw ValidationException::withMessages(['message' => 'Hanya pesan keluar yang gagal yang bisa dikirim ulang.']);
        }

        $message->update(['status' => MessageStatus::Queued, 'error' => null]);
        SendWhatsAppMessageJob::dispatch($message->id)->onQueue('whatsapp');

        return back()->with('success', 'Pesan dikirim ulang.');
    }

    /** @return array<string, mixed> */
    private function props(?WaConversation $active): array
    {
        $withDraft = ScheduleDraft::pending()->pluck('conversation_id')->flip();

        $conversations = WaConversation::query()
            ->with(['client:id,name', 'messages' => fn ($q) => $q->latest('id')->limit(1)])
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get()
            ->map(fn (WaConversation $c) => [
                'id' => $c->id,
                'has_draft' => $withDraft->has($c->id),
                'name' => $c->client?->name ?? $c->display_name ?? $c->phone,
                'phone' => $c->phone,
                'is_client' => $c->client_id !== null,
                'unread_count' => $c->unread_count,
                'last_message_at' => $c->last_message_at?->toIso8601String(),
                'preview' => str($c->messages->first()?->body ?? '')->limit(60)->toString(),
            ]);

        return [
            'conversations' => $conversations,
            'active' => $active === null ? null : [
                'id' => $active->id,
                'name' => $active->client?->name ?? $active->display_name ?? $active->phone,
                'phone' => $active->phone,
                'client_id' => $active->client_id,
                'draft' => $this->draftProps($active),
                // Diambil dari yang terbaru, lalu dibalik supaya tampil dari lama ke baru.
                'messages' => $active->messages()->latest('id')->limit(self::MESSAGE_LIMIT)->get()
                    ->reverse()->values()
                    ->map(fn ($m) => [
                        'id' => $m->id,
                        'direction' => $m->direction->value,
                        'body' => $m->body,
                        'status' => $m->status->value,
                        'status_label' => $m->status->label(),
                        'error' => $m->error,
                        'at' => ($m->sent_at ?? $m->created_at)->toIso8601String(),
                    ]),
            ],
            'can_reply' => request()->user()->isAdmin(),
        ];
    }

    /** Draf jadwal dari AI yang menunggu keputusan admin untuk percakapan ini. */
    private function draftProps(WaConversation $conversation): ?array
    {
        $draft = $conversation->scheduleDrafts()->pending()->latest('id')->first();
        if ($draft === null) {
            return null;
        }

        $x = $draft->extracted;

        return [
            'id' => $draft->id,
            'summary' => $x['summary'] ?? '',
            'confidence' => $x['confidence'] ?? null,
            'fields' => [
                'client_name' => $x['client_name'] ?? null,
                'degree_level' => $x['degree_level'] ?? null,
                'field_of_study' => $x['field_of_study'] ?? null,
                'service_note' => $x['service_note'] ?? null,
                'requested_date' => $x['requested_date'] ?? null,
                'requested_time' => $x['requested_time'] ?? null,
                'duration_minutes' => $x['duration_minutes'] ?? null,
                'session_type' => $x['session_type'] ?? null,
            ],
            'created_at' => $draft->created_at->toIso8601String(),
            'client_registered' => $conversation->client_id !== null,
        ];
    }
}
