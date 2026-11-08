<?php

namespace App\Services\Sheets\Tabs;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentQuota;
use App\Services\Sheets\Cell;
use App\Services\Sheets\SheetTab;
use Carbon\CarbonImmutable;

/** Satu baris per paket yang dibeli client: masa aktif, harga, status bayar, dan sisa kuota. Bukti bayar tidak disalin. */
final class PaketClientTab implements SheetTab
{
    public function key(): string
    {
        return 'paket';
    }

    public function title(): string
    {
        return 'Paket Client';
    }

    public function headers(): array
    {
        return ['ID', 'Client', 'Paket', 'Kategori', 'Mulai', 'Berakhir', 'Sisa hari', 'Status paket', 'Harga', 'Status bayar', 'Tanggal bayar', 'Metode bayar', 'Tutor pembimbing', 'Kuota terpakai'];
    }

    public function rows(): array
    {
        $tz = config('app.timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();

        return Enrollment::query()
            ->with(['client' => fn ($q) => $q->withTrashed(), 'package:id,name,category', 'primaryTutor:id,name', 'quotas' => fn ($q) => $q->orderBy('id')])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->limit((int) config('koedu.sheets.max_rows'))
            ->get()
            ->map(fn (Enrollment $e) => Cell::row([
                $e->id,
                $e->client->name.($e->client->trashed() ? ' (dihapus)' : ''),
                $e->package->name,
                $e->package->category->label(),
                $e->starts_on->format('Y-m-d'),
                $e->ends_on->format('Y-m-d'),
                // Sisa hari hanya berarti untuk paket yang masih aktif.
                $e->status === EnrollmentStatus::Active ? max(0, (int) $today->diffInDays($e->ends_on->toImmutable()->startOfDay(), false)) : '',
                $e->status->label(),
                $e->price,
                $e->payment_status->label(),
                $e->paid_at?->setTimezone($tz)->format('Y-m-d') ?? '',
                $e->payment_method ?? '',
                $e->primaryTutor?->name ?? '',
                $e->quotas->map(fn (EnrollmentQuota $q) => $this->quota($q))->implode('; '),
            ]))
            ->values()
            ->all();
    }

    public function syncedConsultationIds(): array
    {
        return [];
    }

    private function quota(EnrollmentQuota $q): string
    {
        return $q->total === null ? "{$q->benefit_type->label()} {$q->used} (tanpa batas)" : "{$q->benefit_type->label()} {$q->used}/{$q->total}";
    }
}
