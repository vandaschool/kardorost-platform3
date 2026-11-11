<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function student(): User
    {
        $user = User::factory()->create(['password' => 'current-password']);
        $user->roles()->attach(Role::where('key', 'student')->value('id'));

        return $user;
    }

    public function test_password_change_requires_current_password(): void
    {
        $user = $this->student();
        $this->actingAs($user)->patch('/account/password', ['current_password' => 'wrong', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('current-password', $user->fresh()->password));
    }

    public function test_valid_password_change_replaces_hash(): void
    {
        $user = $this->student();
        $this->actingAs($user)->patch('/account/password', ['current_password' => 'current-password', 'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseHas('audit_events', ['action' => 'password.changed']);
    }

    public function test_private_pages_are_not_cacheable(): void
    {
        $this->actingAs($this->student())->get('/student')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_csrf_protection_is_active_outside_test_environment(): void
    {
        app()->detectEnvironment(fn () => 'local');
        $this->post('/login', ['phone' => '09123456789', 'password' => 'secret123'])->assertStatus(419);
    }
}
