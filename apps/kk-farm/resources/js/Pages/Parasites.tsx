import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';

type Sample = {
    id: number; no: string; goat: { id: string; number: string } | null; pen: string | null; penId: number | null; on: string; reason: string; epg: number | null; famacha: number | null; bcs: number | null;
    lab: string | null; cost: number | null; retestOf: string | null; preEpg: number | null; reduction: number | null; resistance: boolean; aboveGuide: boolean;
    decision: string | null; decisionNote: string | null; decidedBy: string | null; treated: number; retestDue: string | null; retestOverdue: boolean; retested: boolean; by: string;
};
type Props = {
    samples: Sample[];
    kpi: { trend: Array<{ month: string; epg: number; samples: number }>; targetedRate: number | null; targeted: number; blind: number; treatedShare: number | null; resistance: number; awaiting: number; retestsDue: number };
    goats: Array<{ id: string; farm_number: string; pen_id: number | null }>; pens: Array<{ id: number; name: string }>;
    dewormers: Array<{ id: number; name: string; unit: string; stock: number }>; guide: { epg: number | null; famacha: number | null };
    options: { reasons: Record<string, string>; decisions: Record<string, string>; routes: Record<string, string> }; isVet: boolean;
};

const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const plusDays = (n: number) => new Date(Date.now() + n * 864e5).toISOString().slice(0, 10);
const nul = (v: string) => (v === '' ? null : v);

/** D-200: journey K Parasite Surveillance-to-Control (V3 flow 34): test, targeted dose set by the vet, retest. */
export default function Parasites(p: Props) {
    const [tab, setTab] = useTabParam('samples');
    const max = Math.max(1, ...p.kpi.trend.map((t) => t.epg));
    return (
        <>
            <Head title="Parasite Control" />
            <PageHeader title="Parasite Testing & Control" description="Test first, then the vet doses only the goats that need it, with the withdrawal and a retest. No blind repeat dosing." />
            <StatRow>
                <Stat label="Waiting for vet decision" value={p.kpi.awaiting} tone={p.kpi.awaiting ? 'alert' : undefined} />
                <Stat label="Targeted treatment rate" value={p.kpi.targetedRate !== null ? `${p.kpi.targetedRate}%` : '—'} note={`${p.kpi.targeted} after a test · ${p.kpi.blind} without (90 days)`} />
                <Stat label="Retests due" value={p.kpi.retestsDue} tone={p.kpi.retestsDue ? 'alert' : undefined} />
                <Stat label="Resistance alerts (1 year)" value={p.kpi.resistance} tone={p.kpi.resistance ? 'alert' : undefined} note="Egg count fell less than 95%" />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'samples', label: 'Samples & Decisions', count: p.kpi.awaiting || undefined }, { id: 'new', label: 'Record Sample' }, { id: 'trend', label: 'FEC Trend' }, ...(p.isVet ? [{ id: 'guide', label: 'Vet Guide' }] : [])]} />
            {tab === 'new' ? <SampleForm {...p} onDone={() => setTab('samples')} /> : null}
            {tab === 'samples' ? (
                <Panel flush title="Samples" description={p.guide.epg || p.guide.famacha ? `Highlighted at or above the vet's guide: ${p.guide.epg ? `${p.guide.epg} EPG` : ''}${p.guide.epg && p.guide.famacha ? ' or ' : ''}${p.guide.famacha ? `FAMACHA ${p.guide.famacha}` : ''}` : 'No guide set: the vet decides each sample.'}>
                    {p.samples.length ? <ul className="divide-y divide-rule">{p.samples.map((s) => <SampleRow key={s.id} s={s} {...p} />)}</ul> : <Empty title="No parasite samples yet" />}
                </Panel>
            ) : null}
            {tab === 'trend' ? (
                <Panel title="Average Egg Count by Month" description="Faecal egg count (EPG), last 6 months">
                    {p.kpi.trend.length ? (
                        <ul className="space-y-2 p-4">{p.kpi.trend.map((t) => (
                            <li key={t.month} className="flex items-center gap-3 text-sm"><span className="w-20 text-ink-soft">{t.month}</span>
                                <span className="h-4 rounded-full bg-cobalt-700" style={{ width: `${Math.max(2, (t.epg / max) * 70)}%` }} /><strong>{t.epg}</strong><span className="text-xs text-ink-soft">{t.samples} samples</span></li>
                        ))}</ul>
                    ) : <Empty title="No egg counts yet" />}
                </Panel>
            ) : null}
            {tab === 'guide' && p.isVet ? <Guide guide={p.guide} /> : null}
        </>
    );
}

function SampleForm({ goats, pens, options, samples, onDone }: Props & { onDone: () => void }) {
    const form = useForm({ animal_id: '', pen_id: '', sampled_on: today(), reason: 'suspected', epg: '', famacha: '', bcs: '', lab: '', cost: '', retest_of_id: '', notes: '' });
    const e = form.errors as Record<string, string>;
    const treated = samples.filter((s) => s.decision === 'treat' && !s.retested);
    return (
        <Panel title="Record Sample" description="An egg count from the lab and / or FAMACHA (eye-lid colour) and body score on the farm. A goat or a pooled PEN sample.">
            <form className="grid gap-3 p-4 sm:grid-cols-4" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, animal_id: nul(d.animal_id), pen_id: nul(d.pen_id), epg: nul(d.epg), famacha: nul(d.famacha), bcs: nul(d.bcs), cost: nul(d.cost), retest_of_id: nul(d.retest_of_id), lab: nul(d.lab), notes: nul(d.notes) })); form.post('/parasites/samples', { preserveScroll: true, onSuccess: onDone }); }}>
                <Field label="Reason" error={e.reason}><select className="field" value={form.data.reason} onChange={(x) => form.setData('reason', x.target.value)}>{Object.entries(options.reasons).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                {form.data.reason === 'retest' ? (
                    <Field label="Retest of" error={e.retest_of_id}><select className="field" value={form.data.retest_of_id} onChange={(x) => { const s = treated.find((t) => String(t.id) === x.target.value); form.setData((d) => ({ ...d, retest_of_id: x.target.value, animal_id: s?.goat?.id ?? '', pen_id: s?.penId ? String(s.penId) : '' })); }}><option value="">Choose…</option>{treated.map((s) => <option key={s.id} value={s.id}>{s.no} · {s.goat?.number ?? s.pen} · {s.epg} EPG</option>)}</select></Field>
                ) : null}
                <Field label="Goat" error={e.animal_id}><select className="field" value={form.data.animal_id} onChange={(x) => form.setData('animal_id', x.target.value)}><option value="">—</option>{goats.map((g) => <option key={g.id} value={g.id}>{g.farm_number}</option>)}</select></Field>
                <Field label="Or pooled PEN" error={e.pen_id}><select className="field" value={form.data.pen_id} onChange={(x) => form.setData('pen_id', x.target.value)}><option value="">—</option>{pens.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
                <Field label="Sampled on" error={e.sampled_on}><input type="date" max={today()} className="field" value={form.data.sampled_on} onChange={(x) => form.setData('sampled_on', x.target.value)} /></Field>
                <Field label="Egg count (EPG)" error={e.epg}><input type="number" min={0} className="field" value={form.data.epg} onChange={(x) => form.setData('epg', x.target.value)} /></Field>
                <Field label="FAMACHA (1–5)" error={e.famacha}><select className="field" value={form.data.famacha} onChange={(x) => form.setData('famacha', x.target.value)}><option value="">—</option>{[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}</select></Field>
                <Field label="Body score (1–5)" error={e.bcs}><select className="field" value={form.data.bcs} onChange={(x) => form.setData('bcs', x.target.value)}><option value="">—</option>{[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}</select></Field>
                <Field label="Lab" error={e.lab}><input className="field" value={form.data.lab} onChange={(x) => form.setData('lab', x.target.value)} /></Field>
                <Field label="Lab fee PKR" error={e.cost}><input type="number" min={0} className="field" value={form.data.cost} onChange={(x) => form.setData('cost', x.target.value)} /></Field>
                <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                <div className="flex items-end"><Button type="submit" disabled={form.processing}>Save Sample</Button></div>
            </form>
        </Panel>
    );
}

function SampleRow({ s, goats, dewormers, options, isVet }: { s: Sample } & Props) {
    const [open, setOpen] = useState(false);
    const pool = s.goat ? [{ id: s.goat.id, farm_number: s.goat.number }] : goats.filter((g) => g.pen_id === s.penId);
    const form = useForm({ decision: s.aboveGuide ? 'treat' : 'monitor', note: '', animal_ids: pool.map((g) => g.id), medicine_id: String(dewormers[0]?.id ?? ''), qty_per_goat: '', route: 'oral', meat_clear_on: '', milk_clear_on: '', no_withdrawal: false, retest_due_on: plusDays(14) });
    const e = form.errors as Record<string, string>;
    const toggle = (id: string) => form.setData('animal_ids', form.data.animal_ids.includes(id) ? form.data.animal_ids.filter((x) => x !== id) : [...form.data.animal_ids, id]);
    return (
        <li className={`px-4 py-3 text-sm ${s.aboveGuide && !s.decision ? 'bg-watch-100/30' : ''}`}>
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono text-cobalt-900">{s.no}</strong>
                {s.goat ? <Link href={`/goats/${s.goat.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{s.goat.number}</Link> : <span>PEN {s.pen}</span>}
                <span className="text-ink-soft">{s.on} · {options.reasons[s.reason]}</span>
                {s.epg !== null ? <Chip className={s.aboveGuide ? 'bg-crit-100 text-crit-700' : 'bg-glaze text-cobalt-900'}>{s.epg} EPG</Chip> : null}
                {s.famacha ? <Chip className={s.famacha >= 4 ? 'bg-crit-100 text-crit-700' : 'bg-glaze text-cobalt-900'}>FAMACHA {s.famacha}</Chip> : null}
                {s.bcs ? <span className="text-xs">BCS {s.bcs}</span> : null}
                {s.retestOf ? <Chip className={s.resistance ? 'bg-crit-100 text-crit-700' : 'bg-ok-100 text-ok-700'}>Retest of {s.retestOf}: {s.preEpg} → {s.epg}{s.reduction !== null ? ` (${s.reduction}% down)` : ''}{s.resistance ? ' · possible resistance' : ''}</Chip> : null}
                <span className="ml-auto flex items-center gap-2">
                    {s.decision ? <Chip className={s.decision === 'treat' ? 'bg-cobalt-100 text-cobalt-800' : 'bg-glaze text-ink-soft'}>{options.decisions[s.decision]}{s.treated ? ` · ${s.treated} goat(s)` : ''} · {s.decidedBy}</Chip> : isVet ? <Button onClick={() => setOpen(!open)}>Decide</Button> : <span className="text-xs text-ink-soft">Waiting for the vet</span>}
                    {s.retestOverdue ? <Chip className="bg-watch-100 text-watch-700">Retest overdue ({s.retestDue})</Chip> : s.retestDue && !s.retested ? <span className="text-xs text-ink-soft">Retest {s.retestDue}</span> : null}
                </span>
            </div>
            {s.decisionNote ? <p className="mt-1 text-ink-soft">{s.decisionNote}</p> : null}
            {open && !s.decision ? (
                <form className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-4" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, meat_clear_on: nul(d.meat_clear_on), milk_clear_on: nul(d.milk_clear_on), ...(d.decision === 'treat' ? {} : { animal_ids: null, medicine_id: null, qty_per_goat: null, route: null, retest_due_on: null }) })); form.post(`/parasites/samples/${s.id}/decide`, { preserveScroll: true, onSuccess: () => setOpen(false) }); }}>
                    <Field label="Decision"><select className="field" value={form.data.decision} onChange={(x) => form.setData('decision', x.target.value)}>{Object.entries(options.decisions).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    {form.data.decision === 'treat' ? (
                        <>
                            <Field label="Dewormer" error={e.medicine_id}><select className="field" value={form.data.medicine_id} onChange={(x) => form.setData('medicine_id', x.target.value)}>{dewormers.map((d) => <option key={d.id} value={d.id}>{d.name} ({d.stock} {d.unit} in store)</option>)}</select></Field>
                            <Field label="Dose per goat" error={e.qty_per_goat}><input type="number" step="0.1" className="field" value={form.data.qty_per_goat} onChange={(x) => form.setData('qty_per_goat', x.target.value)} /></Field>
                            <Field label="Route"><select className="field" value={form.data.route} onChange={(x) => form.setData('route', x.target.value)}>{Object.entries(options.routes).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                            <Field label="Meat clear on" error={e.meat_clear_on}><input type="date" min={today()} className="field" value={form.data.meat_clear_on} disabled={form.data.no_withdrawal} onChange={(x) => form.setData('meat_clear_on', x.target.value)} /></Field>
                            <Field label="Milk clear on"><input type="date" min={today()} className="field" value={form.data.milk_clear_on} disabled={form.data.no_withdrawal} onChange={(x) => form.setData('milk_clear_on', x.target.value)} /></Field>
                            <label className="flex items-end gap-2 pb-2 text-sm"><input type="checkbox" checked={form.data.no_withdrawal} onChange={(x) => form.setData('no_withdrawal', x.target.checked)} /> No withdrawal</label>
                            <Field label="Retest on" error={e.retest_due_on}><input type="date" min={plusDays(1)} className="field" value={form.data.retest_due_on} onChange={(x) => form.setData('retest_due_on', x.target.value)} /></Field>
                            <div className="sm:col-span-4"><p className="mb-1 text-xs font-semibold">Goats to treat {e.animal_ids ? <span className="text-crit-700">{e.animal_ids}</span> : null}</p><div className="flex flex-wrap gap-2">{pool.map((g) => <label key={g.id} className="flex items-center gap-1"><input type="checkbox" checked={form.data.animal_ids.includes(g.id)} onChange={() => toggle(g.id)} /> <span className="font-mono">{g.farm_number}</span></label>)}</div></div>
                        </>
                    ) : null}
                    <Field label="Note"><input className="field" value={form.data.note} onChange={(x) => form.setData('note', x.target.value)} /></Field>
                    <div className="flex items-end gap-2"><Button type="submit" disabled={form.processing}>Save Decision</Button><Button type="button" variant="secondary" onClick={() => setOpen(false)}>Cancel</Button></div>
                </form>
            ) : null}
        </li>
    );
}

function Guide({ guide }: { guide: Props['guide'] }) {
    const form = useForm({ fec_treat_epg: guide.epg !== null ? String(guide.epg) : '', famacha_treat: guide.famacha !== null ? String(guide.famacha) : '' });
    return (
        <Panel title="Treatment Guide" description="Samples at or above these are highlighted. It never doses anything: you decide each sample.">
            <form className="flex flex-wrap items-end gap-3 p-4" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ fec_treat_epg: nul(d.fec_treat_epg), famacha_treat: nul(d.famacha_treat) })); form.post('/parasites/guide', { preserveScroll: true }); }}>
                <Field label="Egg count at or above (EPG)" error={form.errors.fec_treat_epg}><input type="number" className="field w-40" value={form.data.fec_treat_epg} onChange={(x) => form.setData('fec_treat_epg', x.target.value)} /></Field>
                <Field label="FAMACHA at or above" error={form.errors.famacha_treat}><select className="field w-32" value={form.data.famacha_treat} onChange={(x) => form.setData('famacha_treat', x.target.value)}><option value="">—</option>{[2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}</select></Field>
                <Button type="submit" disabled={form.processing}>Save</Button>
            </form>
        </Panel>
    );
}
