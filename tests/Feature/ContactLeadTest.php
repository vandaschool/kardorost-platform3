<?php

namespace Tests\Feature;

use App\Models\ContactLead;
use App\Models\Role;
use App\Models\User;
use App\Services\ContactLeads;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContactLeadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Queue::fake();
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('key', 'admin')->value('id'));
    }

    private function input(array $extra = []): array
    {
        return [...['request_key' => (string) Str::uuid(), 'name' => 'مخاطب آزمایشی', 'phone' => '۰۹۱۲۱۱۱۲۲۲۲', 'audience' => 'individual', 'subject' => 'training', 'message' => 'این درخواست فقط برای آزمون ثبت ارتباط و پیگیری است.', 'consent' => true], ...$extra];
    }

    private function lead(array $extra = []): ContactLead
    {
        return app(ContactLeads::class)->receive($this->input($extra));
    }

    public function test_public_form_has_csrf_unique_request_key_no_account_requirement_and_site_links(): void
    {
        $response = $this->get('/contact?audience=university')->assertOk()->assertSee('بدون ساخت حساب')->assertSee('name="_token"', false)->assertSee('name="request_key"', false)->assertSee('value="university" selected', false);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/')->assertSee('/contact?audience=company', false);
        $this->get('/sitemap.xml')->assertSee('/contact');
    }

    public function test_anonymous_submission_records_consent_normalized_phone_and_no_account_or_outbound_message(): void
    {
        $users = User::count();
        $this->post('/contact', $this->input())->assertRedirect('/contact')->assertSessionHas('contact_success');
        $lead = ContactLead::sole();
        $this->assertSame('989121112222', $lead->phone);
        $this->assertSame('new', $lead->status);
        $this->assertSame(1, $lead->revision);
        $this->assertNotNull($lead->consented_at);
        $this->assertSame('contact_follow_up_v1', $lead->consent_version);
        $this->assertDatabaseCount('contact_lead_events', 1);
        $this->assertSame($users, User::count());
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_company_and_university_require_organization_name_while_individual_does_not(): void
    {
        foreach (['company', 'university'] as $audience) {
            $this->post('/contact', $this->input(['audience' => $audience]))->assertSessionHasErrors('organization_name');
        }
        $this->post('/contact', $this->input())->assertSessionHasNoErrors();
        $this->post('/contact', $this->input(['audience' => 'university', 'organization_name' => 'دانشگاه صرفاً آزمایشی']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('contact_leads', 2);
    }

    public function test_consent_and_valid_payload_are_required_without_partial_records(): void
    {
        foreach ([['consent' => false], ['phone' => '123'], ['message' => 'کم'], ['audience' => 'admin'], ['request_key' => '1'], ['name' => ' a '], ['message' => str_repeat('a', 4001)]] as $bad) {
            try {
                $this->lead($bad);
                $this->fail('Invalid input was accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('contact_leads', 0);
                $this->assertDatabaseCount('contact_lead_events', 0);
            }
        }
    }

    public function test_malformed_utf8_cannot_poison_the_private_admin_queue(): void
    {
        foreach (['name', 'phone', 'organization_name', 'message'] as $key) {
            try {
                $this->lead([$key => "invalid\xFFtext"]);
                $this->fail('Malformed UTF-8 was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($key, $exception->errors());
                $this->assertDatabaseCount('contact_leads', 0);
            }
        }
    }

    public function test_idempotent_replay_does_not_duplicate_or_overwrite_original_contact_data(): void
    {
        $input = $this->input();
        $first = app(ContactLeads::class)->receive($input);
        $second = app(ContactLeads::class)->receive([...$input, 'phone' => '09123334444', 'name' => 'نام جایگزین', 'message' => 'این پیام نباید متن درخواست اولیه را بازنویسی کند.']);
        $this->assertSame($first->id, $second->id);
        $this->assertSame('مخاطب آزمایشی', $second->name);
        $this->assertSame('989121112222', $second->phone);
        $this->assertDatabaseCount('contact_leads', 1);
        $this->assertDatabaseCount('contact_lead_events', 1);
    }

    public function test_honeypot_uses_generic_response_without_storing_a_lead(): void
    {
        $this->post('/contact', [...$this->input(), 'website' => 'https://bot.invalid'])->assertRedirect('/contact')->assertSessionHas('contact_success');
        $this->assertDatabaseCount('contact_leads', 0);
        $this->assertDatabaseCount('contact_lead_events', 0);
    }

    public function test_public_post_rate_limit_rejects_sixth_request_before_storing_it(): void
    {
        for ($index = 0; $index < 5; $index++) {
            $this->post('/contact', $this->input())->assertRedirect('/contact');
        }
        $this->post('/contact', $this->input())->assertTooManyRequests();
        $this->assertDatabaseCount('contact_leads', 5);
    }

    public function test_private_contact_fields_and_follow_up_notes_are_encrypted_at_rest(): void
    {
        $lead = $this->lead(['audience' => 'company', 'organization_name' => 'مجموعه آزمایشی محرمانه']);
        $raw = DB::table('contact_leads')->find($lead->id);
        foreach (['name', 'phone', 'organization_name', 'message'] as $key) {
            $this->assertNotSame($lead->{$key}, $raw->{$key});
        }
        app(ContactLeads::class)->followUp($this->admin, $lead, ['revision' => 1, 'status' => 'working', 'note' => 'توضیح خصوصی پیگیری']);
        $this->assertStringNotContainsString('توضیح خصوصی پیگیری', DB::table('contact_lead_events')->latest('id')->value('note'));
        $this->assertArrayNotHasKey('phone_fingerprint', $lead->toArray());
    }

    public function test_only_current_admin_can_read_or_follow_up_contact_requests(): void
    {
        $lead = $this->lead();
        $this->get('/admin/contact-leads')->assertRedirect('/login');
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $peer = User::factory()->create();
            $peer->roles()->attach(Role::where('key', $role)->value('id'));
            $this->actingAs($peer)->get('/admin/contact-leads/'.$lead->id)->assertForbidden()->assertDontSee($lead->message);
            $this->post('/admin/contact-leads/'.$lead->id.'/follow-up', ['revision' => 1, 'status' => 'closed', 'note' => 'دسترسی غیرمجاز'])->assertForbidden();
        }
        $this->admin->roles()->detach();
        $this->actingAs($this->admin)->get('/admin/contact-leads')->assertForbidden();
    }

    public function test_admin_queue_omits_message_until_detail_and_never_exposes_fingerprints_or_request_keys(): void
    {
        $lead = $this->lead();
        $response = $this->actingAs($this->admin)->get('/admin/contact-leads')->assertInertia(fn (Assert $page) => $page->where('leads.total', 1)->missing('leads.data.0.message')->missing('leads.data.0.request_key')->missing('leads.data.0.phone_fingerprint'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/admin/contact-leads/'.$lead->id)->assertInertia(fn (Assert $page) => $page->where('selected.message', $lead->message)->where('events.data.0.actor_name', 'فرم عمومی')->missing('selected.request_key')->missing('selected.phone_fingerprint'));
        $this->assertDatabaseHas('audit_events', ['action' => 'contact_lead.viewed', 'subject_id' => $lead->id]);
    }

    public function test_follow_up_supports_notes_without_status_change_and_preserves_all_revisions(): void
    {
        $lead = $this->lead();
        $url = '/admin/contact-leads/'.$lead->id.'/follow-up';
        $this->actingAs($this->admin)->post($url, ['revision' => 1, 'status' => 'working', 'note' => 'هماهنگی اولیه در دست بررسی است'])->assertSessionHasNoErrors();
        $this->post($url, ['revision' => 2, 'status' => 'working', 'note' => 'یادداشت دوم بدون تغییر وضعیت'])->assertSessionHasNoErrors();
        $this->assertSame(3, $lead->fresh()->revision);
        $this->assertSame('working', $lead->fresh()->status);
        $this->assertDatabaseCount('contact_lead_events', 3);
        $last = $lead->events()->latest('id')->firstOrFail();
        $this->assertSame('working', $last->from_status);
        $this->assertSame('working', $last->to_status);
        $this->assertSame($this->admin->id, $last->actor_id);
    }

    public function test_stale_follow_up_cannot_overwrite_another_admin_decision(): void
    {
        $lead = $this->lead();
        app(ContactLeads::class)->followUp($this->admin, $lead, ['revision' => 1, 'status' => 'closed', 'note' => 'پیگیری بسته شد']);
        $this->actingAs($this->admin)->post('/admin/contact-leads/'.$lead->id.'/follow-up', ['revision' => 1, 'status' => 'qualified', 'note' => 'فرم قدیمی باید رد شود'])->assertSessionHasErrors('lead');
        $this->assertSame('closed', $lead->fresh()->status);
        $this->assertDatabaseCount('contact_lead_events', 2);
        $this->assertSame('پیگیری بسته شد', $lead->events()->latest('id')->firstOrFail()->note);
    }

    public function test_exact_phone_filter_normalizes_persian_and_country_formats(): void
    {
        $this->lead(['audience' => 'company', 'organization_name' => 'مجموعه اول']);
        $this->lead(['phone' => '09123334444', 'audience' => 'university', 'organization_name' => 'دانشگاه دوم']);
        $this->actingAs($this->admin)->get('/admin/contact-leads?status=all&phone='.urlencode('+98 912 111 2222'))->assertInertia(fn (Assert $page) => $page->where('leads.total', 1)->where('leads.data.0.audience', 'company'));
        $this->get('/admin/contact-leads?status=all&audience=university')->assertInertia(fn (Assert $page) => $page->where('leads.total', 1)->where('leads.data.0.organization_name', 'دانشگاه دوم'));
    }

    public function test_matching_existing_verified_phone_and_qualified_state_do_not_create_membership_or_send_sms(): void
    {
        $user = User::factory()->create(['phone' => '989121112222']);
        $lead = $this->lead(['status' => 'qualified', 'user_id' => $user->id, 'role' => 'admin']);
        $this->assertSame('new', $lead->status);
        app(ContactLeads::class)->followUp($this->admin, $lead, ['revision' => 1, 'status' => 'qualified', 'note' => 'فقط پیگیری دستی این درخواست']);
        $this->assertFalse($user->hasRole('admin'));
        $this->assertDatabaseCount('organizations', 0);
        $this->assertDatabaseCount('organization_user', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_public_old_input_and_private_messages_escape_markup(): void
    {
        $name = '<script>contactUnsafe()</script>';
        $this->post('/contact', $this->input(['name' => $name, 'consent' => false]))->assertSessionHasErrors('consent');
        $this->get('/contact')->assertSee('&lt;script&gt;contactUnsafe()', false)->assertDontSee('<script>contactUnsafe()', false);
        $lead = $this->lead(['name' => $name, 'message' => '<script>privateUnsafe()</script> متن کافی برای ثبت درخواست']);
        $this->actingAs($this->admin)->get('/admin/contact-leads/'.$lead->id)->assertInertia(fn (Assert $page) => $page->where('selected.name', $name));
    }

    public function test_migration_refuses_to_destroy_consent_and_follow_up_history(): void
    {
        $this->lead();
        $migration = require database_path('migrations/2026_11_15_222011_create_contact_leads.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
