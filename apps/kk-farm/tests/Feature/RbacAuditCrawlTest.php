<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Support\Pages;
use App\Support\Tenant;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

/**
 * D-261 (RBAC deep audit): the role crawl. Every signed-in route is requested as each of the seven roles on the
 * synthetic demo farm, and the status is recorded:
 *   - GET routes and every page/tab in the page registry: the real response status (200, 302, 403 ...);
 *   - write routes (POST/PUT/PATCH/DELETE) are sent with an EMPTY body: 403 = refused before the handler;
 *     anything else (422 validation, 302, 404, 500) = the request got past authorization into the handler.
 *
 * The result is compared with tests/Feature/rbac/baseline.json (today's behaviour, known findings included), so any
 * change in who can reach what fails here until the baseline is regenerated on purpose:
 *   RBAC_AUDIT=1 php artisan test tests/Feature/RbacAuditCrawlTest.php                    (check, about 4 minutes)
 *   RBAC_AUDIT=1 RBAC_AUDIT_WRITE=1 php artisan test tests/Feature/RbacAuditCrawlTest.php (rewrite the baseline on purpose)
 * Opt-in (RBAC_AUDIT=1) so everyday `php artisan test` stays fast; CI sets RBAC_AUDIT=1. Use a test database of its own
 * (e.g. DB_DATABASE=kkfarm_test_rbac) when another session may be running tests at the same time.
 * Phase 2 replaces the baseline with the spec-correct matrix (KK_FARMS_RBAC.md §8.3) as each finding is fixed.
 * Runs only against the local test database (phpunit.xml: kkfarm_test). Never point it at production.
 */
class RbacAuditCrawlTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['ceo', 'manager', 'vet', 'caretaker', 'feeding', 'cleaning', 'finance'];

    /** Never requested: they end the session or switch accounts, which would spoil the rest of the crawl. */
    private const SKIP = ['POST /logout', 'POST /switch-role', 'POST /account/password', 'POST /login', 'GET /login'];

    protected function setUp(): void
    {
        if (! env('RBAC_AUDIT')) {
            $this->markTestSkipped('RBAC role crawl is opt-in: run with RBAC_AUDIT=1.');
        }
        parent::setUp();
    }

    public function test_every_route_as_every_role_matches_the_baseline(): void
    {
        $this->withoutVite();
        $this->seed(DemoSeeder::class);
        $users = User::whereIn('role', self::ROLES)->orderBy('id')->get()->unique('role')->keyBy('role');
        $this->assertCount(7, $users, 'the demo seeder makes one user per role');
        Tenant::set($users['ceo']->org_id);

        $results = [];
        foreach ($this->targets() as $t) {
            foreach (self::ROLES as $role) {
                Tenant::flush();
                $u = $users[$role]->fresh();
                $code = $this->hit($u, $t['method'], $t['url']);
                $results[$t['id']][$role] = $code;
            }
        }
        ksort($results);

        $dir = __DIR__.'/rbac';
        $file = "$dir/baseline.json";
        if (env('RBAC_AUDIT_WRITE')) {
            @mkdir($dir, 0775, true);
            file_put_contents($file, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->markTestSkipped('Baseline written: '.count($results).' targets × 7 roles.');
        }
        $this->assertFileExists($file, 'Run once with RBAC_AUDIT_WRITE=1 to write the baseline.');
        $base = json_decode(file_get_contents($file), true);
        $diff = [];
        foreach ($results as $id => $row) {
            foreach ($row as $role => $code) {
                $was = $base[$id][$role] ?? null;
                if ($this->bucket($was) !== $this->bucket($code)) {
                    $diff[] = "$id as $role: baseline $was → now $code";
                }
            }
        }
        foreach (array_diff_key($base, $results) as $id => $_) {
            $diff[] = "$id: in the baseline but no longer a route";
        }
        $this->assertSame([], $diff, "Access changed from the baseline:\n".implode("\n", $diff));
    }

    /** 2xx/3xx/4xx-but-not-403 all mean "reached the page or handler"; 403 means refused. */
    private function bucket(?int $code): string
    {
        return match (true) {
            $code === null => 'missing',
            $code === 403 => 'refused',
            $code === 404 => 'not-found',
            $code >= 500 => 'error',
            default => 'reached',
        };
    }

    private function hit(User $u, string $method, string $url): int
    {
        DB::beginTransaction(); // a savepoint: every request starts from the same demo data
        try {
            $this->app['auth']->forgetGuards();
            $res = $this->actingAs($u)->call($method, $url, [], [], [], ['HTTP_ACCEPT' => 'text/html']);

            return $res->getStatusCode();
        } catch (\Throwable) {
            return 599; // the handler threw outside the HTTP kernel
        } finally {
            while (DB::transactionLevel() > 2) {
                DB::rollBack(); // a handler that left a transaction open
            }
            DB::rollBack(); // back to the savepoint (RefreshDatabase keeps the outer transaction)
        }
    }

    /** @return list<array{id: string, method: string, url: string}> */
    private function targets(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            $mw = $r->gatherMiddleware();
            if (! in_array('auth', $mw, true)) {
                continue;
            }
            foreach (array_diff($r->methods(), ['HEAD']) as $method) {
                $uri = '/'.ltrim($r->uri(), '/');
                if (in_array("$method $uri", self::SKIP, true)) {
                    continue;
                }
                $url = $this->fill($r, $uri);
                if ($url === null) {
                    $out[] = ['id' => "$method $uri", 'method' => 'SKIP', 'url' => ''];

                    continue;
                }
                $out[] = ['id' => "$method $uri", 'method' => $method, 'url' => $url];
            }
        }
        // every page and tab in the registry (permission belongs to the destination, RBAC §8.1.4)
        foreach (Pages::registry() as $key => $p) {
            $url = $p['path'].($p['query'] ? '?'.http_build_query($p['query']) : '');
            $out[] = ['id' => "PAGE $key  $url", 'method' => 'GET', 'url' => $url];
        }

        return array_values(array_filter($out, fn ($t) => $t['method'] !== 'SKIP'));
    }

    /** Puts a real id from the demo farm into each {parameter}; null when there is no record to use. */
    private function fill(\Illuminate\Routing\Route $r, string $uri): ?string
    {
        $types = [];
        try {
            $action = $r->getActionName();
            if ($action !== 'Closure') {
                [$cls, $m] = str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke'];
                foreach ((new ReflectionMethod($cls, $m))->getParameters() as $p) {
                    $t = $p->getType();
                    if ($t instanceof \ReflectionNamedType && ! $t->isBuiltin()) {
                        $types[$p->getName()] = $t->getName();
                    }
                }
            }
        } catch (\Throwable) {
        }
        $fixed = ['type' => 'medicine', 'section' => 'herd', 'key' => 'goats', 'path' => 'x', 'module' => 'goats', 'tab' => 'cases', 'app' => 'goats'];

        return $this->fillAgain($r, $uri, $types, $fixed);
    }

    private function fillAgain(\Illuminate\Routing\Route $route, string $uri, array $types, array $fixed): ?string
    {
        // D-266: a record that does not exist on the demo farm gets a made-up id. The action registry answers 403 before the
        // record is looked up (refused role), so refused and allowed roles still tell apart: 403 against 404 or a validation answer.
        return preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($types, $fixed) {
            $name = $m[1];
            if (isset($types[$name]) && is_subclass_of($types[$name], \Illuminate\Database\Eloquent\Model::class)) {
                $model = new $types[$name];
                $id = $model->newQuery()->value($model->getKeyName());

                return (string) ($id ?? ($model->getKeyType() === 'string' ? '00000000-0000-0000-0000-000000000000' : '999999'));
            }
            if ($name === 'id' || $name === 'user') {
                return (string) User::where('role', 'caretaker')->value('id');
            }

            return $fixed[$name] ?? (string) (DB::table('animals')->value('id') ?? '1');
        }, $uri);
    }
}
