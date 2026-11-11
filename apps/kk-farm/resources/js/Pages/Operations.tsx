import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, LibIcon, PageHeader, Panel, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type Checklist = { id: number; name: string; shift: string; items: string[]; run: { id: number; done: number[]; by: string | null; at: string | null; verifiedBy: string | null; notes: string | null } | null };
type IncidentRow = { id: number; at: string; kind: string; severity: string; pen: string | null; goat: { id: string; number: string } | null; description: string; by: string; status: string; resolution: string | null; resolvedBy: string | null; resolvedAt: string | null;
    category: string | null; rootCause: string | null; repeat: boolean; case: { label: string; status: string } | null; sop: string | null };
type Props = {
    checklists: Checklist[]; history: Array<{ on: string; name: string; done: number; total: number; by: string | null; verifiedBy: string | null }>;
    incidents: IncidentRow[]; handovers: Array<{ id: number; on: string; shift: string; from: string; to: string | null; summary: string; pending: string | null }>;
    templates: Array<{ id: number; name: string; shift: string; items: string[]; active: boolean }>;
    pens: Array<{ id: number; name: string }>; goats: Array<{ id: string; farm_number: string }>;
    options: { shifts: Record<string, string>; kinds: Record<string, string>; severity: Record<string, string>; categories: Record<string, string> }; canSupervise: boolean; canResolve: boolean;
    incidentKpi: { capaClosure: number | null; casesClosed: number; cases: number; repeats: number; repeatRate: number | null; escalatedOpen: number }; sops: Array<{ id: number; code: string; title: string }>;
};

const SEV: Record<string, string> = { low: 'bg-glaze text-ink-soft', medium: 'bg-watch-100 text-watch-700', high: 'bg-treat-100 text-treat-700', critical: 'bg-crit-100 text-crit-700' };
const SHIFT_ART: Record<string, string> = { morning: 'ic-active', afternoon: 'ic-recovery', evening: 'ic-on-hold' };
const nowLocal = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);

/** D-180: Farm Operations: today's shift checklists, the incident register and shift handovers. */
export default function Operations(p: Props) {
    const [tab, setTab] = useTabParam('checklists');
    const openIncidents = p.incidents.filter((i) => i.status === 'open').length;
    return (
        <>
            <Head title="Farm Operations" />
            <PageHeader title="Farm Operations" description="Shift checklists, incidents and handovers. Tasks are under Today's Tasks." />
            <Tabs value={tab} onChange={setTab} tabs={[
                { id: 'checklists', label: "Today's Checklists", count: p.checklists.filter((c) => !c.run?.at).length || undefined },
                { id: 'incidents', label: 'Incident Register', count: openIncidents || undefined },
                { id: 'handover', label: 'Shift Handover' },
                { id: 'history', label: 'Checklist History' },
                ...(p.canSupervise ? [{ id: 'templates', label: 'Checklist Setup' }] : []),
            ]} />
            {tab === 'checklists' ? <div className="grid gap-3 lg:grid-cols-3">{p.checklists.map((c) => <ChecklistCard key={c.id} c={c} canSupervise={p.canSupervise} />)}</div> : null}
            {tab === 'incidents' ? <Incidents {...p} /> : null}
            {tab === 'handover' ? <Handover {...p} /> : null}
            {tab === 'history' ? (
                <Panel flush title="Last 7 Days">
                    {p.history.length ? <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Day</th><th>Checklist</th><th>Done</th><th>Completed by</th><th>Verified by</th></tr></thead><tbody>
                        {p.history.map((h, i) => <tr key={i}><td>{h.on}</td><td>{h.name}</td><td className={h.done < h.total ? 'text-crit-700 font-semibold' : ''}>{h.done}/{h.total}</td><td>{h.by ?? '—'}</td><td>{h.verifiedBy ?? '—'}</td></tr>)}
                    </tbody></table></div> : <Empty title="No checklists in the last 7 days" />}
                </Panel>
            ) : null}
            {tab === 'templates' && p.canSupervise ? <Templates {...p} /> : null}
        </>
    );
}

function ChecklistCard({ c, canSupervise }: { c: Checklist; canSupervise: boolean }) {
    const [done, setDone] = useState<number[]>(c.run?.done ?? []);
    const locked = Boolean(c.run?.verifiedBy);
    const save = (next: number[]) => { setDone(next); router.post(`/operations/checklists/${c.id}/tick`, { done: next }, { preserveScroll: true, preserveState: true }); };
    const complete = done.length === c.items.length;
    return (
        <Panel title={c.name} description={c.run?.at ? `Completed by ${c.run.by} at ${c.run.at}${c.run.verifiedBy ? ` · verified by ${c.run.verifiedBy}` : ''}` : `${done.length} of ${c.items.length} done`}
            actions={<LibIcon name={SHIFT_ART[c.shift] ?? 'ic-active'} size={26} />}>
            <ul className="space-y-1 p-3">
                {c.items.map((item, i) => {
                    const on = done.includes(i);
                    return (
                        <li key={i}>
                            <button type="button" disabled={locked} onClick={() => save(on ? done.filter((x) => x !== i) : [...done, i])}
                                className={`flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm transition-colors ${on ? 'bg-ok-100 text-ok-700' : 'bg-glaze text-ink hover:bg-cobalt-100'}`}>
                                <span className={`grid size-5 shrink-0 place-items-center rounded-md ring-1 ${on ? 'bg-ok-700 text-white ring-ok-700' : 'bg-white ring-rule'}`}>{on ? <Check size={13} /> : null}</span>
                                <span className={on ? 'line-through decoration-ok-700/40' : ''}>{item}</span>
                            </button>
                        </li>
                    );
                })}
            </ul>
            {complete && c.run && !c.run.verifiedBy && canSupervise ? <div className="border-t border-rule px-3 py-2.5"><Button onClick={() => router.post(`/operations/runs/${c.run!.id}/verify`, {}, { preserveScroll: true })}><LibIcon name="ic-healthy" size={16} /> Verify</Button></div> : null}
        </Panel>
    );
}

function Incidents({ incidents, pens, goats, options, canResolve, incidentKpi, sops }: Props) {
    const [adding, setAdding] = useState(false);
    const [shown, more] = useFive(incidents);
    const form = useForm({ occurred_at: nowLocal(), kind: 'equipment_failure', severity: 'medium', pen_id: '', animal_id: '', description: '', sop_id: '', containment: '' });
    const e = form.errors as Record<string, string>;
    return (
        <div className="space-y-3">
            {adding ? (
                <Panel title="Report an Incident">
                    <form className="grid gap-3 p-4 sm:grid-cols-3" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, pen_id: d.pen_id || null, animal_id: d.animal_id || null, sop_id: d.sop_id || null, containment: d.containment || null })); form.post('/operations/incidents', { preserveScroll: true, onSuccess: () => { setAdding(false); form.reset(); } }); }}>
                        <Field label="What happened" error={e.kind}><select className="field" value={form.data.kind} onChange={(x) => form.setData('kind', x.target.value)}>{Object.entries(options.kinds).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                        <Field label="When" error={e.occurred_at}><input type="datetime-local" max={nowLocal()} className="field" value={form.data.occurred_at} onChange={(x) => form.setData('occurred_at', x.target.value)} /></Field>
                        <Field label="Severity" error={e.severity} hint="High and critical raise a task for the manager"><select className="field" value={form.data.severity} onChange={(x) => form.setData('severity', x.target.value)}>{Object.entries(options.severity).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                        <Field label="Pen (optional)" error={e.pen_id}><select className="field" value={form.data.pen_id} onChange={(x) => form.setData('pen_id', x.target.value)}><option value="">—</option>{pens.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
                        <Field label="Goat (optional)" error={e.animal_id}><select className="field" value={form.data.animal_id} onChange={(x) => form.setData('animal_id', x.target.value)}><option value="">—</option>{goats.map((g) => <option key={g.id} value={g.id}>{g.farm_number}</option>)}</select></Field>
                        <Field label="Description" error={e.description}><input className="field" value={form.data.description} onChange={(x) => form.setData('description', x.target.value)} /></Field>
                        <Field label="Procedure involved (optional)" hint="The SOP version in force is recorded"><select className="field" value={form.data.sop_id} onChange={(x) => form.setData('sop_id', x.target.value)}><option value="">—</option>{sops.map((x) => <option key={x.id} value={x.id}>{x.code} · {x.title}</option>)}</select></Field>
                        {canResolve ? <Field label="Contain now (optional)" error={e.containment} hint="Hold, isolate or recall; overrides normal work"><select className="field" value={form.data.containment} onChange={(x) => form.setData('containment', x.target.value)}><option value="">No</option><option value="hold">Hold</option><option value="isolate">Isolate</option><option value="recall">Recall</option></select></Field> : null}
                        <div className="flex gap-2 sm:col-span-3"><Button type="submit" disabled={form.processing}>Report</Button><Button type="button" variant="secondary" onClick={() => setAdding(false)}>Cancel</Button></div>
                    </form>
                </Panel>
            ) : null}
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {[['CAPA closure', incidentKpi.capaClosure !== null ? `${incidentKpi.capaClosure}%` : '—'], ['Quality cases closed', `${incidentKpi.casesClosed} of ${incidentKpi.cases}`],
                    ['Repeat incidents (90 days)', `${incidentKpi.repeats}${incidentKpi.repeatRate !== null ? ` · ${incidentKpi.repeatRate}%` : ''}`], ['Waiting on CAPA', incidentKpi.escalatedOpen]].map(([l, v]) => (
                    <div key={l as string} className="rounded-tile bg-glaze px-3 py-2 text-sm"><div className="text-xs text-ink-soft">{l}</div><div className="font-bold text-cobalt-900">{v}</div></div>
                ))}
            </div>
            <Panel flush title="Incident Register" description="Last 90 days, open first. High, critical and repeat incidents go to a quality case and close only through its CAPA." actions={<Button onClick={() => setAdding(true)}>+ Report Incident</Button>}>
                {incidents.length ? <ul className="divide-y divide-rule">{shown.map((i) => <IncidentItem key={i.id} i={i} options={options} canResolve={canResolve} />)}</ul> : <Empty title="No incidents">Report escapes, breakdowns, shortages and other events here.</Empty>}
                {more}
            </Panel>
        </div>
    );
}

function IncidentItem({ i, options, canResolve }: { i: IncidentRow; options: Props['options']; canResolve: boolean }) {
    const [resolving, setResolving] = useState(false);
    const form = useForm({ resolution: '', root_cause: '' });
    const fe = form.errors as Record<string, string>;
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <LibIcon name={i.status === 'open' ? 'ic-sick' : 'ic-healthy'} size={20} />
                <strong className="text-cobalt-900">{options.kinds[i.kind] ?? i.kind}</strong>
                <Chip className={SEV[i.severity]}>{options.severity[i.severity]}</Chip>
                <span className="text-ink-soft">{i.at}{i.pen ? ` · ${i.pen}` : ''} · {i.by}</span>
                {i.goat ? <Link href={`/goats/${i.goat.id}`} className="font-mono text-cobalt-800 hover:underline">{i.goat.number}</Link> : null}
                {i.category ? <span className="text-xs text-ink-soft">{options.categories[i.category]}</span> : null}
                {i.repeat ? <Chip className="bg-treat-100 text-treat-700">Repeat</Chip> : null}
                {i.sop ? <span className="text-xs">SOP {i.sop}</span> : null}
                {i.case ? <Link href="/quality?tab=cases" className="ml-auto text-xs font-semibold text-cobalt-800 hover:underline">Quality case {i.case.label} · {i.case.status}</Link> : null}
                {i.status === 'open' && canResolve ? <span className="ml-auto flex gap-1.5"><Button variant="secondary" onClick={() => router.post(`/operations/incidents/${i.id}/escalate`, {}, { preserveScroll: true })}>Send to CAPA</Button><Button variant="secondary" onClick={() => setResolving(!resolving)}>Resolve</Button></span> : null}
            </div>
            <p className="mt-1">{i.description}</p>
            {i.rootCause ? <p className="mt-1 text-xs text-ink-soft">Root cause: {i.rootCause}</p> : null}
            {i.resolution ? <p className="mt-1 text-ok-700">Resolved by {i.resolvedBy} ({i.resolvedAt}): {i.resolution}</p> : null}
            {resolving ? (
                <form className="mt-2 flex flex-wrap gap-2" onSubmit={(ev) => { ev.preventDefault(); form.post(`/operations/incidents/${i.id}/resolve`, { preserveScroll: true, onSuccess: () => setResolving(false) }); }}>
                    <input className="field flex-1" placeholder="Root cause" value={form.data.root_cause} onChange={(x) => form.setData('root_cause', x.target.value)} />
                    <input className="field flex-1" placeholder="What was done" value={form.data.resolution} onChange={(x) => form.setData('resolution', x.target.value)} />
                    {fe.root_cause ? <span className="w-full text-xs text-crit-700">{fe.root_cause}</span> : null}
                    <Button type="submit" disabled={form.processing}>Save</Button>
                </form>
            ) : null}
        </li>
    );
}

function Handover({ handovers, options }: Props) {
    const hour = new Date().getHours();
    const form = useForm({ shift: hour < 12 ? 'morning' : hour < 17 ? 'afternoon' : 'evening', to_name: '', summary: '', pending: '' });
    const e = form.errors as Record<string, string>;
    const [shown, more] = useFive(handovers);
    return (
        <div className="grid gap-3 lg:grid-cols-2">
            <Panel title="Hand Over Your Shift">
                <form className="space-y-3 p-4" onSubmit={(ev) => { ev.preventDefault(); form.post('/operations/handovers', { preserveScroll: true, onSuccess: () => form.reset('summary', 'pending', 'to_name') }); }}>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Shift" error={e.shift}><select className="field" value={form.data.shift} onChange={(x) => form.setData('shift', x.target.value)}>{Object.entries(options.shifts).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                        <Field label="Handed to" error={e.to_name}><input className="field" value={form.data.to_name} onChange={(x) => form.setData('to_name', x.target.value)} /></Field>
                    </div>
                    <Field label="What happened this shift" error={e.summary}><textarea className="field min-h-20" value={form.data.summary} onChange={(x) => form.setData('summary', x.target.value)} /></Field>
                    <Field label="Still pending for the next shift" error={e.pending}><textarea className="field min-h-16" value={form.data.pending} onChange={(x) => form.setData('pending', x.target.value)} /></Field>
                    <Button type="submit" disabled={form.processing}>Save Handover</Button>
                </form>
            </Panel>
            <Panel flush title="Recent Handovers" description="Last 14 days">
                {handovers.length ? <ul className="divide-y divide-rule">{shown.map((h) => (
                    <li key={h.id} className="px-4 py-3 text-sm">
                        <div className="font-semibold text-cobalt-900">{h.on} · {options.shifts[h.shift]} · {h.from}{h.to ? ` → ${h.to}` : ''}</div>
                        <p className="mt-0.5">{h.summary}</p>
                        {h.pending ? <p className="mt-0.5 text-watch-700">Pending: {h.pending}</p> : null}
                    </li>
                ))}</ul> : <Empty title="No handovers yet" />}
                {more}
            </Panel>
        </div>
    );
}

function Templates({ templates, options }: Props) {
    const [editing, setEditing] = useState<Props['templates'][number] | 'new' | null>(null);
    return (
        <div className="space-y-3">
            <Panel flush title="Checklist Setup" description="The checklists staff tick each shift" actions={<Button onClick={() => setEditing('new')}>+ New Checklist</Button>}>
                <ul className="divide-y divide-rule">{templates.map((t) => (
                    <li key={t.id} className={`flex items-start gap-3 px-4 py-3 text-sm ${t.active ? '' : 'opacity-60'}`}>
                        <div className="flex-1"><strong>{t.name}</strong> <span className="text-ink-soft">· {options.shifts[t.shift]} · {t.items.length} items</span><p className="text-xs text-ink-soft">{t.items.join(' · ')}</p></div>
                        <Button variant="quiet" onClick={() => setEditing(t)}>Edit</Button>
                    </li>
                ))}</ul>
            </Panel>
            {editing ? <TemplateForm t={editing === 'new' ? null : editing} shifts={options.shifts} onDone={() => setEditing(null)} /> : null}
        </div>
    );
}

function TemplateForm({ t, shifts, onDone }: { t: Props['templates'][number] | null; shifts: Record<string, string>; onDone: () => void }) {
    const form = useForm({ name: t?.name ?? '', shift: t?.shift ?? 'morning', items: (t?.items ?? []).join('\n'), active: t?.active ?? true });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title={t ? `Edit ${t.name}` : 'New Checklist'}>
            <form className="grid gap-3 p-4 sm:grid-cols-2" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, items: d.items.split('\n').map((x) => x.trim()).filter(Boolean) })); form.post(t ? `/operations/templates/${t.id}` : '/operations/templates', { preserveScroll: true, onSuccess: onDone }); }}>
                <Field label="Name" error={e.name}><input className="field" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} /></Field>
                <Field label="Shift" error={e.shift}><select className="field" value={form.data.shift} onChange={(x) => form.setData('shift', x.target.value)}>{Object.entries(shifts).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <div className="sm:col-span-2"><Field label="Items, one per line" error={e.items}><textarea className="field min-h-36" value={form.data.items} onChange={(x) => form.setData('items', x.target.value)} /></Field></div>
                <label className="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" checked={form.data.active} onChange={(x) => form.setData('active', x.target.checked)} /> In use</label>
                <div className="flex gap-2 sm:col-span-2"><Button type="submit" disabled={form.processing}>Save</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}
