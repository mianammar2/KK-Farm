<?php
/**
 * D-261 RBAC audit: machine-generated route inventory. Read-only (no database writes).
 * Run from apps/kk-farm:  php ../../docs/decisions/D-261/scripts/inventory.php > ../../docs/decisions/D-261/routes.json
 * For every route: methods, uri, action, middleware, the permission checks found in the action's source,
 * the page-registry key that guards a GET, and the per-role default decision (module middleware AND page rule).
 */
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Pages;

$roles = array_keys(config('kkfarm.roles'));
$mods = config('kkfarm.modules');
$out = [];
foreach (Illuminate\Support\Facades\Route::getRoutes() as $r) {
    $methods = array_values(array_diff($r->methods(), ['HEAD']));
    $mw = $r->gatherMiddleware();
    $action = $r->getActionName();
    $src = ''; $file = null; $line = null; $cls = null; $m = null;
    try {
        if ($action === 'Closure') {
            $ref = new ReflectionFunction($r->getAction('uses'));
        } else {
            [$cls, $m] = str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke'];
            $ref = new ReflectionMethod($cls, $m);
        }
        $file = str_replace(getcwd().'/', '', $ref->getFileName()); $line = $ref->getStartLine();
        $lines = file($ref->getFileName());
        $src = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
    } catch (Throwable $e) { }
    preg_match_all("/passes\\('([a-z_]+)'\\)/", $src, $g);
    preg_match_all("/canModule\\('([a-z_]+)'\\)/", $src, $cm);
    preg_match_all("/Pages::allows(?:Key)?\\([^)]*\\)/", $src, $pa);
    $roleChecks = preg_match_all("/->role\\s*(===|!==)|in_array\\(\\\$[a-z]+->role/", $src);
    // guard helpers: a call in the action to another method of the same class (or a static helper) that itself aborts or checks a role/gate
    $helpers = [];
    if (isset($cls) && $action !== 'Closure') {
        foreach ((new ReflectionClass($cls))->getMethods() as $hm) {
            if ($hm->getName() === ($m ?? '') || $hm->getFileName() === false) continue;
            $hl = file($hm->getFileName());
            $hs = implode('', array_slice($hl, $hm->getStartLine() - 1, $hm->getEndLine() - $hm->getStartLine() + 1));
            if (preg_match('/abort_(unless|if)\(.*(role|passes|canModule|allowed|Pages::)/', $hs) && preg_match('/(\$this->|self::|static::)'.$hm->getName().'\(/', $src)) $helpers[] = $hm->getName();
        }
    }
    if (preg_match_all('/([A-Z][A-Za-z]+::allowed|WorkerController::allowed|Masters::allowed)\(/', $src, $sx)) $helpers = array_merge($helpers, $sx[1]);
    $aborts = preg_match_all('/abort_(unless|if)\(/', $src);
    $modules = [];
    foreach ($mw as $x) if (is_string($x) && str_starts_with($x, 'module:')) $modules = array_merge($modules, explode(',', substr($x, 7)));
    $uri = '/'.ltrim($r->uri(), '/');
    $rule = in_array('GET', $methods) ? Pages::rule(preg_replace('/\{[^}]+\}/', 'X', $uri), []) : null;
    $decision = [];
    foreach ($roles as $role) {
        $okMod = ! $modules || (bool) array_filter($modules, fn ($m) => in_array($role, $mods[$m] ?? [], true));
        $okPage = true;
        if (in_array('GET', $methods) && in_array('auth', $mw)) {
            $u = new App\Models\User(['role' => $role]); $u->id = 0; $u->org_id = null;
            try { $okPage = Pages::allows($u, preg_replace('/\{[^}]+\}/', 'X', $uri), []); } catch (Throwable $e) { $okPage = 'ERR'; }
        }
        $decision[$role] = $okMod && $okPage === true;
    }
    $out[] = [
        'methods' => implode('|', $methods), 'uri' => $uri, 'action' => $action, 'file' => $file, 'line' => $line,
        'auth' => in_array('auth', $mw), 'guest' => in_array('guest', $mw), 'modules' => $modules,
        'throttle' => array_values(array_filter($mw, fn ($x) => is_string($x) && str_starts_with($x, 'throttle'))),
        'gates' => array_values(array_unique($g[1])), 'canModule' => array_values(array_unique($cm[1])),
        'pageChecks' => count($pa[0]), 'roleChecks' => $roleChecks, 'helpers' => array_values(array_unique($helpers)), 'aborts' => $aborts,
        'page_key' => $rule['key'] ?? null, 'page_lock' => $rule['lock'] ?? null, 'default_allow' => $decision,
    ];
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
