<?php

namespace App\Enums;

enum ScheduleType: string
{
    use HasOptions;

    case Flexible = 'flexible';
    case Weekday = 'weekday';
    case Weekend = 'weekend';

    public function label(): string
    {
        return match ($this) {
            self::Flexible => 'Fleksibel',
            self::Weekday => 'Weekday (Senin-Jumat)',
            self::Weekend => 'Weekend (Sabtu-Minggu)',
        };
    }
}
