<?php

$all = ['ceo', 'manager', 'vet', 'caretaker', 'feeding', 'cleaning', 'finance'];

return [
    // D-037. Database role the connection switches into per request so Row-Level Security applies.
    // Superusers bypass RLS, so leave this set everywhere except one-off CLI work (migrations, seeding).
    'tenancy' => ['db_role' => env('TENANCY_DB_ROLE', 'kkfarm_app')],

    // Prefix for farm numbers issued when a goat is registered without an ear tag number.
    'farm_number_prefix' => env('FARM_NUMBER_PREFIX', 'KK'),

    // Shows the "synthetic demo data" badge. Set true only when the DemoSeeder data is loaded.
    'demo' => (bool) env('KKFARM_DEMO', false),

    // D-044: the passwordless role picker. Only honoured in local/testing environments, never on a server.
    'demo_login' => (bool) env('KKFARM_DEMO_LOGIN', false),

    // Candidate roles from the project brief. Actual responsibilities are confirmed after the farm visit.
    'roles' => [
        'ceo' => 'Administrator', // D-095: the owner's account (role key stays ceo)
        'manager' => 'Farm Manager',
        'vet' => 'Veterinarian',
        'caretaker' => 'Caretaker',
        'feeding' => 'Feeding Employee',
        'cleaning' => 'Cleaning Employee',
        'finance' => 'Finance Employee',
    ],

    // D-241: module defaults from KK_FARMS_RBAC.md §4 (● or ◐ = the module opens; pages and actions narrow it down).
    'modules' => [
        'dashboard' => $all, // non-owners get their own role home (§6)
        'goats' => ['ceo', 'manager', 'vet', 'caretaker', 'feeding'],
        'scan' => ['ceo', 'manager', 'vet', 'caretaker', 'feeding'],
        'breeding' => ['ceo', 'manager', 'vet', 'caretaker'],
        'health' => ['ceo', 'manager', 'vet', 'caretaker'],
        'weights' => ['ceo', 'manager', 'vet', 'caretaker', 'feeding'],
        'feeding' => ['ceo', 'manager', 'vet', 'caretaker', 'feeding'],
        'sheds' => ['ceo', 'manager', 'vet', 'caretaker', 'feeding', 'cleaning'],
        'tasks' => $all,
        'employees' => ['ceo', 'manager', 'finance'],
        'finance' => ['ceo', 'manager', 'finance'],
        'value' => ['ceo', 'manager', 'finance'],
        'milking' => ['ceo', 'manager', 'vet', 'caretaker', 'finance'],
        'reports' => ['ceo', 'manager', 'vet', 'feeding', 'finance'],
        'settings' => ['ceo'],
        'sensors' => ['ceo', 'manager'],
        'inventory' => $all,
        'purchase' => ['ceo', 'manager', 'vet', 'feeding', 'finance'],
        'sales' => ['ceo', 'manager', 'finance'],
        'welfare' => ['ceo', 'manager', 'vet', 'caretaker'],
        'fattening' => ['ceo', 'manager', 'vet', 'caretaker', 'feeding'],
        'environment' => ['ceo', 'manager', 'vet', 'caretaker', 'cleaning'],
        'quality' => ['ceo', 'manager', 'vet'],
        'animal_purchase' => ['ceo', 'manager', 'vet', 'finance'],
        'feed_cost' => ['ceo', 'manager', 'feeding', 'finance'], // feeding: stock count only (RBAC §8.3 row wins over §4; pages narrow it)
        'fodder' => ['ceo', 'manager', 'caretaker', 'feeding'],
        'maintenance' => ['ceo', 'manager', 'caretaker', 'feeding', 'cleaning'],
    ],

    // Hard gates from SECURITY_AND_RBAC.md: these never come from a configurable override.
    'gates' => [
        // D-241: action defaults from KK_FARMS_RBAC.md §5. 🔒 vet-locked: only the vet, or a person the administrator
        // ticks on their own page; the role screen cannot change them (the system records, vets decide).
        'clinical' => ['vet'], // 🔒 diagnose and treat
        'open_cases' => ['vet'], // 🔒 open health cases (caretakers report a sick goat instead)
        'approve_breeding' => ['vet'], // 🔒 approve goats for breeding
        // D-252: the health buttons on a goat are the vet's unless the administrator ticks a role or a person
        'health_checkup' => ['ceo', 'vet'], // D-256: the Administrator has every Tasks job by default
        'health_entry' => ['vet'], // New health entry
        'report_concern' => ['ceo', 'vet'], // Report concern (and the health round on the RFID gun)
        // D-255: field jobs, on the RFID gun and in the app
        'record_heat' => ['ceo', 'manager', 'vet', 'caretaker'],
        'record_mating' => ['ceo', 'manager', 'vet', 'caretaker'],
        'give_care' => ['ceo', 'vet'], // vaccination, deworming and other preventive care given
        'move_goats' => ['ceo', 'manager', 'vet', 'caretaker', 'feeding'],
        // D-260: a death is the vet's finding, approved by the administrator; culling takes a goat out of breeding
        'report_death' => ['vet'],
        'approve_death' => ['ceo'],
        'cull_breeding' => ['ceo', 'vet'],
        'manage_medicine_store' => ['ceo', 'vet'],
        'approve_feeding_plan' => ['ceo', 'manager'],
        'plan_rations' => ['ceo', 'manager', 'feeding'],
        'manage_feed_store' => ['ceo', 'manager', 'feeding'],
        'manage_general_store' => ['ceo', 'manager', 'caretaker', 'cleaning'],
        'assign_tags' => ['ceo', 'manager', 'vet', 'caretaker'],
        'manage_sheds' => ['ceo', 'manager'],
        'edit_goats' => ['ceo'], // D-252: only the Administrator edits a goat's details unless a role or person is ticked
        'remove_goats' => ['ceo'], // D-252: only the Administrator removes or restores a goat entry unless a role or person is ticked
        'exit_animal' => ['ceo', 'manager', 'finance'],
        'record_milk' => ['ceo', 'manager', 'caretaker'],
        'manage_milking' => ['ceo', 'manager'],
        'manage_purchase' => ['ceo', 'manager', 'finance'],
        'manage_sales' => ['ceo', 'manager', 'finance'],
        'manage_market' => ['ceo', 'manager', 'finance'],
        'manage_devices' => ['ceo'],
        'record_weights' => ['ceo', 'manager', 'caretaker'], // RBAC §4/§8.3: vet and feeding staff view weights only (a vet's checkup still records its own weight)
    ],

    // Demonstration assumption (Q-PB-2): the real value is confirmed by the veterinarians.
    'demo_gestation_days' => 150,
];
