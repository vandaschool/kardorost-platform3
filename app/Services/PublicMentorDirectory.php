<?php

namespace App\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PublicMentorDirectory
{
    private const FILTERS = ['q' => 120, 'city' => 100, 'focus' => 60, 'language' => 40];

    private function normalize(string $value): string
    {
        $value = strtr(mb_strtolower($value, 'UTF-8'), ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', "\u{200C}" => ' ', "\u{200D}" => ' ']);
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u', '', $value);

        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', $value));
    }

    public function search(array $input): array
    {
        $rules = ['page' => 'nullable|integer|min:1|max:100000'];
        foreach (self::FILTERS as $key => $limit) {
            $rules[$key] = 'nullable|string|max:'.$limit;
        }
        $data = Validator::make($input, $rules)->validate();
        $filters = [];
        foreach (self::FILTERS as $key => $limit) {
            $value = $data[$key] ?? '';
            if (! mb_check_encoding($value, 'UTF-8')) {
                throw ValidationException::withMessages([$key => 'متن معتبر وارد کنید.']);
            }
            $filters[$key] = $this->normalize($value) === '' ? '' : trim($value);
        }
        $normalized = array_map($this->normalize(...), $filters);
        $terms = array_values(array_filter(explode(' ', $normalized['q']), fn ($value) => $value !== ''));
        $page = (int) ($data['page'] ?? 1);
        $matched = 0;
        $visible = false;
        $items = [];
        $facets = ['city' => [], 'focus' => [], 'language' => []];
        // Only reviewed current public snapshots are decrypted; private drafts are never searched.
        // Chunking bounds memory while keeping encrypted snapshots out of a plaintext search index.
        foreach (app(CoachProfiles::class)->visible()->select(['id', 'slug', 'user_id', 'public_enabled', 'current_publication_id', 'public_published_at'])->orderByDesc('public_published_at')->orderByDesc('id')->lazy(200) as $profile) {
            $visible = true;
            $definition = $profile->currentPublication->definition;
            $values = ['city' => [$definition['city']], 'focus' => $definition['focus_areas'], 'language' => $definition['languages']];
            foreach ($values as $key => $labels) {
                foreach ($labels as $label) {
                    if ($label !== '' && count($facets[$key]) < 200) {
                        $facets[$key][$this->normalize($label)] ??= $label;
                    }
                }
            }
            $matches = true;
            foreach ($values as $key => $labels) {
                if ($normalized[$key] !== '' && ! in_array($normalized[$key], array_map($this->normalize(...), $labels), true)) {
                    $matches = false;
                }
            }
            if (! $matches) {
                continue;
            }
            $text = $this->normalize(implode(' ', [$definition['display_name'], $definition['headline'], $definition['bio'], $definition['city'], ...$definition['focus_areas'], ...$definition['languages']]));
            foreach ($terms as $term) {
                if (! str_contains($text, $term)) {
                    $matches = false;
                    break;
                }
            }
            if (! $matches) {
                continue;
            }
            $matched++;
            if ($matched > ($page - 1) * 12 && count($items) < 12) {
                $items[] = $profile;
            }
        }
        foreach ($facets as $key => $labels) {
            if ($filters[$key] !== '') {
                $labels[$normalized[$key]] ??= $filters[$key];
                $filters[$key] = $labels[$normalized[$key]];
            }
            natcasesort($labels);
            $facets[$key] = array_values($labels);
        }
        $query = array_filter($filters, fn ($value) => $value !== '');
        $profiles = new LengthAwarePaginator($items, $matched, 12, $page, ['path' => route('public.mentors'), 'query' => $query]);

        return ['profiles' => $profiles, 'filters' => $filters, 'facets' => $facets, 'has_profiles' => $visible, 'filtered' => count($query) > 0];
    }
}
