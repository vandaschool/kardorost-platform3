<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class Phone
{
    public static function normalize(string $value): string
    {
        $value = strtr($value, array_combine(preg_split('//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
        $value = preg_replace('/[\s()\-]/u', '', $value);
        $value = preg_replace('/^(\+98|0098|98|0)/', '', $value);
        if (! preg_match('/^9[0-9]{9}$/D', $value)) {
            throw ValidationException::withMessages(['phone' => 'شماره موبایل معتبر وارد کنید.']);
        }

        return '98'.$value;
    }
}
