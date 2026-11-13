import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, LibIcon, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type Item = {
    id: number; origin: string | null; sex: string; breed: string | null; weight: number | null; screening: string; notes: string | null; cost: number | null;
    goat: { id: string; number: string; inQuarantine: boolean; status: string; pen: string | null } | null;
};
type Lot = {
    id: number; no: string; kind: string; supplier: string | null; source: string; purpose: string | null; expected: number; budget: number; status: string; requestedBy: string; approvedBy: string | null;
    note: string | null; requestedAt: string; receivedOn: string | null; receivedBy: string | null; pen: string | null; paid: number | null; transport: number | null;
    discrepancy: string | null; reconciledBy: string | null; reconciliation: string | null; billPosted: boolean; billStatus: string | null; items: Item[]; canDecide: boolean;
};
type Props = {
    lots: Lot[]; kpi: { rejection: number | null; quarantineDays: number | null; identity: number | null; costPerHead: number | null; inQuarantine: number };
    suppliers: Array<{ id: number; name: string }>; breeds: Array<{ id: number; name: string }>; pens: Array<{ id: number; name: string; goats: number; biosecure: boolean }>;
    options: { status: Record<string, string>; screening: Record<string, string> }; canApprove: boolean; canReceive: boolean; canTag: boolean; quarantineDays: number;
};

const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const pkr = (n: number) => `PKR ${Math.round(n).toLocaleString()}`;

/** Journey A on one line, so each lot shows where it is. */
const FLOW = ['Request', 'Approved', 'Received & Inspected', 'IDs & Tags', 'Quarantine', 'Cleared & Stocked'];

/** D-190: V3 cross-app journey A, Purchase-to-Quarantine (flow 24). */
export default function AnimalPurchase(p: Props) {
    const [tab, setTab] = useTabParam('lots');
    const pending = p.lots.filter((l) => ['requested', 'approved'].includes(l.status)).length;
    return (
        <>
            <Head title="Goat Purchase Lots" />
            <PageHeader title="Goat Purchase Lots" description={`Purchase request → approval → receive, count and inspect → Animal IDs and RFID → dedicated quarantine pen (${p.quarantineDays} days) → vet clearance → herd. No mixing with the herd before clearance; each goat keeps its lot and cost.`} />
            <StatRow>
                <Stat label="In quarantine now" value={p.kpi.inQuarantine} />
                <Stat label="Intake rejection" value={p.kpi.rejection === null ? '—' : `${p.kpi.rejection}%`} note="Goats rejected at inspection" />
                <Stat label="Average quarantine" value={p.kpi.quarantineDays === null ? '—' : `${p.kpi.quarantineDays} days`} note="Cleared, 180 days" />
                <Stat label="Identity coverage" value={p.kpi.identity === null ? '—' : `${p.kpi.identity}%`} note="Bought goats with RFID" />
                <Stat label="Initial cost / head" value={p.kpi.costPerHead === null ? '—' : pkr(p.kpi.costPerHead)} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'lots', label: 'Purchase Lots', count: pending || undefined }, { id: 'new', label: 'New Purchase Request' }]} />
            {tab === 'new' ? <RequestForm {...p} done={() => setTab('lots')} /> : <Lots {...p} />}
        </>
    );
}

function RequestForm({ suppliers, done }: Props & { done: () => void }) {
    const form = useForm({ supplier_id: '', source: '', purpose: '', expected_head: '', budget: '', kind: 'breeding' });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title="Goat Purchase Request" description="The owner or manager approves it (not the person asking).">
            <form className="grid gap-3 p-4 sm:grid-cols-3" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, supplier_id: d.supplier_id || null })); form.post('/purchase/animals', { preserveScroll: true, onSuccess: () => { form.reset(); done(); } }); }}>
                <Field label="Market or breeder" error={e.source}><input className="field" value={form.data.source} onChange={(x) => form.setData('source', x.target.value)} placeholder="e.g. Shahpur Kanjra cattle market" /></Field>
                <Field label="Supplier" error={e.supplier_id} hint="Optional"><select className="field" value={form.data.supplier_id} onChange={(x) => form.setData('supplier_id', x.target.value)}><option value="">—</option>{suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
                <Field label="Bought for" error={e.kind}><select className="field" value={form.data.kind} onChange={(x) => form.setData('kind', x.target.value)}><option value="breeding">Herd / breeding</option><option value="feeder">Feeders for fattening (meat)</option></select></Field>
                <Field label="Purpose" error={e.purpose}><input className="field" value={form.data.purpose} onChange={(x) => form.setData('purpose', x.target.value)} placeholder="e.g. Fattening lot for Eid" /></Field>
                <Field label="Number of goats" error={e.expected_head}><input type="number" min={1} className="field" value={form.data.expected_head} onChange={(x) => form.setData('expected_head', x.target.value)} /></Field>
                <Field label="Agreed price for the lot (PKR)" error={e.budget}><input type="number" min={1} className="field" value={form.data.budget} onChange={(x) => form.setData('budget', x.target.value)} /></Field>
                <div className="flex items-end"><Button type="submit" disabled={form.processing}><LibIcon name="app-purchase" size={18} /> Send for Approval</Button></div>
            </form>
        </Panel>
    );
}

function Lots(p: Props) {
    const [shown, more] = useFive(p.lots);
    return (
        <Panel flush title="Purchase Lots">
            {p.lots.length ? <ul className="divide-y divide-rule">{shown.map((l) => <LotItem key={l.id} l={l} {...p} />)}</ul> : <Empty title="No goat purchases yet">Start with a New Purchase Request.</Empty>}
            {more}
        </Panel>
    );
}

function LotItem({ l, options, canApprove, canReceive, ...p }: Props & { l: Lot }) {
    const [mode, setMode] = useState<string | null>(null);
    const accepted = l.items.filter((i) => i.goat);
    const cleared = accepted.filter((i) => i.goat && !i.goat.inQuarantine).length;
    const stage = l.status === 'requested' ? 0 : l.status === 'approved' ? 1 : l.status !== 'received' ? -1 : cleared === accepted.length && accepted.length ? 5 : 4;
    const decide = useForm({ approve: true as boolean, note: '' });
    const rec = useForm({ reconciliation: '' });
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono text-cobalt-900">{l.no}</strong>
                <span className="font-semibold">{l.source}</span>{l.supplier ? <span className="text-ink-soft">· {l.supplier}</span> : null}
                <span>{l.expected} goats · {pkr(l.budget)}</span>
                <Chip className={l.status === 'rejected' || l.status === 'cancelled' ? 'bg-crit-100 text-crit-700' : l.status === 'received' ? 'bg-ok-100 text-ok-700' : 'bg-watch-100 text-watch-700'}>{options.status[l.status]}</Chip>
                <span className="text-ink-soft">asked by {l.requestedBy}, {l.requestedAt}{l.approvedBy ? ` · decided by ${l.approvedBy}` : ''}</span>
            </div>
            {stage >= 0 ? <ol className="mt-2 flex flex-wrap items-center gap-1 text-[11px] font-semibold">
                {FLOW.map((s, i) => <li key={s} className="flex items-center gap-1"><span className={`rounded-full px-2 py-0.5 ${i <= stage ? 'bg-cobalt-900 text-white' : 'bg-glaze text-ink-soft ring-1 ring-rule'}`}>{s}</span>{i < FLOW.length - 1 ? <span className="text-ink-faint">→</span> : null}</li>)}
            </ol> : null}
            {l.kind === 'feeder' ? <p className="mt-1 text-xs font-semibold text-treat-700">Feeder lot: each goat is proposed for a finishing batch when the vet clears it from quarantine.</p> : null}
            {l.purpose ? <p className="mt-1 text-ink-soft">{l.purpose}</p> : null}
            {l.note ? <p className="mt-1 text-ink-soft">Note: {l.note}</p> : null}
            {l.status === 'received' ? <p className="mt-1">Received {l.receivedOn} by {l.receivedBy} into <strong>{l.pen}</strong> · paid {pkr(l.paid ?? 0)}{l.transport ? ` + transport ${pkr(l.transport)}` : ''} · bill {l.billPosted ? (l.billStatus === 'paid' ? 'posted, paid' : 'posted, owed to supplier') : 'none'} · {cleared} of {accepted.length} cleared</p> : null}
            {l.discrepancy ? <p className={`mt-1 ${l.reconciledBy ? 'text-ink-soft' : 'font-semibold text-crit-700'}`}>Discrepancy: {l.discrepancy}{l.reconciledBy ? ` · reconciled by ${l.reconciledBy}: ${l.reconciliation}` : ''}</p> : null}
            {l.items.length ? <details className="mt-1"><summary className="cursor-pointer text-xs font-semibold text-ink-soft">Goats ({l.items.length})</summary>
                <div className="overflow-x-auto"><table className="data-table mt-1"><thead><tr><th>Origin ID</th><th>Goat</th><th>Sex</th><th>Breed</th><th>Weight</th><th>Inspection</th><th>Cost</th><th>Now</th></tr></thead><tbody>
                    {l.items.map((i) => <tr key={i.id}><td className="font-mono">{i.origin ?? '—'}</td><td>{i.goat ? <Link href={`/goats/${i.goat.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{i.goat.number}</Link> : '—'}</td>
                        <td>{i.sex}</td><td>{i.breed}</td><td>{i.weight ?? '—'}</td><td><Chip className={i.screening === 'ok' ? 'bg-ok-100 text-ok-700' : i.screening === 'sick' ? 'bg-treat-100 text-treat-700' : 'bg-crit-100 text-crit-700'}>{options.screening[i.screening]}</Chip>{i.notes ? <span className="ml-1 text-xs text-ink-soft">{i.notes}</span> : null}</td>
                        <td>{i.cost !== null ? pkr(i.cost) : '—'}</td><td>{i.goat ? (i.goat.status !== 'active' ? i.goat.status : i.goat.inQuarantine ? 'In quarantine' : `Cleared · ${i.goat.pen ?? ''}`) : 'Returned'}</td></tr>)}
                </tbody></table></div>
            </details> : null}
            <div className="mt-2 flex flex-wrap gap-1.5">
                {l.canDecide ? <Button onClick={() => setMode('decide')}>Approve / Reject</Button> : null}
                {l.status === 'requested' && canApprove && !l.canDecide ? <span className="text-xs text-ink-soft">Someone other than the requester approves it.</span> : null}
                {l.status === 'approved' && canReceive ? <Button onClick={() => setMode('receive')}><LibIcon name="app-purchase" size={16} /> Receive, Count & Inspect</Button> : null}
                {l.status === 'received' && accepted.some((i) => i.goat?.inQuarantine) ? <Link href="/intake" className="self-center text-xs font-semibold text-cobalt-800 hover:underline">Quarantine & vet clearance →</Link> : null}
                {l.discrepancy && !l.reconciledBy && canApprove ? <Button variant="secondary" onClick={() => setMode('reconcile')}>Reconcile</Button> : null}
            </div>
            {mode === 'decide' ? <div className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-3">
                <Field label="Decision"><select className="field" value={decide.data.approve ? 'yes' : 'no'} onChange={(x) => decide.setData('approve', x.target.value === 'yes')}><option value="yes">Approve</option><option value="no">Reject</option></select></Field>
                <Field label="Note" error={decide.errors.note}><input className="field" value={decide.data.note} onChange={(x) => decide.setData('note', x.target.value)} /></Field>
                <div className="flex items-end gap-2"><Button disabled={decide.processing} onClick={() => decide.post(`/purchase/animals/${l.id}/decide`, { preserveScroll: true, onSuccess: () => setMode(null) })}>Save</Button><Button variant="secondary" onClick={() => setMode(null)}>Cancel</Button></div>
            </div> : null}
            {mode === 'reconcile' ? <div className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-3">
                <div className="sm:col-span-2"><Field label="How it was settled" error={rec.errors.reconciliation}><input className="field" value={rec.data.reconciliation} onChange={(x) => rec.setData('reconciliation', x.target.value)} placeholder="Refund received, replacement goat, papers collected…" /></Field></div>
                <div className="flex items-end gap-2"><Button disabled={rec.processing} onClick={() => rec.post(`/purchase/animals/${l.id}/reconcile`, { preserveScroll: true, onSuccess: () => setMode(null) })}>Save</Button><Button variant="secondary" onClick={() => setMode(null)}>Cancel</Button></div>
            </div> : null}
            {mode === 'receive' ? <Receive l={l} {...p} options={options} canApprove={canApprove} canReceive={canReceive} close={() => setMode(null)} /> : null}
        </li>
    );
}

type Row = { origin_ref: string; sex: string; breed_id: string; weight_kg: string; screening: string; notes: string; tag_id: string; rfid_epc: string };

function Receive({ l, pens, breeds, options, canTag, close }: Props & { l: Lot; close: () => void }) {
    const blank = (): Row => ({ origin_ref: '', sex: 'male', breed_id: String(breeds[0]?.id ?? ''), weight_kg: '', screening: 'ok', notes: '', tag_id: '', rfid_epc: '' });
    const form = useForm({ received_on: today(), quarantine_pen_id: String(pens[0]?.id ?? ''), paid: String(l.budget), transport: '', bill_paid: false as boolean, discrepancy: '', items: Array.from({ length: Math.min(l.expected, 60) }, blank) });
    const e = form.errors as Record<string, string>;
    const set = (i: number, k: keyof Row, v: string) => form.setData('items', form.data.items.map((r, j) => (j === i ? { ...r, [k]: v } : r)));
    const accepted = form.data.items.filter((r) => r.screening !== 'rejected').length;
    const perHead = accepted ? (Number(form.data.paid || 0) + Number(form.data.transport || 0)) / accepted : 0;
    const itemErr = Object.entries(e).filter(([k]) => k.startsWith('items.'));
    return (
        <div className="mt-2 space-y-3 rounded-tile bg-glaze p-3">
            <div className="grid gap-2 sm:grid-cols-3">
                <Field label="Received on" error={e.received_on}><input type="date" max={today()} className="field" value={form.data.received_on} onChange={(x) => form.setData('received_on', x.target.value)} /></Field>
                <Field label="Dedicated quarantine pen" error={e.quarantine_pen_id} hint="Empty or holding only quarantined goats"><select className="field" value={form.data.quarantine_pen_id} onChange={(x) => form.setData('quarantine_pen_id', x.target.value)}><option value="">Choose…</option>{pens.map((p) => <option key={p.id} value={p.id}>{p.name}{p.biosecure ? ' (biosecurity)' : ''}{p.goats ? ` · ${p.goats} in quarantine` : ' · empty'}</option>)}</select></Field>
                <Field label="Paid to seller (PKR)" error={e.paid} hint="After any rejected goats"><input type="number" min={0} className="field" value={form.data.paid} onChange={(x) => form.setData('paid', x.target.value)} /></Field>
                <Field label="Transport (PKR)" error={e.transport}><input type="number" min={0} className="field" value={form.data.transport} onChange={(x) => form.setData('transport', x.target.value)} /></Field>
                <Field label="Supplier bill"><select className="field" value={form.data.bill_paid ? 'paid' : 'owed'} onChange={(x) => form.setData('bill_paid', x.target.value === 'paid')}><option value="owed">Owed (pay later)</option><option value="paid">Paid now</option></select></Field>
                <div className="self-end pb-2 text-sm">Cost per accepted goat: <strong>{pkr(perHead)}</strong></div>
            </div>
            <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>#</th><th>Origin ID</th><th>Sex</th><th>Breed</th><th>Weight KG</th><th>Inspection</th><th>Notes</th><th>Tag ID</th>{canTag ? <th>RFID EPC</th> : null}<th /></tr></thead><tbody>
                {form.data.items.map((r, i) => <tr key={i}>
                    <td>{i + 1}</td>
                    <td><input className="field w-28 font-mono" value={r.origin_ref} onChange={(x) => set(i, 'origin_ref', x.target.value)} /></td>
                    <td><select className="field w-24" value={r.sex} onChange={(x) => set(i, 'sex', x.target.value)}><option value="male">Male</option><option value="female">Female</option></select></td>
                    <td><select className="field w-32" value={r.breed_id} onChange={(x) => set(i, 'breed_id', x.target.value)}>{breeds.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select></td>
                    <td><input type="number" step="0.1" className="field w-20" value={r.weight_kg} onChange={(x) => set(i, 'weight_kg', x.target.value)} /></td>
                    <td><select className="field w-44" value={r.screening} onChange={(x) => set(i, 'screening', x.target.value)}>{Object.entries(options.screening).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></td>
                    <td><input className={`field w-40 ${e[`items.${i}.notes`] ? 'ring-2 ring-crit-500' : ''}`} value={r.notes} onChange={(x) => set(i, 'notes', x.target.value)} /></td>
                    <td><input className="field w-28 font-mono" placeholder="Auto" value={r.tag_id} onChange={(x) => set(i, 'tag_id', x.target.value)} /></td>
                    {canTag ? <td><input className="field w-36 font-mono" value={r.rfid_epc} onChange={(x) => set(i, 'rfid_epc', x.target.value)} /></td> : null}
                    <td><button type="button" className="text-crit-700" onClick={() => form.setData('items', form.data.items.filter((_, j) => j !== i))} aria-label="Remove">×</button></td>
                </tr>)}
            </tbody></table></div>
            <Button variant="secondary" onClick={() => form.setData('items', [...form.data.items, blank()])}>+ Add Goat</Button>
            {itemErr.length ? <p className="text-sm text-crit-700">{itemErr.slice(0, 3).map(([k, v]) => `Goat ${Number(k.split('.')[1]) + 1}: ${v}`).join(' · ')}</p> : null}
            <Field label="Discrepancy" error={e.discrepancy} hint={`Counted ${form.data.items.length} of ${l.expected}. Needed when the count differs, an origin ID is missing, or a goat is sick or rejected.`}><input className="field" value={form.data.discrepancy} onChange={(x) => form.setData('discrepancy', x.target.value)} /></Field>
            <div className="flex gap-2"><Button disabled={form.processing} onClick={() => form.post(`/purchase/animals/${l.id}/receive`, { preserveScroll: true, onSuccess: close })}>Register {accepted} Goats into Quarantine</Button><Button variant="secondary" onClick={close}>Cancel</Button></div>
        </div>
    );
}
