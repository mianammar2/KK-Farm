import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import DataGrid from '../components/DataGrid';
import { useTabParam } from '../lib/useTabParam';

type Parent = { id: string; number: string; role: string; breed: string | null; active: boolean; purpose: string | null; kids: number; litters: number; litter: number | null; stillPct: number | null; survival: number | null; adg: number | null; casesPerKid: number | null; interval: number | null; merit: number | null; measured: boolean };
type Check = { key: string; ok: boolean; label: string; info?: boolean };
type Candidate = {
    id: string; number: string; sex: string; breed: string | null; pen: string | null; purpose: string | null; checks: Check[]; eligible: boolean;
    sire: { number: string; merit: number | null } | null; dam: { number: string; merit: number | null } | null; pedigree: number; coi: number;
    bucks: Array<{ id: string; number: string; merit: number | null; coi: number }>; last: string | null;
};
type Props = {
    parents: Parent[]; herd: { litter: number | null; survival: number | null; adg: number | null; stillPct: number | null; casesPerKid: number | null; kids: number };
    kpi: { inbreedingAlerts: number; avgCoi: number | null; selected: number; pedigreeComplete: number | null }; limit: number;
    pairing: { dam: string; sire: string; coi: number } | null; candidates: Candidate[];
    selections: Array<{ id: number; goatId: string; number: string | null; decision: string; reason: string | null; override: boolean; sire: string | null; coi: number | null; by: string; on: string }>;
    bucks: Array<{ id: string; number: string }>; does: Array<{ id: string; number: string }>; pens: Array<{ id: number; name: string }>;
    options: { decisions: Record<string, string> }; isLead: boolean;
};

const meritChip = (m: number | null) => (m === null ? <span className="text-xs text-ink-soft">Not enough kids</span> : <Chip className={m >= 105 ? 'bg-ok-100 text-ok-700' : m < 95 ? 'bg-crit-100 text-crit-700' : 'bg-glaze text-cobalt-900'}>{m}</Chip>);

/** D-201: journey L Genetic Selection-to-Replacement (V3 flow 35). */
export default function Genetics(p: Props) {
    const [tab, setTab] = useTabParam('review');
    return (
        <>
            <Head title="Genetic Selection" />
            <PageHeader title="Genetic Selection & Replacement" description="Replacements chosen on measured kid performance, health and welfare. Pedigree completeness is shown, but it is not proof of merit." />
            <StatRow>
                <Stat label="Replacements selected (12 months)" value={p.kpi.selected} />
                <Stat label="Inbreeding alerts (12 months)" value={p.kpi.inbreedingAlerts} tone={p.kpi.inbreedingAlerts ? 'alert' : undefined} note={`Matings above ${p.limit}%${p.kpi.avgCoi !== null ? ` · average ${p.kpi.avgCoi}%` : ''}`} />
                <Stat label="Pedigree complete" value={p.kpi.pedigreeComplete !== null ? `${p.kpi.pedigreeComplete}%` : '—'} note="Goats with sire and dam on record" />
                <Stat label="Herd kid survival" value={p.herd.survival !== null ? `${p.herd.survival}%` : '—'} note={`ADG ${p.herd.adg ?? '—'} g · litter ${p.herd.litter ?? '—'}`} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'review', label: 'Replacement Review', count: p.candidates.filter((c) => c.eligible).length || undefined }, { id: 'progeny', label: 'Sire & Dam Progeny' }, { id: 'mating', label: 'Inbreeding Check' }, { id: 'decisions', label: 'Decisions' }]} />
            {tab === 'review' ? (
                <Panel flush title="Candidates" description="Old enough and not yet decided. Merit index 100 = herd average of the parent's kids (litter size, survival to weaning, growth, less illness).">
                    {p.candidates.length ? <ul className="divide-y divide-rule">{p.candidates.map((c) => <CandidateRow key={c.id} c={c} {...p} />)}</ul> : <Empty title="No candidates of selection age" />}
                </Panel>
            ) : null}
            {tab === 'progeny' ? (
                <Panel flush title="Progeny Performance" description={`Measured from at least 3 kids. Herd: litter ${p.herd.litter ?? '—'}, survival ${p.herd.survival ?? '—'}%, ADG ${p.herd.adg ?? '—'} g/day, ${p.herd.casesPerKid ?? '—'} cases per kid.`}>
                    {p.parents.length ? <DataGrid id="genetics-progeny" name="Progeny performance" rows={p.parents} rowKey={(x) => x.id} cols={[
                        { key: 'number', label: 'Parent', type: 'text', total: 'count', render: (x) => <Link href={`/goats/${x.id}`} className={`font-mono font-semibold text-cobalt-800 hover:underline ${x.active ? '' : 'opacity-60'}`}>{x.number}</Link> },
                        { key: 'role', label: 'Role', type: 'enum', value: (x) => (x.role === 'sire' ? 'Buck' : 'Doe') },
                        { key: 'breed', label: 'Breed', type: 'enum' },
                        { key: 'activeText', label: 'Status', type: 'enum', value: (x) => (x.active ? 'On farm' : 'Left') },
                        { key: 'kids', label: 'Kids', type: 'number', total: 'sum' },
                        { key: 'litter', label: 'Litter size', type: 'number', total: 'avg' },
                        { key: 'stillPct', label: 'Stillborn %', type: 'number', total: 'avg' },
                        { key: 'survival', label: 'Survival %', type: 'number', total: 'avg' },
                        { key: 'adg', label: 'Kid ADG g', type: 'number', total: 'avg' },
                        { key: 'casesPerKid', label: 'Cases / kid', type: 'number', total: 'avg' },
                        { key: 'interval', label: 'Kidding interval (days)', type: 'number', total: 'avg' },
                        { key: 'merit', label: 'Merit', type: 'enum', render: (x) => meritChip(x.merit) },
                    ]} /> : <Empty title="No kids with recorded parents yet" />}
                </Panel>
            ) : null}
            {tab === 'mating' ? <MatingCheck {...p} /> : null}
            {tab === 'decisions' ? (
                <Panel flush title="Replacement Decisions">
                    {p.selections.length ? <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Goat</th><th>Decision</th><th>Planned buck</th><th>Reason</th><th>By</th></tr></thead><tbody>
                        {p.selections.map((s) => <tr key={s.id}><td><Link href={`/goats/${s.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{s.number}</Link></td><td>{p.options.decisions[s.decision]}{s.override ? <Chip className="ml-1 bg-watch-100 text-watch-700">override</Chip> : null}</td><td>{s.sire ? `${s.sire} · COI ${s.coi}%` : '—'}</td><td>{s.reason ?? '—'}</td><td>{s.by} · {s.on}</td></tr>)}
                    </tbody></table></div> : <Empty title="No decisions yet" />}
                </Panel>
            ) : null}
        </>
    );
}

function CandidateRow({ c, pens, options, isLead, limit }: { c: Candidate } & Props) {
    const [open, setOpen] = useState(false);
    const best = c.bucks.find((b) => b.coi <= limit);
    const form = useForm({ decision: c.eligible ? 'replacement' : 'keep', reason: '', planned_sire_id: best?.id ?? '', pen_id: '' });
    const e = form.errors as Record<string, string>;
    const picked = c.bucks.find((b) => b.id === form.data.planned_sire_id);
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <Link href={`/goats/${c.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{c.number}</Link>
                <span className="text-ink-soft">{c.sex === 'female' ? 'Doe' : 'Buck'}{c.breed ? ` · ${c.breed}` : ''}{c.pen ? ` · ${c.pen}` : ''}</span>
                <Chip className={c.eligible ? 'bg-ok-100 text-ok-700' : 'bg-watch-100 text-watch-700'}>{c.eligible ? 'All checks pass' : 'Checks failing'}</Chip>
                <span className="text-xs">Sire {c.sire ? <>{c.sire.number} {meritChip(c.sire.merit)}</> : '—'} · Dam {c.dam ? <>{c.dam.number} {meritChip(c.dam.merit)}</> : '—'}</span>
                <span className="text-xs text-ink-soft">Pedigree known {c.pedigree}% · own COI {c.coi}%</span>
                {c.last ? <span className="text-xs text-ink-soft">Last: {c.last}</span> : null}
                {isLead ? <Button className="ml-auto" variant="secondary" onClick={() => setOpen(!open)}>Decide</Button> : null}
            </div>
            <ul className="mt-1 flex flex-wrap gap-1.5 text-xs">{c.checks.map((k) => <li key={k.key}><Chip className={k.info ? 'bg-glaze text-ink-soft' : k.ok ? 'bg-ok-100 text-ok-700' : 'bg-crit-100 text-crit-700'}>{k.label}</Chip></li>)}</ul>
            {open ? (
                <form className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-4" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, planned_sire_id: d.decision === 'replacement' && d.planned_sire_id ? d.planned_sire_id : null, pen_id: d.pen_id || null, reason: d.reason || null })); form.post(`/genetics/decide/${c.id}`, { preserveScroll: true, onSuccess: () => setOpen(false) }); }}>
                    <Field label="Decision"><select className="field" value={form.data.decision} onChange={(x) => form.setData('decision', x.target.value)}>{Object.entries(options.decisions).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    {c.sex === 'female' && form.data.decision === 'replacement' ? (
                        <Field label="Planned buck" error={e.planned_sire_id} hint={picked ? `Kid inbreeding ${picked.coi}%` : undefined}><select className="field" value={form.data.planned_sire_id} onChange={(x) => form.setData('planned_sire_id', x.target.value)}><option value="">Decide later</option>{c.bucks.map((b) => <option key={b.id} value={b.id}>{b.number} · merit {b.merit ?? '—'} · COI {b.coi}%{b.coi > limit ? ' (over limit)' : ''}</option>)}</select></Field>
                    ) : null}
                    <Field label="Move to PEN (optional)"><select className="field" value={form.data.pen_id} onChange={(x) => form.setData('pen_id', x.target.value)}><option value="">Stay</option>{pens.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
                    <Field label="Reason" error={e.reason}><input className="field" placeholder="Needed if a check fails" value={form.data.reason} onChange={(x) => form.setData('reason', x.target.value)} /></Field>
                    <div className="flex items-end gap-2 sm:col-span-4"><Button type="submit" disabled={form.processing}>Save Decision</Button><Button type="button" variant="secondary" onClick={() => setOpen(false)}>Cancel</Button></div>
                </form>
            ) : null}
        </li>
    );
}

function MatingCheck({ bucks, does, pairing, limit, isLead }: Props) {
    const [pair, setPair] = useState({ dam: pairing?.dam ?? does[0]?.id ?? '', sire: pairing?.sire ?? bucks[0]?.id ?? '' });
    const settings = useForm({ inbreeding_limit_pct: String(limit) });
    return (
        <div className="space-y-3">
            <Panel title="Inbreeding Check" description="Coefficient of inbreeding of the kid, from the farm's pedigree. Unknown parents count as unrelated, so a thin pedigree can hide relatedness.">
                <div className="flex flex-wrap items-end gap-3 p-4">
                    <Field label="Doe"><select className="field" value={pair.dam} onChange={(x) => setPair({ ...pair, dam: x.target.value })}>{does.map((d) => <option key={d.id} value={d.id}>{d.number}</option>)}</select></Field>
                    <Field label="Buck"><select className="field" value={pair.sire} onChange={(x) => setPair({ ...pair, sire: x.target.value })}>{bucks.map((d) => <option key={d.id} value={d.id}>{d.number}</option>)}</select></Field>
                    <Button onClick={() => router.get('/genetics', { tab: 'mating', ...pair }, { preserveState: true, preserveScroll: true, only: ['pairing'] })}>Check</Button>
                    {pairing ? <p className={`text-lg font-bold ${pairing.coi > limit ? 'text-crit-700' : 'text-ok-700'}`}>{pairing.coi}% {pairing.coi > limit ? `(above ${limit}%)` : '(within limit)'}</p> : null}
                </div>
            </Panel>
            {isLead ? (
                <Panel title="Inbreeding Limit" description="Matings and planned pairings above this need a reason. 6.25% is the kid of first cousins; 12.5% half-siblings; 25% full siblings or parent and offspring.">
                    <form className="flex items-end gap-3 p-4" onSubmit={(ev) => { ev.preventDefault(); settings.post('/genetics/settings', { preserveScroll: true }); }}>
                        <Field label="Limit %" error={settings.errors.inbreeding_limit_pct}><input type="number" step="0.25" className="field w-32" value={settings.data.inbreeding_limit_pct} onChange={(x) => settings.setData('inbreeding_limit_pct', x.target.value)} /></Field>
                        <Button type="submit" disabled={settings.processing}>Save</Button>
                    </form>
                </Panel>
            ) : null}
        </div>
    );
}
