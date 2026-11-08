<?php

namespace App\Services\WhatsApp;

use Carbon\CarbonImmutable;

/** Pesan WhatsApp masuk dalam bentuk yang sama untuk semua provider. */
final readonly class IncomingMessage
{
    public function __construct(
        public string $providerMessageId,
        /** Nomor pengirim, format 62xxx. */
        public string $phone,
        public ?string $displayName,
        public string $type,
        public ?string $body,
        /**
         * Waktu kirim dalam zona waktu aplikasi. Kolom timestamp di database tidak menyimpan zona,
         * jadi nilai dalam UTC akan terbaca bergeser sebesar selisih zona (8 jam untuk WITA).
         */
        public CarbonImmutable $sentAt,
        public string $provider,
    ) {}
}
