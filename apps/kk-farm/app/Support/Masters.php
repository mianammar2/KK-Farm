<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * D-240: one standard for every master list (owner: "add, edit and delete everywhere"). An entry nothing uses is
 * deleted. An entry records use is never deleted (history stays readable): lists with an Active switch hide it from
 * new entries instead; the others say what still uses it.
 */
final class Masters
{
    /** type => [model, module, gate (null = module is enough, array = roles), label column] */
    public const TYPES = [
        'disease' => [\App\Models\Disease::class, 'health', 'clinical', 'name'],
        'symptom' => [\App\Models\Symptom::class, 'health', 'clinical', 'name'], // D-292
        'protocol' => [\App\Models\CareProtocol::class, 'health', 'clinical', 'name'],
        'medicine' => [\App\Models\Medicine::class, 'health', 'manage_medicine_store', 'name'],
        'feed-item' => [\App\Models\FeedItem::class, 'feeding', 'manage_feed_store', 'name'],
        'tmr' => [\App\Models\TmrFormula::class, 'feeding', 'plan_rations', 'name'],
        'nutrition' => [\App\Models\NutritionStandard::class, 'feeding', 'plan_rations', 'name'],
        'account' => [\App\Models\Account::class, 'finance', ['ceo', 'manager', 'finance'], 'name'],
        'store-item' => [\App\Models\StoreItem::class, 'inventory', 'manage_general_store', 'name'],
        'asset' => [\App\Models\Asset::class, 'maintenance', ['ceo', 'manager'], 'name'],
        'checklist' => [\App\Models\ChecklistTemplate::class, 'tasks', ['ceo', 'manager'], 'name'],
        'department' => [\App\Models\Department::class, 'settings', null, 'name'],
        'water-point' => [\App\Models\WaterPoint::class, 'environment', ['ceo', 'manager'], 'name'],
        'vehicle' => [\App\Models\Vehicle::class, 'goats', ['ceo', 'manager'], 'reg_no'],
        'waste-stream' => [\App\Models\WasteStream::class, 'environment', ['ceo', 'manager'], 'name'],
        'field' => [\App\Models\FieldDefinition::class, 'settings', null, 'label'],
        'shed' => [\App\Models\Shed::class, 'sheds', 'manage_sheds', 'name'],
        'pen' => [\App\Models\Pen::class, 'sheds', 'manage_sheds', 'name'],
        'supplier' => [\App\Models\Supplier::class, 'purchase', 'manage_purchase', 'name'],
        'customer' => [\App\Models\Customer::class, 'sales', 'manage_sales', 'name'],
    ];

    public static function allowed(User $user, string $type): bool
    {
        [, $module, $gate] = self::TYPES[$type];

        return $user->canModule($module) && match (true) {
            $gate === null => true,
            is_array($gate) => in_array($user->role, $gate, true),
            default => $user->passes($gate),
        };
    }

    /** @return array<string, int> table => rows that point at this entry */
    public static function uses(Model $m): array
    {
        $table = $m->getTable();
        $refs = collect(DB::select("select tc.table_name as t, kcu.column_name as c from information_schema.table_constraints tc
            join information_schema.key_column_usage kcu on kcu.constraint_name = tc.constraint_name and kcu.table_schema = tc.table_schema
            join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name and ccu.table_schema = tc.table_schema
            where tc.constraint_type = 'FOREIGN KEY' and tc.table_schema = current_schema() and ccu.table_name = ? and ccu.column_name = 'id'", [$table]))
            ->map(fn ($r) => [$r->t, $r->c]);
        $column = Str::singular($table).'_id';
        $refs = $refs->merge(collect(DB::select('select table_name as t, column_name as c from information_schema.columns where table_schema = current_schema() and column_name = ? and table_name <> ?', [$column, $table]))->map(fn ($r) => [$r->t, $r->c]))
            ->unique(fn ($r) => $r[0].'.'.$r[1]);
        $out = [];
        foreach ($refs as [$t, $c]) {
            if ($n = DB::table($t)->where($c, $m->getKey())->count()) {
                $out[$t] = ($out[$t] ?? 0) + $n;
            }
        }

        return $out;
    }

    /** @return array{0: string, 1: string} [outcome deleted|deactivated, message] */
    public static function remove(string $type, Model $m): array
    {
        $name = (string) ($m->{self::TYPES[$type][3]} ?? $m->getKey());
        if ($type === 'field' && $m->builtin) {
            $m->update(['active' => false]);

            return ['deactivated', "{$name} is a standard field: switched off on the form."];
        }
        $uses = self::uses($m);
        if (! $uses) {
            $m->delete();

            return ['deleted', "{$name} deleted."];
        }
        $where = collect($uses)->map(fn ($n, $t) => "{$n} ".str_replace('_', ' ', $t))->implode(', ');
        if (in_array('active', \Illuminate\Support\Facades\Schema::getColumnListing($m->getTable()), true)) {
            $m->update(['active' => false]);

            return ['deactivated', "{$name} is used ({$where}), so it is kept for the history and switched off for new entries."];
        }
        throw \Illuminate\Validation\ValidationException::withMessages(['delete' => "{$name} cannot be deleted: it is still used ({$where})."]);
    }
}
