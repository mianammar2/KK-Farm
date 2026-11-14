#!/usr/bin/env python3
"""D-261: parse KK_FARMS_RBAC.md §8.3 into page x role marks and compare with the page registry and the crawl.
Usage (from the repo root): python3 docs/decisions/D-261/scripts/spec_matrix.py /Users/MAC/goat/KK_FARMS_RBAC.md
Writes docs/decisions/D-261/pages-matrix.md and pages-diff.json. Read-only for the app."""
import json, re, sys, subprocess, os
ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), '../../../..'))
APP = os.path.join(ROOT, 'apps/kk-farm')
OUT = os.path.join(ROOT, 'docs/decisions/D-261')
ROLES = ['ceo', 'manager', 'vet', 'caretaker', 'feeding', 'cleaning', 'finance']
SHORT = dict(zip(ROLES, ['ADM', 'MGR', 'VET', 'CTK', 'FDE', 'CLN', 'FIN']))
spec = open(sys.argv[1]).read()
sec = spec[spec.index('### 8.3'):spec.index('### 8.4')]
rows = []
module = None
for line in sec.splitlines():
    m = re.match(r'#### (.+?) \(`([^`]+)`\)', line)
    if m:
        module = m.group(1).strip(); continue
    if line.startswith('|') and module and not line.startswith('|---') and 'ADM' not in line:
        cells = [c.strip() for c in line.strip().strip('|').split('|')]
        if len(cells) != 8: continue
        label = cells[0]
        marks = [c.replace('●', 'F').replace('—', '-') for c in cells[1:]]
        marks = ['F' if x.startswith('F') else x for x in marks]
        path = re.findall(r'`([^`]+)`', label)
        rows.append({'module': module, 'label': re.sub(r'\s*`[^`]+`', '', label).replace('**', '').replace('🔒', '').strip(), 'path': path[0] if path else None,
                     'vet_locked': '🔒' in label, 'marks': dict(zip(ROLES, marks))})
reg = json.loads(subprocess.run(['php', '-r', "echo json_encode(require 'config/kkfarm_pages.php');"], cwd=APP, capture_output=True, text=True).stdout)
crawl = json.load(open(os.path.join(APP, 'tests/Feature/rbac/baseline.json')))
def norm(s): return re.sub(r'[^a-z0-9]+', ' ', s.lower()).strip()
by_label = {}
for r in reg: by_label.setdefault(norm(r['label']), []).append(r)
def find(row):
    n = norm(row['label'])
    if n in by_label: return by_label[n]
    # labels like "A / B / C" or "X (hub)"
    parts = [norm(p) for p in re.split(r'/|\(', row['label']) if norm(p)]
    hits = [r for p in parts for r in by_label.get(p, [])]
    if hits: return hits
    if row['path']:
        p = row['path'].split('?')[0]; q = dict(x.split('=') for x in row['path'].split('?')[1].split('&')) if '?' in row['path'] else {}
        hits = [r for r in reg if r['path'] == p and (r['query'] or {}) == q]
        if hits: return hits
    return []
def actual(r):
    url = r['path'] + ('?' + '&'.join(f'{k}={v}' for k, v in r['query'].items()) if r['query'] else '')
    row = crawl.get(f"PAGE {r['key']}  {url}")
    return row
diffs, unmatched, md = [], [], []
md.append('| Module | Spec row | Registry key | ' + ' | '.join(SHORT[x] for x in ROLES) + ' |')
md.append('|---|---|---|' + ':-:|' * 7)
used = set()
for row in rows:
    hits = find(row)
    if not hits:
        unmatched.append(row); md.append(f"| {row['module']} | {row['label']} | **NONE** | " + ' | '.join(row['marks'][x] for x in ROLES) + ' |'); continue
    for r in hits:
        used.add(r['key'])
        a = actual(r) or {}
        cells = []
        for role in ROLES:
            want = row['marks'][role]; code = a.get(role)
            got_open = code in (200, 302) if code is not None else None
            want_open = want != '-'
            mark = f"{want}/{'open' if got_open else 'shut' if got_open is False else '?'}"
            if got_open is not None and got_open != want_open:
                mark = f"**{mark}**"
                diffs.append({'module': row['module'], 'spec': row['label'], 'key': r['key'], 'role': role, 'spec_mark': want, 'actual': code,
                              'kind': 'OVER' if got_open else 'UNDER'})
            cells.append(mark)
        md.append(f"| {row['module']} | {row['label']} | `{r['key']}` | " + ' | '.join(cells) + ' |')
extra = [r for r in reg if r['key'] not in used]
# D-266: the spec's page marks per registry key, for the permanent test (tests/Feature/SpecMatrixTest.php). S-1: a shared page takes the most generous of its rows.
SP = {}
for row in rows:
    for r in find(row):
        cur = SP.setdefault(r['key'], {x: False for x in ROLES})
        for x in ROLES: cur[x] = cur[x] or row['marks'][x] != '-'
json.dump(SP, open(os.path.join(APP, 'tests/Feature/rbac/spec_pages.json'), 'w'), indent=1, sort_keys=True)
json.dump({'diffs': diffs, 'spec_rows_without_registry_key': unmatched, 'registry_keys_without_spec_row': [{'key': r['key'], 'label': r['label'], 'path': r['path'], 'query': r['query'], 'roles': r['roles'], 'lock': r['lock']} for r in extra],
           'counts': {'spec_rows': len(rows), 'registry': len(reg), 'diffs': len(diffs)}}, open(os.path.join(OUT, 'pages-diff.json'), 'w'), indent=1, ensure_ascii=False)
open(os.path.join(OUT, 'pages-matrix.md'), 'w').write('# D-261 Page × role matrix (generated)\n\nCell = spec mark / crawl result. F = full, v = view, s = scoped, - = none. **Bold** = disagreement (spec "-" but the page opens = OVER; spec F/v/s but refused = UNDER). v and s cannot be told apart from F by status code: see the report for view-only and scope findings.\n\n' + '\n'.join(md) + '\n')
print(json.dumps({'spec_rows': len(rows), 'registry': len(reg), 'diffs': len(diffs), 'over': sum(d['kind'] == 'OVER' for d in diffs), 'under': sum(d['kind'] == 'UNDER' for d in diffs), 'unmatched_spec': len(unmatched), 'registry_not_in_spec': len(extra)}))
