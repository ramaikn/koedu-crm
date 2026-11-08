<?php

namespace App\Console\Commands;

use App\Services\Ai\AiAvailability;
use App\Services\Ai\AiException;
use App\Services\Ai\GeminiProvider;
use App\Services\Scheduling\ScheduleRequestExtractor;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;

/**
 * Memeriksa pengaturan AI setelah GEMINI_API_KEYS diisi: tiap key, model, dan satu ekstraksi percobaan.
 * API key tidak pernah ditampilkan, hanya nomor urutnya.
 */
class AiCheckCommand extends Command
{
    protected $signature = 'ai:check {--no-generate : Hanya periksa key dan model, tanpa ekstraksi percobaan}';

    protected $description = 'Periksa API key, model, dan ekstraksi jadwal dari chat';

    private const SAMPLE = 'Kak, saya mahasiswa S1 manajemen. Mau konsultasi olah data SmartPLS hari Sabtu depan jam 10 pagi ya, sekitar 1 jam.';

    public function handle(ScheduleRequestExtractor $extractor, CacheFactory $cache): int
    {
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
            $result = $extractor->extractFromText(self::SAMPLE);
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

    private function checkGemini(CacheFactory $cache): bool
    {
        $config = config('koedu.ai');
        $provider = new GeminiProvider($config['gemini']['keys'], $config['gemini']['model'], $config['timeout'], $config['gemini']['key_cooldown'], $cache->store());

        $this->newLine();
        $this->line("Gemini, model {$config['gemini']['model']}:");

        $anyOk = false;
        $rows = [];
        foreach (array_keys($config['gemini']['keys']) as $index) {
            $status = $provider->probe($index);
            $anyOk = $anyOk || $status === 200;
            $rows[] = [
                'Key ke-'.($index + 1),
                match ($status) {
                    200 => 'OK',
                    400, 401, 403 => 'ditolak: key tidak valid atau tidak punya izin',
                    404 => "model tidak ditemukan, periksa GEMINI_MODEL ({$config['gemini']['model']})",
                    429 => 'kuota habis (429)',
                    0 => 'tidak bisa terhubung ke Google',
                    default => "HTTP {$status}",
                },
                $provider->isCoolingDown($index) ? 'sedang istirahat' : 'aktif',
            ];
        }
        $this->table(['Key', 'Status', 'Pemakaian'], $rows);

        if (! $anyOk) {
            $this->error('Tidak ada key Gemini yang berfungsi.');
        }

        return $anyOk;
    }
}
