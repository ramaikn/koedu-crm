<?php

namespace App\Services\Sheets\Tabs;

use App\Enums\AttendanceStatus;
use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use App\Services\Sheets\Cell;
use App\Services\Sheets\SheetTab;
use Illuminate\Support\Collection;

/** Satu baris per sesi: waktu, tutor, peserta, status, kehadiran, dan pesan otomatis yang sudah terkirim. */
final class JadwalTab implements SheetTab
{
    /** @var Collection<int, Consultation>|null */
    private ?Collection $sessions = null;

    public function key(): string
    {
        return 'jadwal';
    }

    public function title(): string
    {
        return 'Jadwal';
    }

    public function headers(): array
    {
        return ['ID', 'Tanggal', 'Hari', 'Mulai', 'Selesai', 'Judul', 'Jenis', 'Tutor', 'Client', 'Peserta', 'Layanan', 'Metode', 'Link / Lokasi', 'Status', 'Kehadiran', 'Tutor diberi tahu', 'Client dikonfirmasi', 'Diperbarui'];
    }

    public function rows(): array
    {
        $tz = config('app.timezone');

        return $this->sessions()->map(function (Consultation $c) use ($tz) {
            $start = $c->starts_at->setTimezone($tz)->locale('id');
            $participants = $c->participants;
            $names = $participants->map(fn (ConsultationParticipant $p) => $p->client?->name)->filter()->implode(', ');

            return Cell::row([
                $c->id,
                $start->format('Y-m-d'),
                $start->translatedFormat('l'),
                $start->format('H:i'),
                $c->ends_at->setTimezone($tz)->format('H:i'),
                $c->title,
                $c->type->label(),
                $c->tutor->name,
                $names,
                $participants->count(),
                $c->benefit_type?->label() ?? '',
                $c->meeting_mode->label(),
                $c->location ?: ($c->meeting_url ?? ''),
                $c->status->label(),
                $this->attendance($c, $participants),
                $c->tutor_notified_at?->setTimezone($tz)->format('Y-m-d H:i') ?? '',
                $participants->isEmpty() ? '' : $participants->whereNotNull('confirmation_sent_at')->count().'/'.$participants->count(),
                $c->updated_at->setTimezone($tz)->format('Y-m-d H:i'),
            ]);
        })->values()->all();
    }

    public function syncedConsultationIds(): array
    {
        return $this->sessions()->pluck('id')->all();
    }

    /** Sesi dari N bulan terakhir sampai semua yang akan datang. Yang lebih lama tetap di aplikasi. */
    private function sessions(): Collection
    {
        return $this->sessions ??= Consultation::query()
            ->with(['tutor:id,name', 'participants.client' => fn ($q) => $q->withTrashed()])
            ->where('starts_at', '>=', now()->subMonths((int) config('koedu.sheets.months_back')))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit((int) config('koedu.sheets.max_rows'))
            ->get();
    }

    /** Ringkasan kehadiran hanya berarti untuk sesi yang sudah selesai. */
    private function attendance(Consultation $c, Collection $participants): string
    {
        if ($c->status->value !== 'completed') {
            return '';
        }

        $present = $participants->where('attendance', AttendanceStatus::Present)->count();
        $absent = $participants->where('attendance', AttendanceStatus::Absent)->count();

        return trim(($present ? "{$present} hadir" : '').($present && $absent ? ', ' : '').($absent ? "{$absent} tidak hadir" : ''));
    }
}
