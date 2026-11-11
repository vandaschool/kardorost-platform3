<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#0f1b3d"><meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
<script>try{const t=localStorage.getItem('kd-theme');document.documentElement.dataset.theme=t||((window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':'light')}catch(e){}</script>
@vite('resources/js/app.js')
@inertiaHead
</head><body>@inertia</body>
</html>
