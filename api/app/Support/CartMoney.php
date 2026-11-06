<?php

namespace App\Support;

final class CartMoney
{
    public static function cents(string $amount): string
    {
        return self::normalize(str_replace('.', '', $amount));
    }

    public static function multiplyCents(string $cents, int $multiplier): string
    {
        if ($multiplier < 0) {
            throw new \InvalidArgumentException('Money multiplier cannot be negative.');
        }

        $carry = 0;
        $result = '';

        for ($index = strlen($cents) - 1; $index >= 0; $index--) {
            $digit = (ord($cents[$index]) - 48) * $multiplier + $carry;
            $result = (string) ($digit % 10).$result;
            $carry = intdiv($digit, 10);
        }

        return self::normalize(($carry > 0 ? (string) $carry : '').$result);
    }

    public static function addCents(string $left, string $right): string
    {
        $left = strrev($left);
        $right = strrev($right);
        $length = max(strlen($left), strlen($right));
        $carry = 0;
        $result = '';

        for ($index = 0; $index < $length; $index++) {
            $leftDigit = $index < strlen($left) ? ord($left[$index]) - 48 : 0;
            $rightDigit = $index < strlen($right) ? ord($right[$index]) - 48 : 0;
            $sum = $leftDigit + $rightDigit + $carry;
            $result .= (string) ($sum % 10);
            $carry = intdiv($sum, 10);
        }

        if ($carry > 0) {
            $result .= (string) $carry;
        }

        return self::normalize(strrev($result));
    }

    public static function format(string $cents): string
    {
        $cents = str_pad(self::normalize($cents), 3, '0', STR_PAD_LEFT);
        $whole = substr($cents, 0, -2);
        $fraction = substr($cents, -2);

        return $whole.'.'.$fraction;
    }

    private static function normalize(string $digits): string
    {
        $normalized = ltrim($digits, '0');

        return $normalized === '' ? '0' : $normalized;
    }
}
