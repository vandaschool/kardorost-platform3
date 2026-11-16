<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CareerResume;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\ResumeVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\LinkedInGuide;
use App\Services\ResumeBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LinkedInGuideTest extends TestCase
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

    private function fixture(array $fields = [], ?User $owner = null): array
    {
        $owner ??= $this->owner;
        $builder = app(ResumeBuilder::class);
        $resume = $builder->create($owner, 'PRIVATE INTERNAL RESUME TITLE');
        $builder->save($owner, $resume, 1, $resume->title, [...$resume->draft,
            'name' => 'نام نسخه ثابت', 'headline' => 'توسعه‌دهنده PHP', 'summary' => "فقط شرح واقعیِ واردشده\nخط دوم", 'phone' => '09120000099', 'email' => 'private-contact@example.test',
            'experience' => [['title' => 'برنامه‌نویس', 'employer' => 'مجموعه آزمایشی', 'period' => '۱۴۰۰ تا ۱۴۰۲', 'description' => 'مسئولیت ثبت‌شده توسط صاحب']],
            'education' => [['degree' => 'رشته ثبت‌شده', 'institution' => 'آموزشگاه آزمایشی', 'period' => '', 'description' => '']],
            'projects' => [['title' => 'پروژه ثبت‌شده', 'description' => 'شرح بدون افزودن عدد یا ادعا']],
            'skills' => ['SQL', 'PHP'], 'links' => [['label' => 'PRIVATE LINK', 'url' => 'https://example.test/private-contact-link']], ...$fields]);
        $version = $builder->snapshot($owner, $resume, 2);

        return [$resume->fresh(), $version];
    }

    private function path(CareerResume $resume, ResumeVersion $version): string
    {
        return route('career.linkedin', ['resume' => $resume, 'version' => $version], false);
    }

    public function test_guide_arranges_only_saved_fields_preserving_unicode_order_and_characters(): void
    {
        [$resume, $version] = $this->fixture();
        $this->actingAs($this->owner)->get($this->path($resume, $version))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertInertia(fn (Assert $page) => $page
            ->component('LinkedInGuide')->where('guide.name', 'نام نسخه ثابت')->where('guide.locale', 'fa')->where('guide.method', LinkedInGuide::METHOD)
            ->has('guide.sections', 6)->where('guide.sections.0.text', 'توسعه‌دهنده PHP')->where('guide.sections.0.characters', mb_strlen('توسعه‌دهنده PHP'))
            ->where('guide.sections.1.text', "فقط شرح واقعیِ واردشده\nخط دوم")->where('guide.sections.2.text', "برنامه‌نویس — مجموعه آزمایشی\n۱۴۰۰ تا ۱۴۰۲\nمسئولیت ثبت‌شده توسط صاحب")
            ->where('guide.sections.3.text', 'رشته ثبت‌شده — آموزشگاه آزمایشی')->where('guide.sections.5.text', "SQL\nPHP")
            ->missing('guide.roles')->missing('guide.phone')->missing('guide.email')->missing('guide.links')->missing('guide.definition')->missing('guide.content_hash'));
        $audit = AuditEvent::where('action', 'resume.linkedin_viewed')->sole();
        $this->assertSame(['version_id' => $version->id, 'version' => 1, 'method' => LinkedInGuide::METHOD, 'scope' => 'owner'], $audit->metadata);
        $this->assertStringNotContainsString('توسعه‌دهنده', json_encode($audit->metadata));
        Http::assertNothingSent();
    }

    public function test_private_plain_text_download_omits_contact_fields_internal_title_and_links(): void
    {
        [$resume, $version] = $this->fixture();
        $response = $this->actingAs($this->owner)->get($this->path($resume, $version).'/download')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        foreach (['توسعه‌دهنده PHP', 'مسئولیت ثبت‌شده توسط صاحب', "SQL\nPHP", 'انتشار خودکار انجام نمی‌شود'] as $value) {
            $response->assertSee($value, false);
        }
        foreach (['09120000099', 'private-contact@example.test', 'private-contact-link', 'PRIVATE INTERNAL RESUME TITLE', 'content_hash', 'created_by'] as $value) {
            $response->assertDontSee($value, false);
        }
        $this->assertSame('owner', AuditEvent::where('action', 'resume.linkedin_exported')->sole()->metadata['scope']);
        $this->assertSame(1, ResumeVersion::count());
        Http::assertNothingSent();
    }

    public function test_all_four_roles_can_use_own_saved_snapshot_without_buying_or_granting_roles(): void
    {
        foreach (['student', 'coach', 'org_admin', 'admin'] as $role) {
            $user = $this->user($role);
            [$resume, $version] = $this->fixture(owner: $user);
            $before = $user->roleKeys();
            $this->actingAs($user)->withSession(['panel_role' => $role])->get($this->path($resume, $version))->assertOk()->assertInertia(fn (Assert $page) => $page->where('role', $role));
            $this->get($this->path($resume, $version).'/download')->assertOk();
            $this->assertSame($before, $user->fresh()->roleKeys());
        }
    }

    public function test_full_admin_can_help_without_membership_and_cannot_expand_selected_version_to_another_parent(): void
    {
        [$resume, $version] = $this->fixture();
        [$other, $otherVersion] = $this->fixture(owner: $this->user('student'));
        $this->actingAs($this->admin)->get($this->path($resume, $version))->assertOk();
        $this->get($this->path($resume, $version).'/download')->assertOk();
        $this->assertSame('admin', AuditEvent::where('action', 'resume.linkedin_exported')->sole()->metadata['scope']);
        $this->get($this->path($resume, $otherVersion))->assertNotFound();
        $this->get($this->path($other, $version).'/download')->assertNotFound();
    }

    public function test_peer_current_teacher_and_same_organization_manager_have_no_private_guide_access(): void
    {
        [$resume, $version] = $this->fixture();
        $coach = $this->user('coach');
        $manager = $this->user('org_admin');
        $organization = Organization::factory()->create();
        $organization->members()->attach($manager->id, ['kind' => 'manager']);
        $organization->members()->attach($this->owner->id, ['kind' => 'member']);
        $enrollment = Enrollment::factory()->create(['user_id' => $this->owner->id]);
        $enrollment->course->update(['coach_id' => $coach->id]);
        foreach ([$coach, $manager, $this->user('student')] as $actor) {
            $this->actingAs($actor)->get($this->path($resume, $version))->assertForbidden();
            $this->get($this->path($resume, $version).'/download')->assertForbidden();
        }
        $this->assertSame(0, AuditEvent::where('action', 'like', 'resume.linkedin_%')->count());
    }

    public function test_new_draft_snapshot_archive_internal_title_and_account_name_do_not_rewrite_old_download(): void
    {
        [$resume, $first] = $this->fixture();
        $this->actingAs($this->owner);
        $old = $this->get($this->path($resume, $first).'/download')->getContent();
        $builder = app(ResumeBuilder::class);
        $builder->save($this->owner, $resume, 3, 'NEW PRIVATE TITLE', [...$resume->draft, 'name' => 'نام نسخه بعدی', 'summary' => 'خلاصه کاملاً تازه']);
        $second = $builder->snapshot($this->owner, $resume, 4);
        $builder->archive($this->owner, $resume, 5, true);
        $this->owner->update(['name' => 'نام جاری حساب']);
        $this->assertSame($old, $this->get($this->path($resume, $first).'/download')->assertOk()->getContent());
        $this->get($this->path($resume, $second).'/download')->assertSee('نام نسخه بعدی', false)->assertSee('خلاصه کاملاً تازه', false)->assertDontSee('نام جاری حساب', false);
    }

    public function test_english_snapshot_keeps_language_source_html_and_long_text_without_translation_or_truncation(): void
    {
        $summary = '<script>window.linkedinInjected=true</script>'.str_repeat('☀️é', 450);
        [$resume, $version] = $this->fixture(['locale' => 'en', 'name' => 'Saved source name', 'headline' => 'Developer <b>SQL</b>', 'summary' => $summary]);
        $this->actingAs($this->owner)->get($this->path($resume, $version))->assertInertia(fn (Assert $page) => $page->where('guide.sections.0.label', 'Headline')->where('guide.sections.0.text', 'Developer <b>SQL</b>')->where('guide.sections.1.text', $summary)->where('guide.sections.1.characters', mb_strlen($summary)));
        $this->get($this->path($resume, $version).'/download')->assertSee('LinkedIn profile draft', false)->assertSee('No translation', false)->assertSee($summary, false);
    }

    public function test_empty_source_has_checklist_and_no_fabricated_headline_skills_or_work_history(): void
    {
        [$resume, $version] = $this->fixture(['headline' => '', 'summary' => '', 'experience' => [], 'education' => [], 'projects' => [], 'skills' => []]);
        $this->actingAs($this->owner)->get($this->path($resume, $version))->assertInertia(fn (Assert $page) => $page->has('guide.sections', 0)->has('guide.checks', 6)->where('guide.checks', fn ($checks) => collect($checks)->every(fn ($check) => $check['present'] === false)));
        $this->get($this->path($resume, $version).'/download')->assertSee('نام نسخه ثابت', false)->assertDontSee('متخصص', false);
    }

    public function test_maximum_source_lists_and_fifty_skills_are_preserved_without_truncation(): void
    {
        [$resume, $version] = $this->fixture(['experience' => array_fill(0, 20, ['title' => 'Role', 'employer' => 'Employer', 'period' => '', 'description' => str_repeat('x', 3000)]), 'education' => array_fill(0, 15, ['degree' => 'Degree', 'institution' => 'School', 'period' => '', 'description' => '']), 'projects' => array_fill(0, 15, ['title' => 'Project', 'description' => '']), 'skills' => array_map(fn ($number) => 'Skill '.$number, range(1, 50))]);
        $this->actingAs($this->owner)->get($this->path($resume, $version))->assertInertia(fn (Assert $page) => $page->has('guide.sections', 53)->where('guide.sections.21.key', 'experience-19')->where('guide.sections.51.key', 'projects-14')->where('guide.sections.52.text', implode("\n", array_map(fn ($number) => 'Skill '.$number, range(1, 50))))->where('guide.checks.5.present', true));
    }

    public function test_corrupt_cipher_wrong_hash_invalid_shape_and_unknown_locale_fail_without_audit_or_draft_fallback(): void
    {
        [$resume, $version] = $this->fixture();
        $original = $version->definition;
        $this->actingAs($this->owner);
        DB::table('resume_versions')->where('id', $version->id)->update(['definition' => 'not encrypted']);
        $this->get($this->path($resume, $version))->assertSessionHasErrors('guide');
        $version->update(['definition' => $original, 'content_hash' => str_repeat('0', 64)]);
        $this->get($this->path($resume, $version).'/download')->assertSessionHasErrors('guide');
        foreach ([['skills' => ['forged' => 'nested']], ['locale' => 'de'], ['unexpected' => 'private-extra']] as $invalid) {
            $definition = [...$original, ...$invalid];
            $version->update(['definition' => $definition, 'content_hash' => hash('sha256', json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))]);
            $this->get($this->path($resume, $version).'/download')->assertSessionHasErrors('guide');
        }
        $this->assertSame(0, AuditEvent::where('action', 'like', 'resume.linkedin_%')->count());
        $this->assertSame('توسعه‌دهنده PHP', $resume->fresh()->draft['headline']);
    }

    public function test_stale_loaded_admin_permission_cannot_read_after_role_loss(): void
    {
        [$resume, $version] = $this->fixture();
        $this->assertTrue($this->admin->hasRole('admin'));
        DB::table('role_user')->where('user_id', $this->admin->id)->delete();
        $this->expectException(HttpException::class);
        app(LinkedInGuide::class)->prepare($this->admin, $resume, $version);
    }

    public function test_stale_loaded_owner_activation_and_verification_cannot_bypass_current_access(): void
    {
        [$resume, $version] = $this->fixture();
        DB::table('users')->where('id', $this->owner->id)->update(['is_active' => false]);
        $this->actingAs($this->owner)->get($this->path($resume, $version).'/download')->assertForbidden();
        DB::table('users')->where('id', $this->owner->id)->update(['is_active' => true, 'phone_verified_at' => null]);
        $this->get($this->path($resume, $version))->assertForbidden();
        $this->assertSame(0, AuditEvent::where('action', 'like', 'resume.linkedin_%')->count());
    }

    public function test_guest_cannot_open_guide_and_new_feature_does_not_store_plaintext_drafts_or_publish_anything(): void
    {
        [$resume, $version] = $this->fixture();
        $this->get($this->path($resume, $version))->assertRedirect('/login');
        $this->get($this->path($resume, $version).'/download')->assertRedirect('/login');
        $raw = DB::table('resume_versions')->where('id', $version->id)->value('definition');
        $this->actingAs($this->owner)->get($this->path($resume, $version))->assertOk();
        $this->get($this->path($resume, $version).'/download')->assertOk();
        $this->assertSame($raw, DB::table('resume_versions')->where('id', $version->id)->value('definition'));
        $this->assertStringNotContainsString('توسعه‌دهنده', $raw);
        $this->assertSame(1, CareerResume::count());
        $this->assertSame(1, ResumeVersion::count());
        $this->assertDatabaseCount('resume_checks', 0);
        $this->assertDatabaseCount('coach_profiles', 0);
        Http::assertNothingSent();
    }
}
