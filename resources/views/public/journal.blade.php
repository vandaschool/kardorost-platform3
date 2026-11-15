@extends('public.layout')
@section('content')
<section class="public-heading"><span class="public-eyebrow">مجله کاردرست</span><h1>یادداشت‌های مسیر حرفه‌ای</h1><p class="public-lead">مقاله‌ها و نوشته‌های منتشرشده تیم کاردرست.</p></section><form method="get" class="public-filter glass"><label>جست‌وجوی مقاله<input name="q" value="{{ $query }}" maxlength="100" placeholder="عنوان مقاله"></label><button class="btn primary">جست‌وجو</button></form>@if($articles->isEmpty())<p class="public-empty">هنوز یادداشتی با این شرایط منتشر نشده است.</p>@else<div class="public-grid">@foreach($articles as $article)@include('public.article-card')@endforeach</div>@endif
@include('public.pagination', ['paginator'=>$articles])
@endsection
