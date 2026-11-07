<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** D-044: real sign-in. Passwords, disabled accounts, temporary passwords, the owner managing users. */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'Farm', 'slug' => 'farm']);
        Tenant::set($this->org->id);
    }

    private function account(string $login, string $role = 'caretaker', array $extra = []): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $login, 'password' => Hash::make('correct-horse'), 'role' => $role, 'org_id' => $this->org->id] + $extra);
    }

    public function test_a_user_signs_in_with_username_and_password(): void
    {
        $user = $this->account('ali');

        $this->post('/login', ['login' => 'ALI', 'password' => 'correct-horse'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_a_wrong_password_or_unknown_user_is_refused_with_the_same_message(): void
    {
        $this->account('ali');

        $this->post('/login', ['login' => 'ali', 'password' => 'wrong'])->assertSessionHasErrors(['login' => 'The username or password is not correct.']);
        $this->post('/login', ['login' => 'nobody', 'password' => 'correct-horse'])->assertSessionHasErrors(['login' => 'The username or password is not correct.']);
        $this->assertGuest();
    }

    public function test_repeated_failures_are_slowed_down(): void
    {
        $this->account('ali');
        foreach (range(1, 5) as $i) {
            $this->post('/login', ['login' => 'ali', 'password' => "wrong-$i"]);
        }

        $this->post('/login', ['login' => 'ali', 'password' => 'correct-horse'])->assertSessionHasErrors('login');
        $this->assertStringContainsString('Too many attempts', session('errors')->first('login'));
        $this->assertGuest();
    }

    public function test_a_disabled_account_cannot_sign_in_and_is_signed_out(): void
    {
        $user = $this->account('ali', 'caretaker', ['active' => false]);
        $this->post('/login', ['login' => 'ali', 'password' => 'correct-horse'])->assertSessionHasErrors('login');

        $user->forceFill(['active' => true])->save();
        $this->actingAs($user)->get('/')->assertOk();
        $user->forceFill(['active' => false])->save();
        $this->actingAs($user->fresh())->get('/')->assertRedirect('/login');
    }

    public function test_the_role_picker_does_not_work_unless_enabled_for_local_testing(): void
    {
        $this->account('ali', 'ceo');
        config(['kkfarm.demo_login' => false]);
        $this->post('/login', ['role' => 'ceo'])->assertNotFound();
        $this->assertGuest();

        config(['kkfarm.demo_login' => true]); // allowed only because the environment is "testing"
        $this->post('/login', ['role' => 'ceo'])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_the_owner_adds_a_user_who_must_choose_a_password_on_first_sign_in(): void
    {
        $owner = $this->account('owner', 'ceo');
        $this->actingAs($owner)->post('/settings/users', ['name' => 'Dr Sana', 'login' => 'Dr.Sana', 'role' => 'vet', 'password' => 'temporary1'])->assertSessionHasNoErrors();
        $vet = User::where('email', 'dr.sana')->sole();
        $this->assertSame([$this->org->id, 'vet', true], [$vet->org_id, $vet->role, $vet->must_change_password]);
        auth()->logout();

        $this->post('/login', ['login' => 'dr.sana', 'password' => 'temporary1'])->assertRedirect('/account/password');
        $this->get('/health')->assertRedirect('/account/password');

        $this->post('/account/password', ['current_password' => 'temporary1', 'password' => 'my-own-pass', 'password_confirmation' => 'my-own-pass'])->assertRedirect('/');
        $this->assertFalse($vet->fresh()->must_change_password);
        $this->assertTrue(Hash::check('my-own-pass', $vet->fresh()->password));
        $this->get('/health')->assertOk();
    }

    public function test_the_owner_resets_a_password_changes_a_role_and_disables_an_account(): void
    {
        $owner = $this->account('owner', 'ceo');
        $staff = $this->account('ali');

        $this->actingAs($owner)->post("/settings/users/{$staff->id}", ['role' => 'manager', 'password' => 'reset-pass-1'])->assertSessionHasNoErrors();
        $staff->refresh();
        $this->assertSame('manager', $staff->role);
        $this->assertTrue($staff->must_change_password);
        $this->assertTrue(Hash::check('reset-pass-1', $staff->password));

        $this->actingAs($owner)->post("/settings/users/{$staff->id}", ['active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($staff->fresh()->active);
    }

    public function test_the_owner_cannot_lock_themself_out(): void
    {
        $owner = $this->account('owner', 'ceo');

        $this->actingAs($owner)->post("/settings/users/{$owner->id}", ['active' => false])->assertSessionHasErrors('role');
        $this->actingAs($owner)->post("/settings/users/{$owner->id}", ['role' => 'caretaker'])->assertSessionHasErrors('role');
        $this->assertTrue($owner->fresh()->active);
        $this->assertSame('ceo', $owner->fresh()->role);
    }

    public function test_only_the_owner_manages_users_and_only_in_their_own_organization(): void
    {
        $owner = $this->account('owner', 'ceo');
        $this->actingAs($this->account('manager1', 'manager'))->get('/settings/users')->assertForbidden();

        $other = Organization::create(['name' => 'Other', 'slug' => 'other']);
        $outsider = User::create(['name' => 'Outsider', 'email' => 'outsider', 'password' => Hash::make('x-x-x-x-x'), 'role' => 'caretaker', 'org_id' => $other->id]);

        $this->actingAs($owner)->get('/settings/users')->assertOk()->assertDontSee('outsider');
        $this->actingAs($owner)->post("/settings/users/{$outsider->id}", ['active' => false])->assertNotFound();
        $this->assertTrue($outsider->fresh()->active);
    }
}
