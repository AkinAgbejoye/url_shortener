<?php

namespace Tests\Unit;

use App\Services\Base62Service;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Base62ServiceTest extends TestCase
{
    /** @return array<string, array{int, string}> */
    public static function values(): array
    {
        return [
            'zero' => [0, '0'],
            'single digit' => [1, '1'],
            'last digit' => [61, 'Z'],
            'two digits' => [62, '10'],
            'larger number' => [3843, 'ZZ'],
            'three digits' => [3844, '100'],
            'largest supported integer' => [PHP_INT_MAX, 'aZl8N0y58M7'],
        ];
    }

    #[DataProvider('values')]
    public function test_it_encodes_and_decodes_known_values(int $number, string $encoded): void
    {
        $service = new Base62Service;

        $this->assertSame($encoded, $service->encode($number));
        $this->assertSame($number, $service->decode($encoded));
    }

    public function test_it_rejects_negative_integers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Base62 only supports non-negative integers.');

        (new Base62Service)->encode(-1);
    }

    public function test_it_rejects_an_empty_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A Base62 value cannot be empty.');

        (new Base62Service)->decode('');
    }

    public function test_it_rejects_an_invalid_character(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Base62 character: !');

        (new Base62Service)->decode('hello!');
    }
}
