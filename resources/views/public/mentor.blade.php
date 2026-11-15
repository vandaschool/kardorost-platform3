@extends('public.layout')
@section('content')
<section class="public-section public-mentor-content"><a href="{{ route('public.mentors') }}" class="text-link">بازگشت به مربیان</a><span class="public-kicker">{{ implode(' · ', $definition['focus_areas']) }}</span><h1>{{ $definition['display_name'] }}</h1><p>{{ $definition['headline'] }}</p></section>
<section class="public-card glass public-section public-mentor-content" data-public-mentor-profile><h2>درباره مربی</h2><p class="public-mentor-bio">{{ $definition['bio'] }}</p>@if($definition['city'])<p>شهر معرفی‌شده: {{ $definition['city'] }}</p>@endif<h2>زمینه‌های فعالیت</h2><ul>@foreach($definition['focus_areas'] as $area)<li>{{ $area }}</li>@endforeach</ul><p>زبان‌ها: {{ implode('، ', $definition['languages']) }}</p><a href="{{ route('consultations', ['mentor' => $profile->slug]) }}" class="btn primary">مشاهده زمان‌های مشاوره این مربی</a></section>
@endsection
