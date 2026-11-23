@extends('public.layout')
@section('content')
<section class="public-section public-mentor-content"><span class="public-kicker">آشنایی پیش از گفت‌وگو</span><h1>مربیان کاردرست</h1><p>زمینه‌های فعالیت و معرفی منتشرشده مربیان را بخوانید.</p></section>
<form method="get" action="{{ route('public.mentors') }}" class="public-filter public-mentor-filter glass" data-mentor-search>
<label>نام یا زمینه فعالیت<input name="q" value="{{ $filters['q'] }}" maxlength="120" placeholder="نام، تخصص یا موضوع گفت‌وگو"></label>
@foreach(['focus'=>'زمینه فعالیت','city'=>'شهر','language'=>'زبان'] as $key=>$label)
<label>{{ $label }}<select name="{{ $key }}"><option value="">همه</option>@foreach($facets[$key] as $value)<option value="{{ $value }}" @selected($filters[$key] === $value)>{{ $value }}</option>@endforeach</select></label>
@endforeach
<button class="btn primary">نمایش مربیان</button>@if($filtered)<a class="btn secondary" href="{{ route('public.mentors') }}">پاک‌کردن فیلترها</a>@endif
</form>
@if($has_profiles)<p class="public-mentor-content" role="status" data-mentor-result-count>{{ \App\Services\PublicFormatting::number($profiles->total()) }} مربی با این شرایط</p>@endif
@if($profiles->isEmpty())<section class="public-card glass public-mentor-content"><p>{{ !$has_profiles ? 'هنوز پروفایلی برای نمایش منتشر نشده است.' : ($profiles->total() ? 'این صفحه نتیجه‌ای ندارد؛ به صفحه اول برگردید.' : 'مربی‌ای با این شرایط پیدا نشد؛ نام یا فیلترها را تغییر دهید.') }}</p>@if($profiles->total())<a class="btn secondary" href="{{ $profiles->url(1) }}">صفحه اول</a>@endif</section>@endif
<section class="public-grid public-section">@foreach($profiles as $profile)@php($definition=$profile->currentPublication->definition)
@php($photo=app(\App\Services\CoachProfilePhotos::class)->publicImage($profile))
<article class="public-card glass public-mentor-content" data-public-mentor>@if($photo)<img class="public-coach-photo" data-public-coach-photo src="{{ $photo['url'] }}" width="{{ $photo['width'] }}" height="{{ $photo['height'] }}" alt="" loading="lazy" decoding="async">@endif<span class="public-kicker">{{ implode(' · ', $definition['focus_areas']) }}</span><h2><a href="{{ route('public.mentor',$profile->slug) }}">{{ $definition['display_name'] }}</a></h2><p>{{ $definition['headline'] }}</p><a class="btn secondary" href="{{ route('public.mentor',$profile->slug) }}">آشنایی با مربی ←</a></article>@endforeach</section>
@include('public.pagination',['paginator'=>$profiles])
@endsection
