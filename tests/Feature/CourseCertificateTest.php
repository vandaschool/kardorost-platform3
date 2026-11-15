<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\CertificatePolicy;
use App\Models\CertificateRequest;
use App\Models\CourseCertificate;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LiveSession;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Submission;
use App\Models\User;
use App\Services\BillingService;
use App\Services\Certification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CourseCertificateTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $admin;

    private Enrollment $enrollment;

    private Lesson $lesson;

    private Assignment $assignment;

    private LiveSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfMinute());
        $this->student = $this->user('student');
        $this->admin = $this->user('admin');
        $this->enrollment = Enrollment::factory()->create(['user_id' => $this->student->id])->fresh();
        $this->lesson = Lesson::factory()->create(['course_section_id' => CourseSection::factory()->create(['course_id' => $this->enrollment->course_id])->id]);
        $this->assignment = Assignment::factory()->create(['cohort_id' => $this->enrollment->cohort_id]);
        $this->session = LiveSession::factory()->create(['cohort_id' => $this->enrollment->cohort_id, 'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour()]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function settings(array $changes = []): array
    {
        return [...['revision' => CertificatePolicy::where('cohort_id', $this->enrollment->cohort_id)->value('revision') ?? 0, 'enabled' => true, 'title' => 'گواهی تکمیل دوره', 'issuer' => 'کاردرست', 'lesson_ids' => [$this->lesson->id], 'session_ids' => [$this->session->id], 'assignment_ids' => [$this->assignment->id], 'attendance_percent' => 100, 'score_percent' => 70, 'require_settled' => false, 'reviewed' => true], ...$changes];
    }

    private function configure(array $changes = []): void
    {
        $this->actingAs($this->admin)->patch('/admin/cohorts/'.$this->enrollment->cohort_id.'/certificate-policy', $this->settings($changes))->assertSessionHasNoErrors();
    }

    private function ready(): void
    {
        LessonProgress::create(['enrollment_id' => $this->enrollment->id, 'lesson_id' => $this->lesson->id, 'completed_at' => now()]);
        Attendance::factory()->create(['live_session_id' => $this->session->id, 'enrollment_id' => $this->enrollment->id, 'status' => 'present']);
        Submission::factory()->create(['assignment_id' => $this->assignment->id, 'enrollment_id' => $this->enrollment->id, 'version' => 1, 'status' => 'reviewed', 'score' => 70, 'reviewed_at' => now()]);
    }

    private function apply(array $changes = []): void
    {
        $this->actingAs($this->student)->post('/certificates/enrollments/'.$this->enrollment->id.'/request', [...['revision' => CertificatePolicy::where('cohort_id', $this->enrollment->cohort_id)->value('revision') ?? 1, 'expected_name' => $this->student->name, 'public_consent' => true], ...$changes])->assertSessionHasNoErrors();
    }

    private function issue(): CourseCertificate
    {
        return app(Certification::class)->review($this->admin, CertificateRequest::latest('id')->firstOrFail(), 1, 'issue', 'نام و شواهد آموزشی بررسی شدند');
    }

    public function test_configuration_requires_scope_review_nonempty_criteria_and_current_revision(): void
    {
        $url = '/admin/cohorts/'.$this->enrollment->cohort_id.'/certificate-policy';
        $this->actingAs($this->admin)->patch($url, $this->settings(['reviewed' => false]))->assertSessionHasErrors('reviewed');
        $this->patch($url, $this->settings(['lesson_ids' => [], 'session_ids' => [], 'assignment_ids' => []]))->assertSessionHasErrors('certificate');
        $this->patch($url, $this->settings(['lesson_ids' => [Lesson::factory()->create()->id]]))->assertSessionHasErrors('certificate');
        $this->patch($url, $this->settings(['session_ids' => [LiveSession::factory()->create()->id]]))->assertSessionHasErrors('certificate');
        $this->patch($url, $this->settings(['assignment_ids' => [Assignment::factory()->create()->id]]))->assertSessionHasErrors('certificate');
        $this->configure();
        $this->patch($url, $this->settings(['revision' => 0]))->assertSessionHasErrors('certificate');
        $this->assertSame(1, CertificatePolicy::first()->revision);
    }

    public function test_only_admin_can_configure_review_revoke_or_view_request_queue(): void
    {
        $this->configure();
        $this->ready();
        $this->apply();
        $request = CertificateRequest::first();
        $certificate = $this->issue();
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->patch('/admin/cohorts/'.$this->enrollment->cohort_id.'/certificate-policy', $this->settings())->assertForbidden();
            $this->get('/admin/certificates')->assertForbidden();
            $this->get('/admin/certificate-policies')->assertForbidden();
            $this->post('/admin/certificate-requests/'.$request->id.'/review', ['action' => 'issue'])->assertForbidden();
            $this->post('/admin/certificates/'.$certificate->id.'/revoke', ['revision' => 1, 'reason' => 'غیرمجاز'])->assertForbidden();
        }
    }

    public function test_owner_and_public_consent_are_required_and_pending_request_is_idempotent(): void
    {
        $this->configure();
        $this->ready();
        $url = '/certificates/enrollments/'.$this->enrollment->id.'/request';
        $data = ['revision' => 1, 'expected_name' => $this->student->name, 'public_consent' => true];
        $this->actingAs($this->user('student'))->post($url, $data)->assertForbidden();
        $this->get('/certificates/enrollments/'.$this->enrollment->id)->assertForbidden();
        $this->actingAs($this->student)->post($url, [...$data, 'public_consent' => false])->assertSessionHasErrors('public_consent');
        $this->post($url, [...$data, 'expected_name' => 'نام قدیمی'])->assertSessionHasErrors('certificate');
        $this->apply();
        $this->apply();
        $this->assertDatabaseCount('certificate_requests', 1);
        $this->assertDatabaseCount('course_certificates', 0);
        $this->assertNotNull(CertificateRequest::first()->public_consent_at);
    }

    public function test_empty_progress_cannot_request_even_when_course_is_paid_and_active(): void
    {
        $this->configure();
        $this->actingAs($this->student)->post('/certificates/enrollments/'.$this->enrollment->id.'/request', ['revision' => 1, 'expected_name' => $this->student->name, 'public_consent' => true])->assertSessionHasErrors('certificate');
        $this->assertDatabaseCount('certificate_requests', 0);
        $this->get('/certificates/enrollments/'.$this->enrollment->id)->assertInertia(fn (Assert $page) => $page->where('evidence.eligible', false));
    }

    public function test_latest_attempt_and_review_state_are_used_instead_of_older_high_score(): void
    {
        $this->configure();
        $this->ready();
        Submission::factory()->create(['assignment_id' => $this->assignment->id, 'enrollment_id' => $this->enrollment->id, 'version' => 2, 'status' => 'submitted', 'score' => null]);
        $evidence = app(Certification::class)->evidence($this->enrollment, CertificatePolicy::first()->definition);
        $this->assertFalse($evidence['eligible']);
        $latest = Submission::where('version', 2)->first();
        $latest->update(['status' => 'reviewed', 'score' => 69, 'reviewed_at' => now()]);
        $this->assertFalse(app(Certification::class)->evidence($this->enrollment, CertificatePolicy::first()->definition)['eligible']);
        $latest->update(['score' => 70]);
        $this->assertTrue(app(Certification::class)->evidence($this->enrollment, CertificatePolicy::first()->definition)['eligible']);
    }

    public function test_future_sessions_excused_attendance_and_unavailable_content_do_not_pass(): void
    {
        $this->configure();
        $this->ready();
        Attendance::query()->update(['status' => 'excused']);
        $this->assertFalse(app(Certification::class)->evidence($this->enrollment, CertificatePolicy::first()->definition)['eligible']);
        Attendance::query()->update(['status' => 'present']);
        $this->session->update(['ends_at' => now()->addHour()]);
        $this->assertFalse(app(Certification::class)->evidence($this->enrollment, CertificatePolicy::first()->definition)['eligible']);
        $this->session->update(['ends_at' => now()->subMinute()]);
        $this->lesson->update(['available_at' => now()->addDay()]);
        $this->assertFalse(app(Certification::class)->evidence($this->enrollment, CertificatePolicy::first()->definition)['eligible']);
    }

    public function test_attendance_percentage_uses_exact_cross_multiplication_without_rounding_up(): void
    {
        $other = LiveSession::factory()->create(['cohort_id' => $this->enrollment->cohort_id, 'ends_at' => now()->subHour()]);
        $this->configure(['session_ids' => [$this->session->id, $other->id], 'attendance_percent' => 51]);
        $this->ready();
        $this->assertFalse(app(Certification::class)->evidence($this->enrollment, CertificatePolicy::first()->definition)['eligible']);
        $this->configure(['session_ids' => [$this->session->id, $other->id], 'attendance_percent' => 50]);
        $this->assertTrue(app(Certification::class)->evidence($this->enrollment, CertificatePolicy::first()->definition)['eligible']);
    }

    public function test_pending_request_keeps_old_policy_when_admin_changes_or_disables_new_requests(): void
    {
        $this->configure();
        $this->ready();
        $this->apply();
        $definition = CertificateRequest::first()->definition;
        $this->configure(['score_percent' => 100, 'issuer' => 'صادرکننده جدید']);
        $this->configure(['enabled' => false]);
        $certificate = $this->issue();
        $this->assertSame($definition, $certificate->definition['policy']);
        $this->assertSame('کاردرست', $certificate->definition['issuer']);
    }

    public function test_admin_must_explicitly_review_and_recompute_evidence_at_issue_time(): void
    {
        $this->configure();
        $this->ready();
        $this->apply();
        $request = CertificateRequest::first();
        $url = '/admin/certificate-requests/'.$request->id.'/review';
        $this->actingAs($this->admin)->post($url, ['revision' => 1, 'action' => 'issue', 'note' => 'بررسی شد', 'reviewed' => false])->assertSessionHasErrors('reviewed');
        Submission::query()->update(['status' => 'revision_requested', 'score' => null]);
        $this->post($url, ['revision' => 1, 'action' => 'issue', 'note' => 'بررسی شد', 'reviewed' => true])->assertSessionHasErrors('certificate');
        $this->assertDatabaseCount('course_certificates', 0);
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_issuance_is_once_and_public_data_stays_frozen_after_profile_and_content_changes(): void
    {
        $this->configure();
        $this->ready();
        $this->apply();
        $certificate = $this->issue();
        $name = $certificate->definition['recipient_name'];
        $title = $certificate->definition['course_title'];
        $this->student->update(['name' => 'نام جدید حساب']);
        $this->enrollment->course->update(['title' => 'عنوان جدید دوره']);
        $this->issue();
        $this->assertDatabaseCount('course_certificates', 1);
        $this->assertSame('active', $this->enrollment->fresh()->status);
        $this->get('/verify/certificates/'.$certificate->verification_token)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, noarchive')->assertInertia(fn (Assert $page) => $page->where('certificate.recipient_name', $name)->where('certificate.course_title', $title)->where('certificate.status', 'issued')->missing('certificate.enrollment_id')->missing('certificate.evidence')->missing('certificate.phone')->missing('certificate.issued_by')->missing('certificate.revocation_reason'));
        $this->assertTrue($certificate->evidence['eligible']);
    }

    public function test_unknown_tokens_and_numeric_certificate_ids_do_not_disclose_records(): void
    {
        $certificate = CourseCertificate::factory()->create();
        $this->get('/verify/certificates/'.$certificate->id)->assertNotFound();
        $this->get('/verify/certificates/'.str_repeat('a', 48))->assertNotFound();
        $this->get('/verify/certificates/'.$certificate->verification_token)->assertOk();
    }

    public function test_revoke_is_versioned_publicly_visible_and_cannot_be_undone_by_approval_replay(): void
    {
        $this->configure();
        $this->ready();
        $this->apply();
        $certificate = $this->issue();
        $url = '/admin/certificates/'.$certificate->id.'/revoke';
        $this->actingAs($this->admin)->post($url, ['revision' => 99, 'reason' => 'اشتباه در صدور'])->assertSessionHasErrors('certificate');
        $this->post($url, ['revision' => 1, 'reason' => 'یادداشت داخلی خصوصی ابطال'])->assertSessionHasNoErrors();
        $this->post($url, ['revision' => 1, 'reason' => 'تکرار ابطال'])->assertSessionHasErrors('certificate');
        $this->issue();
        $this->assertSame('revoked', $certificate->fresh()->status);
        $this->get('/verify/certificates/'.$certificate->verification_token)->assertInertia(fn (Assert $page) => $page->where('certificate.status', 'revoked')->missing('certificate.revocation_reason'));
    }

    public function test_rejection_preserves_history_and_resubmission_uses_current_policy(): void
    {
        $this->configure();
        $this->ready();
        $this->apply();
        $request = CertificateRequest::first();
        $this->actingAs($this->admin)->post('/admin/certificate-requests/'.$request->id.'/review', ['revision' => 1, 'action' => 'reject', 'note' => 'نام حساب را بررسی کنید', 'reviewed' => false])->assertSessionHasNoErrors();
        $this->configure(['issuer' => 'نسخه جدید صادرکننده']);
        $this->apply();
        $this->assertDatabaseCount('certificate_requests', 2);
        $this->assertSame('کاردرست', $request->fresh()->definition['issuer']);
        $this->assertSame('نسخه جدید صادرکننده', CertificateRequest::latest('id')->first()->definition['issuer']);
    }

    public function test_optional_tuition_rule_requires_actual_allocation_and_accepts_free_enrollment(): void
    {
        $this->configure(['require_settled' => true]);
        $this->ready();
        $definition = CertificatePolicy::first()->definition;
        $this->assertTrue(app(Certification::class)->evidence($this->enrollment, $definition)['eligible']);
        $this->enrollment->update(['amount' => 1000000]);
        $this->assertFalse(app(Certification::class)->evidence($this->enrollment, $definition)['eligible']);
        app(BillingService::class)->issue($this->enrollment, $this->enrollment->course, $this->enrollment->cohort, 'full');
        $invoice = $this->enrollment->installments()->first();
        $invoice->update(['paid_at' => now()]);
        $this->assertFalse(app(Certification::class)->evidence($this->enrollment, $definition)['eligible']);
        $invoice->update(['paid_at' => null]);
        $payment = Payment::factory()->create(['installment_id' => $invoice->id]);
        app(BillingService::class)->capture($payment, 'CERT-TUITION');
        $this->assertTrue(app(Certification::class)->evidence($this->enrollment->fresh(), $definition)['eligible']);
    }

    public function test_new_published_content_does_not_silently_expand_selected_request_requirements(): void
    {
        $this->configure();
        $this->ready();
        Lesson::factory()->create(['course_section_id' => $this->lesson->course_section_id]);
        Assignment::factory()->create(['cohort_id' => $this->enrollment->cohort_id]);
        $this->apply();
        $this->assertDatabaseCount('certificate_requests', 1);
    }

    public function test_student_pages_and_admin_queue_have_scoped_real_status(): void
    {
        $this->configure();
        $this->ready();
        $this->apply();
        $this->actingAs($this->student)->get('/certificates')->assertInertia(fn (Assert $page) => $page->has('enrollments.data', 1)->where('enrollments.data.0.enabled', true));
        $this->actingAs($this->user('student'))->get('/certificates')->assertInertia(fn (Assert $page) => $page->has('enrollments.data', 0));
        $this->actingAs($this->admin)->get('/admin/certificates')->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.evidence.eligible', true));
        $this->get('/admin/certificate-policies')->assertOk();
    }

    public function test_coach_and_selected_organization_only_see_scoped_completion_status(): void
    {
        $this->configure();
        $this->ready();
        $this->apply();
        $this->issue();
        $coach = $this->user('coach');
        $this->enrollment->course->update(['coach_id' => $coach->id]);
        $this->actingAs($coach)->get('/teaching/'.$this->enrollment->course_id)->assertInertia(fn (Assert $page) => $page->where('members.0.certificate_status', 'issued')->missing('members.0.verification_token'));
        $this->actingAs($this->user('coach'))->get('/teaching/'.$this->enrollment->course_id)->assertForbidden();
        $organization = Organization::factory()->create(['name' => 'سازمان گواهی', 'type' => 'company', 'status' => 'active']);
        $manager = $this->user('org_admin');
        $organization->members()->attach($manager->id, ['kind' => 'manager']);
        $organization->members()->attach($this->student->id, ['kind' => 'member']);
        $this->actingAs($manager)->withSession(['organization_id' => $organization->id])->get('/organization/members/'.$this->student->id)->assertInertia(fn (Assert $page) => $page->where('learningReports.'.$this->enrollment->id.'.certificate_status', 'issued')->missing('learningReports.'.$this->enrollment->id.'.verification_token'));
        $this->actingAs($this->user('org_admin'))->get('/organization/members/'.$this->student->id)->assertForbidden();
    }

    public function test_migration_preserves_requests_and_issued_documents(): void
    {
        $this->configure();
        $this->ready();
        $this->apply();
        $migration = require database_path('migrations/2026_11_15_111526_create_course_certificates.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
