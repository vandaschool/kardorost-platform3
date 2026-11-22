@php($document = app(\App\Services\ContentDocument::class)->forDisplay($definition['document'] ?? null))
@if($document)
<div data-rich-document>
@foreach($document['blocks'] as $block)
    @switch($block['type'])
        @case('heading')
            @if($block['level'] === 2)<h2 id="content-block-{{ $loop->index + 1 }}">@include('public.content-runs', ['runs' => $block['children']])</h2>@else<h3 id="content-block-{{ $loop->index + 1 }}">@include('public.content-runs', ['runs' => $block['children']])</h3>@endif
            @break
        @case('paragraph')
            <p>@include('public.content-runs', ['runs' => $block['children']])</p>
            @break
        @case('quote')
            <blockquote><p>@include('public.content-runs', ['runs' => $block['children']])</p></blockquote>
            @break
        @case('bullet_list')
            <ul>@foreach($block['items'] as $runs)<li>@include('public.content-runs', ['runs' => $runs])</li>@endforeach</ul>
            @break
        @case('ordered_list')
            <ol>@foreach($block['items'] as $runs)<li>@include('public.content-runs', ['runs' => $runs])</li>@endforeach</ol>
            @break
        @case('image')
            @php($image = app(\App\Services\ContentMediaLibrary::class)->image($block['media'], $block['sha256']))
            @if($image)
                <figure data-content-image><img src="{{ $image['url'] }}" width="{{ $image['width'] }}" height="{{ $image['height'] }}" alt="{{ $block['alt'] }}" loading="lazy" decoding="async">@if($block['caption'] !== '')<figcaption>{{ $block['caption'] }}</figcaption>@endif</figure>
            @else
                <p class="muted" data-unavailable-media>این تصویر دیگر منتشر نیست.</p>
            @endif
            @break
        @case('divider')
            <hr>
            @break
    @endswitch
@endforeach
</div>
@else
@foreach(preg_split('/\R\s*\R/u', $definition['body']) as $paragraph)<p>{{ $paragraph }}</p>@endforeach
@endif
