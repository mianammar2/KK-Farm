import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, LibIcon, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type Line = {
    id: number; goatId: string; number: string | null; breed: string | null; sex: string | null; pedigree: string | null; weight: number | null; price: number; market: number | null;
    status: string; removed: string | null; cost: { purchase: number; feed: number; health: number; total: number } | null; margin: number | null; blockers: string[];
};
type Order = {
    id: number; no: string; customer: string | null; customerId: number | null; basis: string; terms: string; due: string | null; status: string; by: string; approvedBy: string | null;
    invoice: string | null; notes: string | null; dispatched: string | null; collected: string | null; checklist: string[]; owed: number; total: number; errors: number; canApprove: boolean; lines: Line[];
};
type Cand = { id: string; number: string; breed: string | null; sex: string; purpose: string | null; weight: number | null; market: number | null; blockers: string[] };
type Props = {
    orders: Order[]; candidates: Cand[]; customers: Array<{ id: number; name: string }>;
    kpi: { margin: number | null; dispatchError: number | null; daysToCollect: number | null; unsold: number; receivable: number };
    checklist: Record<string, string>; statuses: Record<string, string>; canSell: boolean; canApprove: boolean;
};

const pkr = (n: number | null) => (n === null ? '—' : `PKR ${Math.round(n).toLocaleString()}`);
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const FLOW = ['Goat selected', 'ID, health & ownership clear?', 'Weight, breed & price quote', 'Terms & customer approved', 'Reserved + checklist', 'RFID scan = headcount', 'Invoice & payment', 'Exit', 'Cost basis', 'Profit & buyer'];

/** D-194: V3 cross-app journey E, Sale-to-Cash & Exit (flow 28). */
export default function SaleOrders(p: Props) {
    const [tab, setTab] = useTabParam('open');
    const open = p.orders.filter((o) => ['quote', 'reserved'].includes(o.status));
    const done = p.orders.filter((o) => ['dispatched', 'cancelled'].includes(o.status));
    return (
        <>
            <Head title="Sale Orders" />
            <PageHeader title="Sale Orders & Dispatch" description="Quote clear goats, approve price and customer, reserve, tick the dispatch checklist, scan every goat at loading, then invoice, dispatch and collect. Margin per goat comes from its cost basis." />
            <ol className="mb-3 flex flex-wrap items-center gap-1 text-xs font-semibold">{FLOW.map((s, i) => <li key={s} className="flex items-center gap-1"><span className="rounded-full bg-glaze px-2 py-0.5 text-ink-soft ring-1 ring-rule">{s}</span>{i < FLOW.length - 1 ? <span className="text-ink-faint">→</span> : null}</li>)}</ol>
            <StatRow>
                <Stat label="Margin per goat" value={pkr(p.kpi.margin)} note="Sold, 90 days" tone={p.kpi.margin !== null && p.kpi.margin < 0 ? 'alert' : undefined} />
                <Stat label="Dispatch errors" value={p.kpi.dispatchError === null ? '—' : `${p.kpi.dispatchError}%`} note="Scans that stopped loading" />
                <Stat label="Days to collect" value={p.kpi.daysToCollect ?? '—'} note="Credit sales" />
                <Stat label="Owed by customers" value={pkr(p.kpi.receivable)} tone={p.kpi.receivable ? 'alert' : undefined} />
                <Stat label="Unsold sale stock" value={p.kpi.unsold} note="Sale / meat / fattening, not on an order" />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'open', label: 'Open Orders', count: open.length || undefined }, { id: 'new', label: 'New Quote' }, { id: 'done', label: 'Dispatched & Invoices' }]} />
            {tab === 'new' ? <Quote {...p} done={() => setTab('open')} /> : null}
            {tab === 'open' ? <Orders {...p} list={open} empty="No open orders." /> : null}
            {tab === 'done' ? <Orders {...p} list={done} empty="No dispatched orders yet." /> : null}
        </>
    );
}

function Quote({ candidates, customers, canSell, done }: Props & { done: () => void }) {
    const [picked, setPicked] = useState<Record<string, string>>({});
    const [q, setQ] = useState('');
    const form = useForm({ customer_id: '', buyer: '', basis: 'per_head', rate_per_kg: '', terms: 'cash', due_on: '', notes: '', lines: [] as Array<{ animal_id: string; price: string }> });
    const e = form.errors as Record<string, string>;
    const shown = candidates.filter((c) => !q || c.number.toLowerCase().includes(q.toLowerCase()));
    const ids = Object.keys(picked);
    const total = form.data.basis === 'per_kg' ? ids.reduce((s, id) => s + (candidates.find((c) => c.id === id)?.weight ?? 0) * Number(form.data.rate_per_kg || 0), 0) : ids.reduce((s, id) => s + Number(picked[id] || 0), 0);
    if (!canSell) return <Empty title="Only sales staff quote." />;
    return (
        <Panel title="Price Quote" description="Only goats that are clear can be quoted: no withdrawal, an RFID tag, no hold or restriction, healthy, not on another order.">
            <div className="grid gap-3 p-4 sm:grid-cols-3">
                <Field label="Customer" error={e.customer_id}><select className="field" value={form.data.customer_id} onChange={(x) => form.setData('customer_id', x.target.value)}><option value="">— or type the buyer —</option>{customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
                <Field label="Buyer (if not a customer)" error={e.buyer}><input className="field" value={form.data.buyer} onChange={(x) => form.setData('buyer', x.target.value)} /></Field>
                <Field label="Priced" error={e.basis}><select className="field" value={form.data.basis} onChange={(x) => form.setData('basis', x.target.value)}><option value="per_head">Per goat</option><option value="per_kg">Per KG live weight</option></select></Field>
                {form.data.basis === 'per_kg' ? <Field label="Rate per KG (PKR)" error={e.rate_per_kg}><input type="number" className="field" value={form.data.rate_per_kg} onChange={(x) => form.setData('rate_per_kg', x.target.value)} /></Field> : null}
                <Field label="Payment" error={e.terms}><select className="field" value={form.data.terms} onChange={(x) => form.setData('terms', x.target.value)}><option value="cash">Paid at dispatch</option><option value="credit">Credit (customer owes)</option></select></Field>
                {form.data.terms === 'credit' ? <Field label="Payment due" error={e.due_on}><input type="date" min={today()} className="field" value={form.data.due_on} onChange={(x) => form.setData('due_on', x.target.value)} /></Field> : null}
                <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
            </div>
            <div className="border-t border-rule px-4 py-2"><input className="field w-60" placeholder="Find goat…" value={q} onChange={(x) => setQ(x.target.value)} /></div>
            <div className="max-h-[420px] overflow-auto"><table className="data-table"><thead><tr><th /><th>Goat</th><th>Breed</th><th>Purpose</th><th>Weight</th><th>Market value</th><th>{form.data.basis === 'per_head' ? 'Price (PKR)' : 'Price'}</th><th>Check</th></tr></thead><tbody>
                {shown.map((c) => {
                    const on = c.id in picked;
                    return <tr key={c.id} className={c.blockers.length ? 'opacity-60' : ''}>
                        <td><input type="checkbox" disabled={!!c.blockers.length} checked={on} onChange={(x) => setPicked((pk) => { const n = { ...pk }; if (x.target.checked) n[c.id] = c.market ? String(c.market) : ''; else delete n[c.id]; return n; })} /></td>
                        <td className="font-mono font-semibold">{c.number}</td><td>{c.breed} · {c.sex}</td><td>{c.purpose ?? '—'}</td><td>{c.weight ?? '—'}</td><td>{pkr(c.market)}</td>
                        <td>{form.data.basis === 'per_head' ? <input type="number" disabled={!on} className="field w-28" value={picked[c.id] ?? ''} onChange={(x) => setPicked((pk) => ({ ...pk, [c.id]: x.target.value }))} /> : on && c.weight ? pkr(c.weight * Number(form.data.rate_per_kg || 0)) : '—'}</td>
                        <td>{c.blockers.length ? <span className="text-xs text-crit-700">{c.blockers.join(' · ')}</span> : <Chip className="bg-ok-100 text-ok-700">Clear</Chip>}</td>
                    </tr>;
                })}
            </tbody></table></div>
            {Object.entries(e).filter(([k]) => k.startsWith('lines')).slice(0, 4).map(([k, v]) => <p key={k} className="px-4 text-sm text-crit-700">{v}</p>)}
            <div className="flex items-center gap-3 border-t border-rule p-4">
                <Button disabled={form.processing || !ids.length} onClick={() => { form.transform((d) => ({ ...d, customer_id: d.customer_id || null, buyer: d.buyer || null, rate_per_kg: d.rate_per_kg || null, due_on: d.due_on || null, lines: ids.map((id) => ({ animal_id: id, price: picked[id] || null })) })); form.post('/sales/orders', { preserveScroll: true, onSuccess: () => { setPicked({}); done(); } }); }}><LibIcon name="app-sales" size={18} /> Send Quote for Approval</Button>
                <span className="text-sm">{ids.length} goats · {pkr(total)}</span>
            </div>
        </Panel>
    );
}

function Orders(p: Props & { list: Order[]; empty: string }) {
    const [shown, more] = useFive(p.list, 8);
    return (
        <Panel flush title="Sale Orders">
            {p.list.length ? <ul className="divide-y divide-rule">{shown.map((o) => <OrderItem key={o.id} o={o} {...p} />)}</ul> : <Empty title={p.empty} />}
            {more}
        </Panel>
    );
}

function OrderItem({ o, checklist, statuses }: Props & { o: Order }) {
    const scan = useForm({ code: '' });
    const ship = useForm({ dispatched_on: today() });
    const pay = useForm({ collected_on: today() });
    const live = o.lines.filter((l) => l.status !== 'removed');
    const loaded = live.filter((l) => l.status === 'loaded').length;
    const post = (url: string, data = {}) => router.post(url, data, { preserveScroll: true });
    const tick = (k: string) => post(`/sales/orders/${o.id}/checklist`, { done: o.checklist.includes(k) ? o.checklist.filter((x) => x !== k) : [...o.checklist, k] });
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono text-cobalt-900">{o.no}</strong><span className="font-semibold">{o.customer}</span>
                <span>{live.length} goats · {pkr(o.total)} · {o.basis === 'per_kg' ? 'per KG' : 'per goat'} · {o.terms === 'credit' ? `credit, due ${o.due}` : 'cash'}</span>
                <Chip className={o.status === 'quote' ? 'bg-watch-100 text-watch-700' : o.status === 'reserved' ? 'bg-cobalt-100 text-cobalt-800' : o.status === 'cancelled' ? 'bg-glaze text-ink-soft' : 'bg-ok-100 text-ok-700'}>{statuses[o.status]}</Chip>
                {o.invoice ? <Chip className="bg-glaze text-ink-soft">{o.invoice}</Chip> : null}
                {o.owed ? <Chip className="bg-treat-100 text-treat-700">Owed {pkr(o.owed)}</Chip> : o.collected && o.status === 'dispatched' ? <Chip className="bg-ok-100 text-ok-700">Paid {o.collected}</Chip> : null}
                <span className="text-ink-soft">quoted by {o.by}{o.approvedBy ? ` · approved by ${o.approvedBy}` : ''}{o.errors ? ` · ${o.errors} stopped scans` : ''}</span>
            </div>
            <div className="mt-2 overflow-x-auto"><table className="data-table"><thead><tr><th>Goat</th><th>Breed</th><th>Pedigree</th><th>Weight</th><th>Price</th><th>Market</th>{o.status === 'dispatched' ? <><th>Cost basis</th><th>Margin</th></> : <th>Status</th>}<th /></tr></thead><tbody>
                {o.lines.map((l) => <tr key={l.id} className={l.status === 'removed' ? 'opacity-50' : ''}>
                    <td><Link href={`/goats/${l.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{l.number}</Link></td><td>{l.breed} · {l.sex}</td><td className="text-xs">{l.pedigree ?? '—'}</td><td>{l.weight ?? '—'}</td>
                    <td>{pkr(l.price)}</td><td>{l.market !== null ? <>{pkr(l.market)}{l.market ? <span className={`ml-1 text-xs ${l.price < l.market ? 'text-crit-700' : 'text-ok-700'}`}>{Math.round((l.price / l.market - 1) * 100)}%</span> : null}</> : '—'}</td>
                    {o.status === 'dispatched' ? <><td className="text-xs">{l.cost ? `${pkr(l.cost.total)} (buy ${Math.round(l.cost.purchase).toLocaleString()} · feed ${Math.round(l.cost.feed).toLocaleString()} · health ${Math.round(l.cost.health).toLocaleString()})` : '—'}</td><td className={l.margin !== null && l.margin < 0 ? 'text-crit-700' : 'text-ok-700'}>{pkr(l.margin)}</td></>
                        : <td>{l.status === 'removed' ? <span className="text-xs">Off: {l.removed}</span> : l.blockers.length ? <span className="text-xs text-crit-700">{l.blockers.join(' · ')}</span> : <Chip className={l.status === 'loaded' ? 'bg-ok-100 text-ok-700' : 'bg-glaze text-ink-soft'}>{l.status === 'loaded' ? 'Loaded' : l.status === 'reserved' ? 'Reserved' : 'Quoted'}</Chip>}</td>}
                    <td>{['quoted', 'reserved', 'loaded'].includes(l.status) && o.status !== 'dispatched' ? <button type="button" className="text-xs text-crit-700 hover:underline" onClick={() => { const reason = window.prompt('Why does this goat come off the order? (missing, sick, buyer refused…)'); if (reason) post(`/sales/order-lines/${l.id}/remove`, { reason }); }}>Take off</button> : null}</td>
                </tr>)}
            </tbody></table></div>
            {o.status === 'quote' ? <div className="mt-2 flex flex-wrap gap-1.5">
                {o.canApprove ? <Button onClick={() => post(`/sales/orders/${o.id}/approve`)}>Approve Terms & Customer</Button> : <span className="text-xs text-ink-soft">The owner or manager (not {o.by}) approves the price and customer.</span>}
                <Button variant="secondary" onClick={() => post(`/sales/orders/${o.id}/cancel`)}>Cancel</Button>
            </div> : null}
            {o.status === 'reserved' ? <div className="mt-2 grid gap-3 rounded-tile bg-glaze p-3 lg:grid-cols-3">
                <div><p className="mb-1 text-xs font-semibold">Dispatch checklist</p>{Object.entries(checklist).map(([k, v]) => <label key={k} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={o.checklist.includes(k)} onChange={() => tick(k)} /> {v}</label>)}</div>
                <form onSubmit={(ev) => { ev.preventDefault(); scan.post(`/sales/orders/${o.id}/scan`, { preserveScroll: true, onSuccess: () => scan.reset() }); }}>
                    <Field label={`Scan at loading · ${loaded} of ${live.length} loaded`} error={scan.errors.code} hint="Pull the trigger on the reader or type the tag"><input className={`field font-mono ${scan.errors.code ? 'ring-2 ring-crit-500' : ''}`} value={scan.data.code} onChange={(x) => scan.setData('code', x.target.value)} placeholder="EPC or Tag ID" /></Field>
                </form>
                <div className="space-y-2">
                    <Field label="Dispatch date"><input type="date" max={today()} className="field" value={ship.data.dispatched_on} onChange={(x) => ship.setData('dispatched_on', x.target.value)} /></Field>
                    {(ship.errors as Record<string, string>).dispatch ? <p className="text-xs text-crit-700">{(ship.errors as Record<string, string>).dispatch}</p> : null}
                    <div className="flex gap-2"><Button disabled={ship.processing || loaded !== live.length} onClick={() => ship.post(`/sales/orders/${o.id}/dispatch`, { preserveScroll: true })}>Invoice & Dispatch</Button><Button variant="secondary" onClick={() => post(`/sales/orders/${o.id}/cancel`)}>Cancel</Button></div>
                </div>
            </div> : null}
            {o.status === 'dispatched' && o.owed ? <div className="mt-2 flex items-end gap-2"><Field label="Payment received on"><input type="date" max={today()} className="field" value={pay.data.collected_on} onChange={(x) => pay.setData('collected_on', x.target.value)} /></Field><Button disabled={pay.processing} onClick={() => pay.post(`/sales/orders/${o.id}/collect`, { preserveScroll: true })}>Record Payment</Button></div> : null}
        </li>
    );
}
