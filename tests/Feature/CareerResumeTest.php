<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CareerResume;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\ResumeCheck;
use App\Models\ResumeVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\ResumeBuilder;
use App\Services\ResumeTextReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CareerResumeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->owner = $this->user('student');
        $this->admin = $this->user('admin');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function resume(array $changes = []): CareerResume
    {
        $resume = app(ResumeBuilder::class)->create($this->owner, 'درخواست شغل خصوصی');
        if ($changes) {
            app(ResumeBuilder::class)->save($this->owner, $resume, 1, $resume->title, [...$resume->draft, ...$changes]);
        }

        return $resume->fresh();
    }

    private function snapshot(CareerResume $resume): ResumeVersion
    {
        return app(ResumeBuilder::class)->snapshot($this->owner, $resume, $resume->fresh()->revision);
    }

    private function path(CareerResume $resume, ?ResumeVersion $version = null): string
    {
        return '/career/resumes/'.$resume->id.($version ? '/versions/'.$version->id : '');
    }

    public function test_all_four_roles_can_create_only_their_own_resume(): void
    {
        foreach (['student', 'coach', 'org_admin', 'admin'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->post('/career/resumes', ['title' => 'رزومه من', 'user_id' => $this->owner->id])->assertRedirect();
            $this->assertDatabaseHas('career_resumes', ['user_id' => $user->id]);
            $this->get('/career/resumes')->assertInertia(fn (Assert $page) => $page->has('resumes.data', 1));
        }
        $this->assertSame(0, CareerResume::where('user_id', $this->owner->id)->count());
    }

    public function test_peers_current_coach_and_organization_manager_have_no_implicit_resume_access(): void
    {
        $resume = $this->resume();
        $version = $this->snapshot($resume);
        $coach = $this->user('coach');
        $manager = $this->user('org_admin');
        $organization = Organization::factory()->create();
        $organization->members()->attach($manager->id, ['kind' => 'manager']);
        $organization->members()->attach($this->owner->id, ['kind' => 'member']);
        $enrollment = Enrollment::factory()->create(['user_id' => $this->owner->id]);
        $enrollment->course->update(['coach_id' => $coach->id]);
        $check = app(ResumeTextReview::class)->check($this->owner, $version, 'Private employer Laravel');
        foreach ([$coach, $manager, $this->user('student')] as $outsider) {
            $this->actingAs($outsider)->get($this->path($resume))->assertForbidden();
            $this->get($this->path($resume, $version))->assertForbidden();
            $this->get($this->path($resume, $version).'/print')->assertForbidden();
            $this->get('/career/checks/'.$check->id)->assertForbidden();
            $this->post($this->path($resume, $version).'/checks', ['job_text' => 'Attempt'])->assertForbidden();
            $this->patch($this->path($resume), ['revision' => 2, 'title' => 'Alter', 'definition' => $resume->draft])->assertForbidden();
            $this->get('/career/resumes?view=admin')->assertForbidden();
        }
    }

    public function test_admin_has_scoped_full_resume_read_edit_snapshot_and_review_access(): void
    {
        $resume = $this->resume();
        $this->actingAs($this->admin)->get('/career/resumes?view=admin')->assertInertia(fn (Assert $page) => $page->has('resumes.data', 1)->where('resumes.data.0.owner_name', $this->owner->name));
        $this->get($this->path($resume))->assertOk();
        $this->patch($this->path($resume), ['revision' => 1, 'title' => 'کمک ویرایشی', 'definition' => [...$resume->draft, 'headline' => 'Developer']])->assertRedirect();
        $this->post($this->path($resume).'/snapshots', ['revision' => 2])->assertRedirect();
        $version = ResumeVersion::firstOrFail();
        $this->get($this->path($resume, $version).'/print')->assertOk();
        $this->post($this->path($resume, $version).'/checks', ['job_text' => 'Developer'])->assertRedirect();
        $this->assertSame($this->admin->id, $version->created_by);
    }

    public function test_personal_resume_fields_snapshots_job_and_reports_are_encrypted_at_rest(): void
    {
        $resume = $this->resume(['name' => 'Private Resume Person', 'summary' => 'Unique private career history']);
        $version = $this->snapshot($resume);
        $check = app(ResumeTextReview::class)->check($this->owner, $version, 'Private job description');
        $this->assertStringNotContainsString('درخواست شغل', DB::table('career_resumes')->value('title'));
        $this->assertStringNotContainsString('Private Resume Person', DB::table('career_resumes')->value('draft'));
        $this->assertStringNotContainsString('Unique private', DB::table('resume_versions')->value('definition'));
        $this->assertStringNotContainsString('Private job', DB::table('resume_checks')->value('job_text'));
        $this->assertStringNotContainsString('matched_terms', DB::table('resume_checks')->value('report'));
        $this->assertSame('Private Resume Person', $version->definition['name']);
        $this->assertSame('Private job description', $check->job_text);
        foreach ([[$resume, 'draft'], [$version, 'definition'], [$check, 'report']] as [$model, $key]) {
            $this->assertArrayNotHasKey($key, $model->toArray());
        }
    }

    public function test_saved_draft_and_new_snapshot_never_rewrite_previous_version(): void
    {
        $resume = $this->resume(['summary' => 'First immutable summary']);
        $first = $this->snapshot($resume);
        app(ResumeBuilder::class)->save($this->owner, $resume, 3, $resume->title, [...$resume->draft, 'summary' => 'Second summary']);
        $this->actingAs($this->owner)->get($this->path($resume, $first).'/print')->assertSee('First immutable summary')->assertDontSee('Second summary');
        $second = $this->snapshot($resume);
        $this->assertSame(2, $second->number);
        $this->assertSame('First immutable summary', $first->fresh()->definition['summary']);
        $this->assertSame('Second summary', $second->definition['summary']);
    }

    public function test_reopening_same_saved_snapshot_returns_existing_version_without_duplicate_history(): void
    {
        $resume = $this->resume();
        $version = $this->snapshot($resume);
        $again = app(ResumeBuilder::class)->snapshot($this->owner, $resume, 2);
        $this->assertSame($version->id, $again->id);
        $this->assertSame(1, ResumeVersion::count());
        $this->assertSame(2, $resume->fresh()->revision);
        $this->assertSame(1, AuditEvent::where('action', 'resume.snapshot_created')->count());
    }

    public function test_stale_draft_snapshot_and_archive_forms_do_not_overwrite_current_state(): void
    {
        $resume = $this->resume(['headline' => 'Current headline']);
        $this->actingAs($this->owner)->patch($this->path($resume), ['revision' => 1, 'title' => 'Old', 'definition' => $resume->draft])->assertSessionHasErrors('resume');
        $this->post($this->path($resume).'/snapshots', ['revision' => 1])->assertSessionHasErrors('resume');
        $this->post($this->path($resume).'/archive', ['revision' => 1, 'archived' => true])->assertSessionHasErrors('resume');
        $this->assertSame('Current headline', $resume->fresh()->draft['headline']);
        $this->assertFalse($resume->fresh()->archived);
        $this->assertSame(0, ResumeVersion::count());
    }

    public function test_archive_preserves_print_and_reports_but_blocks_new_draft_and_snapshot_until_restore(): void
    {
        $resume = $this->resume();
        $version = $this->snapshot($resume);
        $this->actingAs($this->owner)->post($this->path($resume).'/archive', ['revision' => 2, 'archived' => true])->assertRedirect();
        $this->patch($this->path($resume), ['revision' => 3, 'title' => $resume->title, 'definition' => $resume->draft])->assertSessionHasErrors('resume');
        $this->post($this->path($resume).'/snapshots', ['revision' => 3])->assertSessionHasErrors('resume');
        $this->get($this->path($resume, $version).'/print')->assertOk();
        $this->get('/career/resumes')->assertInertia(fn (Assert $page) => $page->has('resumes.data', 0));
        $this->get('/career/resumes?state=archived')->assertInertia(fn (Assert $page) => $page->has('resumes.data', 1));
        $this->post($this->path($resume).'/archive', ['revision' => 3, 'archived' => false])->assertRedirect();
        $this->patch($this->path($resume), ['revision' => 4, 'title' => $resume->title, 'definition' => $resume->draft])->assertRedirect();
    }

    public function test_print_and_version_routes_reject_mismatched_resume_version_even_for_admin(): void
    {
        $a = $this->resume();
        $b = $this->resume();
        $version = $this->snapshot($b);
        foreach ([$this->owner, $this->admin] as $actor) {
            $this->actingAs($actor)->get($this->path($a, $version))->assertNotFound();
            $this->get($this->path($a, $version).'/print')->assertNotFound();
            $this->post($this->path($a, $version).'/checks', ['job_text' => 'Mismatch'])->assertNotFound();
        }
        $this->assertSame(0, ResumeCheck::count());
    }

    public function test_print_escapes_text_and_includes_only_explicit_private_snapshot_fields(): void
    {
        $resume = $this->resume(['name' => '<script>window.resumeUnsafe=1</script>', 'summary' => 'My summary', 'email' => 'resume@example.test', 'links' => [['label' => 'Portfolio', 'url' => 'https://example.test/profile']]]);
        $version = $this->snapshot($resume);
        $this->actingAs($this->owner)->get($this->path($resume, $version).'/print')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>window.resumeUnsafe', false)->assertSee('resume@example.test')->assertDontSee($this->owner->phone)->assertDontSee('درخواست شغل خصوصی')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_english_and_persian_templates_preserve_authored_data_and_direction(): void
    {
        $resume = $this->resume(['locale' => 'en', 'name' => 'Person Name', 'education' => [['institution' => 'University', 'degree' => 'Computer Science', 'period' => '2019–2023']]]);
        $version = $this->snapshot($resume);
        $this->actingAs($this->owner)->get($this->path($resume, $version).'/print')->assertSee('dir="ltr"', false)->assertSee('Education')->assertSee('Computer Science')->assertSee('2019–2023');
        $fa = $this->resume(['education' => [['institution' => 'دانشگاه', 'degree' => 'مهندسی', 'period' => '۱۳۹۸ تا ۱۴۰۲']]]);
        $faVersion = $this->snapshot($fa);
        $this->get($this->path($fa, $faVersion).'/print')->assertSee('dir="rtl"', false)->assertSee('۱۳۹۸ تا ۱۴۰۲');
    }

    public function test_unsafe_links_unknown_entry_fields_and_invalid_shapes_are_rejected(): void
    {
        $resume = $this->resume();
        $this->actingAs($this->owner);
        foreach ([['links' => [['label' => 'Unsafe', 'url' => 'javascript:alert(1)']]], ['links' => [['label' => 'Unsafe', 'url' => 'file:///etc/passwd']]], ['experience' => [['employer' => 'Company', 'title' => 'Role', 'is_verified' => true]]], ['skills' => ['key' => 'not-a-list']], ['locale' => 'de'], ['email' => 'invalid'], ['experience' => [['employer' => '', 'title' => 'Role']]]] as $bad) {
            $this->patch($this->path($resume), ['revision' => 1, 'title' => 'CV', 'definition' => [...$resume->draft, ...$bad]])->assertSessionHasErrors();
        }
        $this->assertSame(1, $resume->fresh()->revision);
    }

    public function test_skill_duplicates_whitespace_and_optional_entry_fields_are_canonicalized(): void
    {
        $resume = $this->resume(['skills' => [' SQL ', 'SQL'], 'projects' => [['title' => ' Project ']], 'experience' => [['employer' => ' Company ', 'title' => ' Developer ']]]);
        $this->assertSame(['SQL'], $resume->draft['skills']);
        $this->assertSame(['title' => 'Project', 'description' => ''], $resume->draft['projects'][0]);
        $this->assertSame('', $resume->draft['experience'][0]['period']);
        $this->assertSame('Company', $resume->draft['experience'][0]['employer']);
    }

    public function test_oversized_lists_and_text_do_not_save_partial_draft(): void
    {
        $resume = $this->resume();
        $this->actingAs($this->owner);
        foreach ([['skills' => array_fill(0, 51, 'Skill')], ['summary' => str_repeat('x', 3001)], ['links' => array_fill(0, 11, ['label' => 'Link', 'url' => 'https://example.test'])]] as $bad) {
            $this->patch($this->path($resume), ['revision' => 1, 'title' => 'CV', 'definition' => [...$resume->draft, ...$bad]])->assertSessionHasErrors();
        }
        $this->assertSame(1, $resume->fresh()->revision);
    }

    public function test_creation_limit_counts_private_and_archived_resumes_without_losing_existing_records(): void
    {
        CareerResume::factory()->count(ResumeBuilder::MAX_RESUMES)->create(['user_id' => $this->owner->id, 'archived' => true]);
        $this->actingAs($this->owner)->post('/career/resumes', ['title' => 'One too many'])->assertSessionHasErrors('resume');
        $this->assertSame(20, CareerResume::count());
    }

    public function test_own_and_admin_pagination_and_phone_filter_do_not_mix_accounts(): void
    {
        CareerResume::factory()->count(16)->create(['user_id' => $this->owner->id]);
        CareerResume::factory()->create();
        $this->actingAs($this->owner)->get('/career/resumes?page=2')->assertInertia(fn (Assert $page) => $page->where('resumes.total', 16)->has('resumes.data', 1));
        $this->actingAs($this->admin)->get('/career/resumes?view=admin&phone='.$this->owner->phone)->assertInertia(fn (Assert $page) => $page->where('resumes.total', 16));
    }

    public function test_unverified_and_inactive_accounts_cannot_create_or_access_private_resumes(): void
    {
        $resume = $this->resume();
        $this->owner->update(['phone_verified_at' => null]);
        $this->actingAs($this->owner)->post('/career/resumes', ['title' => 'Unverified'])->assertForbidden();
        $this->owner->forceFill(['is_active' => false])->save();
        $this->actingAs($this->owner)->get($this->path($resume))->assertRedirect('/login');
        $this->assertSame(1, CareerResume::count());
    }

    public function test_structure_review_reports_presence_without_fabricating_an_ats_score(): void
    {
        $resume = $this->resume(['headline' => 'Developer', 'email' => 'person@example.test', 'skills' => ['SQL']]);
        $version = $this->snapshot($resume);
        $check = app(ResumeTextReview::class)->check($this->owner, $version, '');
        $checks = array_column($check->report['checks'], 'present', 'key');
        $this->assertTrue($checks['name']);
        $this->assertTrue($checks['contact']);
        $this->assertTrue($checks['skills']);
        $this->assertFalse($checks['education']);
        $this->assertFalse($check->report['job_provided']);
        $this->assertSame([], $check->report['extracted_terms']);
        $this->assertArrayNotHasKey('score', $check->report);
        $this->assertSame(ResumeTextReview::METHOD, $check->method);
        Http::assertNothingSent();
    }

    public function test_keyword_review_normalizes_persian_and_english_and_matches_whole_technical_terms(): void
    {
        $resume = $this->resume(['skills' => ['Laravel', 'SQL', 'C++', 'C#', '.NET', 'Node.js', 'Go', 'برنامه نویسی', 'JavaScript']]);
        $version = $this->snapshot($resume);
        $check = app(ResumeTextReview::class)->check($this->owner, $version, 'LARAVEL SQL C++ C# .NET Node.js Go برنامه‌نويسي Java Python https://private.invalid/secret');
        foreach (['laravel', 'sql', 'c++', 'c#', '.net', 'node.js', 'go', 'برنامه', 'نویسی'] as $term) {
            $this->assertContains($term, $check->report['matched_terms']);
        }
        $this->assertContains('java', $check->report['missing_terms']);
        $this->assertContains('python', $check->report['missing_terms']);
        $this->assertNotContains('secret', $check->report['extracted_terms']);
        $this->assertArrayNotHasKey('score', $check->report);
    }

    public function test_same_version_and_trimmed_job_description_reuse_one_encrypted_report(): void
    {
        $resume = $this->resume(['skills' => ['Laravel']]);
        $version = $this->snapshot($resume);
        $a = app(ResumeTextReview::class)->check($this->owner, $version, ' Laravel PHP ');
        $b = app(ResumeTextReview::class)->check($this->owner, $version, 'Laravel PHP');
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, ResumeCheck::count());
        $this->assertSame(1, AuditEvent::where('action', 'resume.text_reviewed')->count());
        $this->assertSame(3, $resume->fresh()->revision);
    }

    public function test_review_is_bound_to_its_snapshot_and_newer_draft_cannot_change_the_report(): void
    {
        $resume = $this->resume(['skills' => ['Laravel']]);
        $first = $this->snapshot($resume);
        $check = app(ResumeTextReview::class)->check($this->owner, $first, 'Laravel Python');
        app(ResumeBuilder::class)->save($this->owner, $resume, 3, $resume->title, [...$resume->draft, 'skills' => ['Python']]);
        $second = $this->snapshot($resume);
        $new = app(ResumeTextReview::class)->check($this->owner, $second, 'Laravel Python');
        $this->assertNotSame($check->id, $new->id);
        $this->assertContains('laravel', $check->fresh()->report['matched_terms']);
        $this->assertContains('python', $new->report['matched_terms']);
        $this->actingAs($this->owner)->get('/career/checks/'.$check->id)->assertInertia(fn (Assert $page) => $page->where('version.id', $first->id)->where('check.method', ResumeTextReview::METHOD)->missing('check.job_hash'));
    }

    public function test_oversized_job_description_does_not_create_a_check_or_audit(): void
    {
        $resume = $this->resume();
        $version = $this->snapshot($resume);
        $this->actingAs($this->owner)->post($this->path($resume, $version).'/checks', ['job_text' => str_repeat('x', 12001)])->assertSessionHasErrors('job_text');
        $this->assertSame(0, ResumeCheck::count());
        $this->assertSame(0, AuditEvent::where('action', 'resume.text_reviewed')->count());
    }

    public function test_keyword_extraction_is_bounded_and_generic_words_do_not_inflate_coverage(): void
    {
        $resume = $this->resume(['skills' => ['Laravel']]);
        $version = $this->snapshot($resume);
        $words = array_map(fn ($index) => 'keyword'.$index, range(1, 100));
        $check = app(ResumeTextReview::class)->check($this->owner, $version, 'the and for with برای و یک '.implode(' ', $words));
        $this->assertCount(40, $check->report['extracted_terms']);
        $this->assertNotContains('برای', $check->report['extracted_terms']);
        $this->assertNotContains('the', $check->report['extracted_terms']);
        $this->assertSame([], $check->report['matched_terms']);
    }
}
