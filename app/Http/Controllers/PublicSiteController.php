<?php

namespace App\Http\Controllers;

use App\Models\ContentPage;
use App\Models\ContentPublication;
use App\Models\Course;
use App\Services\CoachProfileLinks;
use App\Services\CoachProfilePhotos;
use App\Services\CoachProfiles;
use App\Services\CohortCapacity;
use App\Services\ContentPublishing;
use App\Services\ContentTags;
use App\Services\PublicJournalFeed;
use App\Services\PublicMentorDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicSiteController extends Controller
{
    private function visible(): Builder
    {
        return ContentPage::where('available', true)->where('current_version', '>', 0)->whereHas('currentPublication', fn ($q) => $q->where('published_at', '<=', now())->whereColumn('content_publications.number', 'content_pages.current_version'))->with('currentPublication');
    }

    private function publishedArticles(): Builder
    {
        return $this->visible()->where('kind', 'article')->orderByDesc(
            ContentPublication::select('published_at')->whereColumn('content_page_id', 'content_pages.id')->whereColumn('number', 'content_pages.current_version')->limit(1)
        )->orderByDesc('id');
    }

    private function render(string $view, string $title, string $description, array $data = []): Response
    {
        return response()->view('public.'.$view, ['title' => $title, 'description' => $description, 'canonical' => rtrim(config('app.url'), '/').request()->getPathInfo(), 'footerPages' => $this->visible()->where('kind', 'page')->orderBy('id')->limit(8)->get()->filter(fn ($page) => app(ContentPublishing::class)->publicDefinition($page)), ...$data])->withHeaders(['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY']);
    }

    public function home(): Response
    {
        $homePage = $this->visible()->where('kind', 'home')->first();
        $home = $homePage ? app(ContentPublishing::class)->publicDefinition($homePage) : null;
        $courses = Course::where('is_published', true)->latest('id')->limit(3)->get(['id', 'slug', 'title', 'description', 'stage', 'price']);
        $articles = $this->publishedArticles()->limit(3)->get()->filter(fn ($page) => app(ContentPublishing::class)->publicDefinition($page));

        $description = ($home['meta_description'] ?? '') ?: ($home['excerpt'] ?? 'کاردرست؛ خودشناسی، تصمیم‌گیری و بازنگری در مسیر حرفه‌ای با آموزش و مشاوره.');

        return $this->render('home', $home['title'] ?? 'آموزش، ارزیابی و مشاوره شغلی', $description, compact('home', 'courses', 'articles'));
    }

    public function catalog(Request $request): Response
    {
        $data = $request->validate(['q' => 'nullable|string|max:100', 'stage' => 'nullable|integer|between:1,3']);
        $q = trim($data['q'] ?? '');
        $courses = Course::where('is_published', true)->when($q !== '', fn ($query) => $query->whereRaw("title LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%']))->when($data['stage'] ?? null, fn ($query, $stage) => $query->where('stage', $stage))->latest('id')->paginate(12, ['id', 'slug', 'title', 'description', 'stage', 'price'])->withQueryString();

        return $this->render('catalog', 'دوره‌های کاردرست', 'دوره‌های منتشرشده کاردرست برای شناخت، تصمیم‌گیری و بازنگری شغلی.', ['courses' => $courses, 'query' => $q, 'stage' => $data['stage'] ?? '']);
    }

    public function course(string $slug): Response
    {
        $course = Course::where('slug', $slug)->where('is_published', true)->firstOrFail(['id', 'slug', 'title', 'description', 'stage', 'price']);
        $cohorts = $course->cohorts()->where('registration_open', true)->orderBy('starts_at')->get()->map(fn ($cohort) => [...$cohort->only('id', 'title', 'format', 'starts_at'), 'available_seats' => app(CohortCapacity::class)->available($cohort)]);

        return $this->render('course', $course->title, mb_substr($course->description, 0, 160), compact('course', 'cohorts'));
    }

    public function contact(Request $request): Response
    {
        $data = $request->validate(['audience' => 'nullable|in:individual,company,university']);

        return $this->render('contact', 'ارتباط با کاردرست', 'ثبت درخواست آموزش، مشاوره و همکاری سازمان یا دانشگاه با کاردرست.', ['requestKey' => (string) Str::uuid(), 'audience' => $data['audience'] ?? 'individual']);
    }

    public function mentors(Request $request, PublicMentorDirectory $directory): Response
    {
        $data = $directory->search($request->only('q', 'city', 'focus', 'language', 'page'));
        $response = $this->render('mentors', 'مربیان کاردرست', 'پروفایل‌های منتشرشده مربیان کاردرست و زمینه‌های فعالیت معرفی‌شده آن‌ها.', [...$data, 'noindex' => $data['filtered']]);
        if ($data['filtered']) {
            $response->headers->set('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }

    public function mentor(string $slug, CoachProfiles $service): Response
    {
        $profile = $service->visible()->where('slug', $slug)->firstOrFail();
        $definition = $service->publishedDefinition($profile);
        abort_unless($definition, 404);
        $links = app(CoachProfileLinks::class)->display($definition['links'] ?? null);

        return $this->render('mentor', $definition['display_name'], mb_substr($definition['headline'], 0, 160), [...compact('profile', 'definition', 'links'), 'photo' => app(CoachProfilePhotos::class)->publicImage($profile)]);
    }

    public function journal(Request $request): Response
    {
        $data = $request->validate(['q' => 'nullable|string|max:100', 'tag' => 'nullable|string|max:40']);
        $query = trim($data['q'] ?? '');
        if (! mb_check_encoding($query, 'UTF-8')) {
            throw ValidationException::withMessages(['q' => 'متن جست‌وجو معتبر نیست.']);
        }
        $tag = ($data['tag'] ?? '') !== '' ? app(ContentTags::class)->filter($data['tag']) : '';
        $tags = app(ContentTags::class)->facets($this->visible()->where('kind', 'article'));
        $articles = $this->publishedArticles()->when($query !== '', fn ($q) => $q->whereHas('currentPublication', fn ($publication) => $publication->where('definition->title', 'like', '%'.$query.'%')))->when($tag !== '', fn ($q) => $q->whereHas('currentPublication', fn ($publication) => $publication->whereJsonContains('definition->tags', $tag)))->paginate(12)->appends(array_filter(['q' => $query, 'tag' => $tag], fn ($value) => $value !== ''));
        $articles->setCollection($articles->getCollection()->filter(fn ($page) => app(ContentPublishing::class)->publicDefinition($page)));

        $response = $this->render('journal', 'مجله کاردرست', 'یادداشت‌ها و مقاله‌های منتشرشده درباره رشد و مسیر حرفه‌ای.', [...compact('articles', 'query', 'tag', 'tags'), 'noindex' => $query !== '' || $tag !== '']);
        if ($query !== '' || $tag !== '') {
            $response->headers->set('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }

    public function journalFeed(PublicJournalFeed $feed): Response
    {
        return response()->view('public.journal-feed', ['entries' => $feed->entries(), 'base' => rtrim(config('app.url'), '/')])
            ->withHeaders(['Content-Type' => 'application/rss+xml; charset=UTF-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, follow']);
    }

    private function content(string $kind, string $slug): Response
    {
        $page = $this->visible()->where('kind', $kind)->where('slug', $slug)->firstOrFail();
        $definition = app(ContentPublishing::class)->publicDefinition($page);
        abort_unless($definition, 404);

        return $this->render('content', $definition['title'], $definition['meta_description'] ?: mb_substr($definition['excerpt'], 0, 160), compact('page', 'definition'));
    }

    public function article(string $slug): Response
    {
        return $this->content('article', $slug);
    }

    public function page(string $slug): Response
    {
        return $this->content('page', $slug);
    }

    public function sitemap(): Response
    {
        $paths = ['/', '/catalog', '/journal', '/mentors', '/contact'];
        foreach (app(CoachProfiles::class)->visible()->without('currentPublication')->orderBy('id')->cursor(['id', 'slug']) as $profile) {
            $paths[] = '/mentors/'.$profile->slug;
        }
        foreach ($this->visible()->select(['id', 'kind', 'slug', 'available', 'current_version'])->orderBy('id')->lazy(200) as $page) {
            if (app(ContentPublishing::class)->publicDefinition($page)) {
                $paths[] = $page->publicPath();
            }
        }
        foreach (Course::where('is_published', true)->orderBy('id')->pluck('slug') as $slug) {
            $paths[] = '/catalog/'.$slug;
        }

        return response()->view('public.sitemap', ['paths' => array_unique($paths), 'base' => rtrim(config('app.url'), '/')])->header('Content-Type', 'application/xml; charset=UTF-8')->header('Cache-Control', 'no-cache');
    }

    public function robots(): Response
    {
        return response("User-agent: *\nDisallow: /admin\nDisallow: /student\nDisallow: /coach\nDisallow: /organization\nDisallow: /support\nDisallow: /finance\nSitemap: ".rtrim(config('app.url'), '/')."/sitemap.xml\n")->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
