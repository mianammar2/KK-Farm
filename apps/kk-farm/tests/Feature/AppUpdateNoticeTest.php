<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use Tests\TestCase;

/** D-330: every page carries the newest Android app on the server, so an open app is told to update at once. */
class AppUpdateNoticeTest extends TestCase
{
    public function test_the_server_app_version_is_read_from_the_published_manifest(): void
    {
        $info = json_decode((string) file_get_contents(public_path('downloads/kkfarm.json')), true);
        $app = HandleInertiaRequests::androidApp();
        $this->assertNotNull($app);
        $this->assertSame((int) $info['versionCode'], $app['code']);
        $this->assertSame($info['versionName'], $app['name']);
    }
}
