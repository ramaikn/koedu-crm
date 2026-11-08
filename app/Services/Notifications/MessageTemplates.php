<?php

namespace App\Services\Notifications;

use App\Enums\MeetingMode;
use App\Models\Consultation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Teks semua pesan notifikasi. Satu tempat supaya nada dan isinya mudah ditinjau dan diubah tim bisnis.
 * Alasan pembatalan bersifat internal dan tidak pernah disertakan di pesan ke client atau tutor.
 */
final class MessageTemplates
{
    private const TZ_LABELS = ['Asia/Makassar' => 'WITA', 'Asia/Jakarta' => 'WIB', 'Asia/Jayapura' => 'WIT'];

    private const MAX_NAMES = 5;

    public function tutorNew(Consultation $c): string
    {
        return "Halo {$c->tutor->name}, ada jadwal bimbingan baru untuk Anda.\n\n"
            .$this->details($c, withTutor: false)
            ."\n\nBila berhalangan hadir, mohon balas pesan ini secepatnya.\n{$this->sender()}";
    }

    public function tutorChanged(Consultation $c, CarbonInterface $oldStart): string
    {
        return "Halo {$c->tutor->name}, jadwal bimbingan Anda diperbarui.\n\n"
            ."Sebelumnya: {$this->when($oldStart)}\n"
            .$this->details($c, withTutor: false, label: 'Sekarang')
            ."\n\nBila berhalangan hadir, mohon balas pesan ini.\n{$this->sender()}";
    }

    /** Untuk tutor yang tidak lagi memegang sesi: sesi dibatalkan atau dialihkan ke tutor lain. */
    public function tutorRelieved(Consultation $c, string $tutorName, CarbonInterface $oldStart, bool $reassigned): string
    {
        $reason = $reassigned ? 'dialihkan ke tutor lain' : 'dibatalkan';

        return "Halo {$tutorName}, jadwal bimbingan berikut {$reason} dan tidak perlu Anda hadiri:\n\n"
            ."{$c->title}\n{$this->when($oldStart)}\nClient: {$this->names($c)}\n\n{$this->sender()}";
    }

    public function tutorReminder(Consultation $c, int $minutesLeft): string
    {
        return "Pengingat: bimbingan \"{$c->title}\" dimulai {$minutesLeft} menit lagi.\n\n"
            .$this->details($c, withTutor: false)
            ."\n\n{$this->sender()}";
    }

    public function clientConfirmation(Consultation $c, string $clientName): string
    {
        return "Halo {$clientName}, jadwal bimbingan Anda sudah dikonfirmasi.\n\n"
            .$this->details($c, withTutor: true)
            ."\n\nBila perlu mengubah jadwal, silakan balas pesan ini.\n{$this->sender()}";
    }

    public function clientChanged(Consultation $c, string $clientName, CarbonInterface $oldStart): string
    {
        return "Halo {$clientName}, jadwal bimbingan Anda diperbarui.\n\n"
            ."Sebelumnya: {$this->when($oldStart)}\n"
            .$this->details($c, withTutor: true, label: 'Sekarang')
            ."\n\nBila kurang sesuai, silakan balas pesan ini.\n{$this->sender()}";
    }

    public function clientTutorChanged(Consultation $c, string $clientName, string $oldTutorName): string
    {
        return "Halo {$clientName}, tutor untuk jadwal bimbingan Anda diganti dari {$oldTutorName}.\n\n"
            .$this->details($c, withTutor: true, label: 'Waktu (tidak berubah)')
            ."\n\nBila ada yang perlu disesuaikan, silakan balas pesan ini.\n{$this->sender()}";
    }

    public function clientCancelled(Consultation $c, string $clientName, CarbonInterface $oldStart): string
    {
        return "Halo {$clientName}, mohon maaf, jadwal bimbingan berikut dibatalkan:\n\n"
            ."{$c->title}\n{$this->when($oldStart)}\n\n"
            ."Kami akan menghubungi Anda untuk jadwal pengganti. Anda juga bisa membalas pesan ini untuk memilih waktu baru.\n{$this->sender()}";
    }

    public function clientDayReminder(Consultation $c, string $clientName): string
    {
        return "Halo {$clientName}, pengingat: besok Anda ada jadwal bimbingan.\n\n"
            .$this->details($c, withTutor: true)
            ."\n\nBila berhalangan, mohon kabari kami lewat pesan ini.\n{$this->sender()}";
    }

    public function clientHourReminder(Consultation $c, string $clientName, int $minutesLeft): string
    {
        return "Halo {$clientName}, bimbingan Anda dimulai {$minutesLeft} menit lagi.\n\n"
            .$this->details($c, withTutor: true)
            ."\n\n{$this->sender()}";
    }

    /**
     * Ringkasan harian untuk admin. Bagian yang kosong dilewati; null bila tidak ada yang perlu dilaporkan.
     *
     * @param  array{date: string, sessions: array{total: int, lines: list<string>}, expiring: array{total: int, lines: list<string>}, unpaid: array{total: int, lines: list<string>}, drafts: int, unread: int, failed: int}  $data
     */
    public function adminDigest(array $data): ?string
    {
        $sections = [];
        $this->section($sections, 'Sesi hari ini', $data['sessions']);
        $this->section($sections, 'Paket berakhir dalam '.config('koedu.notifications.expiring_days').' hari', $data['expiring']);
        $this->section($sections, 'Belum lunas lebih dari '.config('koedu.notifications.unpaid_days').' hari', $data['unpaid']);

        $counts = array_filter([
            $data['drafts'] > 0 ? "Draf jadwal menunggu persetujuan: {$data['drafts']}" : null,
            $data['failed'] > 0 ? "Pesan WhatsApp gagal terkirim (24 jam): {$data['failed']}" : null,
        ]);
        if ($sections === [] && $counts === []) {
            return null;
        }

        $footer = $data['unread'] > 0 ? "\nChat belum dibaca: {$data['unread']}" : '';

        return "Ringkasan harian, {$data['date']}\n\n".implode("\n\n", $sections).($counts === [] ? '' : ($sections === [] ? '' : "\n\n").implode("\n", $counts)).$footer;
    }

    /** @param list<string> $sections */
    private function section(array &$sections, string $title, array $block): void
    {
        if ($block['total'] === 0) {
            return;
        }

        $sections[] = "{$title} ({$block['total']}):\n".implode("\n", array_map(fn ($line) => "- {$line}", $block['lines']));
    }

    private function details(Consultation $c, bool $withTutor, string $label = 'Waktu'): string
    {
        $lines = [$c->title, "{$label}: {$this->when($c->starts_at, $c->ends_at)}"];
        if ($withTutor) {
            $lines[] = "Tutor: {$c->tutor->name}";
        } else {
            $lines[] = "Client: {$this->names($c)}";
        }
        $lines[] = $this->where($c);

        return implode("\n", $lines);
    }

    private function where(Consultation $c): string
    {
        if ($c->meeting_mode === MeetingMode::Offline) {
            return 'Lokasi: '.($c->location ?: 'akan diinformasikan tim');
        }

        return "Metode: {$c->meeting_mode->label()}\n".($c->meeting_url ? "Link: {$c->meeting_url}" : 'Link akan dikirim oleh tim.');
    }

    private function names(Consultation $c): string
    {
        $names = $c->participants->map(fn ($p) => $p->client?->name)->filter()->values();

        return $this->joinNames($names);
    }

    private function joinNames(Collection $names): string
    {
        if ($names->count() <= self::MAX_NAMES) {
            return $names->implode(', ');
        }

        return $names->take(self::MAX_NAMES)->implode(', ').' dan '.($names->count() - self::MAX_NAMES).' lainnya';
    }

    /** "Senin, 9 November 2026 pukul 10.00-11.00 WITA" (jam akhir bila diketahui). */
    public function when(CarbonInterface $start, ?CarbonInterface $end = null): string
    {
        $tz = config('app.timezone');
        $start = $start->copy()->setTimezone($tz)->locale('id');
        $time = $start->format('H.i').($end ? '-'.$end->copy()->setTimezone($tz)->format('H.i') : '');

        return $start->translatedFormat('l, j F Y')." pukul {$time} ".(self::TZ_LABELS[$tz] ?? $tz);
    }

    private function sender(): string
    {
        return config('koedu.notifications.sender_name');
    }
}
