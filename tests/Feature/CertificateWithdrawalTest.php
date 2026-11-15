<?php

namespace Tests\Feature;

use App\Models\CertificatePolicy;
use App\Models\CertificateRequest;
use App\Models\CourseCertificate;
use App\Models\LessonProgress;
use App\Models\Role;
use App\Models\User;
use App\Services\Certification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CertificateWithdrawalTest extends TestCase
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

    private function ready(): CertificateRequest
    {
        $request = CertificateRequest::factory()->create();
        foreach ($request->definition['lessons'] as $lesson) {
            LessonProgress::create(['enrollment_id' => $request->enrollment_id, 'lesson_id' => $lesson['id'], 'completed_at' => now()]);
        }

        return $request;
    }

    private function url(CertificateRequest $request): string
    {
        return '/certificate-requests/'.$request->id.'/withdraw';
    }

    public function test_owner_can_withdraw_pending_request_and_preserve_historical_consent_and_definition(): void
    {
        $request = $this->ready();
        $consent = $request->public_consent_at;
        $definition = $request->definition;
        $this->actingAs($request->enrollment->user)->post($this->url($request), ['revision' => 1])->assertSessionHasNoErrors();
        $request->refresh();
        $this->assertSame('withdrawn', $request->status);
        $this->assertSame(2, $request->revision);
        $this->assertNotNull($request->withdrawn_at);
        $this->assertTrue($consent->equalTo($request->public_consent_at));
        $this->assertSame($definition, $request->definition);
        $this->assertDatabaseCount('course_certificates', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'certificate.request_withdrawn', 'actor_id' => $request->enrollment->user_id, 'subject_id' => $request->id]);
    }

    public function test_foreign_student_coach_manager_or_admin_cannot_withdraw_on_behalf_of_owner(): void
    {
        $request = $this->ready();
        $this->post($this->url($request), ['revision' => 1])->assertRedirect('/login');
        foreach (['student', 'coach', 'org_admin', 'admin'] as $role) {
            $peer = User::factory()->create();
            $peer->roles()->attach(Role::where('key', $role)->value('id'));
            $this->actingAs($peer)->post($this->url($request), ['revision' => 1])->assertForbidden();
        }
        $this->assertSame('pending', $request->fresh()->status);
    }

    public function test_owner_can_withdraw_after_student_role_is_replaced_or_enrollment_cancelled(): void
    {
        $request = $this->ready();
        $owner = $request->enrollment->user;
        $owner->roles()->sync([Role::where('key', 'coach')->value('id')]);
        $request->enrollment->update(['status' => 'cancelled']);
        $this->actingAs($owner)->get('/certificates/enrollments/'.$request->enrollment_id)->assertInertia(fn (Assert $page) => $page->where('can_request', false)->where('requests.0.can_withdraw', true));
        $this->post($this->url($request), ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', $request->fresh()->status);
    }

    public function test_policy_disable_does_not_prevent_owner_from_withdrawing_existing_consent(): void
    {
        $request = $this->ready();
        CertificatePolicy::where('cohort_id', $request->enrollment->cohort_id)->update(['enabled' => false]);
        $this->actingAs($request->enrollment->user)->post($this->url($request), ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', $request->fresh()->status);
    }

    public function test_stale_revision_and_repeated_withdrawal_do_not_change_terminal_history(): void
    {
        $request = $this->ready();
        $this->actingAs($request->enrollment->user)->post($this->url($request), ['revision' => 99])->assertSessionHasErrors('certificate');
        $this->assertSame('pending', $request->fresh()->status);
        $this->post($this->url($request), ['revision' => 1])->assertSessionHasNoErrors();
        $time = $request->fresh()->withdrawn_at;
        $this->post($this->url($request), ['revision' => 1])->assertSessionHasErrors('certificate');
        $this->post($this->url($request), ['revision' => 2])->assertSessionHasErrors('certificate');
        $this->assertSame(2, $request->fresh()->revision);
        $this->assertTrue($time->equalTo($request->fresh()->withdrawn_at));
    }

    public function test_admin_cannot_issue_or_reject_withdrawn_request_even_with_current_revision(): void
    {
        $request = $this->ready();
        app(Certification::class)->withdraw($request->enrollment->user, $request, 1);
        foreach (['issue', 'reject'] as $action) {
            $this->actingAs($this->admin)->post('/admin/certificate-requests/'.$request->id.'/review', ['revision' => 2, 'action' => $action, 'note' => 'بررسی فرم قدیمی', 'reviewed' => true])->assertSessionHasErrors('certificate');
        }
        $this->assertDatabaseCount('course_certificates', 0);
        $this->assertSame('withdrawn', $request->fresh()->status);
    }

    public function test_issued_and_rejected_requests_are_not_withdrawn(): void
    {
        foreach (['issue', 'reject'] as $action) {
            $request = $this->ready();
            app(Certification::class)->review($this->admin, $request, 1, $action, 'نتیجه بررسی آزمایشی');
            $status = $request->fresh()->status;
            $this->actingAs($request->enrollment->user)->post($this->url($request), ['revision' => 2])->assertSessionHasErrors('certificate');
            $this->assertSame($status, $request->fresh()->status);
            $this->assertNull($request->fresh()->withdrawn_at);
        }
        $this->assertDatabaseCount('course_certificates', 1);
        $this->assertSame('issued', CourseCertificate::sole()->status);
    }

    public function test_fresh_request_requires_fresh_explicit_consent_and_keeps_withdrawn_request(): void
    {
        $request = $this->ready();
        $owner = $request->enrollment->user;
        app(Certification::class)->withdraw($owner, $request, 1);
        $this->travel(1)->minutes();
        $url = '/certificates/enrollments/'.$request->enrollment_id.'/request';
        $data = ['revision' => 1, 'expected_name' => $owner->name, 'public_consent' => false];
        $this->actingAs($owner)->post($url, $data)->assertSessionHasErrors('public_consent');
        $this->assertDatabaseCount('certificate_requests', 1);
        $this->post($url, [...$data, 'public_consent' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('certificate_requests', 2);
        $new = CertificateRequest::where('status', 'pending')->sole();
        $this->assertNotSame($request->id, $new->id);
        $this->assertTrue($new->public_consent_at->gt($request->public_consent_at));
        $this->assertSame('withdrawn', $request->fresh()->status);
    }

    public function test_admin_can_filter_withdrawn_history_and_owner_receives_withdrawal_timestamp(): void
    {
        $request = $this->ready();
        app(Certification::class)->withdraw($request->enrollment->user, $request, 1);
        $this->actingAs($this->admin)->get('/admin/certificates?status=withdrawn')->assertInertia(fn (Assert $page) => $page->where('requests.total', 1)->where('requests.data.0.status', 'withdrawn')->where('requests.data.0.evidence', null));
        $this->actingAs($request->enrollment->user)->get('/certificates/enrollments/'.$request->enrollment_id)->assertInertia(fn (Assert $page) => $page->where('requests.0.revision', 2)->where('requests.0.can_withdraw', false)->where('requests.0.withdrawn_at', fn ($value) => is_string($value)));
    }

    public function test_withdrawal_migration_refuses_to_erase_consent_withdrawal_history(): void
    {
        $request = $this->ready();
        app(Certification::class)->withdraw($request->enrollment->user, $request, 1);
        $migration = require database_path('migrations/2026_11_15_212126_add_withdrawal_to_certificate_requests.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
