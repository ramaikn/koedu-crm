<?php

namespace App\Providers;

use App\Services\WhatsApp\CloudApiDriver;
use App\Services\WhatsApp\WahaDriver;
use App\Services\WhatsApp\WhatsAppProvider;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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

    public function boot(): void
    {
        //
    }
}
