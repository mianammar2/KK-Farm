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

    'modules' => [
        'dashboard' => $all,
        'goats' => ['ceo', 'manager', 'vet', 'caretaker', 'feeding', 'finance'],
        'scan' => ['ceo', 'manager', 'vet', 'caretaker', 'feeding'], // D-179: every farm worker can scan
        'breeding' => ['ceo', 'manager', 'vet', 'caretaker'],
        'health' => ['ceo', 'manager', 'vet', 'caretaker'],
        'weights' => ['ceo', 'manager', 'vet', 'caretaker'],
        'feeding' => ['ceo', 'manager', 'vet', 'caretaker', 'feeding'],
        'sheds' => ['ceo', 'manager', 'vet', 'caretaker', 'cleaning'],
        'employees' => ['ceo', 'manager'],
        'tasks' => $all,
        'finance' => ['ceo', 'finance'],
        'value' => ['ceo', 'manager', 'finance'], // D-113: Herd Value
        'milking' => ['ceo', 'manager', 'vet', 'caretaker', 'finance'], // D-117; finance for milk dispatch (D-188)
        'reports' => ['ceo', 'manager', 'finance'],
        'settings' => ['ceo'],
        'sensors' => ['ceo', 'manager', 'vet'], // D-087: devices, readings, sensor alerts
        'inventory' => ['ceo', 'manager', 'vet', 'feeding', 'finance'], // D-178
        'purchase' => ['ceo', 'manager', 'finance'], // D-178
        'sales' => ['ceo', 'manager', 'finance'], // D-178
        'welfare' => ['ceo', 'manager', 'vet', 'caretaker', 'cleaning'], // D-185: App 19 Animal Welfare
        'fattening' => ['ceo', 'manager', 'vet', 'caretaker', 'feeding', 'finance'], // D-187: App 18 Fattening & Meat
        'environment' => ['ceo', 'manager', 'vet', 'caretaker', 'cleaning', 'feeding'], // D-186: App 20 Water, Climate & Environment
        'maintenance' => $all, // D-182: anyone reports a breakdown; owner and manager keep the asset register
    ],

    // Hard gates from SECURITY_AND_RBAC.md: these never come from a configurable override.
    'gates' => [
        'clinical' => ['vet'],
        'approve_feeding_plan' => ['vet'],
        'exit_animal' => ['ceo', 'manager'],
        'assign_tags' => ['ceo', 'manager', 'caretaker'],
        'manage_sheds' => ['ceo', 'manager'], // D-051: add and rename sheds and pens
        'manage_feed_store' => ['ceo', 'manager', 'feeding'], // D-054: feed items and stock
        'plan_rations' => ['ceo', 'manager', 'vet'], // D-054: draft a ration; approve_feeding_plan approves it
        'manage_general_store' => ['ceo', 'manager', 'feeding'], // D-178: general store items and moves
        'manage_purchase' => ['ceo', 'manager', 'finance'], // D-178: suppliers and purchase orders
        'manage_sales' => ['ceo', 'manager', 'finance'], // D-178: customers and sales
        'manage_medicine_store' => ['ceo', 'manager', 'vet'], // D-074: medicines, receipts, disposals
        'manage_devices' => ['ceo', 'manager'], // D-087: add devices, issue their keys
        'edit_goats' => ['ceo', 'manager', 'vet'], // D-091: change a goat's details; ask to remove a wrong entry
        'remove_goats' => ['ceo', 'manager'], // D-091: confirm (or cancel) removing a wrong entry; restore
        'health_checkup' => ['ceo', 'manager', 'vet'], // D-095: routine checkups
        'manage_market' => ['ceo', 'finance'], // D-113: market rates, batch purchase rates, feed price
        'record_milk' => ['ceo', 'manager', 'vet', 'caretaker'], // D-117: milk per session
        'manage_milking' => ['ceo', 'manager', 'vet'], // D-117: dry off, start a lactation by hand, milking settings
        'open_cases' => ['vet', 'ceo'], // D-095: the vet, and the administrator, open health cases (treatments stay with the vet)
    ],

    // Demonstration assumption (Q-PB-2): the real value is confirmed by the veterinarians.
    'demo_gestation_days' => 150,
];
