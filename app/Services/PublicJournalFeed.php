<?php

namespace App\Services;

use App\Models\ContentPublication;

class PublicJournalFeed
{
    private function xmlText(string $value): string
    {
        return trim(preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value));
    }

    /** @return list<array{title: string, description_html: string, url: string, published_at: string}> */
    public function entries(): array
    {
        $publications = ContentPublication::query()
            ->join('content_pages', 'content_pages.id', '=', 'content_publications.content_page_id')
            ->where('content_pages.kind', 'article')
            ->where('content_pages.available', true)
            ->where('content_pages.current_version', '>', 0)
            ->whereColumn('content_pages.current_version', 'content_publications.number')
            ->where('content_publications.published_at', '<=', now())
            ->orderByDesc('content_publications.published_at')
            ->orderByDesc('content_publications.id')
            ->limit(50)
            ->get(['content_publications.*', 'content_pages.slug as public_slug']);
        $entries = [];
        $base = rtrim(config('app.url'), '/');
        foreach ($publications as $publication) {
            try {
                $definition = $publication->definition;
                if (! is_array($definition) || ! is_string($publication->content_hash) || ! hash_equals($publication->content_hash, app(ContentPublishing::class)->hash($definition))) {
                    continue;
                }
                if (! is_string($definition['title'] ?? null) || ! is_string($definition['excerpt'] ?? null) || ! mb_check_encoding($definition['title'].$definition['excerpt'], 'UTF-8')) {
                    continue;
                }
                app(ContentTags::class)->normalize($definition['tags'] ?? null);
                app(ContentCategories::class)->normalize($definition['category'] ?? null);
                app(ContentBylines::class)->normalize($definition['byline'] ?? null);
                $title = $this->xmlText($definition['title']);
                if ($title === '' || strlen($publication->public_slug) > 80 || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $publication->public_slug)) {
                    continue;
                }
                $entries[] = [
                    'title' => $title,
                    // RSS descriptions contain encoded HTML; encode plain text before XML escaping.
                    'description_html' => htmlspecialchars($this->xmlText($definition['excerpt']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    'url' => $base.route('public.article', ['slug' => $publication->public_slug], absolute: false),
                    'published_at' => $publication->published_at->utc()->toRfc2822String(),
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        return $entries;
    }
}
