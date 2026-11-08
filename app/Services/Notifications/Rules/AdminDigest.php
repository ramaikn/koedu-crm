<?php

namespace App\Services\Notifications\Rules;

use App\Enums\ConsultationStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\PaymentStatus;
use App\Models\Consultation;
use App\Models\Enrollment;
use App\Models\ScheduleDraft;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Services\Notifications\MessageTemplates;
use App\Services\Notifications\NotificationRule;
use App\Services\Notifications\NotificationWindow;
use App\Services\Notifications\PlannedNotification;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Ringkasan harian ke admin pada jam yang ditetapkan: sesi hari ini, paket yang hampir berakhir,
 * paket belum lunas, draf jadwal, dan pesan gagal. Tidak ada tagihan otomatis ke client.
 * Tidak dikirim bila tidak ada yang perlu dilaporkan.
 */
final class AdminDigest implements NotificationRule
{
    private const MAX_LINES = 8;

    public function __construct(
        private readonly MessageTemplates $messages,
        private readonly NotificationWindow $window,
    ) {}

    public function plan(CarbonImmutable $now): array
    {
        if ($now->lessThan($this->window->digestAt($now))) {
            return [];
        }

        $recipients = $this->recipients();
        if ($recipients === []) {
            return [];
        }

        $body = $this->messages->adminDigest($this->data($now));
        if ($body === null) {
            return [];
        }

        $date = $now->setTimezone(config('app.timezone'))->toDateString();

        return array_map(fn (array $recipient) => new PlannedNotification(
            type: 'admin_digest',
            phone: $recipient['phone'],
            recipient: $recipient['name'],
            body: $body,
            // Satu ringkasan per penerima per hari. Penanda di cache cukup: kehilangannya hanya berarti satu ringkasan ganda.
            claim: fn () => Cache::add("notifications:digest:{$date}:{$recipient['phone']}", true, now()->addHours(36)),
        ), $recipients);
    }

    /** @return list<array{phone: string, name: string}> nomor dari .env digabung dengan admin aktif yang punya nomor */
    private function recipients(): array
    {
        $byPhone = [];

        foreach (config('koedu.notifications.admin_phones') as $phone) {
            $byPhone[PhoneNumber::normalize($phone)] = 'Admin';
        }
        User::where('role', 'admin')->where('is_active', true)->whereNotNull('phone')->get(['name', 'phone'])
            ->each(function (User $user) use (&$byPhone) {
                $byPhone[PhoneNumber::normalize($user->phone)] = $user->name;
            });

        return collect($byPhone)->map(fn ($name, $phone) => ['phone' => (string) $phone, 'name' => $name])->values()->all();
    }

    /** @return array{date: string, sessions: array{total: int, lines: list<string>}, expiring: array{total: int, lines: list<string>}, unpaid: array{total: int, lines: list<string>}, drafts: int, unread: int, failed: int} */
    private function data(CarbonImmutable $now): array
    {
        $tz = config('app.timezone');
        $local = $now->setTimezone($tz);

        $sessions = Consultation::with(['tutor:id,name', 'participants.client:id,name'])
            ->where('status', ConsultationStatus::Scheduled)
            ->where('starts_at', '>=', $local->startOfDay())
            ->where('starts_at', '<=', $local->endOfDay())
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Consultation $c) => $c->starts_at->setTimezone($tz)->format('H.i').' '.$c->title.' ('.$c->tutor->name.')');

        $expiring = Enrollment::with(['client:id,name', 'package:id,name'])
            ->usableOn($local)
            ->where('ends_on', '<=', $local->addDays((int) config('koedu.notifications.expiring_days'))->toDateString())
            ->orderBy('ends_on')
            ->get()
            ->map(fn (Enrollment $e) => "{$e->client->name}: {$e->package->name}, berakhir ".$e->ends_on->locale('id')->translatedFormat('j M'));

        $unpaid = Enrollment::with(['client:id,name', 'package:id,name'])
            ->where('status', EnrollmentStatus::Active)
            ->where('payment_status', PaymentStatus::Unpaid)
            ->where('created_at', '<=', $local->subDays((int) config('koedu.notifications.unpaid_days')))
            ->orderBy('created_at')
            ->get()
            ->map(fn (Enrollment $e) => "{$e->client->name}: {$e->package->name}, Rp ".number_format($e->price, 0, ',', '.'));

        return [
            'date' => $local->locale('id')->translatedFormat('l, j F Y'),
            'sessions' => $this->limit($sessions),
            'expiring' => $this->limit($expiring),
            'unpaid' => $this->limit($unpaid),
            'drafts' => ScheduleDraft::pending()->count(),
            'unread' => WaConversation::where('unread_count', '>', 0)->count(),
            'failed' => WaMessage::where('direction', MessageDirection::Outbound)->where('status', MessageStatus::Failed)
                ->where('created_at', '>=', $local->subDay())->count(),
        ];
    }

    /** @return array{total: int, lines: list<string>} daftar dipotong agar pesan tetap ringkas; total tetap jumlah sebenarnya */
    private function limit($lines): array
    {
        $all = $lines->values()->all();
        $rest = count($all) - self::MAX_LINES;

        return [
            'total' => count($all),
            'lines' => $rest > 0 ? [...array_slice($all, 0, self::MAX_LINES), "dan {$rest} lainnya (lihat aplikasi)"] : $all,
        ];
    }
}
