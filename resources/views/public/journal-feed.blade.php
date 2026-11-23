<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
<title>مجله کاردرست</title>
<link>{{ $base.route('public.journal', absolute: false) }}</link>
<description>مقاله‌های منتشرشدهٔ کاردرست درباره رشد و مسیر حرفه‌ای</description>
<language>fa-IR</language>
<atom:link href="{{ $base.route('public.journal-feed', absolute: false) }}" rel="self" type="application/rss+xml" />
@if($entries)<lastBuildDate>{{ $entries[0]['published_at'] }}</lastBuildDate>@endif
@foreach($entries as $entry)
<item>
<title>{{ $entry['title'] }}</title>
<link>{{ $entry['url'] }}</link>
<guid isPermaLink="true">{{ $entry['url'] }}</guid>
<description>{{ $entry['description_html'] }}</description>
<pubDate>{{ $entry['published_at'] }}</pubDate>
</item>
@endforeach
</channel>
</rss>
