#!/usr/bin/env python3
"""D-293: find every <select> that picks a goat and turn it into <GoatSelect> (same options, value and onChange).
A select picks a goat when the field it sets is a goat id (animal_id, dam_id, sire_id, ...) or its label names a goat.
Usage from apps/kk-farm: python3 ../../docs/decisions/D-266/scripts/goat_selects.py [--apply]"""
import re, sys, glob, os
GOAT_KEYS = r"(animal_id|dam_id|sire_id|foster_dam_id|planned_sire_id|doe_id|buck_id|kid_id|goat_id|mother_id|father_id|candidate_id)"
GOAT_LABEL = r'label="(Goat|Doe|Buck|Sire|Dam|Mother|Father|Kid|Dead goat|Foster doe|Planned buck|For a goat|Goat \(optional\)|Sire \(if known\)|Dam \(if known\))'
apply = '--apply' in sys.argv
total = 0
for p in sorted(glob.glob('resources/js/**/*.tsx', recursive=True)):
    if p.endswith('GoatSelect.tsx'): continue
    s = open(p).read()
    spans = []
    for m in re.finditer(r'<select\b', s):
        end_open = s.index('>', m.start())
        open_tag = s[m.start():end_open + 1]
        before = s[max(0, m.start() - 260):m.start()]
        is_goat = re.search(r'\.data\.' + GOAT_KEYS + r'\b', open_tag) or re.search(r"setData\('" + GOAT_KEYS + "'", open_tag) or re.search(GOAT_LABEL + r'[^<]*$', before.split('<Field')[-1] if '<Field' in before else '') or 'aria-label="Choose goat"' in open_tag
        if is_goat and ('.data.class' in open_tag or 'mother_status' in open_tag):
            is_goat = None  # not a goat id (a class, a mother's condition)
        if is_goat:
            close = s.index('</select>', end_open)
            spans.append((m.start(), close))
    if not spans: continue
    rel = os.path.relpath(p)
    for a, c in spans:
        line = s.count('\n', 0, a) + 1
        print(f'{rel}:{line}  {s[a:a+110].splitlines()[0]}')
    total += len(spans)
    if apply:
        for a, c in reversed(spans):
            s = s[:c] + '</GoatSelect>' + s[c + len('</select>'):]
            s = s[:a] + '<GoatSelect' + s[a + len('<select'):]
        depth = p.count('/') - 'resources/js/'.count('/')
        imp = "import GoatSelect from '" + ('../' * depth if depth else './') + "components/GoatSelect';\n"
        if 'components/' in p and p.startswith('resources/js/components/'):
            imp = "import GoatSelect from './GoatSelect';\n"
        if 'GoatSelect from' not in s:
            first_import_end = s.index('\n', s.index('import ')) + 1
            s = s[:first_import_end] + imp + s[first_import_end:]
        open(p, 'w').write(s)
print(total, 'goat fields', '(changed)' if apply else '(dry run)')
