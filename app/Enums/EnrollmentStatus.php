<?php

namespace App\Enums;

enum EnrollmentStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Expired = 'expired';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Expired => 'Kedaluwarsa',
            self::Completed => 'Selesai',
            self::Cancelled => 'Dibatalkan',
        };
    }
}
