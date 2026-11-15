@extends('public.layout')
@section('content')
<section class="public-section"><span class="public-eyebrow">گفت‌وگو برای قدم بعد</span><h1>ارتباط با کاردرست</h1><p>درخواست آموزش، مشاوره یا همکاری مجموعه‌تان را بدون ساخت حساب ثبت کنید.</p></section>
@if(session('contact_success'))<section class="public-card glass public-section" role="status" data-contact-success>{{ session('contact_success') }}</section>@endif
<form action="{{ route('public.contact') }}" method="post" class="public-card glass public-contact-form public-section" data-public-contact>
@csrf
<input type="hidden" name="request_key" value="{{ old('request_key',$requestKey) }}">
<input type="text" name="website" value="" hidden tabindex="-1" autocomplete="off" aria-hidden="true">
<label>نام و نام خانوادگی<input name="name" value="{{ old('name') }}" autocomplete="name" minlength="3" maxlength="160" required></label>@error('name')<p class="field-error" role="alert">{{ $message }}</p>@enderror
<label>موبایل برای پیگیری<input name="phone" value="{{ old('phone') }}" type="tel" inputmode="tel" autocomplete="tel" dir="ltr" maxlength="30" required></label>@error('phone')<p class="field-error" role="alert">{{ $message }}</p>@enderror
<label>نوع درخواست‌دهنده<select name="audience" required><option value="individual" @selected(old('audience',$audience)==='individual')>فرد</option><option value="company" @selected(old('audience',$audience)==='company')>سازمان یا شرکت</option><option value="university" @selected(old('audience',$audience)==='university')>دانشگاه</option></select></label>@error('audience')<p class="field-error" role="alert">{{ $message }}</p>@enderror
<label>نام سازمان یا دانشگاه (برای درخواست مجموعه)<input name="organization_name" value="{{ old('organization_name') }}" autocomplete="organization" maxlength="200"></label>@error('organization_name')<p class="field-error" role="alert">{{ $message }}</p>@enderror
<label>موضوع<select name="subject" required><option value="training" @selected(old('subject')==='training')>آموزش و دوره</option><option value="consultation" @selected(old('subject')==='consultation')>مشاوره</option><option value="platform" @selected(old('subject')==='platform')>آشنایی و همکاری با کاردرست</option></select></label>@error('subject')<p class="field-error" role="alert">{{ $message }}</p>@enderror
<label>نیاز یا پرسش شما<textarea name="message" rows="6" minlength="20" maxlength="4000" required>{{ old('message') }}</textarea></label>@error('message')<p class="field-error" role="alert">{{ $message }}</p>@enderror
<label class="public-contact-consent"><input type="checkbox" name="consent" value="1" @checked(old('consent')) required>موافقم نام و موبایل اعلام‌شده برای پاسخ و پیگیری همین درخواست استفاده شود.</label>@error('consent')<p class="field-error" role="alert">{{ $message }}</p>@enderror
@error('request_key')<p class="field-error" role="alert">صفحه را تازه کنید و درخواست را دوباره ثبت کنید.</p>@enderror
<button class="btn primary" type="submit">ثبت درخواست ارتباط</button>
<p class="muted">اگر حساب دارید و پیگیری پرونده آموزشی خود را می‌خواهید، از <a href="{{ auth()->check() ? '/support' : '/login' }}" class="text-link">پشتیبانی داخل پنل</a> استفاده کنید.</p>
</form>
@endsection
