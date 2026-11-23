<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ContentTags
{
    private function fail(): never
    {
        throw ValidationException::withMessages(['tags' => 'تا هشت برچسب متفاوت، هرکدام یک تا چهل نویسهٔ معتبر وارد کنید.']);
    }

    public function label(mixed $value): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            $this->fail();
        }
        $value = trim(preg_replace('/\p{Z}+/u', ' ', $value));
        if ($value === '' || mb_strlen($value, 'UTF-8') > 40) {
            $this->fail();
        }

        return $value;
    }

    public function filter(mixed $value): string
    {
        try {
            return $this->label($value);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['tag' => 'برچسب معتبر تا چهل نویسه انتخاب کنید.']);
        }
    }

    /** @return list<string> */
    public function normalize(mixed $input): array
    {
        if ($input === null) {
            return [];
        }
        if (! is_array($input) || ! array_is_list($input) || count($input) > 8) {
            $this->fail();
        }
        $tags = [];
        foreach ($input as $value) {
            $label = $this->label($value);
            if (in_array($label, $tags, true)) {
                $this->fail();
            }
            $tags[] = $label;
        }

        return $tags;
    }

    /** @return list<string> */
    public function display(mixed $input): array
    {
        try {
            return $this->normalize($input);
        } catch (ValidationException) {
            return [];
        }
    }

    /** @return list<string> */
    public function facets(Builder $pages): array
    {
        $counts = [];
        foreach ((clone $pages)->select(['id', 'available', 'current_version'])->orderBy('id')->lazy(200) as $page) {
            $definition = app(ContentPublishing::class)->publicDefinition($page);
            foreach ($this->display($definition['tags'] ?? null) as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        uksort($counts, fn ($a, $b) => ($counts[$b] <=> $counts[$a]) ?: strcmp((string) $a, (string) $b));

        return array_map(strval(...), array_keys(array_slice($counts, 0, 50, true)));
    }
}
