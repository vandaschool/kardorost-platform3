<?php

namespace App\Http\Controllers;

use App\Models\ContentPage;
use App\Models\Course;
use App\Services\CoachProfileLinks;
use App\Services\CoachProfilePhotos;
use App\Services\CoachProfiles;
use App\Services\CohortCapacity;
use App\Services\PublicMentorDirectory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class PublicSiteController extends Controller
{
    private function visible(): Builder
    {
        return ContentPage::where('available', true)->where('current_version', '>', 0)->whereHas('currentPublication', fn ($q) => $q->where('published_at', '<=', now()))->with('currentPublication');
    }

    private function render(string $view, string $title, string $description, array $data = []): Response
    {
        return response()->view('public.'.$view, ['title' => $title, 'description' => $description, 'canonical' => rtrim(config('app.url'), '/').request()->getPathInfo(), 'footerPages' => $this->visible()->where('kind', 'page')->orderBy('id')->limit(8)->get(), ...$data])->withHeaders(['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'DENY']);
    }

    public function home(): Response
    {
        $home = $this->visible()->where('kind', 'home')->first()?->currentPublication->definition;
        $courses = Course::where('is_published', true)->latest('id')->limit(3)->get(['id', 'slug', 'title', 'description', 'stage', 'price']);
        $articles = $this->visible()->where('kind', 'article')->latest('updated_at')->limit(3)->get();

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
        $data = $request->validate(['q' => 'nullable|string|max:100']);
        $query = trim($data['q'] ?? '');
        $articles = $this->visible()->where('kind', 'article')->when($query !== '', fn ($q) => $q->whereHas('currentPublication', fn ($publication) => $publication->where('definition->title', 'like', '%'.$query.'%')))->latest('updated_at')->paginate(12)->withQueryString();

        return $this->render('journal', 'مجله کاردرست', 'یادداشت‌ها و مقاله‌های منتشرشده درباره رشد و مسیر حرفه‌ای.', compact('articles', 'query'));
    }

    private function content(string $kind, string $slug): Response
    {
        $page = $this->visible()->where('kind', $kind)->where('slug', $slug)->firstOrFail();
        $definition = $page->currentPublication->definition;

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
        foreach ($this->visible()->without('currentPublication')->orderBy('id')->cursor(['id', 'kind', 'slug']) as $page) {
            $paths[] = $page->publicPath();
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
