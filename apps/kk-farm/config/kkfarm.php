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
        'ceo' => 'CEO / Owner',
        'manager' => 'Farm Manager',
        'vet' => 'Veterinarian',
        'caretaker' => 'Caretaker',
        'feeding' => 'Feeding Employee',
        'cleaning' => 'Cleaning Employee',
        'finance' => 'Finance Employee',
    ],

    'modules' => [
        'dashboard' => $all,
        'goats' => ['ceo', 'manager', 'vet', 'caretaker', 'feeding', 'finance'],
        'scan' => ['ceo', 'manager', 'vet', 'caretaker'],
        'breeding' => ['ceo', 'manager', 'vet', 'caretaker'],
        'health' => ['ceo', 'manager', 'vet', 'caretaker'],
        'weights' => ['ceo', 'manager', 'vet', 'caretaker'],
        'feeding' => ['ceo', 'manager', 'vet', 'caretaker', 'feeding'],
        'sheds' => ['ceo', 'manager', 'vet', 'caretaker', 'cleaning'],
        'employees' => ['ceo', 'manager'],
        'tasks' => $all,
        'finance' => ['ceo', 'finance'],
        'reports' => ['ceo', 'manager', 'finance'],
        'settings' => ['ceo'],
    ],

    // Hard gates from SECURITY_AND_RBAC.md: these never come from a configurable override.
    'gates' => [
        'clinical' => ['vet'],
        'approve_feeding_plan' => ['vet'],
        'exit_animal' => ['ceo', 'manager'],
        'assign_tags' => ['ceo', 'manager', 'caretaker'],
        'manage_sheds' => ['ceo', 'manager'], // D-051: add and rename sheds and pens
    ],

    // Demonstration assumption (Q-PB-2): the real value is confirmed by the veterinarians.
    'demo_gestation_days' => 150,
];
