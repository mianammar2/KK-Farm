<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-090: the published Android build the app updates from, and the App and updates page. */
class AppUpdatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_published_build_matches_the_apk_and_the_page_shows_it(): void
    {
        $info = json_decode((string) file_get_contents(public_path('downloads/kkfarm.json')), true);
        $this->assertGreaterThan(11, $info['versionCode']);
        $this->assertSame('/downloads/kkfarm.apk', $info['url']);
        $this->assertSame($info['sha256'], hash_file('sha256', public_path('downloads/kkfarm.apk')), 'the updater refuses a file whose checksum differs');

        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'App Farm', 'slug' => 'app-farm'])->id);
        $user = User::create(['name' => 'Worker', 'email' => 'w@app.test', 'password' => 'x', 'role' => 'caretaker', 'org_id' => Tenant::current()]);
        $this->actingAs($user)->get('/account/app')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Account/AppUpdates')
            ->where('android.versionCode', $info['versionCode'])->where('android.versionName', $info['versionName']));
    }

    public function test_the_web_app_can_be_installed_on_a_phone_home_screen(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true);
        $this->assertSame(['KK Farms', 'standalone', '/'], [$manifest['name'], $manifest['display'], $manifest['start_url']]);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
        $this->get('/login')->assertSee('rel="manifest"', false)->assertSee('apple-mobile-web-app-capable', false);
    }
}
