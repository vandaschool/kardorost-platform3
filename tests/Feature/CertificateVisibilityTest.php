<?php

namespace Tests\Feature;

use App\Models\CertificateVisibilityEvent;
use App\Models\CourseCertificate;
use App\Models\Role;
use App\Models\User;
use App\Services\Certification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CertificateVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CourseCertificate $certificate;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $this->certificate = CourseCertificate::factory()->create();
        $this->owner = $this->certificate->enrollment->user;
    }

    private function publicUrl(): string
    {
        return '/verify/certificates/'.$this->certificate->verification_token;
    }

    private function url(): string
    {
        return '/certificates/'.$this->certificate->id.'/visibility';
    }

    private function close(): void
    {
        app(Certification::class)->visibility($this->owner, $this->certificate, $this->certificate->fresh()->revision, false, false);
    }

    public function test_consented_certificate_is_public_and_owner_can_close_without_changing_document_identity(): void
    {
        $serial = $this->certificate->serial;
        $token = $this->certificate->verification_token;
        $this->get($this->publicUrl())->assertOk()->assertInertia(fn (Assert $page) => $page->where('public_enabled', true));
        $this->actingAs($this->owner)->post($this->url(), ['revision' => 1, 'enabled' => false])->assertSessionHasNoErrors();
        $certificate = $this->certificate->fresh();
        $this->assertFalse($certificate->public_enabled);
        $this->assertSame('issued', $certificate->status);
        $this->assertSame($serial, $certificate->serial);
        $this->assertSame($token, $certificate->verification_token);
        $this->assertNotNull($certificate->public_withdrawn_at);
        $this->assertSame(2, $certificate->revision);
    }

    public function test_private_certificate_is_not_disclosed_to_anonymous_or_other_authenticated_users(): void
    {
        $this->close();
        $this->get($this->publicUrl())->assertNotFound()->assertDontSee($this->certificate->definition['recipient_name']);
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $peer = User::factory()->create();
            $peer->roles()->attach(Role::where('key', $role)->value('id'));
            $this->actingAs($peer)->get($this->publicUrl())->assertNotFound()->assertDontSee($this->certificate->definition['recipient_name']);
        }
    }

    public function test_private_certificate_remains_available_to_active_owner_and_current_full_admin(): void
    {
        $this->close();
        foreach ([$this->owner, $this->admin] as $viewer) {
            $this->actingAs($viewer)->get($this->publicUrl())->assertOk()->assertInertia(fn (Assert $page) => $page->where('public_enabled', false)->where('certificate.serial', $this->certificate->serial)->missing('certificate.public_consent_at')->missing('certificate.evidence'));
        }
        $this->admin->roles()->detach();
        $this->get($this->publicUrl())->assertNotFound();
    }

    public function test_only_owner_can_change_public_visibility_even_if_admin_can_view_private_document(): void
    {
        foreach ([$this->admin, User::factory()->create()] as $peer) {
            $this->actingAs($peer)->post($this->url(), ['revision' => 1, 'enabled' => false])->assertForbidden();
        }
        $this->assertTrue($this->certificate->fresh()->public_enabled);
        $this->assertDatabaseCount('certificate_visibility_events', 0);
    }

    public function test_owner_role_replacement_or_enrollment_cancellation_does_not_remove_privacy_control(): void
    {
        $this->owner->roles()->sync([Role::where('key', 'coach')->value('id')]);
        $this->certificate->enrollment->update(['status' => 'cancelled']);
        $this->actingAs($this->owner)->post($this->url(), ['revision' => 1, 'enabled' => false])->assertSessionHasNoErrors();
        $this->get('/certificates/enrollments/'.$this->certificate->enrollment_id)->assertInertia(fn (Assert $page) => $page->where('can_manage_visibility', true)->where('certificate.public_enabled', false));
    }

    public function test_reenabling_requires_fresh_explicit_consent_and_preserves_old_withdrawal_history(): void
    {
        $this->close();
        $old = $this->certificate->fresh()->public_consent_at;
        $withdrawn = $this->certificate->fresh()->public_withdrawn_at;
        $this->travel(1)->minutes();
        $this->actingAs($this->owner)->post($this->url(), ['revision' => 2, 'enabled' => true])->assertSessionHasErrors('public_consent');
        $this->assertFalse($this->certificate->fresh()->public_enabled);
        $this->post($this->url(), ['revision' => 2, 'enabled' => true, 'public_consent' => true])->assertSessionHasNoErrors();
        $certificate = $this->certificate->fresh();
        $this->assertTrue($certificate->public_enabled);
        $this->assertTrue($certificate->public_consent_at->gt($old));
        $this->assertTrue($withdrawn->equalTo($certificate->public_withdrawn_at));
        $this->assertSame(3, $certificate->revision);
        $this->assertSame([false, true], CertificateVisibilityEvent::orderBy('id')->pluck('enabled')->all());
        $this->assertNotNull(CertificateVisibilityEvent::latest('id')->firstOrFail()->consented_at);
    }

    public function test_closed_or_reopened_document_has_private_no_store_headers_and_no_private_metadata(): void
    {
        $this->close();
        $response = $this->actingAs($this->owner)->get($this->publicUrl())->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        app(Certification::class)->visibility($this->owner, $this->certificate, 2, true, true);
        $this->get($this->publicUrl())->assertInertia(fn (Assert $page) => $page->where('public_enabled', true)->missing('certificate.public_withdrawn_at')->missing('certificate.revocation_reason'));
    }

    public function test_stale_form_or_same_visibility_does_not_create_duplicate_events(): void
    {
        $this->close();
        $this->actingAs($this->owner)->post($this->url(), ['revision' => 1, 'enabled' => true, 'public_consent' => true])->assertSessionHasErrors('certificate');
        $this->post($this->url(), ['revision' => 2, 'enabled' => false])->assertSessionHasErrors('certificate');
        $this->assertDatabaseCount('certificate_visibility_events', 1);
        $this->assertSame(2, $this->certificate->fresh()->revision);
    }

    public function test_visibility_change_invalidates_old_admin_revocation_form_and_never_resurrects_revoked_status(): void
    {
        $this->close();
        $this->actingAs($this->admin)->post('/admin/certificates/'.$this->certificate->id.'/revoke', ['revision' => 1, 'reason' => 'فرم قدیمی'])->assertSessionHasErrors('certificate');
        app(Certification::class)->revoke($this->admin, $this->certificate, 2, 'بازبینی معتبر');
        app(Certification::class)->visibility($this->owner, $this->certificate, 3, true, true);
        $this->assertSame('revoked', $this->certificate->fresh()->status);
        $this->get($this->publicUrl())->assertInertia(fn (Assert $page) => $page->where('certificate.status', 'revoked')->where('public_enabled', true));
    }

    public function test_deactivated_owner_cannot_access_private_document_through_public_route(): void
    {
        $this->close();
        $this->owner->forceFill(['is_active' => false])->save();
        $this->actingAs($this->owner)->get($this->publicUrl())->assertNotFound();
    }

    public function test_public_flag_without_consented_timestamp_does_not_disclose_document(): void
    {
        $this->certificate->update(['public_enabled' => true, 'public_consent_at' => null]);
        $this->get($this->publicUrl())->assertNotFound();
    }

    public function test_owner_gets_visibility_history_but_other_users_cannot_view_its_enrollment_page(): void
    {
        $this->close();
        $this->actingAs($this->owner)->get('/certificates/enrollments/'.$this->certificate->enrollment_id)->assertInertia(fn (Assert $page) => $page->where('can_manage_visibility', true)->where('visibility_events.0.enabled', false)->where('visibility_events.0.revision', 2));
        $this->actingAs(User::factory()->create())->get('/certificates/enrollments/'.$this->certificate->enrollment_id)->assertForbidden();
    }

    public function test_migration_reconstructs_legacy_public_consent_from_original_request(): void
    {
        $migration = require database_path('migrations/2026_11_15_213205_add_public_visibility_to_course_certificates.php');
        $migration->down();
        $migration->up();
        $this->assertTrue($this->certificate->fresh()->public_enabled);
        $this->assertTrue($this->certificate->fresh()->public_consent_at->equalTo($this->certificate->public_consent_at));
        $this->assertDatabaseCount('course_certificates', 1);
    }

    public function test_rollback_refuses_to_erase_user_privacy_choices(): void
    {
        $this->close();
        $migration = require database_path('migrations/2026_11_15_213205_add_public_visibility_to_course_certificates.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
