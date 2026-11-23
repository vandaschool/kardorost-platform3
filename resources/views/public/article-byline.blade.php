@php($byline = app(\App\Services\ContentBylines::class)->display($definition['byline'] ?? null))
@if($byline !== null)<p class="public-byline" data-public-article-byline>نویسنده: <bdi dir="auto">{{ $byline }}</bdi></p>@endif
