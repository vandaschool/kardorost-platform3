<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ContentDocument
{
    private function fail(): never
    {
        throw ValidationException::withMessages(['document' => 'قالب متن معتبر نیست؛ از تیتر، پاراگراف، فهرست، نقل‌قول و لینک وب معتبر استفاده کنید.']);
    }

    private function keys(array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) {
            $this->fail();
        }
    }

    public function link(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            $this->fail();
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 2048 || preg_match('/[\p{C}\s<>"`]/u', $value) || str_contains($value, chr(92))) {
            $this->fail();
        }
        if (str_starts_with($value, '#')) {
            if (! preg_match('/^#[a-zA-Z0-9_-]{1,100}$/D', $value)) {
                $this->fail();
            }

            return $value;
        }
        $parts = parse_url($value);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            $this->fail();
        }
        if (str_starts_with($value, '/') && ! str_starts_with($value, '//') && ! isset($parts['scheme'], $parts['host'])) {
            return $value;
        }
        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ! is_string($parts['host'] ?? null) || (isset($parts['port']) && $parts['port'] < 1)) {
            $this->fail();
        }
        $host = $parts['host'];
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            if (! filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                $this->fail();
            }
        } else {
            if (mb_strlen($host) > 253) {
                $this->fail();
            }
            foreach (explode('.', rtrim($host, '.')) as $label) {
                if (mb_strlen($label) > 63 || ! preg_match('/^[\pL\pN](?:[\pL\pN-]*[\pL\pN])?$/uD', $label)) {
                    $this->fail();
                }
            }
        }

        return $value;
    }

    private function runs(mixed $runs, int &$nodes, int &$characters): array
    {
        if (! is_array($runs) || ! array_is_list($runs) || count($runs) > 200) {
            $this->fail();
        }
        $result = [];
        foreach ($runs as $run) {
            if (! is_array($run)) {
                $this->fail();
            }
            $this->keys($run, ['text', 'bold', 'italic', 'href']);
            if (! is_string($run['text'] ?? null) || ! mb_check_encoding($run['text'], 'UTF-8') || str_contains($run['text'], "\0")) {
                $this->fail();
            }
            foreach (['bold', 'italic'] as $style) {
                if (array_key_exists($style, $run) && ! is_bool($run[$style])) {
                    $this->fail();
                }
            }
            $nodes++;
            $text = str_replace(["\r\n", "\r"], "\n", $run['text']);
            $characters += mb_strlen($text);
            if ($nodes > 2000 || $characters > 60000) {
                $this->fail();
            }
            $href = $this->link($run['href'] ?? null);
            if ($text !== '') {
                $item = ['text' => $text, 'bold' => $run['bold'] ?? false, 'italic' => $run['italic'] ?? false, 'href' => $href];
                $last = array_key_last($result);
                if ($last !== null && $result[$last]['bold'] === $item['bold'] && $result[$last]['italic'] === $item['italic'] && $result[$last]['href'] === $item['href']) {
                    $result[$last]['text'] .= $text;
                } else {
                    $result[] = $item;
                }
            }
        }

        return $result;
    }

    public function normalize(mixed $document): array
    {
        if (! is_array($document) || ($document['version'] ?? null) !== 1 || ! is_array($document['blocks'] ?? null) || ! array_is_list($document['blocks']) || count($document['blocks']) < 1 || count($document['blocks']) > 100) {
            $this->fail();
        }
        $this->keys($document, ['version', 'blocks']);
        $blocks = [];
        $nodes = 0;
        $characters = 0;
        $images = 0;
        foreach ($document['blocks'] as $block) {
            if (! is_array($block) || ! in_array($block['type'] ?? null, ['paragraph', 'heading', 'quote', 'bullet_list', 'ordered_list', 'divider', 'image'], true)) {
                $this->fail();
            }
            $type = $block['type'];
            if ($type === 'image') {
                $this->keys($block, ['type', 'media', 'sha256', 'alt', 'caption']);
                if (! is_string($block['media'] ?? null) || ! Str::isUuid($block['media']) || ! is_string($block['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $block['sha256']) || ! is_string($block['alt'] ?? null) || trim($block['alt']) === '' || ! mb_check_encoding($block['alt'], 'UTF-8') || mb_strlen($block['alt']) > 240 || ! is_string($block['caption'] ?? '') || ! mb_check_encoding($block['caption'] ?? '', 'UTF-8') || mb_strlen($block['caption'] ?? '') > 320 || str_contains($block['alt'].($block['caption'] ?? ''), "\0")) {
                    $this->fail();
                }
                $characters += mb_strlen($block['alt'].($block['caption'] ?? ''));
                $images++;
                if ($images > 20 || $characters > 60000) {
                    $this->fail();
                }
                $blocks[] = ['type' => 'image', 'media' => strtolower($block['media']), 'sha256' => $block['sha256'], 'alt' => trim($block['alt']), 'caption' => trim($block['caption'] ?? '')];
            } elseif ($type === 'divider') {
                $this->keys($block, ['type']);
                $blocks[] = ['type' => 'divider'];
            } elseif (in_array($type, ['bullet_list', 'ordered_list'], true)) {
                $this->keys($block, ['type', 'items']);
                if (! is_array($block['items'] ?? null) || ! array_is_list($block['items']) || count($block['items']) < 1 || count($block['items']) > 50) {
                    $this->fail();
                }
                $items = [];
                foreach ($block['items'] as $runs) {
                    $items[] = $this->runs($runs, $nodes, $characters);
                }
                $blocks[] = ['type' => $type, 'items' => $items];
            } else {
                $this->keys($block, $type === 'heading' ? ['type', 'level', 'children'] : ['type', 'children']);
                if ($type === 'heading' && ! in_array($block['level'] ?? null, [2, 3], true)) {
                    $this->fail();
                }
                $item = ['type' => $type];
                if ($type === 'heading') {
                    $item['level'] = $block['level'];
                }
                $item['children'] = $this->runs($block['children'] ?? null, $nodes, $characters);
                $blocks[] = $item;
            }
        }
        $normalized = ['version' => 1, 'blocks' => $blocks];
        if (trim($this->plainCanonical($normalized)) === '') {
            $this->fail();
        }

        return $normalized;
    }

    private function plainCanonical(array $document): string
    {
        $blocks = [];
        foreach ($document['blocks'] as $block) {
            if ($block['type'] === 'image') {
                $blocks[] = trim($block['alt']."\n".$block['caption']);
            } elseif (isset($block['items'])) {
                $blocks[] = implode("\n", array_map(fn ($runs) => implode('', array_column($runs, 'text')), $block['items']));
            } elseif (isset($block['children'])) {
                $blocks[] = implode('', array_column($block['children'], 'text'));
            }
        }

        return trim(implode("\n\n", $blocks));
    }

    public function plain(array $document): string
    {
        return $this->plainCanonical($this->normalize($document));
    }

    public function forDisplay(mixed $document): ?array
    {
        try {
            return $document === null ? null : $this->normalize($document);
        } catch (ValidationException) {
            return null;
        }
    }
}
