<?php

namespace App\Console\Commands;

use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiException;
use App\Services\Ai\GeminiProvider;
use App\Services\Scheduling\ScheduleRequestExtractor;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

/**
 * Memeriksa pengaturan AI setelah GEMINI_API_KEYS diisi: tiap key dan model, lalu satu ekstraksi percobaan.
 * API key tidak pernah ditampilkan, hanya nomor urutnya.
 */
class AiCheckCommand extends Command
{
    protected $signature = 'ai:check
        {--model= : Coba model ini saja (boleh beberapa, dipisah koma) tanpa mengubah .env}
        {--no-generate : Hanya periksa key dan model, tanpa ekstraksi percobaan}';

    protected $description = 'Periksa API key, model, dan ekstraksi jadwal dari chat';

    private const SAMPLE = 'Kak, saya mahasiswa S1 manajemen. Mau konsultasi olah data SmartPLS hari Sabtu depan jam 10 pagi ya, sekitar 1 jam.';

    public function handle(CacheFactory $cache): int
    {
        if ($override = $this->option('model')) {
            config(['koedu.ai.gemini.models' => array_values(array_filter(array_map('trim', explode(',', $override))))]);
        }

        $providers = config('koedu.ai.providers');
        $this->line('Saklar AI_ENABLED : '.(config('koedu.ai.enabled') ? 'menyala' : 'mati'));
        $this->line('Provider (urutan) : '.($providers === [] ? '-' : implode(' > ', $providers)));

        if (! AiAvailability::enabled()) {
            $this->error('AI belum aktif: isi GEMINI_API_KEYS (atau AI_OPENAI_API_KEY) di .env, lalu jalankan docker compose restart app worker scheduler.');

            return self::FAILURE;
        }

        $failed = false;

        if (in_array('gemini', $providers, true) && config('koedu.ai.gemini.keys') !== []) {
            $failed = ! $this->checkGemini($cache) || $failed;
        }

        if ($this->option('no-generate')) {
            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();
        $this->line('Ekstraksi percobaan: "'.self::SAMPLE.'"');

        try {
            // Dibuat di sini (bukan lewat injeksi) supaya memakai pengaturan --model di atas.
            $result = app(ScheduleRequestExtractor::class)->extractFromText(self::SAMPLE);
        } catch (AiException $e) {
            $this->error('Gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Isian', 'Hasil'], collect([
            'provider / model' => "{$result->provider} / {$result->model}",
            'permintaan jadwal' => $result->isScheduleRequest ? 'ya' : 'tidak (seharusnya ya)',
            'keyakinan' => (string) $result->confidence,
            'jenjang' => $result->degreeLevel,
            'bidang' => $result->fieldOfStudy,
            'layanan' => $result->serviceNote,
            'tanggal' => $result->requestedDate,
            'jam' => $result->requestedTime,
            'durasi (menit)' => $result->durationMinutes,
            'jenis sesi' => $result->sessionType,
            'ringkasan' => $result->summary,
        ])->map(fn ($value, $label) => [$label, $value ?? '-'])->values()->all());
        $this->comment('Pastikan tanggal "Sabtu depan" benar dihitung dari hari ini sebelum AI dipakai untuk chat sungguhan.');

        return $failed || ! $result->isScheduleRequest ? self::FAILURE : self::SUCCESS;
    }

    /** Berhasil bila minimal satu pasangan key dan model bisa menghasilkan jawaban. */
    private function checkGemini(CacheFactory $cache): bool
    {
        $config = config('koedu.ai');
        $models = $config['gemini']['models'];
        $provider = new GeminiProvider($config['gemini']['keys'], $models, $config['timeout'], $config['gemini']['key_cooldown'], $cache->store());

        $this->newLine();
        $this->line('Gemini, urutan model: '.implode(' > ', $models));

        $anyOk = false;
        $rows = [];
        foreach (array_keys($config['gemini']['keys']) as $index) {
            foreach ($models as $model) {
                ['status' => $status, 'message' => $message] = $provider->probe($index, $model);
                $anyOk = $anyOk || $status === 200;
                $detail = $message ? " ({$message})" : '';
                $rows[] = [
                    'Key ke-'.($index + 1),
                    $model,
                    match ($status) {
                        200 => 'OK',
                        400, 401, 403 => 'ditolak: key tidak valid atau tidak punya izin'.$detail,
                        404 => "model tidak bisa dipakai, ganti GEMINI_MODEL{$detail}",
                        429 => 'kuota habis (429)',
                        503 => "Google sedang sibuk (503), akan dilewati ke model berikutnya{$detail}",
                        0 => 'tidak bisa terhubung ke Google',
                        default => "HTTP {$status}{$detail}",
                    },
                ];
            }
        }
        $this->table(['Key', 'Model', 'Status'], $rows);

        if (! $anyOk) {
            $this->error('Tidak ada pasangan key dan model Gemini yang berfungsi.');
        }

        return $anyOk;
    }
}
