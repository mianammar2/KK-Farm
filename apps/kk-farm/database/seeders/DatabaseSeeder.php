<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\User;
use App\Support\FieldCatalog;
use App\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Clean start: no goats, no history. Only what is needed to begin registering real goats.
 * The synthetic demo farm is still available: php artisan migrate:fresh --seed --seeder=DemoSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Creating the organization also creates its goat register fields (FieldCatalog::seed).
        Tenant::set(Organization::create(['name' => 'KK Farms', 'slug' => 'kk-farms'])->id);

        foreach (config('kkfarm.roles') as $role => $label) {
            User::create(['name' => $label, 'email' => "$role@kkfarms.local", 'password' => Hash::make(Str::random(40)), 'role' => $role]);
        }

        FieldCatalog::seedBreeds();

        $shed = Shed::create(['code' => 'SHED-1', 'name' => 'Shed 1', 'status' => 'active']);
        Pen::create(['shed_id' => $shed->id, 'name' => 'Main pen', 'purpose' => 'All goats']);
    }
}
