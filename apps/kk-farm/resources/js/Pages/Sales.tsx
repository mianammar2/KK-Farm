import { Head, Link, router, useForm } from '@inertiajs/react';
import DeleteButton from '../components/DeleteButton';
import { Pencil } from 'lucide-react';
import { useMemo, useState } from 'react';
import AddButton from '../components/AddButton';
import Sheet from '../components/Sheet';
import { Button, Chip, cx, Empty, Field, monthLabel, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import DataGrid from '../components/DataGrid';
import { useFive } from '../lib/useFive';
import { useTabParam } from '../lib/useTabParam';

type SaleRow = { id: number; on: string; category: string; goatId: string | null; goat: string | null; item: string | null; buyer: string | null; customerId: number | null; gross: number; fee: number; net: number; receipt: string | null };
type Customer = { id: number; name: string; phone: string | null; city: string | null; type: string; notes: string | null; active: boolean; sales: number; total: number };
type Props = { sales: SaleRow[]; kpis: { month: number; year: number; goats: number; products: number }; monthly: Array<{ month: string; net: number }>; customers: Customer[]; types: Record<string, string>; can: { manage: boolean } };

const pkr = (n: number) => `PKR ${Math.round(n).toLocaleString('en-PK')}`;
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

/** D-178: Sales: goats (sold from Movements) and products in one place, with customers and monthly totals. */
export default function Sales({ sales, kpis, monthly, customers, types, can }: Props) {
    const [tab, setTab] = useTabParam('sales');
    const [customer, setCustomer] = useState<Customer | 'new' | null>(null);
    const [selling, setSelling] = useState(false);
    const [filter, setFilter] = useState<'all' | 'goats' | 'products'>('all');
    const rows = useMemo(() => sales.filter((s) => filter === 'all' || (filter === 'goats' ? s.goatId : !s.goatId)), [sales, filter]);
    const [shown, more] = useFive(rows, 15);
    const max = Math.max(...monthly.map((m) => m.net), 1);

    return (
        <>
            <Head title="Sales" />
            <PageHeader title="Sales" description="Goats are sold from Movements (an exit marked Sale); products like milk and manure are sold here. Every sale records its income."
                actions={can.manage ? <><AddButton primary={false} label="New Customer" onClick={() => setCustomer('new')} /><AddButton label="Sell Products" onClick={() => setSelling(true)} /><Link href="/movements" className="kk-fix">Sell a Goat</Link></> : null} />
            <StatRow>
                <Stat label="This month" value={pkr(kpis.month)} note="Net of fees" />
                <Stat label="Last 12 months" value={pkr(kpis.year)} />
                <Stat label="Goats sold" value={kpis.goats} note="Last 12 months" />
                <Stat label="Product sales" value={pkr(kpis.products)} note="Last 12 months" />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'sales', label: 'Sales', count: sales.length || undefined }, { id: 'customers', label: 'Customers', count: customers.length || undefined }, { id: 'monthly', label: 'Monthly' }]} />

            {tab === 'sales' ? (
                <Panel flush actions={<select aria-label="Show" className="field w-auto" value={filter} onChange={(e) => setFilter(e.target.value as typeof filter)}><option value="all">Goats and products</option><option value="goats">Goats</option><option value="products">Products</option></select>}>
                    {rows.length ? (
                        <>
                            <div className="overflow-x-auto">
                                <table className="data-table min-w-[46rem]">
                                    <thead><tr><th>Date</th><th>What</th><th>Category</th><th>Customer</th><th className="text-right">Price</th><th className="text-right">Fee</th><th className="text-right">Net</th></tr></thead>
                                    <tbody>
                                        {shown.map((s) => (
                                            <tr key={s.id}>
                                                <td className="whitespace-nowrap">{s.on}</td>
                                                <td>{s.goatId ? <Link href={`/goats/${s.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{s.goat}</Link> : s.item}</td>
                                                <td className="text-sm">{s.category}</td>
                                                <td>{can.manage ? (
                                                    <select aria-label="Customer" className="field h-9 w-auto min-w-36 py-0 text-sm" value={s.customerId ?? ''} onChange={(e) => router.post(`/sales/${s.id}/customer`, { customer_id: e.target.value || null }, { preserveScroll: true })}>
                                                        <option value="">{s.buyer && !s.customerId ? s.buyer : '—'}</option>{customers.filter((c) => c.active || c.id === s.customerId).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                                                    </select>
                                                ) : s.buyer ?? '—'}</td>
                                                <td className="text-right tabular-nums">{pkr(s.gross)}</td><td className="text-right tabular-nums">{s.fee ? pkr(s.fee) : '—'}</td><td className="text-right font-semibold tabular-nums text-ok-700">{pkr(s.net)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            {more}
                        </>
                    ) : <Empty title="No sales yet">Goats are sold from Movements; products here with Sell Products.</Empty>}
                </Panel>
            ) : null}

            {tab === 'customers' ? (
                <Panel flush>
                    {customers.length ? (
                        <ul className="divide-y divide-rule">
                            {customers.map((c) => (
                                <li key={c.id} className={cx('flex flex-wrap items-center gap-2 px-4 py-2.5', !c.active && 'opacity-60')}>
                                    <span className="min-w-0 flex-1"><span className="font-semibold text-cobalt-900">{c.name}</span> <Chip className="bg-glaze text-ink-soft">{types[c.type] ?? c.type}</Chip><span className="block text-sm text-ink-soft">{[c.city, c.phone, c.notes].filter(Boolean).join(' · ') || 'No details yet'}</span></span>
                                    <span className="text-sm text-ink-soft">{c.sales} sale{c.sales === 1 ? '' : 's'} · {pkr(c.total)}</span>
                                    {can.manage ? <><Button type="button" variant="quiet" onClick={() => setCustomer(c)} aria-label={`Edit ${c.name}`}><Pencil size={15} aria-hidden /></Button><DeleteButton type="customer" id={c.id} name={c.name} /></> : null}
                                </li>
                            ))}
                        </ul>
                    ) : <Empty title="No customers yet">{can.manage ? 'Add butchers, traders and buyers you sell to; then choose them on each sale.' : ''}</Empty>}
                </Panel>
            ) : null}

            {tab === 'monthly' ? (
                <Panel title="Net Sales by Month" description="Last 12 months">
                    <div className="flex h-48 items-end gap-1.5">
                        {monthly.map((m) => (
                            <div key={m.month} className="flex flex-1 flex-col items-center gap-1">
                                <span className="text-xs font-semibold tabular-nums text-ink-soft">{m.net ? `${Math.round(m.net / 1000)}k` : ''}</span>
                                <div className="kk-grow w-full rounded-t-md bg-gradient-to-t from-[#1f7a3a] to-[#2e9e4f]" style={{ height: `${Math.max(2, (m.net / max) * 150)}px`, opacity: m.net ? 1 : 0.25 }} />
                                <span className="text-xs text-ink-soft">{monthLabel(m.month)}</span>
                            </div>
                        ))}
                    </div>
                    <div className="-mx-3 mt-4 border-t border-rule sm:-mx-4"><DataGrid id="sales-monthly" name="Net sales by month" rows={monthly} rowKey={(m) => m.month} cols={[
                        { key: 'month', label: 'Month', type: 'text', render: (m) => <b>{monthLabel(m.month)}</b> },
                        { key: 'net', label: 'Net sales (PKR)', type: 'number', total: 'sum', render: (m) => pkr(m.net) },
                    ]} /></div>
                </Panel>
            ) : null}

            {customer ? <Sheet onClose={() => setCustomer(null)}><CustomerForm customer={customer === 'new' ? null : customer} types={types} onDone={() => setCustomer(null)} /></Sheet> : null}
            {selling ? <Sheet onClose={() => setSelling(false)}><ProductSale customers={customers.filter((c) => c.active)} onDone={() => setSelling(false)} /></Sheet> : null}
        </>
    );
}

function CustomerForm({ customer, types, onDone }: { customer: Customer | null; types: Record<string, string>; onDone: () => void }) {
    const form = useForm({ name: customer?.name ?? '', phone: customer?.phone ?? '', city: customer?.city ?? '', type: customer?.type ?? 'individual', notes: customer?.notes ?? '', active: customer?.active ?? true });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title={customer ? `Edit ${customer.name}` : 'New Customer'}>
            <form onSubmit={(ev) => { ev.preventDefault(); customer ? form.post(`/sales/customers/${customer.id}`, { preserveScroll: true, onSuccess: onDone }) : form.post('/sales/customers', { preserveScroll: true, onSuccess: onDone }); }} className="space-y-3">
                <Field label="Name" error={e.name}><input className="field" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} required /></Field>
                <Field label="Type" error={e.type}><select className="field" value={form.data.type} onChange={(x) => form.setData('type', x.target.value)}>{Object.entries(types).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Phone" error={e.phone}><input className="field" inputMode="tel" value={form.data.phone} onChange={(x) => form.setData('phone', x.target.value)} /></Field>
                    <Field label="City" error={e.city}><input className="field" value={form.data.city} onChange={(x) => form.setData('city', x.target.value)} /></Field>
                </div>
                <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                {customer ? <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="size-5" checked={form.data.active} onChange={(x) => form.setData('active', x.target.checked)} /> Active</label> : null}
                <div className="flex gap-2"><Button type="submit" disabled={form.processing}>{customer ? 'Save Customer' : 'Add Customer'}</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}

function ProductSale({ customers, onDone }: { customers: Customer[]; onDone: () => void }) {
    const form = useForm({ sold_on: today(), category: 'product', item: '', customer_id: '', gross: '', fee: '', receipt_ref: '', notes: '' });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title="Sell Products" description="Milk, manure, skins and other sales without a goat.">
            <form onSubmit={(ev) => { ev.preventDefault(); form.post('/sales/products', { preserveScroll: true, onSuccess: onDone }); }} className="space-y-3">
                <Field label="What was sold" error={e.item}><input className="field" value={form.data.item} onChange={(x) => form.setData('item', x.target.value)} placeholder="e.g. Milk 40 L" required /></Field>
                <Field label="Customer" error={e.customer_id}><select className="field" value={form.data.customer_id} onChange={(x) => form.setData('customer_id', x.target.value)}><option value="">No customer</option>{customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Price (PKR)" error={e.gross}><input className="field" type="number" min={0} step="0.01" value={form.data.gross} onChange={(x) => form.setData('gross', x.target.value)} required /></Field>
                    <Field label="Fee or discount" error={e.fee}><input className="field" type="number" min={0} step="0.01" value={form.data.fee} onChange={(x) => form.setData('fee', x.target.value)} /></Field>
                    <Field label="Date" error={e.sold_on}><input className="field" type="date" max={today()} value={form.data.sold_on} onChange={(x) => form.setData('sold_on', x.target.value)} /></Field>
                    <Field label="Receipt" error={e.receipt_ref}><input className="field" value={form.data.receipt_ref} onChange={(x) => form.setData('receipt_ref', x.target.value)} /></Field>
                </div>
                <div className="flex gap-2"><Button type="submit" disabled={form.processing}>Record Sale</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}
