<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ContentCategories
{
    public function normalize(mixed $input): ?string
    {
        if ($input === null || $input === '' || (is_string($input) && mb_check_encoding($input, 'UTF-8') && preg_match('/^\p{Z}*$/u', $input))) {
            return null;
        }
        try {
            return app(ContentTags::class)->label($input);
        } catch (ValidationException) {
            throw ValidationException::withMessages(['category' => 'دستهٔ مقاله باید متن معتبر یک تا چهل نویسه باشد.']);
        }
    }

    public function display(mixed $input): ?string
    {
        try {
            return $this->normalize($input);
        } catch (ValidationException) {
            return null;
        }
    }

    /** @return list<string> */
    public function facets(Builder $pages): array
    {
        $counts = [];
        foreach ((clone $pages)->select(['id', 'available', 'current_version'])->with('currentPublication')->orderBy('id')->lazy(200) as $page) {
            $definition = app(ContentPublishing::class)->publicDefinition($page);
            $category = $this->display($definition['category'] ?? null);
            if ($category !== null) {
                $counts[$category] = ($counts[$category] ?? 0) + 1;
            }
        }
        uksort($counts, fn ($a, $b) => ($counts[$b] <=> $counts[$a]) ?: strcmp((string) $a, (string) $b));

        return array_map(strval(...), array_keys(array_slice($counts, 0, 50, true)));
    }
}
