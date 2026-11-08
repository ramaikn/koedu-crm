<?php

namespace App\Services\Sheets\Tabs;

use App\Enums\ConsultationStatus;
use App\Models\Client;
use App\Services\Sheets\Cell;
use App\Services\Sheets\SheetTab;

/**
 * Satu baris per client. Nomor WhatsApp lengkap (keputusan user), jadi akses Sheet perlu dibatasi.
 * Catatan internal client sengaja tidak disalin.
 */
final class ClientTab implements SheetTab
{
    public function key(): string
    {
        return 'client';
    }

    public function title(): string
    {
        return 'Client';
    }

    public function headers(): array
    {
        return ['ID', 'Nama', 'Nomor WhatsApp', 'Email', 'Universitas', 'Jurusan', 'Bidang', 'Jenjang', 'Status', 'Sumber', 'Paket aktif', 'Sesi selesai', 'Sesi mendatang', 'Terdaftar'];
    }

    public function rows(): array
    {
        $tz = config('app.timezone');

        return Client::query()
            ->with('expertiseField:id,name')
            ->withCount([
                'enrollments as active_packages' => fn ($q) => $q->usableOn(),
                'participations as completed_sessions' => fn ($q) => $q->whereHas('consultation', fn ($c) => $c->where('status', ConsultationStatus::Completed)),
                'participations as upcoming_sessions' => fn ($q) => $q->whereHas('consultation', fn ($c) => $c->where('status', ConsultationStatus::Scheduled)->where('starts_at', '>', now())),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->limit((int) config('koedu.sheets.max_rows'))
            ->get()
            ->map(fn (Client $c) => [
                ...Cell::row([$c->id, $c->name]),
                Cell::text($c->phone),
                ...Cell::row([
                    $c->email,
                    $c->university,
                    $c->study_program,
                    $c->expertiseField?->name,
                    $c->degree_level?->value,
                    $c->status->label(),
                    $c->source,
                    $c->active_packages,
                    $c->completed_sessions,
                    $c->upcoming_sessions,
                    $c->created_at->setTimezone($tz)->format('Y-m-d'),
                ]),
            ])
            ->values()
            ->all();
    }

    public function syncedConsultationIds(): array
    {
        return [];
    }
}
