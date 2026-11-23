<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class ContentBylines
{
    public function normalize(mixed $input): ?string
    {
        if ($input === null) {
            return null;
        }
        if (! is_string($input) || ! mb_check_encoding($input, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $input)) {
            $this->fail();
        }
        $name = trim(preg_replace('/\p{Z}+/u', ' ', $input));
        if (mb_strlen($name, 'UTF-8') > 80) {
            $this->fail();
        }

        return $name === '' ? null : $name;
    }

    public function display(mixed $input): ?string
    {
        try {
            return $this->normalize($input);
        } catch (ValidationException) {
            return null;
        }
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['byline' => 'نام نویسنده باید متن معتبر تا هشتاد نویسه باشد.']);
    }
}
