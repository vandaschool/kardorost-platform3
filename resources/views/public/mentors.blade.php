@extends('public.layout')
@section('content')
<section class="public-section public-mentor-content"><span class="public-kicker">آشنایی پیش از گفت‌وگو</span><h1>مربیان کاردرست</h1><p>زمینه‌های فعالیت و معرفی منتشرشده مربیان را بخوانید.</p></section>
@if($profiles->isEmpty())<section class="public-card glass public-mentor-content"><p>هنوز پروفایلی برای نمایش منتشر نشده است.</p></section>@endif
<section class="public-grid public-section">@foreach($profiles as $profile)@php($definition=$profile->currentPublication->definition)<article class="public-card glass public-mentor-content" data-public-mentor><span class="public-kicker">{{ implode(' · ', $definition['focus_areas']) }}</span><h2><a href="{{ route('public.mentor',$profile->slug) }}">{{ $definition['display_name'] }}</a></h2><p>{{ $definition['headline'] }}</p><a class="btn secondary" href="{{ route('public.mentor',$profile->slug) }}">آشنایی با مربی ←</a></article>@endforeach</section>
@include('public.pagination',['paginator'=>$profiles])
@endsection
