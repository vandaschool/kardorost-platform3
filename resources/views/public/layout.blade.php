<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ $title }} | کاردرست</title><meta name="description" content="{{ $description }}">
@if($noindex ?? false)<meta name="robots" content="noindex, follow">@endif
<link rel="canonical" href="{{ $canonical }}"><meta name="theme-color" content="#0f1b3d">
<meta property="og:title" content="{{ $title }} | کاردرست"><meta property="og:description" content="{{ $description }}"><meta property="og:url" content="{{ $canonical }}"><meta property="og:type" content="{{ ($page->kind ?? '') === 'article' ? 'article' : 'website' }}"><meta property="og:locale" content="fa_IR">
<link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
<script>try{const t=localStorage.getItem('kd-theme');document.documentElement.dataset.theme=t||((window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light')}catch(e){}</script>
@vite('resources/js/public.js')
</head>
<body class="public-site"><a class="skip-link" href="#main-content">رفتن به محتوای اصلی</a>
<header class="public-wrap public-header glass">
<a href="{{ route('home') }}" aria-label="کاردرست، صفحه اصلی"><img class="public-brand logo-light" src="/brand/logo.svg" width="154" height="42" alt="کاردرست"><img class="public-brand logo-dark" src="/brand/logo-reverse.svg" width="154" height="42" alt="کاردرست"></a>
<button type="button" class="public-menu-toggle" data-menu-toggle aria-expanded="false" aria-controls="public-menu" aria-label="فهرست سایت">☰</button>
<nav id="public-menu" class="public-nav" data-public-menu aria-label="فهرست اصلی"><a href="/catalog">دوره‌ها</a><a href="/#path">مسیر کاردرست</a><a href="/mentors">مربیان</a><a href="/journal">مجله</a><a href="/#organizations">سازمان‌ها</a></nav>
<div class="public-tools"><button class="public-theme" type="button" data-theme-toggle aria-label="تغییر پوسته روشن و تیره">◐</button><a class="btn primary" href="{{ auth()->check() ? route('panel') : route('login') }}">{{ auth()->check() ? 'پنل من' : 'ورود / ثبت‌نام' }}</a></div>
</header>
<main id="main-content" class="public-wrap">@yield('content')</main>
<footer class="public-footer"><div class="public-wrap public-footer-grid"><div><a href="/" aria-label="کاردرست"><img class="public-brand logo-light" src="/brand/logo.svg" width="154" height="42" alt="کاردرست"><img class="public-brand logo-dark" src="/brand/logo-reverse.svg" width="154" height="42" alt="کاردرست"></a><p>همراه شما در شناخت و ساختن مسیر حرفه‌ای</p></div><nav aria-label="پیوندهای پایانی"><a href="/catalog">دوره‌ها</a><a href="/mentors">مربیان</a><a href="/journal">مجله</a><a href="{{ auth()->check() ? '/support' : '/contact' }}">ارتباط و پشتیبانی</a>@foreach($footerPages as $footerPage)<a href="{{ $footerPage->publicPath() }}">{{ $footerPage->currentPublication->definition['title'] }}</a>@endforeach</nav></div></footer>
</body></html>
