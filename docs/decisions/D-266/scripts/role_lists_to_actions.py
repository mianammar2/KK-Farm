#!/usr/bin/env python3
"""D-266 step 5b: replace the role-name lists that decide an action inside controllers with named actions (gates), keeping today's reach.
One-off refactor, kept for the record. Run from apps/kk-farm. Each action's default roles are exactly the list it replaces."""
import re, sys, json
A, M, V, C, F, L, N = 'ceo', 'manager', 'vet', 'caretaker', 'feeding', 'cleaning', 'finance'
G = {}  # gate -> (label, roles)
def g(k, label, roles): G[k] = (label, roles); return k
def key(roles): return ','.join(roles)
# file -> {roles tuple -> gate}  (replaces in_array(<user>->role, [...], true))
LISTS = {
 'FodderController': {key([A, M]): g('manage_fodder_setup', 'Set up fodder fields and crops', [A, M]), key([A, M, V]): g('release_fodder', 'Release fodder lots into feed stock', [A, M, V])},
 'Fodder': {key([A, M, V]): 'release_fodder'},
 'BreedingController': {key([A, M, V]): 'manage_breeding'},
 'GeneticsController': {key([A, M, V]): 'manage_breeding'},
 'WeightReviewController': {key([A, M, V]): 'review_weights'},
 'TransportController': {key([A, M]): 'manage_transport', key([A, M, C]): 'record_transit_check'},
 'ReproLossController': {key([A, M]): 'manage_staff'},
 'TrainingController': {key([A, M]): 'manage_staff'},
 'HrController': {key([A, M]): 'manage_staff'},
 'BirthToBreederController': {key([A, M, V]): g('select_breeders', 'Decide breeder and sale selection', [A, M, V])},
 'SaleOrderController': {key([A, M]): g('approve_sale_orders', 'Approve price quotes and sale orders', [A, M])},
 'HousingController': {key([A, M, V]): 'set_pen_status'},
 'WasteController': {key([A, M, V]): g('manage_waste_routes', 'Record waste routes and approve disposals', [A, M, V])},
 'OperationsController': {key([A, M]): 'manage_checklists'},
 'AntimicrobialController': {key([V, A]): g('mark_antimicrobials', 'Mark critically important antibiotics', [A, V])},
 'MaintenanceController': {key([A, M]): 'manage_maintenance'},
 'AnimalPurchaseController': {key([A, M]): g('approve_goat_purchase', 'Approve and reconcile goat purchases', [A, M]), key([A, M, V]): 'receive_goat_lot'},
 'IntakeController': {key([A, V]): g('configure_intake', 'Intake and quarantine settings', [A, V])},
 'EnvironmentController': {key([A, M, V]): g('lead_environment', 'Set up water points and waste, approve supply', [A, M, V]), key([A, V]): g('set_heat_limit', 'Set the heat-stress limit', [A, V])},
 'FeedCostController': {key([A, M, V, F]): g('review_feed_pens', 'Review feed pens and counts', [A, M, V, F]), key([A, M, V]): g('set_feed_cost_settings', 'Feed cost settings', [A, M, V])},
 'FatteningController': {key([A, M]): g('supervise_fattening', 'Create finishing batches and decide selections', [A, M])},
 'DairyController': {key([A, M, V]): g('manage_milk_quality', 'Test, release and trace milk lots', [A, M, V])},
 'FeedLotController': {key([A, M, V]): g('release_feed_lots', 'Test and release feed lots', [A, M, V]), key([A, V]): g('set_feed_lot_limits', 'Feed lot safety limits', [A, V])},
 'MilkProcessingController': {key([A, M, V]): g('manage_milk_processing', 'Manage milk processing batches', [A, M, V])},
 'QualityController': {key([A, M, V]): 'manage_quality'},
 'WaterIncident': {key([A, M, V]): g('approve_water_safe', 'Approve water as safe again', [A, M, V])},
 'Movements': {key([A, M]): g('approve_movements', 'Record exits and corrections in movements', [A, M])},
 'EndOfLifeController': {key([A, M, V]): 'request_end_of_life', key([A, M]): g('authorise_end_of_life', 'Authorise a humane end of life', [A, M])},
 'TaskController': {key([A, M]): g('assign_tasks', 'Give tasks to other people and departments', [A, M])},
 'Task': {key([A, M]): g('see_all_tasks', 'See every task, not only their own and their department\'s', [A, M])},
}
SPECIAL = {  # file -> [(old, new)] exact, applied first
 'FodderController': [("abort_unless($request->user()->role === 'ceo', 403, 'The owner turns Fodder & Pasture on or off.');", "abort_unless($request->user()->passes('switch_fodder'), 403, 'The owner turns Fodder & Pasture on or off.');"),
                       ("'isOwner' => $request->user()->role === 'ceo'", "'isOwner' => $request->user()->passes('switch_fodder')"),
                       ("$this->manager($request) || $request->user()->role === 'feeding'", "$request->user()->passes('record_fodder_work')")],
 'TransportController': [],
 'OperationsController': [("$this->supervisor($request) || $request->user()->role === 'vet'", "$request->user()->passes('resolve_incidents')"), ("$this->supervisor($request) || $user->role === 'vet'", "$user->passes('resolve_incidents')")],
 'FatteningController': [("$this->supervisor($request) || $request->user()->role === 'finance'", "$request->user()->passes('settle_fattening')")],
 'DairyController': [("$this->quality($request) || $request->user()->role === 'caretaker'", "$request->user()->passes('test_milk_lots')"), ("$this->quality($request) || $request->user()->role === 'finance'", "$request->user()->passes('handle_milk_money')")],
 'MilkProcessingController': [("$this->supervisor($request) || $request->user()->role === 'caretaker'", "$request->user()->passes('record_milk_processing')")],
 'QualityController': [("$this->lead($request) || $request->user()->role === 'finance'", "$request->user()->passes('settle_quality_costs')")],
 'DiseaseController': [("in_array($request->user()->role, ['ceo', 'manager'], true) || $request->user()->passes('clinical')", "$request->user()->passes('manage_disease_control') || $request->user()->passes('clinical')")],
 'WelfareController': [("in_array($user->role, ['ceo', 'manager'], true)", "$user->passes('manage_welfare_cases')")],
 'EndOfLifeController': [("'isVet' => $role === 'vet', 'canAuthorise' => in_array($role, ['ceo', 'manager'], true)", "'isVet' => $request->user()->passes('clinical'), 'canAuthorise' => $request->user()->passes('authorise_end_of_life')"), ("abort_unless($review->requested_by === $request->user()->name || in_array($request->user()->role, ['ceo', 'manager', 'vet'], true), 403);", "abort_unless($review->requested_by === $request->user()->name || $request->user()->passes('request_end_of_life'), 403);")],
 'HealthController': [("($request->user()->passes('health_checkup') || $request->user()->role === 'ceo')", "($request->user()->passes('health_checkup') || $request->user()->passes('correct_health_records'))"), ("$request->user()->passes('clinical') || $request->user()->role === 'ceo'", "$request->user()->passes('clinical') || $request->user()->passes('keep_vet_masters')")],
 'ActivityController': [("->when($user->role !== 'ceo',", "->when(! $user->passes('see_security_log'),")],
 'Revisions': [("$user->role === 'ceo' ||", "$user->passes('correct_health_records') ||")],
 'AntimicrobialController': [],
 'ReproLossController': [],
}
SEP = [("&& $request->user()->role !== 'ceo'", "&& ! $request->user()->passes('override_separation')"), ("&& $user->role !== 'ceo'", "&& ! $user->passes('override_separation')"), ("&& $by->role !== 'ceo'", "&& ! $by->passes('override_separation')")]
g('record_fodder_work', 'Record fodder crops, inputs and harvests', [A, M, F]); g('switch_fodder', 'Switch the Fodder app on or off', [A])
g('resolve_incidents', 'Resolve incidents and place holds', [A, M, V]); g('settle_fattening', 'Sell and settle fattening animals', [A, M, N])
g('test_milk_lots', 'Test milk lots', [A, M, V, C]); g('handle_milk_money', 'Handle milk invoices, orders and dispatch', [A, M, V, N]); g('record_milk_processing', 'Record milk processing batches', [A, M, V, C])
g('settle_quality_costs', 'Settle quality costs', [A, M, V, N]); g('manage_disease_control', 'Manage disease control and outbreaks', [A, M]); g('manage_welfare_cases', 'Manage welfare cases under vet care', [A, M])
g('correct_health_records', 'Correct health records others made', [A]); g('keep_vet_masters', 'Keep the disease master and protocols', [A])
g('see_security_log', 'See the security entries of the Activity Log', [A]); g('override_separation', 'Approve something they prepared themselves', [A])
total = 0
import glob, os
files = {os.path.basename(p)[:-4]: p for p in glob.glob('app/Http/Controllers/*.php') + glob.glob('app/Support/*.php') + glob.glob('app/Models/*.php')}
for name, rules in LISTS.items():
    p = files[name]; s = open(p).read(); o = s
    for old, new in SPECIAL.get(name, []): s = s.replace(old, new)
    def sub(m):
        roles = [x.strip().strip("'") for x in m.group(2).split(',')]
        k = key(roles); k2 = key(sorted(roles))
        hit = rules.get(k) or next((v for kk, v in rules.items() if sorted(kk.split(',')) == sorted(roles)), None)
        return f"{m.group(1)}->passes('{hit}')" if hit else m.group(0)
    s = re.sub(r"in_array\((\$[A-Za-z>()-]*?)->role, \[([^\]]*)\], true\)", sub, s)
    for old, new in SEP: s = s.replace(old, new)
    if s != o: open(p, 'w').write(s); total += 1
for name, repl in SPECIAL.items():
    if name in LISTS: continue
    p = files[name]; s = open(p).read(); o = s
    for old, new in repl: s = s.replace(old, new)
    if s != o: open(p, 'w').write(s); total += 1
# separation-of-duties checks in files that had no list
for name in ['FeedLotController', 'MilkProcessingController', 'HousingController', 'WasteController', 'EnvironmentController', 'SaleOrderController', 'AnimalPurchaseController', 'QualityController', 'EndOfLifeController', 'FatteningController', 'DairyController', 'WaterIncident', 'Fodder']:
    pass
open('/tmp/role_list_gates.json', 'w').write(json.dumps(G))
print(f'{total} files changed; {len(G)} actions')
