@extends('public.layout')
@section('content')
<article><header class="public-heading">@if($page->kind === 'article')<a href="/journal" class="text-link">مجله کاردرست ←</a>@endif<h1>{{ $definition['title'] }}</h1><p class="public-lead">{{ $definition['excerpt'] }}</p>@if($page->kind === 'article')@include('public.article-category', ['definition' => $definition])@include('public.article-tags', ['definition' => $definition])@endif</header><div class="public-prose glass">@include('public.content-body', ['definition' => $definition])</div></article>
@endsection
