<?php

namespace App\Services;

class PublicFormatting
{
    public static function number(int|float $value): string
    {
        return strtr(number_format($value, fmod($value, 1) === 0.0 ? 0 : 1, '٫', '٬'), array_combine(range(0, 9), preg_split('//u', '۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY)));
    }
}
