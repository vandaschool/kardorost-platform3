@php($labels = app(\App\Services\ContentTags::class)->display($definition['tags'] ?? null))
@if($labels)<ul class="public-article-tags" data-public-article-tags aria-label="برچسب‌های مقاله">@foreach($labels as $label)<li><a href="{{ route('public.journal', ['tag' => $label], absolute: false) }}">{{ $label }}</a></li>@endforeach</ul>@endif
