<?php

/*
 * D-241: page-level access (KK_FARMS_RBAC.md §8.3). Permission belongs to the destination page, not the menu it sits
 * in: every menu entry pointing at a page uses that page's rule. A rule is [path, query (null = none), roles];
 * the most specific match wins (path + every query value). ● / v / s in the document all mean "may open"; what
 * a role may do on the page is the action permissions (§5) and later per-page scoping. Module access (§4) still
 * applies on top: a page in a module the role cannot open stays closed.
 *
 * Roles: ceo (ADM) manager (MGR) vet (VET) caretaker (CTK) feeding (FDE) cleaning (CLN) finance (FIN).
 */

$A = 'ceo'; $M = 'manager'; $V = 'vet'; $C = 'caretaker'; $F = 'feeding'; $L = 'cleaning'; $N = 'finance';
$all = [$A, $M, $V, $C, $F, $L, $N];

return [
    // Fieldwork
    ['/worker', null, [$A, $M, $V, $C, $F]],
    ['/scan', null, [$A, $M, $V, $C, $F]],
    ['/account/app', null, [$A, $M, $V, $C, $F]],
    ['/tasks', ['tab' => 'mine'], $all],
    ['/activity', null, [$A, $M]],

    // Dashboard
    ['/', null, [$A, $M]], // Executive Dashboard; everyone else goes to their own home
    ['/dashboard/scorecard', null, [$A]],
    ['/home', null, $all],
    ['/actions', null, $all],
    ['/dashboard/weight-change', null, [$A, $M, $V, $C, $F]],
    ['/reports', null, [$A, $M, $V, $F, $N]],
    ['/settings', null, [$A]],

    // Livestock
    ['/goats', null, [$A, $M, $V, $C, $F]],
    ['/goats/register', null, [$A, $M]],
    ['/intake', null, [$A, $M, $V, $C]],
    ['/goats', ['show' => 'newborn'], [$A, $M, $V, $C]],
    ['/goats', ['show' => 'pregnant'], [$A, $M, $V, $C]],
    ['/goats', ['show' => 'attention'], [$A, $M, $V, $C]],
    ['/goats', ['show' => 'missing'], [$A, $M, $V, $C]],
    ['/goats', ['show' => 'no_pen'], [$A, $M, $C]],
    ['/goats', ['show' => 'no_weight'], [$A, $M, $V, $C]],
    ['/movements', null, [$A, $M, $V, $C, $N]],
    ['/reports', ['section' => 'herd'], [$A, $M, $V]],
    ['/reports', ['section' => 'age'], [$A, $M, $V]],
    ['/reports', ['section' => 'breeds'], [$A, $M, $V]],
    ['/reports', ['section' => 'exits'], [$A, $M, $V, $N]],
    ['/reports', ['section' => 'sires'], [$A, $M, $V]],
    ['/reports', ['section' => 'spending'], [$A, $M, $N]],

    // Breeding & Maternity
    ['/breeding', null, [$A, $M, $V, $C]],
    ['/breeding', ['tab' => 'pedigree'], [$A, $M, $V]],
    ['/breeding', ['tab' => 'performance'], [$A, $M, $V]],
    ['/breeding', ['tab' => 'settings'], [$A, $M, $V]],
    ['/breeding/eligibility', null, [$A, $M, $V]],
    ['/genetics', null, [$A, $M, $V]],
    ['/repro-loss', null, [$A, $M, $V]],

    // Veterinary
    ['/health', null, [$A, $M, $V, $C]],
    ['/health', ['tab' => 'cases'], [$A, $M, $V]],
    ['/health', ['tab' => 'mortality'], [$A, $M, $V]],
    ['/health', ['tab' => 'protocols'], [$A, $V]],
    ['/health', ['tab' => 'diseases'], [$A, $V]],
    ['/health', ['tab' => 'store'], [$A, $M, $V]],
    ['/health/antimicrobials', null, [$A, $M, $V]],
    ['/disease', null, [$A, $M, $V]],
    ['/disease', ['tab' => 'restricted'], [$A, $M, $V, $C]],
    ['/parasites', null, [$A, $M, $V]],

    // Feeding
    ['/feeding', null, [$A, $M, $V, $C, $F]],
    ['/feeding', ['tab' => 'tmr'], [$A, $M, $V, $F]],
    ['/feeding', ['tab' => 'nutrition'], [$A, $M, $V, $F]],
    ['/feeding', ['tab' => 'store'], [$A, $M, $F]],
    ['/feeding', ['tab' => 'times'], [$A, $M, $F]],
    ['/feeding/lots', null, [$A, $M, $V, $F]],
    ['/feed-cost', null, [$A, $M, $N]],
    ['/feed-cost', ['tab' => 'stock'], [$A, $M, $F, $N]], // §8.3 gives FDE the stock count although §4 hides Feed true cost: the page row wins

    // Housing
    ['/sheds', null, [$A, $M, $V, $C, $F]],
    ['/housing', null, [$A, $M, $V, $C, $F, $L]],

    // Growth
    ['/growth', null, [$A, $M, $V, $C, $F]],
    ['/growth', ['tab' => 'selection'], [$A, $M, $V]],
    ['/growth', ['tab' => 'selections'], [$A, $M, $V]],
    ['/growth', ['tab' => 'plan'], [$A, $M]],
    ['/weights', null, [$A, $M, $V, $C]],
    ['/weights/review', null, [$A, $M, $V]],

    // Operations & tasks (lists are scoped to the person's own tasks and department)
    ['/tasks', null, $all],
    ['/operations', null, $all],
    ['/operations', ['tab' => 'handover'], [$A, $M, $V, $C, $F, $L]],
    ['/operations', ['tab' => 'templates'], [$A, $M]],
    ['/employees', null, [$A, $M, $N]],

    // Inventory
    ['/inventory', null, $all],
    ['/inventory', ['tab' => 'general'], [$A, $M, $C, $L, $N]],
    ['/inventory', ['tab' => 'reorder'], [$A, $M, $V, $C, $F, $L]],

    // Purchase
    ['/purchase', null, [$A, $M, $V, $F, $N]],
    ['/purchase', ['tab' => 'suppliers'], [$A, $M, $N]],
    ['/purchase/animals', null, [$A, $M, $V, $N]],
    ['/purchase/animals', ['tab' => 'new'], [$A, $M, $N]],

    // Sales (with herd value and market rates)
    ['/sales', null, [$A, $M, $N]],
    ['/sales/readiness', null, [$A, $M, $N]],
    ['/sales/orders', null, [$A, $M, $N]],
    ['/transport', null, [$A, $M, $N]],
    ['/transport', ['tab' => 'vehicles'], [$A, $M]],
    ['/value', null, [$A, $M, $N]],

    // Accounting
    ['/finance', null, [$A, $M, $N]],
    ['/finance/books', null, [$A, $M, $N]],
    ['/finance/books', ['tab' => 'journal'], [$A, $N]],
    ['/finance/books', ['tab' => 'ledger'], [$A, $N]],
    ['/finance/books', ['tab' => 'trial'], [$A, $N]],
    ['/finance/books', ['tab' => 'accounts'], [$A, $N]],

    // Employees
    ['/hr', null, [$A, $M, $N]],
    ['/hr', ['tab' => 'roster'], [$A, $M]],
    ['/hr/training', null, [$A, $M]],

    // Maintenance & sensors
    ['/maintenance', null, [$A, $M, $C, $F, $L]],
    ['/maintenance', ['tab' => 'due'], [$A, $M]],
    ['/maintenance', ['tab' => 'assets'], [$A, $M]],
    ['/maintenance', ['tab' => 'history'], [$A, $M]],
    ['/sensors', null, [$A, $M]],
    ['/sensors', ['tab' => 'connect'], [$A]],

    // Dairy
    ['/milking', null, [$A, $M, $V, $C]],
    ['/dairy', null, [$A, $M, $V, $C, $N]],
    ['/dairy', ['tab' => 'sessions'], [$A, $M, $C]],
    ['/dairy', ['tab' => 'lots'], [$A, $M, $V, $C]],
    ['/dairy', ['tab' => 'orders'], [$A, $M, $N]],
    ['/dairy', ['tab' => 'trace'], [$A, $M, $N]],
    ['/dairy', ['tab' => 'money'], [$A, $M, $N]],
    ['/dairy/processing', null, [$A, $M, $C]],

    // Fattening
    ['/fattening', null, [$A, $M, $V, $C, $F]],
    ['/fattening', ['tab' => 'selection'], [$A, $M, $C]],
    ['/fattening', ['tab' => 'new'], [$A, $M, $C]],
    ['/fattening', ['tab' => 'profit'], [$A, $M]],

    // Welfare
    ['/welfare', null, [$A, $M, $V, $C]],
    ['/welfare', ['tab' => 'closed'], [$A, $M, $V]],
    ['/welfare/end-of-life', null, [$A, $M, $V]],

    // Environment
    ['/environment', null, [$A, $M, $V, $C, $L]],
    ['/environment', ['tab' => 'tests'], [$A, $M, $V, $L]],
    ['/environment', ['tab' => 'setup'], [$A, $M]],
    ['/waste', null, [$A, $M, $V, $L]],
    ['/waste', ['tab' => 'routes'], [$A, $M]],

    // Quality
    ['/quality', null, [$A, $M, $V]],

    // Fodder
    ['/fodder', null, [$A, $M, $C, $F]],
    ['/fodder', ['tab' => 'lots'], [$A, $M, $F]],
    ['/fodder', ['tab' => 'fields'], [$A, $M, $F]],
    ['/fodder', ['tab' => 'report'], [$A, $M, $F]],
    ['/fodder', ['tab' => 'setup'], [$A, $M]],
];
