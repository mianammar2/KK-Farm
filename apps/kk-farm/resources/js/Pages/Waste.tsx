import { Head, router, useForm } from '@inertiajs/react';
import DeleteButton from '../components/DeleteButton';
import { useState } from 'react';
import { Button, Chip, Empty, Field, PageHeader, Panel, pkr, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';

type Stream = {
    id: number; kind: string; name: string; method: string; instructions: string | null; adviser: string | null; adviserRole: string | null; advised: string | null; advisedOn: string | null; contractor: string | null; licence: string | null;
    every: number | null; approved: boolean; active: boolean; biohazard: boolean; missed: number; overdue: boolean; last: string | null;
};
type Disposal = { id: number; on: string; stream: string | null; kind: string | null; qty: number | null; unit: string | null; method: string; deviation: string | null; goat: string | null; evidence: string | null; ppe: boolean; disinfected: boolean; cost: number | null; by: string; verifiedBy: string | null; audit: string | null; notes: string | null };
type Props = {
    streams: Stream[]; disposals: Disposal[]; carcasses: Array<{ id: string; number: string; died: string | null }>;
    kpi: { missedPickups: number; audited: number | null; toAudit: number; carcassesPending: number; unapproved: number; cost90: number };
    options: { kinds: Record<string, string>; methods: Record<string, string> }; isLead: boolean; me: string;
};

const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const nul = (v: string) => (v === '' ? null : v);

/** D-204: journey O Waste-to-Safe-Disposal (V3 flow 38). */
export default function Waste(p: Props) {
    const [tab, setTab] = useTabParam('dispose');
    return (
        <>
            <Head title="Waste & Disposal" />
            <PageHeader title="Waste & Safe Disposal" description="Manure, wastewater, sharps, medical waste and carcasses, each by the route a qualified local adviser set, with evidence and an audit." />
            <StatRow>
                <Stat label="Carcasses to dispose" value={p.kpi.carcassesPending} tone={p.kpi.carcassesPending ? 'alert' : undefined} />
                <Stat label="Missed pickups (90 days)" value={p.kpi.missedPickups} tone={p.kpi.missedPickups ? 'alert' : undefined} />
                <Stat label="Disposals audited" value={p.kpi.audited !== null ? `${p.kpi.audited}%` : '—'} note={`${p.kpi.toAudit} waiting`} />
                <Stat label="Routes without adviser" value={p.kpi.unapproved} tone={p.kpi.unapproved ? 'alert' : undefined} note={p.kpi.cost90 ? `Cost 90 days ${pkr(p.kpi.cost90)}` : undefined} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'dispose', label: 'Record Disposal' }, { id: 'log', label: 'Disposal Log', count: p.kpi.toAudit || undefined }, { id: 'routes', label: 'Waste Routes' }]} />
            {tab === 'dispose' ? <Dispose {...p} onDone={() => setTab('log')} /> : null}
            {tab === 'log' ? <Log {...p} /> : null}
            {tab === 'routes' ? <Routes {...p} /> : null}
        </>
    );
}

function Dispose({ streams, carcasses, options, onDone }: Props & { onDone: () => void }) {
    const usable = streams.filter((s) => s.approved);
    const form = useForm({ stream_id: String(usable[0]?.id ?? ''), disposed_on: today(), qty: '', unit: 'kg', method: usable[0]?.method ?? '', deviation_reason: '', animal_id: '', evidence_ref: '', ppe_used: false, area_disinfected: false, cost: '', notes: '' });
    const e = form.errors as Record<string, string>;
    const s = streams.find((x) => String(x.id) === form.data.stream_id);
    if (!usable.length) return <Panel><Empty title="No approved waste routes">Add a route with the method your qualified adviser set, under Waste Routes.</Empty></Panel>;
    return (
        <Panel title="Record Disposal" description={s?.instructions ? `Adviser's instructions: ${s.instructions}` : undefined}>
            <form className="grid gap-3 p-4 sm:grid-cols-4" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, qty: nul(d.qty), deviation_reason: nul(d.deviation_reason), animal_id: nul(d.animal_id), evidence_ref: nul(d.evidence_ref), cost: nul(d.cost), notes: nul(d.notes) })); form.post('/waste/disposals', { preserveScroll: true, onSuccess: onDone }); }}>
                <Field label="Route" error={e.stream_id}><select className="field" value={form.data.stream_id} onChange={(x) => { const n = streams.find((y) => String(y.id) === x.target.value); form.setData((d) => ({ ...d, stream_id: x.target.value, method: n?.method ?? d.method })); }}>{usable.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
                <Field label="Date" error={e.disposed_on}><input type="date" max={today()} className="field" value={form.data.disposed_on} onChange={(x) => form.setData('disposed_on', x.target.value)} /></Field>
                <Field label="Method" error={e.method}><select className="field" value={form.data.method} onChange={(x) => form.setData('method', x.target.value)}>{Object.entries(options.methods).map(([k, v]) => <option key={k} value={k}>{v}{s?.method === k ? ' (adviser)' : ''}</option>)}</select></Field>
                {form.data.method !== s?.method ? <Field label="Why a different method" error={e.deviation_reason}><input className="field" value={form.data.deviation_reason} onChange={(x) => form.setData('deviation_reason', x.target.value)} /></Field> : null}
                {s?.kind === 'carcass' ? <Field label="Dead goat" error={e.animal_id}><select className="field" value={form.data.animal_id} onChange={(x) => form.setData('animal_id', x.target.value)}><option value="">Choose…</option>{carcasses.map((c) => <option key={c.id} value={c.id}>{c.number} · died {c.died}</option>)}</select></Field> : null}
                <Field label="Quantity"><input type="number" className="field" value={form.data.qty} onChange={(x) => form.setData('qty', x.target.value)} /></Field>
                <Field label="Unit"><select className="field" value={form.data.unit} onChange={(x) => form.setData('unit', x.target.value)}>{['kg', 'litre', 'count', 'trolley', 'bag'].map((u) => <option key={u} value={u}>{u}</option>)}</select></Field>
                <Field label="Manifest / receipt no." error={e.evidence_ref}><input className="field" value={form.data.evidence_ref} onChange={(x) => form.setData('evidence_ref', x.target.value)} /></Field>
                <Field label="Cost PKR"><input type="number" className="field" value={form.data.cost} onChange={(x) => form.setData('cost', x.target.value)} /></Field>
                <Field label="Notes"><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                <div className="space-y-1 text-sm sm:col-span-2">
                    <label className="flex items-center gap-2"><input type="checkbox" checked={form.data.ppe_used} onChange={(x) => form.setData('ppe_used', x.target.checked)} /> Gloves / protection used</label>
                    <label className="flex items-center gap-2"><input type="checkbox" checked={form.data.area_disinfected} onChange={(x) => form.setData('area_disinfected', x.target.checked)} /> Area and tools disinfected</label>
                    {e.ppe_used ? <p className="text-xs text-crit-700">{e.ppe_used}</p> : null}
                </div>
                <div className="flex items-end sm:col-span-4"><Button type="submit" disabled={form.processing}>Save Disposal</Button></div>
            </form>
        </Panel>
    );
}

function Log({ disposals, options, isLead, me }: Props) {
    return (
        <Panel flush title="Disposal Log" description="Audited by the owner, manager or vet, not the person who recorded it.">
            {disposals.length ? <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Date</th><th>Route</th><th>Method</th><th>Qty</th><th>Evidence</th><th>Controls</th><th>Cost</th><th>By</th><th>Audit</th></tr></thead><tbody>
                {disposals.map((d) => <tr key={d.id}><td>{d.on}</td><td>{d.stream}{d.goat ? <span className="block font-mono text-xs">{d.goat}</span> : null}</td><td>{options.methods[d.method]}{d.deviation ? <span className="block text-xs text-watch-700">Not the adviser's: {d.deviation}</span> : null}</td>
                    <td>{d.qty ?? '—'} {d.unit ?? ''}</td><td>{d.evidence ?? '—'}</td><td className="text-xs">{d.ppe ? 'PPE' : ''}{d.ppe && d.disinfected ? ' · ' : ''}{d.disinfected ? 'disinfected' : ''}</td><td>{d.cost ? pkr(d.cost / 100) : '—'}</td><td>{d.by}</td>
                    <td>{d.verifiedBy ? <Chip className="bg-ok-100 text-ok-700">{d.verifiedBy}</Chip> : isLead && d.by !== me ? <Button variant="secondary" onClick={() => router.post(`/waste/disposals/${d.id}/audit`, {}, { preserveScroll: true })}>Audit</Button> : <span className="text-xs text-ink-soft">Waiting</span>}</td></tr>)}
            </tbody></table></div> : <Empty title="No disposals yet" />}
        </Panel>
    );
}

function Routes({ streams, options, isLead }: Props) {
    const [edit, setEdit] = useState<Stream | 'new' | null>(null);
    return (
        <div className="space-y-3">
            {edit ? <RouteForm s={edit === 'new' ? null : edit} options={options} onDone={() => setEdit(null)} /> : null}
            <Panel flush title="Waste Routes" description="The method, handling and biosecurity steps come from a qualified local adviser (livestock officer, environment consultant or vet). The system records them; it does not choose them." actions={isLead ? <Button onClick={() => setEdit('new')}>+ Route</Button> : undefined}>
                {streams.length ? <ul className="divide-y divide-rule">{streams.map((s) => (
                    <li key={s.id} className="px-4 py-2 text-sm"><div className="flex flex-wrap items-center gap-2"><strong>{s.name}</strong><span className="text-ink-soft">{options.kinds[s.kind]} · {options.methods[s.method]}{s.contractor ? ` · ${s.contractor}` : ''}{s.licence ? ` (${s.licence})` : ''}</span>
                        {s.approved ? <Chip className="bg-ok-100 text-ok-700">Set by {s.adviser}, {s.adviserRole} · {s.advised}</Chip> : <Chip className="bg-crit-100 text-crit-700">No adviser: not usable</Chip>}
                        {s.biohazard ? <Chip className="bg-treat-100 text-treat-700">Biological</Chip> : null}
                        {s.every ? <Chip className={s.overdue ? 'bg-crit-100 text-crit-700' : 'bg-glaze text-ink-soft'}>Pickup every {s.every} d · last {s.last ?? 'never'}{s.missed ? ` · ${s.missed} missed` : ''}</Chip> : null}
                        {isLead ? <><Button variant="quiet" className="ml-auto" onClick={() => setEdit(s)}>Edit</Button><DeleteButton type="waste-stream" id={s.id} name={s.name} /></> : null}</div>
                        {s.instructions ? <p className="text-xs text-ink-soft">{s.instructions}</p> : null}</li>
                ))}</ul> : <Empty title="No waste routes yet" />}
            </Panel>
        </div>
    );
}

function RouteForm({ s, options, onDone }: { s: Stream | null; options: Props['options']; onDone: () => void }) {
    const form = useForm({ kind: s?.kind ?? 'manure', name: s?.name ?? '', method: s?.method ?? 'compost', instructions: s?.instructions ?? '', adviser: s?.adviser ?? '', adviser_role: s?.adviserRole ?? '', advised_on: s?.advisedOn ?? '', contractor: s?.contractor ?? '', licence_no: s?.licence ?? '', pickup_every_days: s?.every ? String(s.every) : '', active: s?.active ?? true });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title={s ? `Edit ${s.name}` : 'New Waste Route'}>
            <form className="grid gap-3 p-4 sm:grid-cols-4" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, instructions: nul(d.instructions), adviser: nul(d.adviser), adviser_role: nul(d.adviser_role), advised_on: nul(d.advised_on), contractor: nul(d.contractor), licence_no: nul(d.licence_no), pickup_every_days: nul(d.pickup_every_days) })); form.post(s ? `/waste/streams/${s.id}` : '/waste/streams', { preserveScroll: true, onSuccess: onDone }); }}>
                <Field label="Waste" error={e.kind}><select className="field" value={form.data.kind} onChange={(x) => form.setData('kind', x.target.value)}>{Object.entries(options.kinds).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Name" error={e.name}><input className="field" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} /></Field>
                <Field label="Method (as advised)" error={e.method}><select className="field" value={form.data.method} onChange={(x) => form.setData('method', x.target.value)}>{Object.entries(options.methods).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Pickup every (days)"><input type="number" className="field" value={form.data.pickup_every_days} onChange={(x) => form.setData('pickup_every_days', x.target.value)} /></Field>
                <Field label="Qualified adviser" error={e.adviser}><input className="field" value={form.data.adviser} onChange={(x) => form.setData('adviser', x.target.value)} /></Field>
                <Field label="Adviser's role" error={e.adviser_role}><input className="field" placeholder="e.g. District livestock officer" value={form.data.adviser_role} onChange={(x) => form.setData('adviser_role', x.target.value)} /></Field>
                <Field label="Date advised" error={e.advised_on}><input type="date" max={today()} className="field" value={form.data.advised_on} onChange={(x) => form.setData('advised_on', x.target.value)} /></Field>
                <Field label="Contractor" error={e.contractor}><input className="field" value={form.data.contractor} onChange={(x) => form.setData('contractor', x.target.value)} /></Field>
                <Field label="Licence no."><input className="field" value={form.data.licence_no} onChange={(x) => form.setData('licence_no', x.target.value)} /></Field>
                <div className="sm:col-span-3"><Field label="Adviser's handling and biosecurity instructions"><input className="field" value={form.data.instructions} onChange={(x) => form.setData('instructions', x.target.value)} /></Field></div>
                <div className="flex gap-2 sm:col-span-4"><Button type="submit" disabled={form.processing}>Save</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}
