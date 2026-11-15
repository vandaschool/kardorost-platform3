@extends('public.layout')
@section('content')
<article><header class="public-heading">@if($page->kind === 'article')<a href="/journal" class="text-link">مجله کاردرست ←</a>@endif<h1>{{ $definition['title'] }}</h1><p class="public-lead">{{ $definition['excerpt'] }}</p></header><div class="public-prose glass">@foreach(preg_split('/\R\s*\R/u', $definition['body']) as $paragraph)<p>{{ $paragraph }}</p>@endforeach</div></article>
@endsection
