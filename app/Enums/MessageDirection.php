<?php

namespace App\Enums;

enum MessageDirection: string
{
    use HasOptions;

    case Inbound = 'inbound';
    case Outbound = 'outbound';

    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'Masuk',
            self::Outbound => 'Keluar',
        };
    }
}
