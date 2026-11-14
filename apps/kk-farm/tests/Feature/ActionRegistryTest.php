<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceAction;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use ReflectionFunction;
use ReflectionMethod;
use Tests\TestCase;

/**
 * D-266 (audit F-01): the permanent guard for write enforcement. A write route without a declared rule fails here
 * (and answers 403 in production), so a new endpoint can never ship open.
 */
class ActionRegistryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, \Illuminate\Routing\Route> "METHOD /uri" => route, for every signed-in write route */
    private function writeRoutes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            if (! in_array('auth', $r->gatherMiddleware(), true)) {
                continue;
            }
            foreach (array_diff($r->methods(), ['GET', 'HEAD']) as $m) {
                $out["$m /".ltrim($r->uri(), '/')] = $r;
            }
        }

        return $out;
    }

    public function test_every_signed_in_write_route_declares_its_rule(): void
    {
        $rows = config('kkfarm_actions');
        $missing = array_keys(array_diff_key($this->writeRoutes(), $rows));
        $this->assertSame([], $missing, "Write routes with no row in config/kkfarm_actions.php:\n".implode("\n", $missing));
    }

    public function test_no_row_is_left_over_for_a_route_that_no_longer_exists(): void
    {
        $orphans = array_keys(array_diff_key(config('kkfarm_actions'), $this->writeRoutes()));
        $this->assertSame([], $orphans, "Rows for routes that do not exist:\n".implode("\n", $orphans));
    }

    public function test_every_row_is_well_formed_and_refers_to_real_gates_and_apps(): void
    {
        $gates = array_keys(config('kkfarm.gates'));
        $modules = array_keys(config('kkfarm.modules'));
        $bad = [];
        foreach (config('kkfarm_actions') as $key => $row) {
            $has = isset($row['module']) || isset($row['gate']) || isset($row['page']);
            if (isset($row['open'])) {
                $has = is_string($row['open']) && strlen($row['open']) > 10;
            }
            foreach ((array) ($row['gate'] ?? []) as $g) {
                $has = $has && in_array($g, $gates, true);
            }
            foreach ((array) ($row['module'] ?? []) as $m) {
                $has = $has && in_array($m, $modules, true);
            }
            if (! $has) {
                $bad[] = $key;
            }
        }
        $this->assertSame([], $bad, "Malformed rows:\n".implode("\n", $bad));
    }

    public function test_a_row_that_says_the_controller_checks_further_is_true(): void
    {
        $rows = config('kkfarm_actions');
        $liars = [];
        foreach ($this->writeRoutes() as $key => $route) {
            if (! isset($rows[$key]['inline'])) {
                continue;
            }
            $src = $this->source($route);
            $named = preg_match('/(visibleTo|canHandle)/', $rows[$key]['inline'], $t) && str_contains(file_get_contents($this->file($route)), $t[1].'(');
            if (! $named && ! preg_match('/abort_(unless|if)\(|->authorize\(|::allowed\(|passes\(|->role\b|canHandle\(|\$this->(vet|owner|manager|reviewer|admin)\(/', $src)) {
                $liars[] = $key;
            }
        }
        $this->assertSame([], $liars, "Rows marked inline whose action has no check:\n".implode("\n", $liars));
    }

    private function file(\Illuminate\Routing\Route $route): string
    {
        $action = $route->getActionName();

        return ($action === 'Closure' ? new ReflectionFunction($route->getAction('uses')) : new ReflectionMethod(...(str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke'])))->getFileName();
    }

    private function source(\Illuminate\Routing\Route $route): string
    {
        $action = $route->getActionName();
        $ref = $action === 'Closure' ? new ReflectionFunction($route->getAction('uses')) : new ReflectionMethod(...(str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke']));
        $lines = file($ref->getFileName());

        return implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    }

    public function test_an_undeclared_write_route_is_refused_even_for_the_administrator(): void
    {
        $org = Organization::create(['name' => 'Registry Farm', 'slug' => 'registry-farm']);
        Tenant::set($org->id);
        $ceo = User::create(['name' => 'Admin', 'email' => 'admin@registry.test', 'password' => 'x', 'role' => 'ceo', 'org_id' => $org->id, 'active' => true]);
        Route::middleware(['web', 'auth'])->post('/__undeclared-probe', fn () => 'reached');
        $this->actingAs($ceo)->post('/__undeclared-probe')->assertForbidden();
    }

    public function test_the_rule_check_follows_module_gate_and_open(): void
    {
        $org = Organization::create(['name' => 'Rule Farm', 'slug' => 'rule-farm']);
        Tenant::set($org->id);
        $mk = fn (string $role) => new User(['role' => $role, 'org_id' => $org->id]);
        $this->assertTrue(EnforceAction::allows($mk('feeding'), ['module' => 'feeding', 'gate' => 'record_feeding']));
        $this->assertFalse(EnforceAction::allows($mk('vet'), ['module' => 'feeding', 'gate' => 'record_feeding'])); // F-07
        $this->assertTrue(EnforceAction::allows($mk('finance'), ['open' => 'any signed-in person']));
        $this->assertFalse(EnforceAction::allows($mk('ceo'), [])); // an empty row never opens anything
    }

    /** Every right the code asks for with passes('name') exists: a typo or a pruned gate would silently refuse everyone. */
    public function test_every_right_the_code_asks_for_exists(): void
    {
        $gates = array_keys(config('kkfarm.gates'));
        $unknown = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            preg_match_all("/passes\\('([a-z_]+)'\\)/", file_get_contents($file->getPathname()), $m);
            foreach (array_unique($m[1]) as $g) {
                if (! in_array($g, $gates, true)) {
                    $unknown[] = $g.' in '.str_replace(app_path().'/', '', $file->getPathname());
                }
            }
        }
        $this->assertSame([], $unknown, "passes() asks for rights that do not exist:\n".implode("\n", $unknown));
    }
}
