<?php

namespace App\Enums;

/**
 * Helper untuk enum berlabel: dipakai form dan filter di frontend.
 * Enum pemakai wajib punya method label().
 */
trait HasOptions
{
    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
