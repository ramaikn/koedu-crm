<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    #[DataProvider('numbers')]
    public function test_normalizes_indonesian_numbers(string $raw, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($raw));
    }

    public static function numbers(): array
    {
        return [
            'awalan 0' => ['0812-3456-7890', '6281234567890'],
            'awalan +62 dengan spasi' => ['+62 812 3456 7890', '6281234567890'],
            'awalan 8' => ['81234567890', '6281234567890'],
            'ID WhatsApp dari WAHA' => ['6281234567890@c.us', '6281234567890'],
        ];
    }
}
