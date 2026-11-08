<?php

namespace App\Services\Sheets;

/** Satu tab di Google Sheets dan cara mengisinya dari database. Isi tab selalu menggantikan seluruh isi lama. */
interface SheetTab
{
    /** Kunci tetap untuk antrean dan status (mis. "jadwal"). */
    public function key(): string;

    /** Judul tab di Google Sheets. */
    public function title(): string;

    /** @return list<string> */
    public function headers(): array;

    /**
     * Baris data. Sel sudah diamankan lewat Cell (teks pengguna tidak dibaca sebagai rumus).
     *
     * @return list<list<mixed>>
     */
    public function rows(): array;

    /** @return list<int> id konsultasi yang ikut tersalin, untuk menandai sheet_synced_at. Kosong bila tab bukan jadwal. */
    public function syncedConsultationIds(): array;
}
