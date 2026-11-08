<?php

namespace App\Providers;

use App\Observers\SheetSyncObserver;
use App\Services\Ai\AiProvider;
use App\Services\Ai\FallbackAiProvider;
use App\Services\Ai\GeminiProvider;
use App\Services\Ai\OpenAiCompatibleProvider;
use App\Services\Sheets\GoogleSheetsClient;
use App\Services\Sheets\ServiceAccountCredentials;
use App\Services\WhatsApp\CloudApiDriver;
use App\Services\WhatsApp\WahaDriver;
use App\Services\WhatsApp\WhatsAppProvider;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AiProvider::class, fn () => $this->makeAiProvider());

        // Dibuat saat dipakai, bukan saat boot: file kunci yang salah baru ketahuan ketika sinkron dijalankan.
        $this->app->bind(GoogleSheetsClient::class, fn () => new GoogleSheetsClient(
            (string) config('koedu.sheets.spreadsheet_id'),
            ServiceAccountCredentials::fromFile(config('koedu.sheets.credentials_path')),
            $this->app->make(CacheFactory::class)->store(),
        ));

        // Driver dipilih dari WHATSAPP_DRIVER. Bukan singleton, supaya config bisa diganti saat test.
        $this->app->bind(WhatsAppProvider::class, function () {
            $config = config('koedu.whatsapp');

            return match ($config['driver']) {
                'waha' => new WahaDriver($config['waha']['url'], $config['waha']['session'], $config['waha']['api_key']),
                'cloud_api' => new CloudApiDriver(
                    $config['cloud_api']['phone_number_id'],
                    $config['cloud_api']['access_token'],
                    $config['cloud_api']['app_secret'],
                    $config['cloud_api']['verify_token'],
                    $config['cloud_api']['api_version'],
                ),
                default => throw new InvalidArgumentException("WHATSAPP_DRIVER tidak dikenal: {$config['driver']}"),
            };
        });
    }

    /** Merakit provider AI sesuai AI_PROVIDERS. Nama yang tidak dikenal dianggap salah konfigurasi. */
    private function makeAiProvider(): AiProvider
    {
        $config = config('koedu.ai');

        $providers = array_map(fn (string $name) => match ($name) {
            'gemini' => new GeminiProvider(
                $config['gemini']['keys'],
                $config['gemini']['models'],
                $config['timeout'],
                $config['gemini']['key_cooldown'],
                $this->app->make(CacheFactory::class)->store(),
            ),
            'openai' => new OpenAiCompatibleProvider($config['openai']['base_url'], $config['openai']['key'], $config['openai']['model'], $config['timeout']),
            default => throw new InvalidArgumentException("AI_PROVIDERS memuat provider yang tidak dikenal: {$name}"),
        }, $config['providers']);

        return new FallbackAiProvider($providers);
    }

    public function boot(): void
    {
        // Salinan ke Google Sheets: data yang tampil di sana memicu sinkron saat berubah.
        foreach (array_keys(SheetSyncObserver::TABS_BY_MODEL) as $model) {
            $model::observe(SheetSyncObserver::class);
        }
    }
}
