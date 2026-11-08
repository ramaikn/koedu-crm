<?php

namespace App\Enums;

enum ConsultationType: string
{
    use HasOptions;

    case Private = 'private';
    case Group = 'group';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Privat',
            self::Group => 'Grup',
        };
    }
}
