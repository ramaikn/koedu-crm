<?php

namespace App\Enums;

enum UserRole: string
{
    use HasOptions;

    case Director = 'director';
    case Admin = 'admin';
    case Tutor = 'tutor';

    public function label(): string
    {
        return match ($this) {
            self::Director => 'Director',
            self::Admin => 'Admin',
            self::Tutor => 'Tutor',
        };
    }
}
