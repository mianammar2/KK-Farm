import { Head, useForm } from '@inertiajs/react';
import DeleteButton from '../components/DeleteButton';
import { useState } from 'react';
import { Button, Chip, Empty, Field, LibIcon, PageHeader, Panel, pkr, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type AssetRow = { id: number; code: string; name: string; category: string; shed: string | null; shedId: number | null; status: string; purchased: string | null; cost: number | null; every: number | null; next: string | null; nextIso: string | null; due: boolean; overdue: boolean; notes: string | null };
type Job = { id: number; asset: string | null; assetCode: string | null; kind: string; on: string; problem: string; priority: string; status: string; by: string; doneOn: string | null; work: string | null; cost: number | null; doneBy: string | null };
type Props = {
    assets: AssetRow[]; jobs: Job[]; spend: { month: number; year: number }; sheds: Array<{ id: number; name: string }>;
    options: { categories: Record<string, string>; status: Record<string, string>; kinds: Record<string, string>; priority: Record<string, string> }; canManage: boolean;
};

const STATUS_CLS: Record<string, string> = { working: 'bg-ok-100 text-ok-700', under_repair: 'bg-crit-100 text-crit-700', retired: 'bg-glaze text-ink-soft', open: 'bg-watch-100 text-watch-700', in_progress: 'bg-cobalt-100 text-cobalt-800', done: 'bg-ok-100 text-ok-700' };
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

/** D-182: Maintenance & Assets: asset register, open jobs (breakdowns and services), services due, and repair costs. */
export default function Maintenance(p: Props) {
    const [tab, setTab] = useTabParam('jobs');
    const [reporting, setReporting] = useState<{ assetId: number; kind: string } | null>(null);
    const open = p.jobs.filter((j) => j.status !== 'done');
    const due = p.assets.filter((a) => a.due);
    return (
        <>
            <Head title="Maintenance & Assets" />
            <PageHeader title="Maintenance & Assets" description="Report a breakdown, keep services on time and see what repairs cost." actions={<Button onClick={() => setReporting({ assetId: p.assets[0]?.id ?? 0, kind: 'repair' })} disabled={!p.assets.length}><LibIcon name="app-maintenance" size={18} /> Report a Breakdown</Button>} />
            <StatRow>
                <Stat label="Open jobs" value={open.length} tone={open.length ? 'alert' : undefined} />
                <Stat label="Under repair" value={p.assets.filter((a) => a.status === 'under_repair').length} />
                <Stat label="Services due (14 days)" value={due.length} tone={due.some((a) => a.overdue) ? 'alert' : undefined} />
                <Stat label="Spent this month" value={pkr(p.spend.month)} note={`${pkr(p.spend.year)} this year`} />
            </StatRow>
            {reporting ? <ReportForm {...p} preset={reporting} onDone={() => setReporting(null)} /> : null}
            <Tabs value={tab} onChange={setTab} tabs={[
                { id: 'jobs', label: 'Jobs', count: open.length || undefined }, { id: 'due', label: 'Services Due', count: due.length || undefined },
                { id: 'assets', label: 'Asset Register', count: p.assets.length || undefined }, { id: 'history', label: 'Repair History' },
            ]} />
            {tab === 'jobs' ? <Jobs {...p} jobs={open} empty="No open jobs. Report a breakdown when something stops working." /> : null}
            {tab === 'history' ? <Jobs {...p} jobs={p.jobs.filter((j) => j.status === 'done')} empty="No finished jobs in the last 90 days." /> : null}
            {tab === 'due' ? (
                <Panel flush title="Preventive Services Due" description="From each asset's service interval">
                    {due.length ? <ul className="divide-y divide-rule">{due.map((a) => (
                        <li key={a.id} className="flex flex-wrap items-center gap-2 px-4 py-2.5 text-sm">
                            <strong>{a.name}</strong><span className="font-mono text-ink-soft">{a.code}</span>
                            <span className={a.overdue ? 'font-semibold text-crit-700' : 'text-watch-700'}>{a.overdue ? `Overdue since ${a.next}` : `Due ${a.next}`}</span>
                            <Button variant="secondary" className="ml-auto" onClick={() => setReporting({ assetId: a.id, kind: 'preventive' })}>Open Service Job</Button>
                        </li>
                    ))}</ul> : <Empty title="No services due in the next 14 days" />}
                </Panel>
            ) : null}
            {tab === 'assets' ? <Assets {...p} /> : null}
        </>
    );
}

function Jobs({ jobs, options, canManage, empty }: Props & { jobs: Job[]; empty: string }) {
    const [shown, more] = useFive(jobs);
    const [closing, setClosing] = useState<number | null>(null);
    return (
        <Panel flush>
            {jobs.length ? <ul className="divide-y divide-rule">{shown.map((j) => (
                <li key={j.id} className="px-4 py-3 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                        <strong className="text-cobalt-900">{j.asset}</strong><span className="font-mono text-xs text-ink-soft">{j.assetCode}</span>
                        <Chip className="bg-glaze text-ink-soft">{options.kinds[j.kind]}</Chip>
                        <Chip className={STATUS_CLS[j.status]}>{j.status === 'done' ? 'Done' : j.status === 'in_progress' ? 'In Progress' : 'Open'}</Chip>
                        <span className="text-ink-soft">{j.on} · {options.priority[j.priority]} · {j.by}</span>
                        {j.status !== 'done' && canManage ? <Button variant="secondary" className="ml-auto" onClick={() => setClosing(closing === j.id ? null : j.id)}>Close Job</Button> : null}
                    </div>
                    <p className="mt-1">{j.problem}</p>
                    {j.work ? <p className="mt-1 text-ok-700">Done {j.doneOn} by {j.doneBy}: {j.work}{j.cost ? ` · ${pkr(j.cost)}` : ''}</p> : null}
                    {closing === j.id ? <CloseForm id={j.id} onDone={() => setClosing(null)} /> : null}
                </li>
            ))}</ul> : <Empty title={empty} />}
            {more}
        </Panel>
    );
}

function CloseForm({ id, onDone }: { id: number; onDone: () => void }) {
    const form = useForm({ done_on: today(), work_done: '', cost: '', paid_to: '', paid: true });
    const e = form.errors as Record<string, string>;
    return (
        <form className="mt-2 grid gap-3 rounded-tile bg-glaze p-3 sm:grid-cols-4" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, cost: d.cost || null })); form.post(`/maintenance/jobs/${id}/close`, { preserveScroll: true, onSuccess: onDone }); }}>
            <Field label="Work done" error={e.work_done}><input className="field" value={form.data.work_done} onChange={(x) => form.setData('work_done', x.target.value)} /></Field>
            <Field label="Done on" error={e.done_on}><input type="date" max={today()} className="field" value={form.data.done_on} onChange={(x) => form.setData('done_on', x.target.value)} /></Field>
            <Field label="Cost PKR" error={e.cost} hint="Goes to Accounting as Maintenance & Repairs"><input type="number" min={0} className="field" value={form.data.cost} onChange={(x) => form.setData('cost', x.target.value)} /></Field>
            <Field label="Paid to" error={e.paid_to}><input className="field" value={form.data.paid_to} onChange={(x) => form.setData('paid_to', x.target.value)} /></Field>
            <label className="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" checked={form.data.paid} onChange={(x) => form.setData('paid', x.target.checked)} /> Already paid</label>
            <div className="flex gap-2 sm:col-span-3"><Button type="submit" disabled={form.processing}>Close Job</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}

function ReportForm({ assets, options, preset, onDone }: Props & { preset: { assetId: number; kind: string }; onDone: () => void }) {
    const form = useForm({ asset_id: String(preset.assetId), kind: preset.kind, problem: '', priority: preset.kind === 'repair' ? 'high' : 'medium' });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title={preset.kind === 'repair' ? 'Report a Breakdown' : 'Open a Job'} className="mb-4">
            <form className="grid gap-3 p-4 sm:grid-cols-4" onSubmit={(ev) => { ev.preventDefault(); form.post('/maintenance/jobs', { preserveScroll: true, onSuccess: onDone }); }}>
                <Field label="Asset" error={e.asset_id}><select className="field" value={form.data.asset_id} onChange={(x) => form.setData('asset_id', x.target.value)}>{assets.filter((a) => a.status !== 'retired').map((a) => <option key={a.id} value={a.id}>{a.name} ({a.code})</option>)}</select></Field>
                <Field label="Job" error={e.kind}><select className="field" value={form.data.kind} onChange={(x) => form.setData('kind', x.target.value)}>{Object.entries(options.kinds).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Priority" error={e.priority}><select className="field" value={form.data.priority} onChange={(x) => form.setData('priority', x.target.value)}>{Object.entries(options.priority).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Problem / what to do" error={e.problem}><input className="field" value={form.data.problem} onChange={(x) => form.setData('problem', x.target.value)} /></Field>
                <div className="flex gap-2 sm:col-span-4"><Button type="submit" disabled={form.processing}>Save</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}

function Assets({ assets, sheds, options, canManage }: Props) {
    const [editing, setEditing] = useState<AssetRow | 'new' | null>(null);
    return (
        <div className="space-y-3">
            {editing ? <AssetForm a={editing === 'new' ? null : editing} sheds={sheds} options={options} onDone={() => setEditing(null)} /> : null}
            <Panel flush title="Asset Register" actions={canManage ? <Button onClick={() => setEditing('new')}>+ New Asset</Button> : undefined}>
                {assets.length ? <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Code</th><th>Asset</th><th>Category</th><th>Location</th><th>Status</th><th>Next service</th><th /></tr></thead><tbody>
                    {assets.map((a) => (
                        <tr key={a.id}>
                            <td className="font-mono">{a.code}</td><td className="font-semibold">{a.name}</td><td>{options.categories[a.category]}</td><td>{a.shed ?? '—'}</td>
                            <td><Chip className={STATUS_CLS[a.status]}>{options.status[a.status]}</Chip></td>
                            <td className={a.overdue ? 'font-semibold text-crit-700' : ''}>{a.next ?? '—'}{a.every ? <span className="text-xs text-ink-soft"> · every {a.every} d</span> : null}</td>
                            <td className="whitespace-nowrap">{canManage ? <><Button variant="quiet" onClick={() => setEditing(a)}>Edit</Button><DeleteButton type="asset" id={a.id} name={a.name} /></> : null}</td>
                        </tr>
                    ))}
                </tbody></table></div> : <Empty title="No assets yet">{canManage ? 'Add your scales, RFID readers, feed mixer, pumps, fans and vehicles.' : 'The owner or manager adds them.'}</Empty>}
            </Panel>
        </div>
    );
}

function AssetForm({ a, sheds, options, onDone }: { a: AssetRow | null; sheds: Props['sheds']; options: Props['options']; onDone: () => void }) {
    const form = useForm({
        code: a?.code ?? '', name: a?.name ?? '', category: a?.category ?? 'scale', shed_id: a?.shedId ? String(a.shedId) : '', purchased_on: a?.purchased ?? '',
        cost: a?.cost !== null && a?.cost !== undefined ? String(a.cost) : '', status: a?.status ?? 'working', service_every_days: a?.every ? String(a.every) : '', next_service_on: a?.nextIso ?? '', notes: a?.notes ?? '',
    });
    const e = form.errors as Record<string, string>;
    const blank = (d: typeof form.data) => ({ ...d, shed_id: d.shed_id || null, purchased_on: d.purchased_on || null, cost: d.cost || null, service_every_days: d.service_every_days || null, next_service_on: d.next_service_on || null });
    return (
        <Panel title={a ? `Edit ${a.name}` : 'New Asset'}>
            <form className="grid gap-3 p-4 sm:grid-cols-4" onSubmit={(ev) => { ev.preventDefault(); form.transform(blank); form.post(a ? `/maintenance/assets/${a.id}` : '/maintenance/assets', { preserveScroll: true, onSuccess: onDone }); }}>
                <Field label="Code" error={e.code}><input className="field font-mono" placeholder="e.g. SCALE-01" value={form.data.code} onChange={(x) => form.setData('code', x.target.value.toUpperCase())} /></Field>
                <Field label="Name" error={e.name}><input className="field" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} /></Field>
                <Field label="Category" error={e.category}><select className="field" value={form.data.category} onChange={(x) => form.setData('category', x.target.value)}>{Object.entries(options.categories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Shed" error={e.shed_id}><select className="field" value={form.data.shed_id} onChange={(x) => form.setData('shed_id', x.target.value)}><option value="">Farm-wide</option>{sheds.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
                <Field label="Purchased on" error={e.purchased_on}><input type="date" className="field" value={form.data.purchased_on} onChange={(x) => form.setData('purchased_on', x.target.value)} /></Field>
                <Field label="Cost PKR" error={e.cost}><input type="number" min={0} className="field" value={form.data.cost} onChange={(x) => form.setData('cost', x.target.value)} /></Field>
                <Field label="Service every (days)" error={e.service_every_days}><input type="number" min={1} className="field" value={form.data.service_every_days} onChange={(x) => form.setData('service_every_days', x.target.value)} /></Field>
                <Field label="Next service" error={e.next_service_on} hint="Empty: worked out from the interval"><input type="date" className="field" value={form.data.next_service_on} onChange={(x) => form.setData('next_service_on', x.target.value)} /></Field>
                <Field label="Status" error={e.status}><select className="field" value={form.data.status} onChange={(x) => form.setData('status', x.target.value)}>{Object.entries(options.status).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                <div className="flex items-end gap-2 sm:col-span-2"><Button type="submit" disabled={form.processing}>Save</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}
