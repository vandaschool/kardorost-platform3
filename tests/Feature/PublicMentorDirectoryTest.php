<?php

namespace Tests\Feature;

use App\Models\CoachProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\CoachProfiles;
use App\Services\PublicMentorDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PublicMentorDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('key', 'admin')->value('id'));
    }

    private function published(array $changes = []): CoachProfile
    {
        $profile = CoachProfile::factory()->create();
        $profile->update(['draft' => [...$profile->draft, 'city' => 'تهران', 'focus_areas' => ['توسعه فردی'], ...$changes]]);
        app(CoachProfiles::class)->submit($profile->owner, $profile, 1, true);
        app(CoachProfiles::class)->review($this->admin, $profile, 2, 'publish', null);

        return $profile->fresh();
    }

    public function test_search_uses_only_current_reviewed_public_copy_and_not_private_draft_or_account(): void
    {
        $profile = $this->published(['display_name' => 'Public Name', 'headline' => 'CURRENT-TOPIC']);
        app(CoachProfiles::class)->save($profile->owner, [...$profile->draft, 'headline' => 'PRIVATE-DRAFT-TOPIC'], $profile->revision);
        $profile->owner->update(['name' => 'PRIVATE-ACCOUNT-NAME']);
        $this->get('/mentors?q=CURRENT-TOPIC')->assertOk()->assertSee('Public Name')->assertDontSee('PRIVATE-DRAFT-TOPIC')->assertDontSee('PRIVATE-ACCOUNT-NAME');
        foreach (['PRIVATE-DRAFT-TOPIC', 'PRIVATE-ACCOUNT-NAME'] as $query) {
            $this->assertSame(0, app(PublicMentorDirectory::class)->search(['q' => $query])['profiles']->total());
        }
    }

    public function test_persian_arabic_letters_diacritics_whitespace_and_joiners_match(): void
    {
        $this->published(['display_name' => 'كيان مهارت', 'headline' => 'مربی توسعه‌فردی', 'city' => 'اراک', 'focus_areas' => ['کوچینگ فردی']]);
        $result = app(PublicMentorDirectory::class)->search(['q' => '  کِیان توسعه فردی  ', 'city' => 'اراك', 'focus' => 'كوچينگ فردي', 'language' => 'فارسي']);
        $this->assertSame(1, $result['profiles']->total());
        $this->assertSame('اراک', $result['filters']['city']);
        $this->assertSame('کوچینگ فردی', $result['filters']['focus']);
        $this->assertSame('فارسی', $result['filters']['language']);
        $this->get('/mentors?'.http_build_query(['city' => 'اراك']))->assertSee('value="اراک" selected', false);
    }

    public function test_all_query_terms_and_all_exact_filters_are_required(): void
    {
        $match = $this->published(['display_name' => 'مربی مسیر حرفه‌ای', 'city' => 'شیراز', 'languages' => ['فارسی', 'English'], 'focus_areas' => ['رهبری تیم']]);
        $this->published(['display_name' => 'مربی مسیر دیگر', 'city' => 'تهران', 'languages' => ['فارسی'], 'focus_areas' => ['رهبری تیم']]);
        $result = app(PublicMentorDirectory::class)->search(['q' => 'مسیر حرفه', 'city' => 'شیراز', 'focus' => 'رهبری تیم', 'language' => 'english']);
        $this->assertSame([$match->id], $result['profiles']->getCollection()->pluck('id')->all());
        foreach ([['q' => 'مسیر ناموجود'], ['city' => 'شیرا'], ['focus' => 'رهبری'], ['language' => 'French']] as $input) {
            $this->assertSame(0, app(PublicMentorDirectory::class)->search($input)['profiles']->total());
        }
    }

    public function test_percent_underscore_and_sql_like_input_are_literal_not_wildcards(): void
    {
        $this->published();
        foreach (['%', '_', "' OR 1=1"] as $query) {
            $this->assertSame(0, app(PublicMentorDirectory::class)->search(['q' => $query])['profiles']->total());
        }
    }

    public function test_hidden_draft_ineligible_and_withdrawn_profiles_do_not_contribute_search_results_or_facets(): void
    {
        $profiles = collect(range(1, 5))->map(fn ($index) => $this->published(['city' => 'PRIVATE-CITY-'.$index, 'focus_areas' => ['PRIVATE-FOCUS-'.$index]]));
        $profiles[0]->update(['public_enabled' => false]);
        User::whereKey($profiles[1]->user_id)->update(['is_active' => false]);
        $profiles[2]->owner->roles()->detach();
        $profiles[3]->owner->update(['phone_verified_at' => null]);
        app(CoachProfiles::class)->withdraw($profiles[4]->owner, $profiles[4], $profiles[4]->revision, null);
        CoachProfile::factory()->create();
        $result = app(PublicMentorDirectory::class)->search([]);
        $this->assertFalse($result['has_profiles']);
        $this->assertSame(['city' => [], 'focus' => [], 'language' => []], $result['facets']);
        $this->get('/mentors')->assertOk()->assertDontSee('PRIVATE-CITY')->assertDontSee('PRIVATE-FOCUS')->assertSee('هنوز پروفایلی');
    }

    public function test_old_publication_terms_disappear_only_after_fresh_reviewed_publication(): void
    {
        $profile = $this->published(['headline' => 'OLD-PUBLISHED-TOPIC']);
        $service = app(CoachProfiles::class);
        $service->save($profile->owner, [...$profile->draft, 'headline' => 'NEW-PUBLISHED-TOPIC'], $profile->revision);
        $profile = $profile->fresh();
        $this->assertSame(1, app(PublicMentorDirectory::class)->search(['q' => 'OLD-PUBLISHED-TOPIC'])['profiles']->total());
        $service->submit($profile->owner, $profile, $profile->revision, true);
        $profile = $profile->fresh();
        $service->review($this->admin, $profile, $profile->revision, 'publish', null);
        $this->assertSame(0, app(PublicMentorDirectory::class)->search(['q' => 'OLD-PUBLISHED-TOPIC'])['profiles']->total());
        $this->assertSame(1, app(PublicMentorDirectory::class)->search(['q' => 'NEW-PUBLISHED-TOPIC'])['profiles']->total());
    }

    public function test_search_pagination_is_stable_latest_publication_first_and_preserves_filters(): void
    {
        $profiles = collect(range(1, 14))->map(fn ($index) => $this->published(['display_name' => 'مربی صفحه‌بندی '.$index]));
        $old = $profiles->first();
        $old->update(['public_published_at' => now()->addMinute()]);
        $first = app(PublicMentorDirectory::class)->search(['q' => 'صفحه‌بندی', 'city' => 'تهران', 'page' => 1]);
        $second = app(PublicMentorDirectory::class)->search(['q' => 'صفحه‌بندی', 'city' => 'تهران', 'page' => 2]);
        $this->assertSame(14, $first['profiles']->total());
        $this->assertCount(12, $first['profiles']);
        $this->assertCount(2, $second['profiles']);
        $this->assertSame($old->id, $first['profiles'][0]->id);
        $this->assertEmpty(array_intersect($first['profiles']->pluck('id')->all(), $second['profiles']->pluck('id')->all()));
        parse_str(parse_url($first['profiles']->nextPageUrl(), PHP_URL_QUERY), $query);
        $this->assertSame(['q' => 'صفحه‌بندی', 'city' => 'تهران', 'page' => '2'], $query);
    }

    public function test_query_output_is_escaped_private_noindex_and_canonical_does_not_include_filters(): void
    {
        $this->published();
        $response = $this->get('/mentors?'.http_build_query(['q' => '<script>alert(1)</script>']));
        $response->assertOk()->assertHeader('X-Robots-Tag', 'noindex, follow')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertSee('content="noindex, follow"', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertSee('rel="canonical" href="'.rtrim(config('app.url'), '/').'/mentors"', false);
        $this->get('/mentors')->assertOk()->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_invalid_filters_and_pages_are_rejected_and_blank_search_is_not_filtered(): void
    {
        foreach (['q[]=bad', 'city[]=bad', 'focus='.str_repeat('x', 61), 'q='.str_repeat('x', 121), 'page=0', 'page=100001', 'language='.str_repeat('x', 41)] as $query) {
            $this->getJson('/mentors?'.$query)->assertUnprocessable();
        }
        $result = app(PublicMentorDirectory::class)->search(['q' => " \u{200C}  "]);
        $this->assertFalse($result['filtered']);
        $this->assertSame('', $result['filters']['q']);
    }

    public function test_invalid_utf8_does_not_enter_public_template_or_search_normalization(): void
    {
        $this->expectException(ValidationException::class);
        app(PublicMentorDirectory::class)->search(['q' => "\xC3\x28"]);
    }
}
