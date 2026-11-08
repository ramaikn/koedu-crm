<?php

namespace App\Observers;

use App\Models\Client;
use App\Models\Consultation;
use App\Models\ConsultationParticipant;
use App\Models\Enrollment;
use App\Models\EnrollmentQuota;
use App\Models\Tutor;
use App\Services\Sheets\SheetSyncDispatcher;
use Illuminate\Database\Eloquent\Model;

/**
 * Memicu sinkron Google Sheets saat data yang tampil di sana berubah. Satu pengamat untuk semua model,
 * dengan peta "model berubah, tab mana yang terpengaruh". Perubahan lewat query builder tidak terdeteksi;
 * itu ditutup oleh pencocokan penuh tiap malam.
 */
class SheetSyncObserver
{
    /** @var array<class-string<Model>, list<string>> */
    public const TABS_BY_MODEL = [
        Consultation::class => ['jadwal'],
        ConsultationParticipant::class => ['jadwal', 'client'],
        Client::class => ['client', 'jadwal', 'paket'],
        Enrollment::class => ['paket', 'client'],
        EnrollmentQuota::class => ['paket'],
        Tutor::class => ['jadwal', 'paket'],
    ];

    public function __construct(private readonly SheetSyncDispatcher $dispatcher) {}

    public function saved(Model $model): void
    {
        $this->touch($model);
    }

    public function deleted(Model $model): void
    {
        $this->touch($model);
    }

    public function restored(Model $model): void
    {
        $this->touch($model);
    }

    private function touch(Model $model): void
    {
        $this->dispatcher->touch(...(self::TABS_BY_MODEL[$model::class] ?? []));
    }
}
