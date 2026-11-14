#!/usr/bin/env python3
"""D-261: compare KK_FARMS_RBAC.md §4 (modules) and §5 (actions) with config/kkfarm.php defaults.
Usage: python3 docs/decisions/D-261/scripts/spec_modules_actions.py /Users/MAC/goat/KK_FARMS_RBAC.md  -> docs/decisions/D-261/modules-actions.md"""
import json, re, sys, subprocess, os
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../../..'))
APP = os.path.join(ROOT, 'apps/kk-farm')
ROLES = ['ceo', 'manager', 'vet', 'caretaker', 'feeding', 'cleaning', 'finance']
SH = ['ADM', 'MGR', 'VET', 'CTK', 'FDE', 'CLN', 'FIN']
def tinker(code): return json.loads(subprocess.run(['php', 'artisan', 'tinker', '--execute', code], cwd=APP, capture_output=True, text=True).stdout.strip().splitlines()[-1])
cfg = tinker('echo json_encode(config("kkfarm"));')
labels = tinker('echo json_encode(["m"=>App\\Support\\Access::MODULE_LABELS,"g"=>App\\Support\\Access::GATE_LABELS]);')
spec = open(sys.argv[1]).read()
def table(start, end):
    s = spec[spec.index(start):spec.index(end)]
    out = []
    for line in s.splitlines():
        if line.startswith('|') and not line.startswith('|---') and 'ADM' not in line:
            c = [x.strip() for x in line.strip().strip('|').split('|')]
            if len(c) == 8: out.append(c)
    return out
def norm(s): return re.sub(r'[^a-z0-9]+', ' ', s.lower().replace('🔒', '')).strip()
md = ['# D-261 Module and action matrices (generated)', '', 'Cell = spec / code default. ● full, ◐ limited, ✔ allowed, — none. **Bold** = disagreement.', '', '## Modules (§4 vs `config/kkfarm.php` modules)', '',
      '| Module | key | ' + ' | '.join(SH) + ' |', '|---|---|' + ':-:|' * 7]
mdiff = 0
inv = {norm(v): k for k, v in labels['m'].items()}
alias = {'fieldwork rfid scan': 'scan', 'breeding maternity': 'breeding', 'operations tasks': 'tasks', 'sensors devices': 'sensors', 'environment water climate': 'environment'}
for c in table('## 4. Module', '### Notes on every'):
    k = inv.get(norm(c[0])) or alias.get(norm(c[0]))
    if not k:
        md.append(f'| {c[0]} | **NONE** | ' + ' | '.join(c[1:]) + ' |'); continue
    cells = []
    for i, r in enumerate(ROLES):
        want = c[i + 1] != '—'; got = r in cfg['modules'].get(k, [])
        cell = f"{c[i+1]}/{'✔' if got else '—'}"
        if want != got: cell = f'**{cell}**'; mdiff += 1
        cells.append(cell)
    md.append(f'| {c[0]} | `{k}` | ' + ' | '.join(cells) + ' |')
md += ['', '## Actions (§5 vs `config/kkfarm.php` gates)', '', '| Action | gate | ' + ' | '.join(SH) + ' |', '|---|---|' + ':-:|' * 7]
adiff = 0
ginv = {norm(v): k for k, v in labels['g'].items()}
used = set()
for c in table('## 5. Actions', '🔒 = **Vet-locked'):
    k = ginv.get(norm(c[0]))
    if not k:
        md.append(f'| {c[0]} | **NONE** | ' + ' | '.join(c[1:]) + ' |'); continue
    used.add(k); cells = []
    for i, r in enumerate(ROLES):
        want = c[i + 1].startswith('✔'); got = r in cfg['gates'].get(k, [])
        cell = f"{c[i+1]}/{'✔' if got else '—'}"
        if want != got: cell = f'**{cell}**'; adiff += 1
        cells.append(cell)
    md.append(f'| {c[0]} | `{k}` | ' + ' | '.join(cells) + ' |')
md += ['', '### Gates in code with no §5 row', '', '| gate | label | roles |', '|---|---|---|']
for k, roles in cfg['gates'].items():
    if k not in used: md.append(f"| `{k}` | {labels['g'].get(k, '?')} | {', '.join(SH[ROLES.index(r)] for r in roles)} |")
open(os.path.join(ROOT, 'docs/decisions/D-261/modules-actions.md'), 'w').write('\n'.join(md) + '\n')
print(json.dumps({'module_diffs': mdiff, 'action_diffs': adiff}))
