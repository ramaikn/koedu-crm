<?php

namespace App\Enums;

enum ClientStatus: string
{
    use HasOptions;

    case Lead = 'lead';
    case Active = 'active';
    case Inactive = 'inactive';
    case Alumni = 'alumni';

    public function label(): string
    {
        return match ($this) {
            self::Lead => 'Calon client',
            self::Active => 'Aktif',
            self::Inactive => 'Tidak aktif',
            self::Alumni => 'Alumni',
        };
    }
}
