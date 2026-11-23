@php($category = app(\App\Services\ContentCategories::class)->display($definition['category'] ?? null))
@if($category !== null)<a class="public-tag gold" data-public-article-category href="{{ route('public.journal', ['category' => $category], absolute: false) }}">{{ $category }}</a>@endif
