<?php

namespace Tests\Feature;

use App\Http\Controllers\BillingPlanController;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\EnrollmentConsultationBenefit;
use App\Models\Role;
use App\Models\User;
use App\Services\CourseConsultationBenefits;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EnrollmentCurrentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['platform.finance.transfer_destination' => '6037991234567890']);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function fixture(string $scenario): array
    {
        $student = $this->user('student');
        $admin = $this->user('admin');
        $coach = $this->user('coach');
        $cohort = Cohort::factory()->create(['course_id' => Course::factory()->state(['price' => in_array($scenario, ['free_new', 'active_reuse'], true) ? 0 : 1000000, 'is_published' => true]), 'capacity' => 10]);
        if ($cohort->course->price > 0) {
            app(CourseConsultationBenefits::class)->configure($admin, $cohort, ['revision' => 0, 'enabled' => true, 'coach_id' => $coach->id, 'title' => 'Software-only signup promise', 'sessions' => 2, 'session_minutes' => 60, 'valid_days' => 30, 'activation' => 'first_payment']);
        }
        $enrollment = in_array($scenario, ['held_reuse', 'expired_renew', 'active_reuse'], true) ? app(EnrollmentService::class)->reserve($student, $cohort) : null;
        if ($scenario === 'expired_renew') {
            $enrollment->update(['expires_at' => now()->subMinute()]);
        }

        return [$student, $admin, $coach, $cohort, $enrollment];
    }

    private function snapshot(): string
    {
        $state = [];
        foreach (['cohorts', 'cohort_installments', 'enrollments', 'installments', 'payments', 'cohort_consultation_benefits', 'enrollment_consultation_benefits', 'consultation_grants', 'audit_events', 'notifications'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public static function scenarios(): array
    {
        return array_map(fn ($scenario) => [$scenario], ['free_new', 'paid_new', 'held_reuse', 'expired_renew', 'active_reuse']);
    }

    public static function accessChanges(): array
    {
        $cases = [];
        foreach (self::scenarios() as [$scenario]) {
            foreach (['activity', 'role', 'phone'] as $change) {
                $cases[$scenario.'_'.$change] = [$scenario, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('accessChanges')]
    public function test_current_signup_eligibility_is_required_even_with_a_stale_account_or_existing_quote(string $scenario, string $change): void
    {
        [$student, $admin, $coach, $cohort] = $this->fixture($scenario);
        match ($change) {
            'activity' => User::whereKey($student->id)->update(['is_active' => false]),
            'phone' => User::whereKey($student->id)->update(['phone_verified_at' => null]),
            'role' => $student->roles()->sync([Role::where('key', 'coach')->value('id')]),
        };
        $before = $this->snapshot();
        try {
            app(EnrollmentService::class)->reserve($student, $cohort);
            $this->fail('Current active verified student eligibility is required for signup.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    #[DataProvider('scenarios')]
    public function test_current_student_can_signup_or_reuse_without_rewriting_frozen_quotes(string $scenario): void
    {
        [$student, $admin, $coach, $cohort, $existing] = $this->fixture($scenario);
        $before = $this->snapshot();
        $result = app(EnrollmentService::class)->reserve($student, $cohort);
        $this->assertSame($student->id, $result->user_id);
        $this->assertSame(in_array($scenario, ['free_new', 'active_reuse'], true) ? 'active' : 'pending_payment', $result->status);
        if (in_array($scenario, ['held_reuse', 'active_reuse'], true)) {
            $this->assertSame($existing->id, $result->id);
            $this->assertSame($before, $this->snapshot());
        }
        if ($scenario === 'expired_renew') {
            $this->assertSame($existing->id, $result->id);
            $this->assertSame(2, $result->billing_version);
            $this->assertSame(1, $result->installments()->where('billing_version', 1)->whereNotNull('voided_at')->count());
            $this->assertDatabaseHas('enrollment_consultation_benefits', ['enrollment_id' => $result->id, 'billing_version' => 1, 'status' => 'superseded']);
        }
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_held_quote_reuse_preserves_original_benefit_when_future_cohort_coach_is_unavailable(): void
    {
        [$student, $admin, $coach, $cohort, $existing] = $this->fixture('held_reuse');
        $futureCoach = $this->user('coach');
        app(CourseConsultationBenefits::class)->configure($admin, $cohort, ['revision' => 1, 'enabled' => true, 'coach_id' => $futureCoach->id, 'title' => 'Software-only next signup promise', 'sessions' => 3, 'session_minutes' => 60, 'valid_days' => 30, 'activation' => 'first_payment']);
        User::whereKey($futureCoach->id)->update(['is_active' => false]);
        $before = $this->snapshot();
        $this->assertSame($existing->id, app(EnrollmentService::class)->reserve($student, $cohort)->id);
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseHas('enrollment_consultation_benefits', ['enrollment_id' => $existing->id, 'coach_id' => $coach->id]);
        Http::assertNothingSent();
    }

    private function planData(string $action, int $revision = 0): array
    {
        return ['expected_revision' => $revision, 'installment_enabled' => $action !== 'manual', 'manual_payment_enabled' => $action === 'manual', 'parts' => $action === 'manual' ? [] : [['amount' => $action === 'replace' ? 50000 : 40000], ['amount' => $action === 'replace' ? 50000 : 60000, 'due_date' => now()->addMonth()->toDateString()]]];
    }

    private function updatePlan(User $actor, Cohort $cohort, array $data): void
    {
        $request = Request::create('/admin/cohorts/'.$cohort->id.'/billing', 'PATCH', $data);
        $request->setUserResolver(fn () => $actor);
        app(BillingPlanController::class)->update($request, $cohort);
    }

    public static function planActions(): array
    {
        return array_map(fn ($action) => [$action], ['installments', 'manual', 'replace']);
    }

    public static function planAccessChanges(): array
    {
        $cases = [];
        foreach (self::planActions() as [$action]) {
            foreach (['activity', 'role'] as $change) {
                $cases[$action.'_'.$change] = [$action, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('planAccessChanges')]
    public function test_current_admin_is_required_before_plan_write_using_a_stale_request_account(string $action, string $change): void
    {
        [$student, $admin, $coach, $cohort] = $this->fixture('paid_new');
        if ($action === 'replace') {
            $this->updatePlan($admin, $cohort, $this->planData('installments'));
        }
        if ($change === 'activity') {
            User::whereKey($admin->id)->update(['is_active' => false]);
        } else {
            $admin->roles()->detach();
        }
        $before = $this->snapshot();
        try {
            $this->updatePlan($admin, $cohort, $this->planData($action, $action === 'replace' ? 1 : 0));
            $this->fail('Current active admin is required for financial plan changes.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    #[DataProvider('planActions')]
    public function test_current_admin_can_change_future_plan_without_rewriting_existing_personal_quote(string $action): void
    {
        [$student, $admin, $coach, $cohort, $enrollment] = $this->fixture('held_reuse');
        $quote = [$enrollment->fresh()->getAttributes(), $enrollment->installments()->get()->toJson(), EnrollmentConsultationBenefit::where('enrollment_id', $enrollment->id)->orderBy('id')->get()->toJson()];
        if ($action === 'replace') {
            $this->updatePlan($admin, $cohort, $this->planData('installments'));
        }
        $this->updatePlan($admin, $cohort, $this->planData($action, $action === 'replace' ? 1 : 0));
        $this->assertSame($quote, [$enrollment->fresh()->getAttributes(), $enrollment->installments()->get()->toJson(), EnrollmentConsultationBenefit::where('enrollment_id', $enrollment->id)->orderBy('id')->get()->toJson()]);
        $this->assertSame($action !== 'manual', $cohort->fresh()->installment_enabled);
        $this->assertSame($action === 'manual', $cohort->fresh()->manual_payment_enabled);
        $this->assertSame($action === 'replace' ? 2 : 1, $cohort->fresh()->billing_revision);
        Http::assertNothingSent();
    }
}
