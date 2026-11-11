import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, LibIcon, PageHeader, Panel, pkr, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';
import { MilkTracePanel, RecallKpis, type RecallKpi, type TraceData } from '../components/MilkTrace';

type Session = {
    session: string; produced: number; held: number; colostrum: number; does: number;
    doeList: Array<{ id: string; number: string | null; litres: number; held: boolean; reason: string | null }>;
    closed: { kid: number; discard: number; saleable: number; by: string; lot: string | null } | null;
};
type Lot = {
    id: number; code: string; litres: number; remaining: number; status: string; session: string; tank: string | null; temp: number | null; residue: string | null;
    testedBy: string | null; releasedBy: string | null; hold: string | null; buyers: Array<{ on: string; to: string; litres: number; kind: string }>;
};
type Order = { id: number; no: string; customer: string | null; litres: number; remaining: number; price: number; wanted: string; terms: string; creditDays: number | null; status: string; by: string };
type Invoice = { id: number; invoice: string; on: string; to: string | null; litres: number; amount: number; lot: string | null; order: string | null; temp: number | null; due: string | null; overdue: boolean; collected: string | null };
type Month = { month: string; produced: number; saleable: number; lost: number; kid: number; colostrum: number; sold: number; revenue: number; feed: number | null; contribution: number | null; revenuePerLitre: number | null };
type Props = {
    day: string; sessions: Session[]; lots: Lot[]; customers: Array<{ id: number; name: string }>; options: { status: Record<string, string> }; canQuality: boolean;
    kpi: { perDoeDay: number | null; saleablePct: number | null; lossPct: number | null; produced: number; revenue: number; costPerLitre: number | null; feedDay: number; costActual: boolean; revenuePerLitre: number | null; contributionPerLitre: number | null; owed: number };
    orders: Order[]; invoices: Invoice[]; monthly: Month[]; maxTemp: number;
    trace: TraceData | null; recallKpi: RecallKpi; // D-196
};

const LOT_CLS: Record<string, string> = { pending_qc: 'bg-watch-100 text-watch-700', stored: 'bg-cobalt-100 text-cobalt-800', released: 'bg-ok-100 text-ok-700', hold: 'bg-treat-100 text-treat-700', rejected: 'bg-crit-100 text-crit-700', dispatched: 'bg-glaze text-ink-soft', recalled: 'bg-crit-100 text-crit-700' };
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

/** D-188: App 17 Dairy & Milk (V3 flow 17): close sessions, milk lots, QA, release, dispatch, hold and recall. */
export default function Dairy(p: Props) {
    const [tab, setTab] = useTabParam('sessions');
    const waiting = p.lots.filter((l) => ['pending_qc', 'stored', 'hold'].includes(l.status)).length;
    return (
        <>
            <Head title="Dairy & Milk" />
            <PageHeader title="Dairy & Milk Production" description="Per-doe litres are logged under Milking. Here each session is closed, saleable milk becomes a lot, and only QA-released lots are dispatched." actions={<Link href="/milking"><Button variant="secondary"><LibIcon name="ic-lactating" size={18} /> Log Litres per Doe</Button></Link>} />
            <StatRow>
                <Stat label="Litres / doe / day (30 days)" value={p.kpi.perDoeDay ?? '—'} note={`${p.kpi.produced} L produced`} />
                <Stat label="Saleable share" value={p.kpi.saleablePct !== null ? `${p.kpi.saleablePct}%` : '—'} note={p.kpi.lossPct !== null ? `${p.kpi.lossPct}% held or discarded` : undefined} />
                <Stat label="Milk revenue (30 days)" value={pkr(p.kpi.revenue)} />
                <Stat label={p.kpi.costActual ? 'Feed cost / litre' : 'Feed cost / litre (estimate)'} value={p.kpi.costPerLitre !== null ? pkr(p.kpi.costPerLitre) : '—'} note={p.kpi.costActual ? 'from meals fed to does in milk' : `does in milk eat about ${pkr(p.kpi.feedDay)}/day`} />
                <Stat label="Revenue / litre sold" value={p.kpi.revenuePerLitre !== null ? pkr(p.kpi.revenuePerLitre) : '—'} note={p.kpi.contributionPerLitre !== null ? `contribution ${pkr(p.kpi.contributionPerLitre)}/L` : undefined} tone={p.kpi.contributionPerLitre !== null && p.kpi.contributionPerLitre < 0 ? 'alert' : undefined} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'sessions', label: 'Close Sessions' }, { id: 'lots', label: 'Milk Lots & QA', count: waiting || undefined }, { id: 'orders', label: 'Milk Orders', count: p.orders.filter((o) => o.status === 'open').length || undefined }, { id: 'trace', label: 'Dispatch & Trace' }, { id: 'money', label: 'Invoices & Milk Profit', count: p.invoices.filter((i) => !i.collected).length || undefined }]} />
            {tab === 'sessions' ? (
                <div className="space-y-3">
                    <div className="flex items-center gap-2 text-sm"><span className="font-semibold">Day</span><input type="date" max={today()} className="field !w-44 !py-1.5" value={p.day} onChange={(e) => router.get('/dairy', { tab: 'sessions', day: e.target.value }, { preserveScroll: true })} /></div>
                    <div className="grid gap-3 lg:grid-cols-2">{p.sessions.map((s) => <SessionCard key={s.session} s={s} day={p.day} />)}</div>
                </div>
            ) : null}
            {tab === 'lots' ? <Lots {...p} lots={p.lots.filter((l) => !['dispatched', 'recalled', 'rejected'].includes(l.status))} empty="No lots waiting. Close a session to create one." /> : null}
            {tab === 'trace' ? <><RecallKpis k={p.recallKpi} /><Lots {...p} lots={p.lots} empty="No milk lots in the last 30 days." /></> : null}
            {p.trace ? <MilkTracePanel t={p.trace} canQuality={p.canQuality} onClose={() => router.get('/dairy', { tab: tab === 'trace' ? 'trace' : tab }, { preserveScroll: true })} /> : null}
            {tab === 'orders' ? <Orders {...p} /> : null}
            {tab === 'money' ? <Money {...p} /> : null}
        </>
    );
}

function SessionCard({ s, day }: { s: Session; day: string }) {
    const form = useForm({ milked_on: day, session: s.session, hygiene_ok: true, equipment_ok: true, operator: '', mastitis: [] as string[], kid_l: '', discard_l: '', notes: '' });
    const e = form.errors as Record<string, string>;
    const d = form.data;
    const available = Math.max(0, s.produced - s.held - s.colostrum);
    const saleable = d.hygiene_ok && d.equipment_ok ? Math.max(0, available - Number(d.kid_l || 0) - Number(d.discard_l || 0)) : 0;
    const title = s.session === 'morning' ? 'Morning (AM)' : 'Evening (PM)';
    if (s.closed) {
        return (
            <Panel title={title} description={`Closed by ${s.closed.by}`} actions={s.closed.lot ? <Chip className="bg-ok-100 text-ok-700">Lot {s.closed.lot}</Chip> : <Chip className="bg-glaze text-ink-soft">No saleable milk</Chip>}>
                <Balance produced={s.produced} held={s.held} colostrum={s.colostrum} kid={s.closed.kid} discard={s.closed.discard} saleable={s.closed.saleable} />
            </Panel>
        );
    }
    if (!s.does) return <Panel title={title}><Empty title="No litres logged yet">Log each doe's litres under Milking first.</Empty></Panel>;
    return (
        <Panel title={title} description={`${s.does} does · ${s.produced} L logged`}>
            <form className="space-y-3 p-4" onSubmit={(ev) => { ev.preventDefault(); form.transform((x) => ({ ...x, kid_l: x.kid_l || 0, discard_l: x.discard_l || 0 })); form.post('/dairy/sessions', { preserveScroll: true }); }}>
                <div className="rounded-tile bg-glaze p-3 text-sm">
                    <p className="mb-1.5 font-bold text-cobalt-900">1 · Hygiene, equipment and operator check</p>
                    <div className="flex flex-wrap items-center gap-3">
                        <label className="flex items-center gap-1.5"><input type="checkbox" checked={d.hygiene_ok} onChange={(x) => form.setData('hygiene_ok', x.target.checked)} /> Udders and hands clean</label>
                        <label className="flex items-center gap-1.5"><input type="checkbox" checked={d.equipment_ok} onChange={(x) => form.setData('equipment_ok', x.target.checked)} /> Buckets / machine clean</label>
                        <input className="field !w-44 !py-1" placeholder="Milker" value={d.operator} onChange={(x) => form.setData('operator', x.target.value)} />
                        {e.operator ? <span className="text-xs text-crit-700">{e.operator}</span> : null}
                    </div>
                    {!d.hygiene_ok || !d.equipment_ok ? <p className="mt-1 text-xs font-semibold text-crit-700">A failed check discards this session's saleable milk.</p> : null}
                </div>
                <div className="rounded-tile bg-glaze p-3 text-sm">
                    <p className="mb-1.5 font-bold text-cobalt-900">2 · Pre-milking udder check</p>
                    <p className="mb-1 text-xs text-ink-soft">Tick any doe with a hard, hot or swollen udder or clots in the milk: her milk is held and the vet gets a task.</p>
                    <div className="flex flex-wrap gap-1.5">{s.doeList.map((x) => (
                        <label key={x.id} className={`flex items-center gap-1 rounded-full px-2 py-0.5 text-xs ring-1 ${x.held ? 'bg-crit-100 text-crit-700 ring-crit-500/30' : d.mastitis.includes(x.id) ? 'bg-treat-100 text-treat-700 ring-treat-400' : 'bg-white ring-rule'}`} title={x.reason ?? ''}>
                            {x.held ? null : <input type="checkbox" checked={d.mastitis.includes(x.id)} onChange={() => form.setData('mastitis', d.mastitis.includes(x.id) ? d.mastitis.filter((y) => y !== x.id) : [...d.mastitis, x.id])} />}
                            <span className="font-mono">{x.number}</span> {x.litres} L{x.held ? ' · held' : ''}
                        </label>
                    ))}</div>
                </div>
                <div className="rounded-tile bg-glaze p-3 text-sm">
                    <p className="mb-1.5 font-bold text-cobalt-900">3 · Kid milk and colostrum</p>
                    <div className="grid gap-2 sm:grid-cols-3">
                        <div className="pt-1">Colostrum (first {3} days): <strong>{s.colostrum} L</strong><div className="text-xs text-ink-soft">never saleable</div></div>
                        <Field label="Fed to kids L" error={e.kid_l}><input type="number" step="0.1" min={0} className="field" value={d.kid_l} onChange={(x) => form.setData('kid_l', x.target.value)} /></Field>
                        <Field label="Other discard L"><input type="number" step="0.1" min={0} className="field" value={d.discard_l} onChange={(x) => form.setData('discard_l', x.target.value)} /></Field>
                    </div>
                </div>
                <Balance produced={s.produced} held={s.held} colostrum={s.colostrum} kid={Number(d.kid_l || 0)} discard={d.hygiene_ok && d.equipment_ok ? Number(d.discard_l || 0) : available - Number(d.kid_l || 0)} saleable={saleable} />
                {e.session ? <p className="text-sm text-crit-700">{e.session}</p> : null}
                <Button type="submit" disabled={form.processing}>Close Session{saleable > 0 ? ` & Create Lot (${saleable.toFixed(1)} L)` : ''}</Button>
            </form>
        </Panel>
    );
}

function Balance({ produced, held, colostrum, kid, discard, saleable }: { produced: number; held: number; colostrum: number; kid: number; discard: number; saleable: number }) {
    const parts = [['Held (vet)', held, 'bg-crit-500'], ['Colostrum', colostrum, 'bg-watch-400'], ['Kids', kid, 'bg-turq-600'], ['Discard', Math.max(0, discard), 'bg-rule-strong'], ['Saleable', Math.max(0, saleable), 'bg-ok-700']] as const;
    return (
        <div className="space-y-1.5 px-4 pb-3 text-xs">
            <div className="flex h-3 overflow-hidden rounded-full bg-rule/50">{parts.map(([l, v, c]) => (produced > 0 && v > 0 ? <span key={l} className={c} style={{ width: `${(v / produced) * 100}%` }} title={`${l} ${v} L`} /> : null))}</div>
            <div className="flex flex-wrap gap-x-3 gap-y-0.5 text-ink-soft"><strong className="text-cobalt-900">{produced} L produced</strong>{parts.map(([l, v]) => <span key={l}>{l} {Math.round(v * 10) / 10} L</span>)}</div>
        </div>
    );
}

function Lots({ lots, customers, options, canQuality, empty, orders, maxTemp }: Props & { empty: string }) {
    const [shown, more] = useFive(lots);
    return (
        <Panel flush>
            {lots.length ? <ul className="divide-y divide-rule">{shown.map((l) => <LotItem key={l.id} l={l} customers={customers} options={options} canQuality={canQuality} orders={orders} maxTemp={maxTemp} />)}</ul> : <Empty title={empty} />}
            {more}
        </Panel>
    );
}

function LotItem({ l, customers, options, canQuality, orders, maxTemp }: { l: Lot; customers: Props['customers']; options: Props['options']; canQuality: boolean; orders: Order[]; maxTemp: number }) {
    const [mode, setMode] = useState<'test' | 'dispatch' | 'hold' | 'recall' | 'temp' | null>(null);
    const temp = useForm({ temp_c: '' });
    const test = useForm({ appearance_ok: true, smell_ok: true, residue: 'negative', tank: '', storage_temp_c: '', notes: '' });
    const send = useForm({ kind: 'sale', litres: String(l.remaining), dispatched_on: today(), customer_id: '', buyer: '', price_per_litre: '', order_id: '', terms: 'cash', credit_days: '30', dispatch_temp_c: '', container_ok: false as boolean, vehicle_ok: false as boolean });
    const openOrders = orders.filter((o) => o.status === 'open');
    const reason = useForm({ reason: '' });
    const e = { ...(test.errors as Record<string, string>), ...(send.errors as Record<string, string>) };
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono text-cobalt-900">{l.code}</strong>
                <Chip className={LOT_CLS[l.status]}>{options.status[l.status]}</Chip>
                <span className="text-ink-soft">{l.session} · {l.litres} L{l.remaining < l.litres ? ` · ${l.remaining} L left` : ''}{l.tank ? ` · ${l.tank}` : ''}{l.temp !== null ? ` · ${l.temp} °C` : ''}{l.residue ? ` · residue ${l.residue}` : ''}</span>
                <span className="ml-auto flex flex-wrap gap-1.5">
                    {['pending_qc', 'hold'].includes(l.status) ? <Button variant="secondary" onClick={() => setMode(mode === 'test' ? null : 'test')}>Quality & Residue Check</Button> : null}
                    {l.status === 'stored' && canQuality ? <Button onClick={() => router.post(`/dairy/lots/${l.id}/release`, {}, { preserveScroll: true })}>Release</Button> : null}
                    {l.status === 'released' ? <Button onClick={() => setMode(mode === 'dispatch' ? null : 'dispatch')}>Dispatch</Button> : null}
                    {['stored', 'released'].includes(l.status) && canQuality ? <Button variant="quiet" onClick={() => setMode('hold')}>Hold</Button> : null}
                    {['released', 'dispatched', 'hold'].includes(l.status) && canQuality ? <Button variant="quiet" onClick={() => setMode('recall')}>Recall</Button> : null}
                    {['pending_qc', 'stored', 'released'].includes(l.status) ? <Button variant="quiet" onClick={() => setMode(mode === 'temp' ? null : 'temp')}>Log Temperature</Button> : null}
                    <Button variant="secondary" onClick={() => router.get('/dairy', { tab: new URLSearchParams(window.location.search).get('tab') ?? 'lots', trace: l.id }, { preserveScroll: true, preserveState: true })}>Trace</Button>
                </span>
            </div>
            {l.testedBy || l.releasedBy || l.hold ? <p className="mt-1 text-xs text-ink-soft">{l.testedBy ? `Tested by ${l.testedBy}` : ''}{l.releasedBy ? ` · released by ${l.releasedBy}` : ''}{l.hold ? ` · ${l.hold}` : ''}</p> : null}
            {l.buyers.length ? <p className="mt-1 text-xs">Sent to: {l.buyers.map((b) => `${b.to} ${b.litres} L (${b.on})`).join(', ')}</p> : null}
            {mode === 'test' ? (
                <form className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-6" onSubmit={(ev) => { ev.preventDefault(); test.transform((x) => ({ ...x, storage_temp_c: x.storage_temp_c || null })); test.post(`/dairy/lots/${l.id}/test`, { preserveScroll: true, onSuccess: () => setMode(null) }); }}>
                    <label className="flex items-center gap-1.5 pt-5"><input type="checkbox" checked={test.data.appearance_ok} onChange={(x) => test.setData('appearance_ok', x.target.checked)} /> Appearance normal</label>
                    <label className="flex items-center gap-1.5 pt-5"><input type="checkbox" checked={test.data.smell_ok} onChange={(x) => test.setData('smell_ok', x.target.checked)} /> Smell normal</label>
                    <Field label="Antibiotic residue"><select className="field" value={test.data.residue} onChange={(x) => test.setData('residue', x.target.value)}><option value="negative">Negative</option><option value="positive">Positive</option><option value="not_tested">Not tested</option></select></Field>
                    <Field label="Tank"><input className="field" value={test.data.tank} onChange={(x) => test.setData('tank', x.target.value)} /></Field>
                    <Field label="Chilled to °C" error={e.storage_temp_c}><input type="number" step="0.1" className="field" value={test.data.storage_temp_c} onChange={(x) => test.setData('storage_temp_c', x.target.value)} /></Field>
                    <div className="flex items-end"><Button type="submit" disabled={test.processing}>Save Check</Button></div>
                    <p className="text-xs text-ink-soft sm:col-span-6">Passes only with normal appearance and smell and a negative residue test; otherwise the lot is rejected for discard.</p>
                </form>
            ) : null}
            {mode === 'dispatch' ? (
                <form className="mt-2 grid gap-2 rounded-tile bg-turq-100/50 p-3 sm:grid-cols-6" onSubmit={(ev) => { ev.preventDefault(); send.transform((x) => ({ ...x, customer_id: x.customer_id || null, order_id: x.order_id || null, price_per_litre: x.kind === 'sale' && !x.order_id ? x.price_per_litre : null, dispatch_temp_c: x.dispatch_temp_c || null, credit_days: x.terms === 'credit' ? x.credit_days : null })); send.post(`/dairy/lots/${l.id}/dispatch`, { preserveScroll: true, onSuccess: () => setMode(null) }); }}>
                    <Field label="To"><select className="field" value={send.data.kind} onChange={(x) => send.setData('kind', x.target.value)}><option value="sale">Buyer (sale)</option><option value="processing">Processing</option></select></Field>
                    <Field label="Litres" error={e.litres}><input type="number" step="0.1" className="field" value={send.data.litres} onChange={(x) => send.setData('litres', x.target.value)} /></Field>
                    {send.data.kind === 'sale' && openOrders.length ? <Field label="Milk order"><select className="field" value={send.data.order_id} onChange={(x) => { const o = openOrders.find((q) => String(q.id) === x.target.value); send.setData((d) => ({ ...d, order_id: x.target.value, litres: o ? String(Math.min(o.remaining, l.remaining)) : d.litres })); }}><option value="">No order</option>{openOrders.map((o) => <option key={o.id} value={o.id}>{o.no} · {o.customer} · {o.remaining} L</option>)}</select></Field> : null}
                    {send.data.kind === 'sale' && !send.data.order_id ? <>
                        <Field label="Customer"><select className="field" value={send.data.customer_id} onChange={(x) => send.setData('customer_id', x.target.value)}><option value="">—</option>{customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
                        <Field label="Or buyer"><input className="field" value={send.data.buyer} onChange={(x) => send.setData('buyer', x.target.value)} /></Field>
                        <Field label="PKR / litre" error={e.price_per_litre}><input type="number" className="field" value={send.data.price_per_litre} onChange={(x) => send.setData('price_per_litre', x.target.value)} /></Field>
                        <Field label="Payment"><select className="field" value={send.data.terms} onChange={(x) => send.setData('terms', x.target.value)}><option value="cash">Paid now</option><option value="credit">Credit</option></select></Field>
                    </> : null}
                    {send.data.kind === 'sale' ? <>
                        <Field label="Milk °C at loading" error={e.dispatch_temp_c} hint={`${maxTemp} °C or colder`}><input type="number" step="0.1" className="field" value={send.data.dispatch_temp_c} onChange={(x) => send.setData('dispatch_temp_c', x.target.value)} /></Field>
                        <label className="flex items-center gap-1.5 pt-5"><input type="checkbox" checked={send.data.container_ok} onChange={(x) => send.setData('container_ok', x.target.checked)} /> Container clean</label>
                        <label className="flex items-center gap-1.5 pt-5"><input type="checkbox" checked={send.data.vehicle_ok} onChange={(x) => send.setData('vehicle_ok', x.target.checked)} /> Vehicle fit</label>
                    </> : null}
                    <div className="flex items-end"><Button type="submit" disabled={send.processing}>Dispatch</Button></div>
                </form>
            ) : null}
            {mode === 'temp' ? (
                <form className="mt-2 flex items-end gap-2" onSubmit={(ev) => { ev.preventDefault(); temp.post(`/dairy/lots/${l.id}/temperature`, { preserveScroll: true, onSuccess: () => { temp.reset(); setMode(null); } }); }}>
                    <Field label={`Storage temperature °C (limit ${maxTemp} °C)`} error={(temp.errors as Record<string, string>).temp_c}><input type="number" step="0.1" className="field" value={temp.data.temp_c} onChange={(x) => temp.setData('temp_c', x.target.value)} autoFocus /></Field>
                    <Button type="submit" disabled={temp.processing}>Save</Button>
                </form>
            ) : null}
            {mode === 'hold' || mode === 'recall' ? (
                <form className="mt-2 flex gap-2" onSubmit={(ev) => { ev.preventDefault(); reason.post(`/dairy/lots/${l.id}/${mode}`, { preserveScroll: true, onSuccess: () => setMode(null) }); }}>
                    <input className="field flex-1" placeholder={mode === 'hold' ? 'Why hold (retest before release)' : 'Why recall (buyers will be listed to notify)'} value={reason.data.reason} onChange={(x) => reason.setData('reason', x.target.value)} />
                    <Button type="submit" variant={mode === 'recall' ? 'danger' : 'primary'} disabled={reason.processing}>{mode === 'hold' ? 'Hold Lot' : 'Recall Lot'}</Button>
                </form>
            ) : null}
        </li>
    );
}

/** D-195: customer milk orders, filled from Released lots (Dispatch on a lot). */
function Orders({ orders, customers, canQuality }: Props) {
    const [adding, setAdding] = useState(false);
    const form = useForm({ customer_id: '', buyer: '', litres: '', price_per_litre: '', wanted_on: today(), terms: 'cash', credit_days: '30', notes: '' });
    const e = form.errors as Record<string, string>;
    return (
        <div className="space-y-3">
            {adding ? <Panel title="New Milk Order">
                <form className="grid gap-2 p-4 sm:grid-cols-4" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, customer_id: d.customer_id || null, buyer: d.buyer || null, credit_days: d.terms === 'credit' ? d.credit_days : null })); form.post('/dairy/orders', { preserveScroll: true, onSuccess: () => { setAdding(false); form.reset(); } }); }}>
                    <Field label="Customer" error={e.customer_id}><select className="field" value={form.data.customer_id} onChange={(x) => form.setData('customer_id', x.target.value)}><option value="">—</option>{customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
                    <Field label="Or buyer"><input className="field" value={form.data.buyer} onChange={(x) => form.setData('buyer', x.target.value)} /></Field>
                    <Field label="Litres" error={e.litres}><input type="number" step="0.5" className="field" value={form.data.litres} onChange={(x) => form.setData('litres', x.target.value)} /></Field>
                    <Field label="PKR / litre" error={e.price_per_litre}><input type="number" className="field" value={form.data.price_per_litre} onChange={(x) => form.setData('price_per_litre', x.target.value)} /></Field>
                    <Field label="Wanted on" error={e.wanted_on}><input type="date" className="field" value={form.data.wanted_on} onChange={(x) => form.setData('wanted_on', x.target.value)} /></Field>
                    <Field label="Payment"><select className="field" value={form.data.terms} onChange={(x) => form.setData('terms', x.target.value)}><option value="cash">Paid on delivery</option><option value="credit">Credit</option></select></Field>
                    {form.data.terms === 'credit' ? <Field label="Credit days" error={e.credit_days}><input type="number" className="field" value={form.data.credit_days} onChange={(x) => form.setData('credit_days', x.target.value)} /></Field> : null}
                    <div className="flex items-end gap-2"><Button type="submit" disabled={form.processing}>Save Order</Button><Button type="button" variant="secondary" onClick={() => setAdding(false)}>Cancel</Button></div>
                </form>
            </Panel> : null}
            <Panel flush title="Milk Orders" description="Fill an order from a Released lot (Milk Lots → Dispatch → choose the order). The lot it came from stays traceable." actions={canQuality ? <Button onClick={() => setAdding(true)}>+ Milk Order</Button> : undefined}>
                {orders.length ? <ul className="divide-y divide-rule">{orders.map((o) => <li key={o.id} className="flex flex-wrap items-center gap-2 px-4 py-2.5 text-sm">
                    <strong className="font-mono text-cobalt-900">{o.no}</strong><span className="font-semibold">{o.customer}</span>
                    <span>{o.litres} L at {pkr(o.price)}/L · wanted {o.wanted} · {o.terms === 'credit' ? `credit ${o.creditDays} days` : 'cash'}</span>
                    <Chip className={o.status === 'open' ? 'bg-watch-100 text-watch-700' : o.status === 'fulfilled' ? 'bg-ok-100 text-ok-700' : 'bg-glaze text-ink-soft'}>{o.status === 'open' ? `${o.remaining} L to send` : o.status === 'fulfilled' ? 'Fulfilled' : 'Cancelled'}</Chip>
                    {o.status === 'open' && canQuality ? <Button variant="quiet" className="ml-auto" onClick={() => router.post(`/dairy/orders/${o.id}/cancel`, {}, { preserveScroll: true })}>Cancel</Button> : null}
                </li>)}</ul> : <Empty title="No milk orders" />}
            </Panel>
        </div>
    );
}

/** D-195: milk invoices (paid or owed) and the monthly milk profit view. */
function Money({ invoices, monthly, kpi }: Props) {
    const [shown, more] = useFive(invoices, 10);
    return (
        <div className="space-y-3">
            <Panel flush title="Milk Profit by Month" description="Produced, lost (held or discarded), kept for kids and colostrum, sold; revenue less the feed of the does in milk.">
                <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Month</th><th>Produced L</th><th>Saleable</th><th>Held / discarded</th><th>Kids</th><th>Colostrum</th><th>Sold L</th><th>Revenue</th><th>Revenue / L</th><th>Feed cost</th><th>Contribution</th></tr></thead><tbody>
                    {monthly.map((m) => <tr key={m.month}><td className="font-semibold">{m.month}</td><td>{m.produced}</td><td>{m.saleable}</td><td>{m.lost}</td><td>{m.kid}</td><td>{m.colostrum}</td><td>{m.sold}</td><td>{pkr(m.revenue)}</td><td>{m.revenuePerLitre !== null ? pkr(m.revenuePerLitre) : '—'}</td><td>{m.feed !== null ? pkr(m.feed) : '—'}</td><td className={m.contribution !== null && m.contribution < 0 ? 'text-crit-700' : ''}>{m.contribution !== null ? pkr(m.contribution) : '—'}</td></tr>)}
                </tbody></table></div>
            </Panel>
            <Panel flush title="Milk Invoices" description={kpi.owed ? `Customers owe ${pkr(kpi.owed)} for milk.` : 'Nothing owed for milk.'}>
                {invoices.length ? <ul className="divide-y divide-rule">{shown.map((i) => <InvoiceRow key={i.id} i={i} />)}</ul> : <Empty title="No milk invoices yet" />}
                {more}
            </Panel>
        </div>
    );
}

function InvoiceRow({ i }: { i: Invoice }) {
    const form = useForm({ collected_on: today() });
    return (
        <li className="flex flex-wrap items-center gap-2 px-4 py-2.5 text-sm">
            <strong className="font-mono text-cobalt-900">{i.invoice}</strong><span className="font-semibold">{i.to}</span>
            <span className="text-ink-soft">{i.on} · {i.litres} L · lot {i.lot}{i.order ? ` · ${i.order}` : ''}{i.temp !== null ? ` · ${i.temp} °C` : ''}</span>
            <span className="font-semibold">{pkr(i.amount)}</span>
            {i.collected ? <Chip className="bg-ok-100 text-ok-700">Paid {i.collected}</Chip> : <>
                <Chip className={i.overdue ? 'bg-crit-100 text-crit-700' : 'bg-treat-100 text-treat-700'}>{i.overdue ? `Overdue since ${i.due}` : `Owed, due ${i.due}`}</Chip>
                <input type="date" max={today()} className="field ml-auto !w-40" value={form.data.collected_on} onChange={(x) => form.setData('collected_on', x.target.value)} />
                <Button variant="secondary" disabled={form.processing} onClick={() => form.post(`/dairy/dispatches/${i.id}/collect`, { preserveScroll: true })}>Record Payment</Button>
            </>}
        </li>
    );
}
