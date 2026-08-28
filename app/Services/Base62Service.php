<?php

namespace App\Services;

class Base62Service
{
    private string $characters =
        '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public function encode(int $number): string
    {
        if ($number === 0) {
            return $this->characters[0];
        }

        $result = '';

        while ($number > 0) {
            $remainder = $number % 62;

            $result = $this->characters[$remainder] . $result;

            $number = intdiv($number, 62);
        }

        return $result;
    }
}