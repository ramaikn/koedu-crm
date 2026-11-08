<?php

namespace App\Enums;

enum PaymentStatus: string
{
    use HasOptions;

    case Unpaid = 'unpaid';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Belum bayar',
            self::Paid => 'Lunas',
        };
    }
}
