<?php

namespace App\Enums;

enum DeliveryMode: string
{
    use HasOptions;

    case Private = 'private';
    case Group = 'group';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Privat 1-on-1',
            self::Group => 'Grup',
        };
    }
}
