<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Present = 'present';
    case Absent = 'absent';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Belum diabsen',
            self::Present => 'Hadir',
            self::Absent => 'Tidak hadir',
        };
    }
}
