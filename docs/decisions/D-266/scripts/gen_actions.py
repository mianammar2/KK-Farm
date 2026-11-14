#!/usr/bin/env python3
"""D-266: generate config/kkfarm_actions.php (one entry per write route) and config/kkfarm_action_gates.php (the new gates).
Input: routes JSON from docs/decisions/D-261/scripts/inventory.php. Every write route must match a rule or have an inline check,
or this script stops and lists it (deny by default: nothing is silently left open).
Usage (from apps/kk-farm): php ../../docs/decisions/D-261/scripts/inventory.php > /tmp/routes.json && python3 ../../docs/decisions/D-266/scripts/gen_actions.py /tmp/routes.json"""
import json, re, sys, os
A, M, V, C, F, L, N = 'ceo', 'manager', 'vet', 'caretaker', 'feeding', 'cleaning', 'finance'
EXISTING = {'record_weights', 'assign_tags', 'move_goats', 'record_milk', 'clinical', 'manage_devices', 'manage_feed_store', 'manage_books', 'manage_staff', 'request_end_of_life'}
NEW = {}  # gate -> (label, roles)
def g(key, label, roles): NEW[key] = (label, roles); return key
RULES = [  # (regex on "METHOD /uri", entry)  first match wins
 (r'^POST /fodder/', {'gate': g('manage_fodder', 'Manage fodder fields, crops and lots', [A, M, F])}),
 (r'^POST /fattening/selections', {'gate': g('manage_meat_selection', 'Propose meat selection', [A, M])}),
 (r'^POST /fattening/', {'gate': g('manage_fattening', 'Manage fattening batches and selections', [A, M, C])}),
 (r'^POST /(environment/(water-points|settings)|waste/streams)', {'gate': g('manage_environment_setup', 'Set up water points, waste streams and environment settings', [A, M])}),
 (r'^POST /environment/issues/\{issue\}/(supply|approve-supply|health-check|resolve)', {'gate': g('handle_water_issues', 'Handle water and heat issues', [A, M, V, C, L])}),
 (r'^POST /environment/water/tests', {'gate': g('record_water_tests', 'Record water lab tests', [A, M, V])}),
 (r'^POST /(environment/(rounds|water/report)|waste/disposals)', {'gate': g('record_environment', 'Record shed rounds, water tests and waste', [A, M, C, L])}),
 (r'^POST /transport/trips/\{trip\}/check', {'gate': g('record_transit_check', 'Record a check on the road', [A, M, V, C])}),
 (r'^POST /transport/', {'gate': g('manage_transport', 'Manage vehicles, trips and claims', [A, M])}),
 (r'^POST /feed-cost/settings', {'gate': g('manage_feed_settings', 'Feed cost settings', [A, M])}),
 (r'^POST /feed-cost/', {'gate': 'manage_feed_store'}),
 (r'^POST /weights/review/', {'gate': g('review_weights', 'Review held weights', [A, M, V])}),
 (r'^POST /growth/\{animal\}/intervene', {'gate': g('record_interventions', 'Open a nutrition or vet intervention', [A, M, V])}),
 (r'^POST /growth/interventions/', {'gate': g('record_followup', 'Record an intervention follow-up weight', [A, M, V, C])}),
 (r'^POST /growth/', {'gate': g('manage_growth', 'Growth plans and breeder selection', [A, M])}),
 (r'^POST /weights/\{record\}', {'gate': 'record_weights'}),
 (r'^POST /quality/', {'gate': g('manage_quality', 'Manage quality cases, holds and SOPs', [A, M, V])}),
 (r'^POST /welfare/end-of-life/\{review\}/withdraw', {'gate': 'request_end_of_life', 'inline': 'the requester or an authoriser'}),
 (r'^POST /welfare/(checks|cases)$', {'gate': g('record_welfare_checks', 'Record welfare checks and report a welfare case', [A, M, V, C])}),
 (r'^POST /maintenance/assets', {'gate': g('manage_maintenance', 'Manage assets and close maintenance jobs', [A, M])}),
 (r'^POST /maintenance/jobs/\{job\}/close', {'gate': 'manage_maintenance', 'inline': 'manager check'}),
 (r'^POST /maintenance/jobs$', {'gate': g('report_fault', 'Report a fault', [A, M, C, F, L])}),
 (r'^POST /operations/(templates|runs)', {'gate': g('manage_checklists', 'Manage and verify checklists', [A, M])}),
 (r'^POST /operations/handovers', {'gate': g('record_handover', 'Write a shift handover', [A, M, V, C, F, L])}),
 (r'^POST /operations/checklists/\{template\}/tick', {'module': 'tasks', 'why': 'any holder of Operations ticks the checklist they are given'}),
 (r'^POST /tasks/\{task\}/(toggle|status)', {'module': 'tasks', 'inline': 'Task::visibleTo: own and department tasks only'}),
 (r'^POST /worker/start', {'module': 'scan', 'inline': 'WorkerController::allowed(job)'}),
 (r'^POST /worker/(stop|done)', {'open': 'ends or counts the person\'s own running job (session only)'}),
 (r'^POST /(logout|switch-role|account/password)$', {'open': 'the signed-in person\'s own session and password (switch-role works only with the local demo login)'}),
 (r'^POST /intake/settings', {'gate': g('manage_intake', 'Intake and quarantine settings', [A, M])}),
 (r'^POST /purchase/animals/\{lot\}/(cancel|reconcile)', {'gate': g('manage_purchase_lots', 'Cancel or reconcile goat purchase lots', [A, M, N])}),
 (r'^POST /purchase/animals/\{lot\}/receive', {'gate': g('receive_goat_lot', 'Receive a goat purchase lot (health check)', [A, M, V])}),
 (r'^POST /goats/rfid/disarm', {'gate': 'assign_tags'}),
 (r'^POST /goats/\{animal\}/photos', {'gate': g('add_goat_photos', 'Add goat photos', [A, M, V, C])}),
 (r'^POST /repro-loss/staff/', {'gate': 'manage_staff', 'inline': 'owner or manager check'}),
 (r'^POST /repro-loss/\{case\}/(contain|material|sample)', {'module': 'breeding', 'inline': 'ReproLoss::canHandle: the vet or trained staff'}),
 (r'^POST /genetics/', {'gate': g('manage_breeding', 'Genetics, breeding settings and closing matings', [A, M, V])}),
 (r'^POST /breeding/matings/\{mating\}/close', {'gate': 'manage_breeding'}),
 (r'^POST /breeding/(kid-care|weanings|matings/\{mating\}/kidding)', {'gate': g('record_breeding', 'Record kidding, kid care and weaning', [A, M, V, C])}),
 (r'^POST /health/antimicrobials/', {'gate': 'clinical'}),
 (r'^POST /parasites/samples', {'gate': g('record_parasite_samples', 'Record parasite samples', [A, M, V, C])}),
 (r'^POST /feeding/lots/limits', {'gate': g('manage_feed_lots', 'Feed lot limits', [A, M, F])}),
 (r'^POST /feeding/(record|leftover)', {'gate': g('record_feeding', 'Record feed given and left over', [A, M, F])}),
 (r'^POST /housing/pens/\{pen\}/status', {'gate': g('set_pen_status', 'Set a pen\'s status (restricted, closed)', [A, M, V])}),
 (r'^POST /housing/(pens/\{pen\}/clean|cleanings)', {'gate': g('record_cleaning', 'Record pen cleaning', [A, M, C, L])}),
 (r'^POST /presence', {'gate': 'move_goats'}),
 (r'^POST /hr/(training|skills|leave/\{leave\})', {'gate': 'manage_staff', 'inline': 'manager check'}),
 (r'^POST /dairy/sessions', {'gate': 'record_milk'}),
 (r'^POST /dairy/lots/\{lot\}/(hold|recall)', {'gate': g('manage_milk_lots', 'Hold or recall milk lots', [A, M])}),
 (r'^POST /finance/', {'gate': 'manage_books'}),
 (r'^POST /sensors/alerts/', {'gate': 'manage_devices'}),
 (r'^(POST|DELETE) /settings/', {'module': 'settings', 'why': 'Settings is the Administrator\'s alone (Access::module)'}),
]
def inline_abort(x):
    """True when the action itself aborts on a role, right or helper (hard-coded role lists included): the rule must not widen or contradict it."""
    try: L = open(x['file']).read().splitlines()
    except Exception: return False
    i = x['line'] - 1; body = []
    for j in range(i, min(i + 70, len(L))):
        body.append(L[j])
        if j > i and L[j].startswith('    }'): break
    return any(re.search(r'abort_(unless|if)', l) and re.search(r'role|passes|canModule|allowed|Pages|vet|manager|reviewer|ceo|lead\(|supervisor|approver', l) for l in body)
routes = json.load(open(sys.argv[1]))
out, missing = {}, []
for x in routes:
    if not x['auth'] or x['methods'] == 'GET': continue
    for m in x['methods'].split('|'):
        key = f"{m} {x['uri']}"
        mods = x['modules']
        mod = (mods[0] if len(mods) == 1 else mods) if mods else None
        inl = inline_abort(x) or bool(x['gates'] or x['roleChecks'] or x['canModule'] or x['helpers'] or x['pageChecks'])
        hit = None if inl else next((e for rx, e in RULES if re.search(rx, key)), None)
        if hit:
            e = dict(hit)
            if 'module' not in e and 'open' not in e and mod: e['module'] = mod
        elif inl:
            # rules that name a worker/tasks inline note still apply to those routes (they carry their own explanation)
            forced = next((e for rx, e in RULES if re.search(rx, key) and ('inline' in e or 'open' in e)), None)
            e = dict(forced) if forced else {'inline': 'the action checks ' + ', '.join(x['gates'] or ['the person\'s role or a right'])}
            if mod: e['module'] = mod
            else: e['open'] = 'no single app owns this route: the action checks the job or master list itself'
        else:
            missing.append(key); continue
        out[key] = e
if missing:
    print('UNASSIGNED write routes (add a rule):'); [print('  ', k) for k in missing]; sys.exit(1)
used = {gg for e in out.values() for gg in ([e['gate']] if isinstance(e.get('gate'), str) else e.get('gate', []))}
NEW = {k: v for k, v in NEW.items() if k in used}  # a gate no row uses would be a control that does nothing
def php(v, ind=0):
    if isinstance(v, list): return '[' + ', '.join(php(i) for i in v) + ']'
    if isinstance(v, str): return "'" + v.replace('\\', '\\\\').replace("'", "\\'") + "'"
    return str(v)
cfg = os.getcwd() + '/config'
lines = ["<?php", "", "/*", " * D-266: the action registry. One row per write route (POST/PUT/PATCH/DELETE): the rule the server enforces before the request reaches its controller.",
         " * Deny by default: a write route with no row answers 403 (App\\Http\\Middleware\\EnforceAction), and tests/Feature/ActionRegistryTest.php fails on it.",
         " * Row keys: 'module' (the person holds the app; a list means any one of them), 'gate' (the person holds the action; a list means any one),",
         " * 'open' (anyone signed in: needs a reason), 'inline' (the controller makes its own further check), 'why' (a note for the reader).",
         " * Generated by docs/decisions/D-266/scripts/gen_actions.py; edit rows by hand afterwards as the Access Rights panel takes them over.",
         " */", "", "return ["]
for k in sorted(out): lines.append(f"    {php(k)} => [" + ', '.join(f"{php(a)} => {php(b)}" for a, b in out[k].items()) + "],")
lines.append("];")
open(cfg + '/kkfarm_actions.php', 'w').write('\n'.join(lines) + '\n')
gl = ["<?php", "", "// D-266: the actions the action registry introduces. Defaults keep today's reach, minus what the D-261 audit and owner decisions took away.",
      "// Merged into config('kkfarm.gates') and Access::labels(). Generated by docs/decisions/D-266/scripts/gen_actions.py.", "", "return [", "    'gates' => ["]
for k, (lab, roles) in NEW.items(): gl.append(f"        {php(k)} => {php(roles)},")
gl += ["    ],", "    'labels' => ["]
for k, (lab, roles) in NEW.items(): gl.append(f"        {php(k)} => {php(lab)},")
gl += ["    ],", "];"]
open(cfg + '/kkfarm_action_gates.php', 'w').write('\n'.join(gl) + '\n')
print(f"{len(out)} write routes declared; {len(NEW)} new gates")
