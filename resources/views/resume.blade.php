@php
$english = $definition['locale'] === 'en';
$labels = $english ? ['summary'=>'Profile', 'experience'=>'Experience', 'education'=>'Education', 'projects'=>'Projects', 'skills'=>'Skills', 'links'=>'Links'] : ['summary'=>'درباره من', 'experience'=>'تجربه کاری', 'education'=>'تحصیلات', 'projects'=>'پروژه‌ها', 'skills'=>'مهارت‌ها', 'links'=>'پیوندها'];
@endphp
<!doctype html><html lang="{{ $definition['locale'] }}" dir="{{ $english ? 'ltr' : 'rtl' }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>{{ $definition['name'] }} — {{ $english ? 'Resume' : 'رزومه' }}</title>@vite('resources/js/public.js')
<style>
@page { size:A4; margin:16mm 17mm; }
body { background:#eef0f3; color:#172033; margin:0; }
.resume-tools { padding:18px; display:flex; justify-content:center; flex-wrap:wrap; gap:15px; overflow-wrap:anywhere; }
.resume-paper { background:white; width:min(210mm,calc(100% - 32px)); padding:16mm 17mm; margin:0 auto 40px; color:#172033; font-family:Vazirmatn,Arial,sans-serif; font-size:11pt; line-height:1.8; overflow-wrap:anywhere; }
.resume-paper h1, .resume-paper h2, .resume-paper h3 { font-family:inherit; font-weight:700; }
[lang="en"] .resume-paper { font-family:Arial,"Liberation Sans",sans-serif; }
.resume-paper h1 { font-size:25pt; margin:0 0 3mm; line-height:1.4; }
.resume-paper h2 { font-size:13pt; margin:6mm 0 2mm; border-bottom:1px solid #c8ced9; padding-bottom:1mm; break-after:avoid; }
.resume-paper h3 { font-size:11pt; margin:3mm 0 1mm; break-after:avoid; }
.resume-paper p { white-space:pre-wrap; margin:0 0 2mm; }
.resume-paper .resume-contact { display:flex; flex-wrap:wrap; gap:4mm; margin-block:3mm; font-size:10pt; }
.resume-paper a { color:#172033; text-decoration:underline; }
.resume-paper ul { margin:0; padding-inline-start:5mm; }
.resume-paper .resume-period { color:#49556b; font-size:10pt; }
.resume-paper .resume-item { break-inside:avoid; }
@media print {
 html, body { background:white!important; color:#172033!important; }
 .resume-tools { display:none!important; }
 .resume-paper { width:auto; padding:0; margin:0; box-shadow:none; border:0; }
 a { text-decoration:none!important; }
}
</style></head><body><div class="resume-tools"><button class="btn primary" onclick="window.print()">{{ $english ? 'Print / Save PDF' : 'چاپ / ذخیره PDF' }}</button><span>{{ ($shared ?? false) ? ($english ? 'Shared resume snapshot' : 'نسخه اشتراکی رزومه') : ($english ? 'Private snapshot' : 'نسخه خصوصی ثابت') }} {{ $version }} @if($shared ?? false) · {{ $english ? 'Link expires: ' : 'مهلت لینک: ' }} <bdi>{{ $expires_at }}</bdi>@endif</span></div><main class="resume-paper">
<header><h1>{{ $definition['name'] }}</h1>@if($definition['headline'])<p>{{ $definition['headline'] }}</p>@endif<div class="resume-contact">@foreach(['email','phone','city'] as $field)@if($definition[$field])<span @if(in_array($field,['email','phone'])) dir="ltr" @endif>{{ $definition[$field] }}</span>@endif @endforeach</div></header>
@if($definition['summary'])<section><h2>{{ $labels['summary'] }}</h2><p>{{ $definition['summary'] }}</p></section>@endif
@foreach(['experience','education','projects'] as $section)@if(count($definition[$section]))<section><h2>{{ $labels[$section] }}</h2>@foreach($definition[$section] as $entry)<article class="resume-item"><h3>{{ $entry['title'] ?? $entry['degree'] ?? '' }}@if($entry['employer'] ?? $entry['institution'] ?? '') · {{ $entry['employer'] ?? $entry['institution'] }}@endif</h3>@if($entry['period'] ?? '')<p class="resume-period">{{ $entry['period'] }}</p>@endif @if($entry['description'] ?? '')<p>{{ $entry['description'] }}</p>@endif</article>@endforeach</section>@endif @endforeach
@if(count($definition['skills']))<section><h2>{{ $labels['skills'] }}</h2><p>{{ implode(' · ', $definition['skills']) }}</p></section>@endif
@if(count($definition['links']))<section><h2>{{ $labels['links'] }}</h2><ul>@foreach($definition['links'] as $link)<li><a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer">{{ $link['label'] }}</a>: <span dir="ltr">{{ $link['url'] }}</span></li>@endforeach</ul></section>@endif
</main></body></html>
