@extends('public.layout')
@section('content')
<section class="public-heading"><span class="public-eyebrow">یادگیری هدفمند</span><h1>دوره‌های کاردرست</h1><p class="public-lead">دوره مناسب مسیر خود را بررسی کنید؛ جزئیات، نوبت و ظرفیت را پیش از ثبت‌نام ببینید.</p></section>
<form method="get" class="public-filter glass"><label>جست‌وجوی دوره<input name="q" value="{{ $query }}" maxlength="100" placeholder="نام دوره"></label><label>مرحله مسیر<select name="stage"><option value="">همه مرحله‌ها</option><option value="1" @selected((string)$stage === '1')>خودشناسی شغلی</option><option value="2" @selected((string)$stage === '2')>تصمیم‌گیری شغلی</option><option value="3" @selected((string)$stage === '3')>بازنگری شغلی</option></select></label><button class="btn primary">نمایش دوره‌ها</button></form>
@if($courses->isEmpty())<p class="public-empty">دوره‌ای با این شرایط منتشر نشده است.</p>@else<div class="public-grid">@foreach($courses as $course)@include('public.course-card')@endforeach</div>@endif
@include('public.pagination', ['paginator' => $courses])
@endsection
