<?php

namespace Tests\Feature;

use App\Models\GunCommand;
use App\Models\GunDevice;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** D-354: a gun app reports itself; the Administrator sends it a command and sees the answer; other farms never see it. */
class GunRemoteTest extends TestCase
{
    use RefreshDatabase;

    private function org(string $slug): array
    {
        $org = Organization::create(['name' => $slug, 'slug' => $slug]);
        Tenant::set($org->id);
        $mk = fn ($role) => User::create(['name' => ucfirst($role)." {$slug}", 'email' => "{$role}@{$slug}.test", 'password' => 'x', 'role' => $role, 'org_id' => $org->id, 'active' => true]);

        return [$org, $mk('ceo'), $mk('caretaker')];
    }

    public function test_a_gun_reports_takes_a_command_and_answers(): void
    {
        [, $admin, $caretaker] = $this->org('gun-farm');
        $id = (string) Str::uuid();

        $this->actingAs($caretaker)->postJson('/gun/report', ['install_id' => $id, 'version' => '0.8.4', 'code' => 24, 'reader' => 'ready', 'state' => ['page' => '/apps', 'battery' => '80%']])
            ->assertOk()->assertJson(['commands' => [], 'every' => 60]);
        $gun = GunDevice::sole();
        $this->assertSame('Gun 1', $gun->label);
        $this->assertSame('Caretaker gun-farm', $gun->last_user);

        $this->actingAs($caretaker)->post("/settings/guns/{$gun->id}/commands", ['command' => 'test_read'])->assertForbidden();
        $this->actingAs($admin)->post("/settings/guns/{$gun->id}/commands", ['command' => 'write_tag'])->assertSessionHasErrors('command');
        $this->actingAs($admin)->post("/settings/guns/{$gun->id}/commands", ['command' => 'test_read'])->assertRedirect();
        $cmd = GunCommand::sole();

        $this->actingAs($caretaker)->postJson('/gun/report', ['install_id' => $id])
            ->assertJson(['commands' => [['id' => $cmd->id, 'command' => 'test_read']], 'every' => 2]);
        $this->assertSame('sent', $cmd->fresh()->status);
        $this->actingAs($caretaker)->postJson('/gun/report', ['install_id' => $id])->assertJson(['commands' => []]); // sent once only

        $this->actingAs($caretaker)->postJson('/gun/report', ['install_id' => $id, 'logs' => "line 1\nline 2", 'results' => [['id' => $cmd->id, 'ok' => true, 'text' => '42 reads, 7 tags']]])->assertOk();
        $this->assertSame('done', $cmd->fresh()->status);
        $this->assertSame('42 reads, 7 tags', $cmd->fresh()->result);

        $this->actingAs($admin)->get('/settings/guns')->assertInertia(fn ($p) => $p->component('Settings/Guns')->has('guns', 1)
            ->where('guns.0.reader', 'ready')->where('guns.0.online', true)->where('guns.0.logLines', 2)->where('guns.0.commands.0.status', 'done'));
        $this->actingAs($admin)->get("/settings/guns/{$gun->id}/logs")->assertOk()->assertSee('line 2');
        $this->actingAs($caretaker)->get('/settings/guns')->assertForbidden();

        $this->actingAs($admin)->post("/settings/guns/{$gun->id}", ['label' => 'Shed 1 gun'])->assertRedirect();
        $this->assertSame('Shed 1 gun', $gun->fresh()->label);
        $this->actingAs($admin)->delete("/settings/guns/{$gun->id}")->assertRedirect();
        $this->assertSame(0, GunDevice::count());
        $this->assertSame(0, GunCommand::count());
    }

    public function test_remote_screen_tap_and_image(): void
    {
        [, $admin, $caretaker] = $this->org('screen-farm');
        $id = (string) Str::uuid();
        $this->actingAs($caretaker)->postJson('/gun/report', ['install_id' => $id, 'can' => ['remote' => true]])->assertOk();
        $gun = GunDevice::sole();

        $this->actingAs($admin)->post("/settings/guns/{$gun->id}/commands", ['command' => 'tap', 'x' => 1.5, 'y' => 0.2])->assertSessionHasErrors('x');
        $this->actingAs($admin)->post("/settings/guns/{$gun->id}/commands", ['command' => 'tap', 'x' => 0.5, 'y' => 0.25])->assertRedirect();
        $this->actingAs($caretaker)->postJson('/gun/report', ['install_id' => $id])
            ->assertJson(['commands' => [['command' => 'tap', 'args' => ['x' => 0.5, 'y' => 0.25]]], 'every' => 2]);

        $jpeg = "\xFF\xD8\xFF\xE0".str_repeat('x', 50);
        $this->actingAs($caretaker)->postJson('/gun/screen', ['install_id' => $id, 'image' => base64_encode('not a jpeg'), 'w' => 720, 'h' => 1440])->assertStatus(422);
        $this->actingAs($caretaker)->postJson('/gun/screen', ['install_id' => $id, 'image' => base64_encode($jpeg), 'w' => 720, 'h' => 1440])->assertOk();
        $this->assertSame($jpeg, $this->actingAs($admin)->get("/settings/guns/{$gun->id}/screen")->assertOk()->assertHeader('Content-Type', 'image/jpeg')->getContent());
        $this->actingAs($caretaker)->get("/settings/guns/{$gun->id}/screen")->assertForbidden();
        $this->actingAs($admin)->get('/settings/guns')->assertInertia(fn ($p) => $p->where('guns.0.can.remote', true)->missing('guns.0.screen'));
    }

    public function test_the_gun_reports_with_its_own_key_after_the_first_signed_in_report(): void
    {
        [, $admin, $caretaker] = $this->org('key-farm');
        $id = (string) Str::uuid();
        $key = $this->actingAs($caretaker)->postJson('/gun/report', ['install_id' => $id])->assertOk()->json('key');
        $this->assertStringStartsWith('kkg_', $key);
        $this->actingAs($caretaker)->postJson('/gun/report', ['install_id' => $id])->assertOk()->assertJsonMissingPath('key'); // given once
        $gun = GunDevice::sole();
        $this->actingAs($admin)->post("/settings/guns/{$gun->id}/commands", ['command' => 'open_app'])->assertRedirect();

        auth()->logout();
        $this->postJson('/api/v1/gun/report', ['install_id' => $id], ['Authorization' => 'Bearer wrong'])->assertStatus(401);
        $this->postJson('/api/v1/gun/report', ['install_id' => (string) Str::uuid()], ['Authorization' => "Bearer {$key}"])->assertStatus(409);
        $this->postJson('/api/v1/gun/report', ['install_id' => $id, 'version' => '0.8.5', 'state' => ['onScreen' => false]], ['Authorization' => "Bearer {$key}"])
            ->assertOk()->assertJson(['commands' => [['command' => 'open_app']]]);
        $this->postJson('/api/v1/gun/screen', ['install_id' => $id, 'image' => base64_encode("\xFF\xD8\xFF".'abc'), 'w' => 10, 'h' => 20], ['Authorization' => "Bearer {$key}"])->assertOk();
        $this->assertSame('0.8.5', $gun->fresh()->app_version);
        $this->assertNotNull($gun->fresh()->screen_at);
    }

    public function test_another_farm_never_sees_the_gun(): void
    {
        [, , $caretaker] = $this->org('farm-a');
        $this->actingAs($caretaker)->postJson('/gun/report', ['install_id' => (string) Str::uuid(), 'version' => '0.8.4'])->assertOk();
        $gunId = GunDevice::sole()->id;

        [$orgB, $adminB] = $this->org('farm-b');
        Tenant::enter($orgB->id);
        $this->assertSame(0, GunDevice::count());
        Tenant::leave();
        $this->actingAs($adminB)->get('/settings/guns')->assertInertia(fn ($p) => $p->has('guns', 0));
        $this->actingAs($adminB)->post("/settings/guns/{$gunId}/commands", ['command' => 'test_read'])->assertNotFound();
    }
}
