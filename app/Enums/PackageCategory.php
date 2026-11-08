<?php

namespace App\Enums;

enum PackageCategory: string
{
    use HasOptions;

    case Premium = 'premium';
    case Recovery = 'recovery';
    case Privat = 'privat';
    case KelasGrup = 'kelas_grup';
    case Beasiswa = 'beasiswa';

    public function label(): string
    {
        return match ($this) {
            self::Premium => 'Kelas Premium Harian',
            self::Recovery => 'Kelas Recovery Skripsi',
            self::Privat => 'Bimbingan Privat',
            self::KelasGrup => 'Kelas Online Grup',
            self::Beasiswa => 'Bimbingan Beasiswa',
        };
    }
}
