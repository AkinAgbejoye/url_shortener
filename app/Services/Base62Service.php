<?php

namespace App\Services;

class Base62Service
{
    private string $characters =
        '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public function encode(int $number): string
    {
        if ($number < 0) {
            throw new \InvalidArgumentException('Base62 only supports non-negative integers.');
        }

        if ($number === 0) {
            return $this->characters[0];
        }

        $result = '';

        while ($number > 0) {
            $remainder = $number % 62;

            $result = $this->characters[$remainder].$result;

            $number = intdiv($number, 62);
        }

        return $result;
    }

    public function decode(string $value): int
    {
        if ($value === '') {
            throw new \InvalidArgumentException('A Base62 value cannot be empty.');
        }

        $result = 0;

        foreach (str_split($value) as $character) {
            $position = strpos($this->characters, $character);

            if ($position === false) {
                throw new \InvalidArgumentException("Invalid Base62 character: {$character}");
            }

            $result = ($result * 62) + $position;
        }

        return $result;
    }
}
