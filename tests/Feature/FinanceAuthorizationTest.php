<?php

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\BillingService;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinanceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function user(string $role = 'student'): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', $role)->value('id'));

        return $u;
    }

    private function enrollment(User $u): Enrollment
    {
        $c = Cohort::factory()->create(['course_id' => Course::factory()->create(['price' => 1000000])->id]);

        return app(EnrollmentService::class)->reserve($u, $c);
    }

    public function test_financial_page_only_returns_owners_invoices_and_payments(): void
    {
        $a = $this->user();
        $b = $this->user();
        $e = $this->enrollment($a);
        $other = $this->enrollment($b);
        $i = $e->installments()->first();
        $j = $other->installments()->first();
        app(BillingService::class)->attempt($b, $j);
        $this->actingAs($a)->get('/finance')->assertOk()->assertInertia(fn (Assert $p) => $p->component('Finance')->has('enrollments', 1)->where('enrollments.0.id', $e->id)->has('payments.data', 0));
        $this->post('/installments/'.$j->id.'/pay')->assertForbidden();
    }

    public function test_admin_finance_and_recovery_are_not_available_to_other_roles(): void
    {
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->get('/admin/finance')->assertForbidden();
        }
    }

    public function test_admin_dashboard_distinguishes_allocated_and_unallocated_funds(): void
    {
        $u = $this->user();
        $e = $this->enrollment($u);
        $i = $e->installments()->first();
        $a = Payment::factory()->create(['user_id' => $u->id, 'enrollment_id' => $e->id, 'installment_id' => $i->id, 'amount' => $i->amount]);
        $b = Payment::factory()->create(['user_id' => $u->id, 'enrollment_id' => $e->id, 'installment_id' => $i->id, 'amount' => $i->amount]);
        app(BillingService::class)->capture($a, 'valid-a');
        app(BillingService::class)->capture($b, 'extra-b');
        $this->actingAs($this->user('admin'))->get('/admin/finance?tab=payments')->assertOk()->assertInertia(fn (Assert $p) => $p->component('AdminFinance')->where('stats.allocated', 1000000)->where('stats.unallocated', 1000000));
    }

    public function test_expired_unpaid_reservation_is_not_counted_as_student_debt(): void
    {
        $u = $this->user();
        $e = $this->enrollment($u);
        $e->installments()->update(['due_date' => now()->subDay()->toDateString()]);
        $e->update(['expires_at' => now()->subHour()]);
        $this->actingAs($this->user('admin'))->get('/admin/finance?tab=debts')->assertInertia(fn (Assert $p) => $p->has('rows.data', 0));
    }

    public function test_unverified_payment_cannot_be_marked_paid_by_recovery_action(): void
    {
        $u = $this->user();
        $e = $this->enrollment($u);
        $i = $e->installments()->first();
        $p = Payment::factory()->create(['user_id' => $u->id, 'enrollment_id' => $e->id, 'installment_id' => $i->id, 'amount' => $i->amount, 'status' => 'review_required']);
        $this->actingAs($this->user('admin'))->post('/admin/finance/payments/'.$p->id.'/allocate')->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_admin_debt_list_contains_only_current_unpaid_invoice_of_active_student(): void
    {
        $u = $this->user();
        $e = $this->enrollment($u);
        $invoice = $e->installments()->first();
        $invoice->update(['due_date' => now()->subDay()->toDateString()]);
        $e->update(['status' => 'active', 'expires_at' => null]);
        $this->actingAs($this->user('admin'))->get('/admin/finance?tab=debts')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.id', $invoice->id)->where('rows.data.0.amount',1000000));
    }
}
