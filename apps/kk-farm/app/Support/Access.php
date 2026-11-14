<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;

/**
 * D-215: who may open which area (module) and do which action (gate). Three layers, interlinked:
 *   1. built-in defaults (config/kkfarm.php);
 *   2. the farm business's role settings (Settings → Access Rights by Role), stored per organization;
 *   3. a user's own ticks (Settings → Users → the person), which override their role either way.
 * Protected: the owner always keeps Settings (no lock-out); diagnosing/treating and approving rations stay with the
 * vet whatever is ticked (the system records and recommends, vets decide).
 */
class Access
{
    /** D-241: vet-locked (RBAC §5 🔒): fixed for roles; only a per-person grant by the administrator adds someone. */
    public const PROTECTED_GATES = ['clinical', 'open_cases', 'approve_breeding'];

    public const MODULE_LABELS = [
        'dashboard' => 'Dashboard', 'goats' => 'Livestock (goat register)', 'scan' => 'Tasks & RFID scan', 'breeding' => 'Breeding & Maternity', 'health' => 'Veterinary',
        'weights' => 'Growth & weights', 'feeding' => 'Feeding', 'sheds' => 'Housing & pens', 'tasks' => 'Operations & tasks', 'employees' => 'Employees', 'finance' => 'Accounting & finance',
        'value' => 'Herd value', 'milking' => 'Dairy', 'reports' => 'Reports', 'settings' => 'Settings', 'sensors' => 'Sensors & devices', 'inventory' => 'Inventory',
        'purchase' => 'Purchase', 'sales' => 'Sales', 'welfare' => 'Welfare', 'fattening' => 'Fattening', 'environment' => 'Environment (water & climate)', 'quality' => 'Quality',
        'animal_purchase' => 'Goat purchase lots', 'feed_cost' => 'Feed true cost', 'fodder' => 'Fodder', 'maintenance' => 'Maintenance',
    ];

    public const GATE_LABELS = [
        'clinical' => 'Diagnose and treat', 'approve_feeding_plan' => 'Approve feeding plans', 'exit_animal' => 'Record goats leaving the farm', 'record_weights' => 'Record weights', 'assign_tags' => 'Assign RFID tags',
        'manage_sheds' => 'Add sheds and pens', 'manage_feed_store' => 'Feed store items and stock', 'plan_rations' => 'Draft rations', 'manage_general_store' => 'General store',
        'manage_purchase' => 'Suppliers and purchase orders', 'manage_sales' => 'Customers and sales', 'manage_medicine_store' => 'Medicine store', 'manage_devices' => 'Devices and keys',
        'edit_goats' => 'Edit goat details', 'remove_goats' => 'Remove or restore goat entries', 'health_checkup' => 'Routine health checkups', 'manage_market' => 'Market rates',
        'record_milk' => 'Record milk', 'manage_milking' => 'Milking settings', 'open_cases' => 'Open health cases', 'approve_breeding' => 'Approve goats for breeding',
        'health_entry' => 'New health entry', 'report_concern' => 'Report concern',
        'see_health_history' => 'See a goat\'s health history and treatments', 'see_goat_pedigree' => 'See a goat\'s parentage, offspring and records', 'manage_books' => 'Post to the books (journals, accounts)', 'manage_staff' => 'Add staff, record attendance and leave', 'request_end_of_life' => 'Ask for a humane end-of-life review', 'request_goat_purchase' => 'Raise a goat purchase',
        'record_heat' => 'Record heat', 'record_mating' => 'Record mating', 'give_care' => 'Give vaccines and deworming', 'move_goats' => 'Move goats between pens',
        'report_death' => 'Report a death', 'approve_death' => 'Approve a death (the goat leaves the farm)', 'cull_breeding' => 'Cull from breeding', 'check_teeth' => 'Check teeth', 'record_height' => 'Measure height',
    ];

    /** D-266: every action's label: the built-in ones and those the action registry adds. */
    public static function labels(): array
    {
        return self::GATE_LABELS + (config('kkfarm_action_gates.labels') ?? []);
    }

    /** The farm business's saved role settings, cached for the request (Tenant::memo is cleared per request). */
    private static function org(?string $orgId): ?Organization
    {
        return $orgId ? Tenant::memo("access.org.$orgId", fn () => Organization::find($orgId) ?? new Organization) : null;
    }

    /** @return array<string, array<int, string>> module => roles, for this farm business */
    public static function roleModules(?string $orgId): array
    {
        $saved = self::org($orgId)?->role_modules;
        $saved = is_string($saved) ? json_decode($saved, true) : $saved;

        $out = [];
        foreach (config('kkfarm.modules') as $m => $roles) {
            $out[$m] = isset($saved[$m]) ? array_values($saved[$m]) : $roles;
        }

        return $out;
    }

    /** @return array<string, array<int, string>> gate => roles */
    public static function roleGates(?string $orgId): array
    {
        $saved = self::org($orgId)?->role_gates;
        $saved = is_string($saved) ? json_decode($saved, true) : $saved;
        $out = [];
        foreach (config('kkfarm.gates') as $g => $roles) {
            $out[$g] = in_array($g, self::PROTECTED_GATES, true) || ! isset($saved[$g]) ? $roles : array_values($saved[$g]);
        }

        return $out;
    }

    public static function module(User $u, string $module): bool
    {
        if ($module === 'settings') {
            return $u->role === 'ceo'; // D-266 F-08: Settings is the Administrator's alone, even when ticked for a person or role
        }
        $own = $u->module_access[$module] ?? null;
        if ($own !== null) {
            return (bool) $own;
        }

        return in_array($u->role, self::roleModules($u->org_id)[$module] ?? [], true);
    }

    public static function gate(User $u, string $gate): bool
    {
        $own = $u->gate_access[$gate] ?? null;
        if (in_array($gate, self::PROTECTED_GATES, true)) {
            return $own !== null ? (bool) $own : in_array($u->role, config("kkfarm.gates.$gate", []), true);
        }
        if ($own !== null) {
            return (bool) $own;
        }

        return in_array($u->role, self::roleGates($u->org_id)[$gate] ?? [], true);
    }

    /** D-241 (RBAC §11): which apps a person gains and loses when their role changes (their own ticks stay). */
    public static function roleDiff(\App\Models\User $u, string $to): array
    {
        $mods = self::roleModules($u->org_id);
        $has = fn (string $role) => collect($mods)->filter(fn ($roles, $m) => ($u->module_access[$m] ?? null) ?? in_array($role, $roles, true))->keys();
        [$before, $after] = [$has($u->role), $has($to)];
        $label = fn ($m) => self::MODULE_LABELS[$m] ?? $m;

        return ['gain' => $after->diff($before)->map($label)->values()->all(), 'lose' => $before->diff($after)->map($label)->values()->all()];
    }
}
