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
        ];
    }

    #[DataProvider('values')]
    public function test_it_encodes_and_decodes_known_values(int $number, string $encoded): void
    {
        $service = new Base62Service;

        $this->assertSame($encoded, $service->encode($number));
        $this->assertSame($number, $service->decode($encoded));
    }

    public function test_it_rejects_invalid_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Base62Service)->decode('hello!');
    }
}
