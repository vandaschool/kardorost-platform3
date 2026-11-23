<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class CoachProfileLinks
{
    private function fail(): never
    {
        throw ValidationException::withMessages(['links' => 'تا پنج پیوند با عنوان ۳ تا ۸۰ نویسه و نشانی کامل وب معتبر وارد کنید؛ نشانی تکراری، ورود با نام کاربری/رمز و پیوند اجرایی پذیرفته نمی‌شود.']);
    }

    /** @return list<array{label: string, url: string}> */
    public function normalize(mixed $input): array
    {
        if ($input === null) {
            return [];
        }
        if (! is_array($input) || ! array_is_list($input) || count($input) > 5) {
            $this->fail();
        }
        $result = [];
        $urls = [];
        foreach ($input as $link) {
            if (! is_array($link) || array_diff(array_keys($link), ['label', 'url']) !== [] || ! is_string($link['label'] ?? null) || ! mb_check_encoding($link['label'], 'UTF-8')) {
                $this->fail();
            }
            $label = trim($link['label']);
            if (mb_strlen($label) < 3 || mb_strlen($label) > 80 || preg_match('/[\x00-\x1F\x7F]/u', $label)) {
                $this->fail();
            }
            try {
                $url = app(ContentDocument::class)->link($link['url'] ?? null);
            } catch (ValidationException) {
                $this->fail();
            }
            if (! is_string($url) || ! preg_match('~^https?://~i', $url) || in_array($url, $urls, true)) {
                $this->fail();
            }
            $urls[] = $url;
            $result[] = ['label' => $label, 'url' => $url];
        }

        return $result;
    }

    /** @return list<array{label: string, url: string}> */
    public function display(mixed $input): array
    {
        try {
            return $this->normalize($input);
        } catch (ValidationException) {
            return [];
        }
    }
}
