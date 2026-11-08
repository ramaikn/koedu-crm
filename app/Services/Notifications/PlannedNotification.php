<?php

namespace App\Services\Notifications;

use Carbon\CarbonImmutable;
use Closure;

/**
 * Satu pesan yang menurut aturan perlu dikirim. Keputusan (siapa, apa, kapan) dipisahkan dari efek samping:
 * `claim` menandai di database bahwa pesan ini diambil dan hanya mengembalikan true untuk satu pemanggil,
 * sehingga dua proses yang jalan bersamaan tidak mengirim pesan yang sama dua kali.
 */
final readonly class PlannedNotification
{
    /**
     * @param  Closure(): bool  $claim
     * @param  string|null  $body  null = hanya klaim tanpa mengirim (mis. tidak ada yang berubah untuk diberitahukan)
     */
    public function __construct(
        public string $type,
        public ?string $phone,
        public ?string $recipient,
        public ?string $body,
        public Closure $claim,
        /** Waktu sesi terkait, dipakai untuk menentukan apakah pesan mendesak di luar jam kirim. */
        public ?CarbonImmutable $sessionStart = null,
        public bool $respectsWindow = true,
    ) {}

    public function isSilent(): bool
    {
        return $this->body === null;
    }
}
