import { Head, router } from '@inertiajs/react';
import { ScanLine } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Sheet from '../components/Sheet';
import { Button, Empty, Field, PageHeader, Panel, Stat, StatRow } from '../components/ui';
import { haptic } from '../lib/native';

/**
 * D-338: count goats by scanning. Choose the whole farm, a shed or a PEN, press Start count, then scan: the gun's trigger
 * (one or many tags at once), a touch tag, or a typed Tag ID. A pop card shows the total, each breed and sex against what
 * should be there, live; Save keeps the count with what was missing, what belongs elsewhere and tags on no goat.
 */
type Goat = { id: string; number: string; breed: string; sex: string; penId: number | null; shedId: number | null; pen: string | null; tags: string[] };
type Shed = { id: number; name: string; pens: Array<{ id: number; name: string }> };
type Saved = { id: number; on: string; scope: string; expected: number; counted: number; missing: string[]; extra: string[]; unknown: string[]; byBreed: Record<string, { expected: number; counted: number }>; by: string; notes: string | null };
type Props = { goats: Goat[]; sheds: Shed[]; history: Saved[] };
type Scope = 'farm' | 'shed' | 'pen';

const SEX: Record<string, string> = { male: 'Male', female: 'Female' };
type Hook = { kkScan?: (code: string) => boolean; kkScanMany?: (codes: string[]) => boolean };

export default function GoatCount({ goats, sheds, history }: Props) {
    const [scope, setScope] = useState<Scope>('farm');
    const [shedId, setShedId] = useState('');
    const [penId, setPenId] = useState('');
    const [counting, setCounting] = useState(false);
    const [seen, setSeen] = useState<string[]>([]); // goat ids, in scan order
    const [unknown, setUnknown] = useState<string[]>([]);
    const [last, setLast] = useState<{ text: string; tone: 'good' | 'warn' | 'bad' } | null>(null);
    const [typed, setTyped] = useState('');
    const [notes, setNotes] = useState('');
    const [saving, setSaving] = useState(false);
    const [view, setView] = useState<Saved | null>(null);
    const box = useRef<HTMLInputElement>(null);

    const byCode = useMemo(() => {
        const m = new Map<string, Goat>();
        goats.forEach((g) => { m.set(g.number.toUpperCase(), g); g.tags.forEach((t) => m.set(t.toUpperCase(), g)); });
        return m;
    }, [goats]);
    const byId = useMemo(() => new Map(goats.map((g) => [g.id, g])), [goats]);
    const expected = useMemo(() => goats.filter((g) => scope === 'farm' || (scope === 'shed' ? String(g.shedId) === shedId : String(g.penId) === penId)), [goats, scope, shedId, penId]);
    const expectedIds = useMemo(() => new Set(expected.map((g) => g.id)), [expected]);
    const scopeLabel = scope === 'farm' ? 'Whole farm' : scope === 'shed' ? `Shed ${sheds.find((s) => String(s.id) === shedId)?.name ?? ''}` : `PEN ${sheds.flatMap((s) => s.pens).find((p) => String(p.id) === penId)?.name ?? ''}`;
    const ready = scope === 'farm' || (scope === 'shed' ? shedId !== '' : penId !== '');

    const seenRef = useRef<string[]>([]);
    const reset = () => { seenRef.current = []; setSeen([]); setUnknown([]); setLast(null); };
    const take = useCallback((codes: string[]) => {
        let added = 0; let other = 0; const bad: string[] = [];
        const next = [...seenRef.current];
        codes.forEach((raw) => {
            const g = byCode.get(raw.trim().toUpperCase());
            if (!g) { bad.push(raw.trim().toUpperCase()); return; }
            if (next.includes(g.id)) return;
            next.push(g.id); added++;
            if (!expectedIds.has(g.id)) other++;
        });
        seenRef.current = next;
        setSeen(next);
        if (bad.length) setUnknown((u) => Array.from(new Set([...u, ...bad])));
        if (codes.length === 1) {
            const g = byCode.get(codes[0].trim().toUpperCase());
            setLast(!g ? { text: `${codes[0].toUpperCase()}: no goat has this tag`, tone: 'bad' } : added ? { text: `${g.number} · ${g.breed}${other ? ` · belongs to ${g.pen ?? 'another PEN'}` : ''}`, tone: other ? 'warn' : 'good' } : { text: `${g.number} already counted`, tone: 'warn' });
        } else {
            setLast({ text: `${codes.length} tags read: ${added} new${other ? `, ${other} from elsewhere` : ''}${bad.length ? `, ${bad.length} on no goat` : ''}`, tone: bad.length || other ? 'warn' : 'good' });
        }
        haptic(added ? 35 : 120);
    }, [byCode, expectedIds]);

    // the gun (trigger reads, touch tags) hands its reads to this page while a count is open
    useEffect(() => {
        if (!counting) return;
        const w = window as unknown as Hook;
        const before = { one: w.kkScan, many: w.kkScanMany };
        w.kkScan = (code) => { take([code]); return true; };
        w.kkScanMany = (codes) => { take(codes); return true; };
        const focus = window.setInterval(() => { if (document.activeElement?.tagName !== 'INPUT' && document.activeElement?.tagName !== 'TEXTAREA') box.current?.focus({ preventScroll: true }); }, 800);
        return () => { w.kkScan = before.one; w.kkScanMany = before.many; window.clearInterval(focus); };
    }, [counting, take]);

    const start = () => { reset(); setNotes(''); setCounting(true); setTimeout(() => box.current?.focus(), 60); };
    const save = () => {
        setSaving(true);
        router.post('/goats/count', { scope, shed_id: scope === 'shed' ? shedId : null, pen_id: scope === 'pen' ? penId : null, counted_ids: seen, unknown, notes: notes || null }, {
            preserveScroll: true, onSuccess: () => setCounting(false), onFinish: () => setSaving(false),
        });
    };

    const counted = seen.map((id) => byId.get(id)).filter((g): g is Goat => Boolean(g));
    const inScope = counted.filter((g) => expectedIds.has(g.id));
    const extra = counted.filter((g) => !expectedIds.has(g.id));
    const missing = expected.filter((g) => !seen.includes(g.id));
    const breeds = useMemo(() => {
        const m = new Map<string, { expected: number; counted: number }>();
        expected.forEach((g) => { const r = m.get(g.breed) ?? { expected: 0, counted: 0 }; r.expected++; m.set(g.breed, r); });
        counted.forEach((g) => { const r = m.get(g.breed) ?? { expected: 0, counted: 0 }; r.counted++; m.set(g.breed, r); });
        return Array.from(m.entries()).sort((a, b) => a[0].localeCompare(b[0]));
    }, [expected, counted]);
    const sexes = (list: Goat[]) => Object.entries(list.reduce<Record<string, number>>((a, g) => ({ ...a, [g.sex]: (a[g.sex] ?? 0) + 1 }), {})).map(([k, n]) => `${SEX[k] ?? k} ${n}`).join(' · ');

    return (
        <>
            <Head title="Goat Count" />
            <PageHeader title="Goat Count" description="Count the goats by scanning their tags. See the total, each breed and sex against what should be there, and which goats were not scanned." />

            <Panel title="New count">
                <div className="grid gap-3 sm:grid-cols-3 sm:items-end">
                    <Field label="Where">
                        <select className="field" value={scope} onChange={(e) => setScope(e.target.value as Scope)}>
                            <option value="farm">Whole farm</option>
                            <option value="shed">One shed</option>
                            <option value="pen">One PEN</option>
                        </select>
                    </Field>
                    {scope === 'shed' ? (
                        <Field label="Shed"><select className="field" value={shedId} onChange={(e) => setShedId(e.target.value)}><option value="">Choose…</option>{sheds.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
                    ) : scope === 'pen' ? (
                        <Field label="PEN"><select className="field" value={penId} onChange={(e) => setPenId(e.target.value)}><option value="">Choose…</option>{sheds.map((s) => <optgroup key={s.id} label={s.name}>{s.pens.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</optgroup>)}</select></Field>
                    ) : <div className="hidden sm:block" />}
                    <Button type="button" onClick={start} disabled={!ready}><ScanLine size={16} aria-hidden /> Start count</Button>
                </div>
                <p className="mt-3 text-sm text-ink-soft">{ready ? `${expected.length} goats should be here.` : 'Choose where to count.'} Scan with the gun's trigger (many goats at once), touch a tag, or type a Tag ID.</p>
            </Panel>

            <Panel flush title="Saved counts" className="mt-6">
                {history.length ? (
                    <div className="overflow-x-auto">
                        <table className="data-table min-w-[640px]">
                            <thead><tr><th>Date</th><th>Where</th><th>Counted</th><th>Should be</th><th>Not scanned</th><th>From elsewhere</th><th>By</th></tr></thead>
                            <tbody>{history.map((c) => (
                                <tr key={c.id}>
                                    <td><button type="button" className="text-cobalt-800 hover:underline" onClick={() => setView(c)}>{c.on}</button></td>
                                    <td>{c.scope}</td><td>{c.counted}</td><td>{c.expected}</td><td>{c.missing.length || '—'}</td><td>{c.extra.length || '—'}</td><td>{c.by}</td>
                                </tr>
                            ))}</tbody>
                        </table>
                    </div>
                ) : <Empty title="No counts yet">Start a count above.</Empty>}
            </Panel>

            {counting ? (
                <Sheet onClose={() => { if (!seen.length || window.confirm('Close without saving this count?')) setCounting(false); }}>
                    <div className="p-5 sm:p-6">
                        <h2 className="text-lg font-semibold text-cobalt-950">Counting: {scopeLabel}</h2>
                        <StatRow>
                            <Stat label="Counted" value={`${inScope.length} of ${expected.length}`} tone={inScope.length === expected.length && expected.length > 0 ? 'good' : undefined} note={sexes(inScope) || undefined} />
                            <Stat label="Not scanned yet" value={missing.length} tone={missing.length ? 'alert' : 'good'} />
                            <Stat label="From elsewhere" value={extra.length} tone={extra.length ? 'alert' : undefined} note={extra.length ? 'Scanned here, recorded in another PEN' : undefined} />
                        </StatRow>
                        <form className="mt-4 flex gap-2" onSubmit={(e) => { e.preventDefault(); if (typed.trim()) { take([typed]); setTyped(''); } }}>
                            <input ref={box} className="field font-mono uppercase" value={typed} onChange={(e) => setTyped(e.target.value.toUpperCase())} placeholder="Scan, touch a tag, or type a Tag ID" autoComplete="off" spellCheck={false} />
                            <Button type="submit" variant="secondary">Add</Button>
                        </form>
                        {last ? <p className={`mt-2 text-sm ${last.tone === 'good' ? 'text-turq-800' : last.tone === 'warn' ? 'text-watch-700' : 'text-crit-700'}`}>{last.text}</p> : null}

                        <table className="data-table mt-4">
                            <thead><tr><th>Breed</th><th>Counted</th><th>Should be</th></tr></thead>
                            <tbody>{breeds.map(([b, r]) => <tr key={b}><td>{b}</td><td>{r.counted}</td><td>{r.expected}</td></tr>)}</tbody>
                        </table>

                        {missing.length ? (
                            <details className="mt-4"><summary className="cursor-pointer text-sm text-cobalt-800">Not scanned yet ({missing.length})</summary>
                                <p className="mt-2 font-mono text-sm leading-6">{missing.map((g) => g.number).join(', ')}</p></details>
                        ) : null}
                        {extra.length ? (
                            <details className="mt-2"><summary className="cursor-pointer text-sm text-cobalt-800">From elsewhere ({extra.length})</summary>
                                <ul className="mt-2 text-sm">{extra.map((g) => <li key={g.id}><span className="font-mono">{g.number}</span> · recorded in {g.pen ?? 'no PEN'}</li>)}</ul></details>
                        ) : null}
                        {unknown.length ? (
                            <details className="mt-2"><summary className="cursor-pointer text-sm text-cobalt-800">Tags on no goat ({unknown.length})</summary>
                                <p className="mt-2 font-mono text-sm">{unknown.join(', ')}</p></details>
                        ) : null}

                        <Field label="Notes"><input className="field" value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={200} /></Field>
                        <div className="mt-4 flex flex-wrap justify-end gap-2">
                            <Button type="button" variant="secondary" onClick={() => { if (window.confirm('Start this count again from zero?')) reset(); }}>Start again</Button>
                            <Button type="button" onClick={save} disabled={saving || (!seen.length && !unknown.length)}>Save count</Button>
                        </div>
                    </div>
                </Sheet>
            ) : null}

            {view ? (
                <Sheet onClose={() => setView(null)}>
                    <div className="p-5 sm:p-6">
                        <h2 className="text-lg font-semibold text-cobalt-950">{view.scope} · {view.on}</h2>
                        <p className="text-sm text-ink-soft">Counted by {view.by}{view.notes ? ` · ${view.notes}` : ''}</p>
                        <StatRow>
                            <Stat label="Counted" value={`${view.counted - view.extra.length} of ${view.expected}`} />
                            <Stat label="Not scanned" value={view.missing.length} tone={view.missing.length ? 'alert' : 'good'} />
                            <Stat label="From elsewhere" value={view.extra.length} />
                        </StatRow>
                        <table className="data-table mt-4">
                            <thead><tr><th>Breed</th><th>Counted</th><th>Should be</th></tr></thead>
                            <tbody>{Object.entries(view.byBreed).map(([b, r]) => <tr key={b}><td>{b}</td><td>{r.counted}</td><td>{r.expected}</td></tr>)}</tbody>
                        </table>
                        {view.missing.length ? <p className="mt-4 text-sm"><span className="text-ink-soft">Not scanned:</span> <span className="font-mono">{view.missing.join(', ')}</span></p> : null}
                        {view.extra.length ? <p className="mt-2 text-sm"><span className="text-ink-soft">From elsewhere:</span> <span className="font-mono">{view.extra.join(', ')}</span></p> : null}
                        {view.unknown.length ? <p className="mt-2 text-sm"><span className="text-ink-soft">Tags on no goat:</span> <span className="font-mono">{view.unknown.join(', ')}</span></p> : null}
                    </div>
                </Sheet>
            ) : null}
        </>
    );
}
