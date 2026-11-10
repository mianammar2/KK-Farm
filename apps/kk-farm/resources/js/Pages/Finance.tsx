import { Head, Link, router, useForm } from '@inertiajs/react';
import { useConfirm } from '../components/Confirm';
import { useMemo, useState } from 'react';
import Chart from '../components/LazyChart';
import { axis } from '../lib/chartTheme';
import { Button, Chip, cx, Field, monthLabel, PageHeader, Panel, pkr, pkrShort, Stat, StatRow } from '../components/ui';
import AddButton from '../components/AddButton';
import Sheet from '../components/Sheet';

type Entry = { id: number; direction: 'in' | 'out'; category: string; amount: number; on: string; counterparty: string | null; description: string | null; by: string; reversed: boolean; isReversal: boolean; payment: 'paid' | 'pending' | 'partial'; paidBy: string | null; reference: string | null; goatId: string | null; goat: string | null };
type SaleRow = { id: number; on: string; category: string; goatId: string | null; what: string | null; buyer: string | null; gross: number; fee: number; net: number; receipt: string | null; by: string };
type Props = { summary: Record<string, number>; monthly: Array<{ month: string; income: number; spend: number }>; categories: Array<{ name: string; value: number }>; entries: Entry[]; categoryOptions: string[]; sales: SaleRow[]; goats: Array<{ id: string; farm_number: string }>; pending: number; accounts: Array<{ id: number; label: string; type: string; money: boolean }> };

export default function Finance({ summary, monthly, categories, entries, categoryOptions, sales, goats, pending, accounts }: Props) {
    const [ask, confirmBox] = useConfirm(); // D-133
    const [direction, setDirection] = useState('');
    const [category, setCategory] = useState('');
    const [adding, setAdding] = useState<'' | 'entry' | 'sale'>(''); // D-127
    const moneyAccounts = accounts.filter((a) => a.money);
    const pickable = (d: string) => accounts.filter((a) => (d === 'in' ? a.type === 'income' : a.type === 'expense'));
    const form = useForm({ direction: 'out', account_id: String(pickable('out')[0]?.id ?? ''), money_account_id: String(moneyAccounts[0]?.id ?? ''), paid_now: '', amount: '', counterparty: '', description: '', occurred_on: new Date().toLocaleDateString('en-CA'), payment_status: 'paid', paid_by: '', reference: '', animal_id: '' });
    const rows = entries.filter((e) => (!direction || e.direction === direction) && (!category || e.category === category));

    const trend = useMemo(() => ({
        tooltip: { trigger: 'axis', valueFormatter: (v: number) => pkr(v) },
        legend: { bottom: 0, itemWidth: 12, itemHeight: 10 },
        grid: { left: 8, right: 12, top: 14, bottom: 30, containLabel: true },
        xAxis: axis.category(monthly.map((m) => monthLabel(m.month))),
        yAxis: axis.value((v) => `${v / 1000}k`),
        series: [
            { name: 'Income', type: 'bar', data: monthly.map((m) => m.income), itemStyle: { color: '#0b4f35', borderRadius: [3, 3, 0, 0] } },
            { name: 'Spending', type: 'bar', data: monthly.map((m) => m.spend), itemStyle: { color: '#c18a14', borderRadius: [3, 3, 0, 0] } },
        ],
    }), [monthly]);

    const split = useMemo(() => {
        const rows = [...categories].reverse();
        return {
            tooltip: { trigger: 'axis', axisPointer: { type: 'none' }, valueFormatter: (v: number) => pkr(v) },
            grid: { left: 8, right: 58, top: 6, bottom: 6, containLabel: true },
            xAxis: { type: 'value', show: false },
            yAxis: { ...axis.category(rows.map((c) => c.name)), axisLine: { show: false } },
            series: [{ type: 'bar', barMaxWidth: 18, data: rows.map((c) => c.value), itemStyle: { color: '#0b4f35', borderRadius: [0, 3, 3, 0] }, label: { show: true, position: 'right', color: '#013624', fontWeight: 600, formatter: (p: { value: number }) => pkrShort(p.value).replace('PKR ', '') } }],
        };
    }, [categories]);

    return (
        <>
            {confirmBox}
            <Head title="Expenses & Finance" />
            <PageHeader title="Expenses & finance" description="Only the CEO and finance roles can open this. Every entry posts a balanced double-entry journal; a correction is a reversing entry." actions={<><Link href="/finance/books" className="text-sm font-semibold text-turq-800 hover:underline">Accounts (books)</Link><AddButton primary={false} label="Record a product sale" onClick={() => setAdding('sale')} /><AddButton label="Record an entry" onClick={() => setAdding('entry')} /></>} />
            <StatRow>
                <Stat label="This month in" value={pkrShort(summary.monthIn)} />
                <Stat label="This month out" value={pkrShort(summary.monthOut)} />
                <Stat label="12 months in" value={pkrShort(summary.yearIn)} />
                <Stat label="12 months out" value={pkrShort(summary.yearOut)} />
                <Stat label="12 month result" value={pkrShort(summary.yearIn - summary.yearOut)} tone={summary.yearIn >= summary.yearOut ? 'good' : 'alert'} />
                <Stat label="Unpaid expenses" value={pkrShort(pending)} note="Pending or partly paid" tone={pending ? 'alert' : undefined} />
            </StatRow>

            <div className="mb-4 grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
                <Panel title="Monthly income and spending"><Chart option={trend} label="Monthly income and spending" height={250} /></Panel>
                <Panel title="Spending by category"><Chart option={split} label="Spending by category" height={250} /></Panel>
            </div>

            {adding === 'entry' ? <Sheet onClose={() => setAdding('')}>
                <Panel title="Record an entry">
                    <form onSubmit={(e) => { e.preventDefault(); form.post('/finance/entries', { preserveScroll: true, onSuccess: () => { form.reset('amount', 'counterparty', 'description'); setAdding(''); } }); }} className="space-y-3">
                        <Field label="Type"><select className="field" value={form.data.direction} onChange={(e) => form.setData({ ...form.data, direction: e.target.value, account_id: String(pickable(e.target.value)[0]?.id ?? '') })}><option value="out">Money out</option><option value="in">Money in</option></select></Field>
                        <Field label={form.data.direction === 'in' ? 'Income account' : 'Expense account'} error={(form.errors as Record<string, string>).account_id}><select className="field" value={form.data.account_id} onChange={(e) => form.setData('account_id', e.target.value)}>{pickable(form.data.direction).map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}</select></Field>
                        <Field label="Amount (PKR)" error={form.errors.amount}><input inputMode="decimal" className="field" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} /></Field>
                        <Field label={form.data.direction === 'out' ? 'Vendor' : 'Payer'}><input className="field" value={form.data.counterparty} onChange={(e) => form.setData('counterparty', e.target.value)} /></Field>
                        {form.data.direction === 'out' ? (
                            <div className="grid grid-cols-2 gap-2">
                                <Field label="Payment"><select className="field" value={form.data.payment_status} onChange={(e) => form.setData('payment_status', e.target.value)}><option value="paid">Paid</option><option value="pending">Pending</option><option value="partial">Partly paid</option></select></Field>
                                <Field label="Paid by"><input className="field" value={form.data.paid_by} onChange={(e) => form.setData('paid_by', e.target.value)} /></Field>
                            </div>
                        ) : null}
                        {form.data.direction === 'out' && form.data.payment_status === 'partial' ? <Field label="Paid now (PKR)" error={(form.errors as Record<string, string>).paid_now}><input inputMode="decimal" className="field" value={form.data.paid_now} onChange={(e) => form.setData('paid_now', e.target.value)} /></Field> : null}
                        {form.data.direction === 'in' || form.data.payment_status !== 'pending' ? <Field label={form.data.direction === 'in' ? 'Received into' : 'Paid from'}><select className="field" value={form.data.money_account_id} onChange={(e) => form.setData('money_account_id', e.target.value)}>{moneyAccounts.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}</select></Field> : null}
                        <Field label="For a goat (optional)"><select className="field" value={form.data.animal_id} onChange={(e) => form.setData('animal_id', e.target.value)}><option value="">Whole farm</option>{goats.map((g) => <option key={g.id} value={g.id}>{g.farm_number}</option>)}</select></Field>
                        <Field label="Invoice / reference"><input className="field" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} /></Field>
                        <Field label="Date" error={form.errors.occurred_on}><input type="date" className="field" value={form.data.occurred_on} onChange={(e) => form.setData('occurred_on', e.target.value)} /></Field>
                        <Button type="submit" className="w-full" disabled={form.processing}>Save entry</Button>
                    </form>
                </Panel>
            </Sheet> : null}
            {adding === 'sale' ? <Sheet onClose={() => setAdding('')}><ProductSaleForm onDone={() => setAdding('')} /></Sheet> : null}
            <div>
                <Panel flush title="Ledger" actions={
                    <div className="flex flex-wrap gap-2">
                        <select aria-label="Direction" className="field w-auto" value={direction} onChange={(e) => setDirection(e.target.value)}><option value="">In and out</option><option value="in">Money in</option><option value="out">Money out</option></select>
                        <select aria-label="Category" className="field w-auto" value={category} onChange={(e) => setCategory(e.target.value)}><option value="">All categories</option>{categoryOptions.map((c) => <option key={c}>{c}</option>)}</select>
                    </div>
                }>
                    <div className="max-h-[60vh] overflow-auto">
                        <table className="data-table min-w-[680px]">
                            <thead><tr><th>Date</th><th>Category</th><th>Party</th><th>Amount</th><th>Payment</th><th>Recorded by</th><th /></tr></thead>
                            <tbody>
                                {rows.map((e) => (
                                    <tr key={e.id} className={cx(e.reversed && 'text-ink-faint')}>
                                        <td className="whitespace-nowrap">{e.on}</td>
                                        <td>{e.category}{e.isReversal ? <Chip className="ml-1 bg-cobalt-100 text-cobalt-800">Reversal</Chip> : null}</td>
                                        <td className="max-w-48 truncate">{e.counterparty ?? '—'}{e.goat ? <Link href={`/goats/${e.goatId}`} className="ml-1 font-mono text-xs text-cobalt-800 hover:underline">{e.goat}</Link> : null}{e.reference ? <span className="block text-xs text-ink-faint">{e.reference}</span> : null}</td>
                                        <td className={cx('font-semibold whitespace-nowrap', !e.reversed && (e.direction === 'in' ? 'text-ok-700' : 'text-cobalt-900'))}>{e.direction === 'in' ? '+' : '−'}{pkr(e.amount)}</td>
                                        <td>{e.direction === 'out' && e.payment !== 'paid' ? <span className="flex items-center gap-1.5"><Chip className="bg-watch-100 text-watch-700">{e.payment === 'pending' ? 'Pending' : 'Partly paid'}</Chip>{!e.reversed ? <button type="button" className="text-xs font-semibold text-turq-800 hover:underline" onClick={() => router.post(`/finance/entries/${e.id}/paid`, {}, { preserveScroll: true })}>Mark paid</button> : null}</span> : <span className="text-sm text-ink-soft">Paid{e.paidBy ? ` · ${e.paidBy}` : ''}</span>}</td>
                                        <td className="text-sm text-ink-soft">{e.by}</td>
                                        <td>{e.reversed ? <span className="text-sm">Reversed</span> : e.isReversal ? null : <button type="button" className="text-sm font-semibold text-turq-800 hover:underline" onClick={async () => { if (await ask({ title: 'Reverse this entry?', message: `${e.category}, PKR ${e.amount.toLocaleString('en-PK')} on ${e.on}. A reversing entry is added; this cannot be undone.`, confirm: 'Yes, reverse' })) router.post(`/finance/entries/${e.id}/reverse`, {}, { preserveScroll: true }); }}>Reverse</button>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            </div>

            {/* D-076: workbook "Sales". Goat sales come from Movements; products are recorded here. */}
            <div className="mt-4">
                <SalesList sales={sales} />
            </div>
        </>
    );
}

function SalesList({ sales }: { sales: SaleRow[] }) {
    const [all, setAll] = useState(false);
    const rows = all ? sales : sales.slice(0, 10);
    return (
        <Panel flush title="Sales" description="Goat sales are recorded from Movements (or Record exit on the goat). Net = price − fee or discount.">
            {sales.length ? (
                <div className="overflow-x-auto">
                    <table className="data-table min-w-[680px]">
                        <thead><tr><th>Date</th><th>What</th><th>Category</th><th>Buyer</th><th>Price</th><th>Fee</th><th>Net</th><th>Receipt</th></tr></thead>
                        <tbody>
                            {rows.map((s) => (
                                <tr key={s.id}>
                                    <td className="whitespace-nowrap">{s.on}</td>
                                    <td>{s.goatId ? <Link href={`/goats/${s.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{s.what}</Link> : s.what}</td>
                                    <td>{s.category}</td><td className="max-w-40 truncate">{s.buyer ?? '—'}</td>
                                    <td>{pkr(s.gross)}</td><td>{s.fee ? pkr(s.fee) : '—'}</td><td className="font-semibold text-ok-700">{pkr(s.net)}</td><td className="text-ink-soft">{s.receipt ?? ''}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {sales.length > 10 ? <button type="button" onClick={() => setAll(!all)} className="w-full px-4 py-2 text-sm font-semibold text-cobalt-800 hover:bg-glaze">{all ? 'Show less' : `Show ${sales.length - 10} more`}</button> : null}
                </div>
            ) : <p className="p-4 text-sm text-ink-soft">No sales yet.</p>}
        </Panel>
    );
}

function ProductSaleForm({ onDone }: { onDone?: () => void }) {
    const form = useForm({ sold_on: new Date().toLocaleDateString('en-CA'), category: 'product', item: '', buyer: '', gross: '', fee: '', receipt_ref: '', notes: '' });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title="Record a product sale" description="Milk, manure, skins and other sales without a goat">
            <form onSubmit={(ev) => { ev.preventDefault(); form.post('/finance/sales', { preserveScroll: true, onSuccess: () => { form.reset('item', 'buyer', 'gross', 'fee', 'receipt_ref', 'notes'); onDone?.(); } }); }} className="space-y-3">
                <Field label="What was sold" error={e.item}><input className="field" value={form.data.item} onChange={(x) => form.setData('item', x.target.value)} placeholder="e.g. Milk 40 L" /></Field>
                <Field label="Category" error={e.category}><select className="field" value={form.data.category} onChange={(x) => form.setData('category', x.target.value)}><option value="product">Goat product</option><option value="other">Other</option></select></Field>
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Price (PKR)" error={e.gross}><input inputMode="decimal" className="field" value={form.data.gross} onChange={(x) => form.setData('gross', x.target.value)} /></Field>
                    <Field label="Fee / discount" error={e.fee}><input inputMode="decimal" className="field" value={form.data.fee} onChange={(x) => form.setData('fee', x.target.value)} /></Field>
                </div>
                <Field label="Buyer" error={e.buyer}><input className="field" value={form.data.buyer} onChange={(x) => form.setData('buyer', x.target.value)} /></Field>
                <Field label="Receipt ref" error={e.receipt_ref}><input className="field" value={form.data.receipt_ref} onChange={(x) => form.setData('receipt_ref', x.target.value)} /></Field>
                <Field label="Date" error={e.sold_on}><input type="date" className="field" value={form.data.sold_on} onChange={(x) => form.setData('sold_on', x.target.value)} /></Field>
                <Button type="submit" className="w-full" disabled={form.processing}>Save sale</Button>
            </form>
        </Panel>
    );
}
