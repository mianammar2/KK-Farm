<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-244: Settings → Users and Sign-In. */
class UsersPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $org = Organization::create(['name' => 'User Farm', 'slug' => 'user-farm']);
        Tenant::set($org->id);
        Department::seedDefaults((string) $org->id);
        $this->owner = $this->make('Owner One', 'owner', 'ceo', ['last_login_at' => now()->subHour()]);
    }

    private function make(string $name, string $login, string $role, array $more = []): User
    {
        return User::create($more + ['name' => $name, 'email' => $login, 'password' => Hash::make('secret-pass-1'), 'role' => $role, 'org_id' => Tenant::current(), 'active' => true, 'department_id' => Department::forRole($role)]);
    }

    private function page(string $query = '')
    {
        return $this->actingAs($this->owner)->get('/settings/users'.$query);
    }

    public function test_filters_search_and_summary_match_the_data(): void
    {
        $this->make('Sana Vet', 'dr.sana', 'vet', ['last_login_at' => now()->subDays(2), 'contact_email' => 'sana@example.com']);
        $this->make('Ali Care', 'ali', 'caretaker');
        $this->make('Bilal Off', 'bilal', 'cleaning', ['active' => false, 'last_login_at' => now()->subWeek()]);
        $this->make('Hina Fin', 'hina', 'finance', ['module_access' => ['goats' => true], 'last_login_at' => now()]);

        $this->page()->assertInertia(fn (AssertableInertia $p) => $p->component('Settings/Users')->has('users', 5)
            ->where('summary', ['total' => 5, 'active' => 4, 'disabled' => 1, 'never' => 1, 'custom' => 1, 'activeAdmins' => 1]));
        $this->page('?q=SANA')->assertInertia(fn ($p) => $p->where('users.0.name', 'Sana Vet')->has('users', 1));
        $this->page('?q=sana@example')->assertInertia(fn ($p) => $p->has('users', 1));
        $this->page('?role=caretaker')->assertInertia(fn ($p) => $p->where('users.0.name', 'Ali Care')->has('users', 1));
        $this->page('?status=disabled')->assertInertia(fn ($p) => $p->where('users.0.name', 'Bilal Off')->has('users', 1));
        $this->page('?status=never')->assertInertia(fn ($p) => $p->where('users.0.name', 'Ali Care')->has('users', 1));
        $this->page('?status=custom')->assertInertia(fn ($p) => $p->where('users.0.name', 'Hina Fin')->has('users', 1));
        $this->page('?dept='.Department::forRole('vet'))->assertInertia(fn ($p) => $p->where('users.0.name', 'Sana Vet')->has('users', 1));
        $this->page('?sort=last')->assertInertia(fn ($p) => $p->where('users', fn ($u) => collect($u)->pluck('name')->first() === 'Hina Fin' && collect($u)->pluck('name')->last() === 'Ali Care'));
        $this->page('?q=nobody')->assertInertia(fn ($p) => $p->has('users', 0)->where('summary.total', 5)->where('filters.q', 'nobody'));
    }

    public function test_the_page_and_its_api_are_for_the_administrator_only_and_send_no_secrets(): void
    {
        $mgr = $this->make('Mo Manager', 'mo', 'manager', ['module_access' => ['settings' => true]]);
        Tenant::flush();
        foreach (['/settings/users', "/settings/users/{$this->owner->id}/details", '/settings/users/check-login?login=x'] as $url) {
            $this->actingAs($mgr)->get($url)->assertForbidden();
        }
        $this->actingAs($mgr)->post("/settings/users/{$this->owner->id}", ['role' => 'manager'])->assertForbidden();
        $this->actingAs($mgr)->post('/settings/users/bulk', ['ids' => [$this->owner->id], 'action' => 'disable'])->assertForbidden();
        $this->actingAs($mgr)->post("/settings/users/{$this->owner->id}/reset-password")->assertForbidden();

        $json = json_encode($this->page()->viewData('page'));
        foreach (['password', 'remember_token', '$2y$', 'module_access'] as $secret) {
            $this->assertStringNotContainsString($secret, str_replace('"tempPassword":null', '', $json), $secret);
        }
        $details = $this->actingAs($this->owner)->getJson("/settings/users/{$mgr->id}/details")->assertOk()->json();
        $this->assertStringNotContainsString('$2y$', json_encode($details));
        $this->assertSame([['kind' => 'App', 'label' => 'Settings', 'given' => true]], $details['custom']);
    }

    public function test_guards_hold_for_the_screen_and_for_direct_calls(): void
    {
        // yourself
        $this->actingAs($this->owner)->post("/settings/users/{$this->owner->id}", ['role' => 'manager'])->assertSessionHasErrors('role');
        $this->actingAs($this->owner)->post("/settings/users/{$this->owner->id}", ['active' => false])->assertSessionHasErrors('role');
        $this->actingAs($this->owner)->post('/settings/users/bulk', ['ids' => [$this->owner->id], 'action' => 'disable'])->assertSessionHasErrors('bulk');
        $this->assertTrue($this->owner->fresh()->active);
        // the last active Administrator, changed by another Administrator who is then disabled
        $second = $this->make('Second Owner', 'second', 'ceo');
        $this->actingAs($second)->post("/settings/users/{$this->owner->id}", ['active' => false])->assertSessionHasNoErrors();
        $this->assertFalse($this->owner->fresh()->active);
        $this->actingAs($second)->post('/settings/users/bulk', ['ids' => [$second->id], 'action' => 'disable'])->assertSessionHasErrors('bulk');
        $this->owner = $this->owner->fresh();
        $this->owner->forceFill(['active' => true])->save();
        $this->actingAs($this->owner)->post("/settings/users/{$second->id}", ['role' => 'vet'])->assertSessionHasNoErrors();
        $this->assertSame('vet', $second->fresh()->role);
        $this->assertTrue(Activity::where('action', 'like', 'Changed the role of Second Owner: Administrator → Veterinarian%')->exists());
    }

    public function test_profile_changes_role_preview_and_unique_username(): void
    {
        $ali = $this->make('Ali Care', 'ali', 'caretaker');
        $this->page()->assertInertia(fn ($p) => $p->where('users', fn ($u) => ($r = collect($u)->firstWhere('login', 'ali')) && in_array('Livestock (goat register)', $r['diffs']['finance']['lose'], true))
            ->where('roleHome.finance', 'My Home')->where('roleHome.manager', 'Executive Dashboard'));
        $this->actingAs($this->owner)->getJson('/settings/users/check-login?login=OWNER')->assertJson(['free' => false, 'valid' => true]);
        $this->actingAs($this->owner)->getJson("/settings/users/check-login?login=ali&except={$ali->id}")->assertJson(['free' => true]);
        $this->actingAs($this->owner)->getJson('/settings/users/check-login?login=bad%20name')->assertJson(['valid' => false]);
        $this->actingAs($this->owner)->post("/settings/users/{$ali->id}", ['login' => 'OWNER'])->assertSessionHasErrors(['login' => 'This username is already taken.']);
        $this->actingAs($this->owner)->post("/settings/users/{$ali->id}", ['name' => 'Ali Raza', 'login' => 'Ali.Raza', 'email' => 'ali@example.com'])->assertSessionHasNoErrors();
        $this->assertSame(['Ali Raza', 'ali.raza', 'ali@example.com'], [$ali->fresh()->name, $ali->fresh()->email, $ali->fresh()->contact_email]);
        $this->assertTrue(Activity::where('audience', 'security')->where('action', 'Changed the username of Ali Care: ali → ali.raza')->exists());

        $this->actingAs($this->owner)->post('/settings/users', ['name' => 'New Person', 'login' => 'ali.raza', 'role' => 'feeding', 'password' => 'temp-pass-1'])->assertSessionHasErrors('login');
        $this->actingAs($this->owner)->post('/settings/users', ['name' => 'New Person', 'login' => 'newbie', 'email' => 'n@example.com', 'role' => 'feeding', 'password' => 'temp-pass-1'])->assertSessionHasNoErrors();
        $new = User::where('email', 'newbie')->sole();
        $this->assertSame([true, Department::forRole('feeding'), 'n@example.com'], [$new->must_change_password, $new->department_id, $new->contact_email]);
    }

    public function test_a_reset_password_is_shown_once_and_must_be_changed(): void
    {
        $ali = $this->make('Ali Care', 'ali', 'caretaker');
        DB::table('sessions')->insert(['id' => 'sess-ali', 'user_id' => $ali->id, 'payload' => 'x', 'last_activity' => time()]);
        $this->actingAs($this->owner)->post("/settings/users/{$ali->id}/reset-password")->assertRedirect();
        $temp = session('temp_password');
        $this->assertSame(['id' => $ali->id, 'name' => 'Ali Care', 'login' => 'ali'], array_intersect_key($temp, array_flip(['id', 'name', 'login'])));
        $this->assertTrue(Hash::check($temp['password'], $ali->fresh()->password));
        $this->assertTrue($ali->fresh()->must_change_password);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $ali->id)->count(), 'signed out everywhere');
        $this->actingAs($this->owner)->get('/settings/users')->assertInertia(fn ($p) => $p->where('tempPassword.password', $temp['password']));
        $this->actingAs($this->owner)->get('/settings/users')->assertInertia(fn ($p) => $p->where('tempPassword', null)); // only once
        $this->assertTrue(Activity::where('action', 'like', 'Reset the password of Ali Care%')->exists());
        $this->assertFalse(Activity::where('action', 'like', "%{$temp['password']}%")->exists());

        auth()->logout();
        $this->post('/login', ['login' => 'ali', 'password' => $temp['password']])->assertRedirect('/account/password');
    }

    public function test_bulk_changes_and_disabling_signs_out(): void
    {
        $a = $this->make('Ali Care', 'ali', 'caretaker');
        $b = $this->make('Bilal Clean', 'bilal', 'cleaning');
        DB::table('sessions')->insert(['id' => 'sess-b', 'user_id' => $b->id, 'payload' => 'x', 'last_activity' => time()]);
        $feeding = Department::forRole('feeding');
        $this->actingAs($this->owner)->post('/settings/users/bulk', ['ids' => [$a->id, $b->id], 'action' => 'department', 'department_id' => $feeding])->assertSessionHasNoErrors();
        $this->assertSame([$feeding, $feeding], [$a->fresh()->department_id, $b->fresh()->department_id]);
        $this->actingAs($this->owner)->post('/settings/users/bulk', ['ids' => [$b->id], 'action' => 'disable'])->assertSessionHasNoErrors();
        $this->assertFalse($b->fresh()->active);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $b->id)->count());
        $this->actingAs($this->owner)->post('/settings/users/bulk', ['ids' => [$b->id], 'action' => 'enable'])->assertSessionHasNoErrors();
        $this->assertTrue($b->fresh()->active);
        $this->actingAs($this->owner)->post('/settings/users/bulk', ['ids' => [$b->id], 'action' => 'role'])->assertSessionHasErrors('action'); // no bulk role changes
        $this->assertSame(4, Activity::where('audience', 'security')->where(fn ($q) => $q->where('action', 'like', 'Changed the department%')->orWhere('action', 'like', 'Changed the sign-in%'))->count()); // 2 moves, off, on
    }

    public function test_a_department_is_deleted_only_when_empty_and_without_open_tasks(): void
    {
        $vet = Department::where('name', 'Veterinary')->sole();
        $this->actingAs($this->owner)->delete("/settings/departments/{$vet->id}")->assertSessionHasNoErrors(); // empty: no vet user here
        $this->assertNull(Department::find($vet->id));

        $care = Department::where('name', 'Caretaking')->sole();
        $ali = $this->make('Ali Care', 'ali', 'caretaker');
        $this->actingAs($this->owner)->delete("/settings/departments/{$care->id}")->assertSessionHasErrors(['department' => 'Caretaking cannot be deleted: 1 person still in it. Move them first.']);
        $ali->forceFill(['department_id' => null])->save();
        Task::create(['title' => 'Weigh kids', 'type' => 'other', 'department_id' => $care->id, 'due_on' => now(), 'priority' => 'medium', 'status' => 'open']);
        $this->actingAs($this->owner)->delete("/settings/departments/{$care->id}")->assertSessionHasErrors('department');
        $this->assertNotNull(Department::find($care->id));
        $this->page('?tab=departments')->assertInertia(fn ($p) => $p->where('departments', fn ($d) => ($c = collect($d)->firstWhere('name', 'Caretaking')) && $c['openTasks'] === 1 && $c['members'] === []));

        $this->actingAs($this->owner)->post("/settings/departments/{$care->id}", ['name' => 'Animal Care'])->assertSessionHasNoErrors();
        $this->assertTrue(Activity::where('action', 'Renamed department Caretaking → Animal Care')->exists());
    }
}
