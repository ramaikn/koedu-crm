<?php

namespace App\Services\Notifications;

use App\Actions\WhatsApp\SendWhatsAppMessage;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\WaMessage;
use App\Services\Notifications\Rules\AdminDigest;
use App\Services\Notifications\Rules\ClientSessionNotices;
use App\Services\Notifications\Rules\SessionReminders;
use App\Services\Notifications\Rules\TutorSessionNotices;
use App\Services\Sheets\SheetSyncDispatcher;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Menjalankan semua aturan notifikasi dan mengirim hasilnya lewat satu pintu pesan WA (SendWhatsAppMessage).
 *
 * Urutan pengaman untuk tiap pesan: nomor diizinkan, di dalam jam kirim, lalu "klaim" dan antre pesan dalam
 * satu transaksi. Bila mengantre gagal, klaim dibatalkan sehingga pesan dicoba lagi di putaran berikutnya.
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly TutorSessionNotices $tutorNotices,
        private readonly ClientSessionNotices $clientNotices,
        private readonly SessionReminders $reminders,
        private readonly AdminDigest $digest,
        private readonly NotificationWindow $window,
        private readonly SendWhatsAppMessage $send,
        private readonly SheetSyncDispatcher $sheets,
    ) {}

    /**
     * @param  bool  $dryRun  hanya menampilkan apa yang akan terjadi, tanpa klaim dan tanpa mengirim
     */
    public function run(?CarbonImmutable $now = null, bool $dryRun = false): NotificationRunResult
    {
        $now ??= CarbonImmutable::now(config('app.timezone'));
        $enabled = (bool) config('koedu.notifications.enabled');
        $result = new NotificationRunResult($enabled, $this->window->isOpen($now), $dryRun);

        // Saat mati, tidak ada yang dijalankan sama sekali. Mode uji tetap menampilkan rencananya.
        if (! $enabled && ! $dryRun) {
            return $result;
        }

        foreach ($this->plan($now) as $notification) {
            $this->process($notification, $now, $dryRun, $result);
        }

        if (! $dryRun) {
            // Kolom "tutor diberi tahu" dan "client dikonfirmasi" di tab Jadwal berubah lewat query langsung (tanpa pengamat model).
            if ($result->total(NotificationRunResult::SENT) > 0) {
                $this->sheets->touch('jadwal');
            }
            Log::info('Notifikasi dijalankan', $result->toArray($this->failedRecently()));
        }

        return $result;
    }

    /** Jumlah pesan keluar yang gagal terkirim dalam 24 jam terakhir. Untuk peringatan di n8n dan dashboard. */
    public function failedRecently(): int
    {
        return WaMessage::where('direction', MessageDirection::Outbound)
            ->where('status', MessageStatus::Failed)
            ->where('created_at', '>=', now()->subDay())
            ->count();
    }

    /** @return list<PlannedNotification> */
    private function plan(CarbonImmutable $now): array
    {
        $planned = [];
        foreach ([$this->tutorNotices, $this->clientNotices, $this->reminders, $this->digest] as $rule) {
            array_push($planned, ...$rule->plan($now));
        }

        return $planned;
    }

    private function process(PlannedNotification $notification, CarbonImmutable $now, bool $dryRun, NotificationRunResult $result): void
    {
        $type = $notification->type;

        if (! $notification->isSilent() && ! $this->isAllowed($notification->phone)) {
            $result->add($type, NotificationRunResult::NOT_ALLOWED);
            $dryRun && $result->preview($notification, NotificationRunResult::NOT_ALLOWED);

            return;
        }

        if ($notification->respectsWindow && ! $this->window->allows($now, $notification->sessionStart)) {
            $result->add($type, NotificationRunResult::DEFERRED);
            $dryRun && $result->preview($notification, NotificationRunResult::DEFERRED);

            return;
        }

        if ($dryRun) {
            $result->add($type, NotificationRunResult::PLANNED);
            $result->preview($notification, NotificationRunResult::PLANNED);

            return;
        }

        try {
            $outcome = DB::transaction(function () use ($notification) {
                if (! ($notification->claim)()) {
                    return NotificationRunResult::ALREADY_TAKEN;
                }
                if ($notification->isSilent()) {
                    return NotificationRunResult::CLAIMED_ONLY;
                }

                $this->send->handle(PhoneNumber::normalize($notification->phone), $notification->body, null, $notification->recipient);

                return NotificationRunResult::SENT;
            });
        } catch (Throwable $e) {
            // Transaksi dibatalkan, jadi klaim juga batal dan pesan dicoba lagi di putaran berikutnya.
            Log::error('Notifikasi gagal diantre', ['type' => $type, 'error' => $e->getMessage()]);
            $outcome = NotificationRunResult::ERROR;
        }

        $result->add($type, $outcome);
    }

    /** Tanpa daftar NOTIFY_ALLOWED_PHONES semua nomor diizinkan. Dengan daftar, hanya nomor di dalamnya. */
    private function isAllowed(?string $phone): bool
    {
        $allowed = config('koedu.notifications.allowed_phones');
        if ($allowed === []) {
            return true;
        }

        return $phone !== null && in_array(PhoneNumber::normalize($phone), array_map(PhoneNumber::normalize(...), $allowed), true);
    }
}
