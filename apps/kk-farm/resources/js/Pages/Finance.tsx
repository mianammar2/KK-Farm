import { Head, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import Chart, { axis } from '../components/Chart';
import { Button, Chip, cx, Field, monthLabel, Note, PageHeader, Panel, pkr, pkrShort, Stat, StatRow } from '../components/ui';

type Entry = { id: number; direction: 'in' | 'out'; category: string; amount: number; on: string; counterparty: string | null; description: string | null; by: string; reversed: boolean; isReversal: boolean };
type Props = { summary: Record<string, number>; monthly: Array<{ month: string; income: number; spend: number }>; categories: Array<{ name: string; value: number }>; entries: Entry[]; categoryOptions: string[] };

export default function Finance({ summary, monthly, categories, entries, categoryOptions }: Props) {
    const [direction, setDirection] = useState('');
    const [category, setCategory] = useState('');
    const form = useForm({ direction: 'out', category: categoryOptions[0], amount: '', counterparty: '', description: '', occurred_on: new Date().toLocaleDateString('en-CA') });
    const rows = entries.filter((e) => (!direction || e.direction === direction) && (!category || e.category === category));

    const trend = useMemo(() => ({
        tooltip: { trigger: 'axis', valueFormatter: (v: number) => pkr(v) },
        legend: { bottom: 0, itemWidth: 12, itemHeight: 10 },
        grid: { left: 8, right: 12, top: 14, bottom: 30, containLabel: true },
        xAxis: axis.category(monthly.map((m) => monthLabel(m.month))),
        yAxis: axis.value((v) => `${v / 1000}k`),
        series: [
            { name: 'Income', type: 'bar', data: monthly.map((m) => m.income), itemStyle: { color: '#1d5c2e', borderRadius: [3, 3, 0, 0] } },
            { name: 'Spending', type: 'bar', data: monthly.map((m) => m.spend), itemStyle: { color: '#b8892b', borderRadius: [3, 3, 0, 0] } },
        ],
    }), [monthly]);

    const split = useMemo(() => {
        const rows = [...categories].reverse();
        return {
            tooltip: { trigger: 'axis', axisPointer: { type: 'none' }, valueFormatter: (v: number) => pkr(v) },
            grid: { left: 8, right: 58, top: 6, bottom: 6, containLabel: true },
            xAxis: { type: 'value', show: false },
            yAxis: { ...axis.category(rows.map((c) => c.name)), axisLine: { show: false } },
            series: [{ type: 'bar', barMaxWidth: 18, data: rows.map((c) => c.value), itemStyle: { color: '#1d5c2e', borderRadius: [0, 3, 3, 0] }, label: { show: true, position: 'right', color: '#163d22', fontWeight: 600, formatter: (p: { value: number }) => pkrShort(p.value).replace('PKR ', '') } }],
        };
    }, [categories]);

    return (
        <>
            <Head title="Expenses & Finance" />
            <PageHeader title="Expenses & finance" description="Only the CEO and finance roles can open this. Entries are never edited: a correction is a reversing entry." />
            <StatRow>
                <Stat label="This month in" value={pkrShort(summary.monthIn)} />
                <Stat label="This month out" value={pkrShort(summary.monthOut)} />
                <Stat label="12 months in" value={pkrShort(summary.yearIn)} />
                <Stat label="12 months out" value={pkrShort(summary.yearOut)} />
                <Stat label="12 month result" value={pkrShort(summary.yearIn - summary.yearOut)} tone={summary.yearIn >= summary.yearOut ? 'good' : 'alert'} />
            </StatRow>
            <div className="mb-4"><Note>All figures are synthetic and do not describe the real farm.</Note></div>

            <div className="mb-4 grid gap-4 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
                <Panel title="Monthly income and spending"><Chart option={trend} label="Monthly income and spending" height={250} /></Panel>
                <Panel title="Spending by category"><Chart option={split} label="Spending by category" height={250} /></Panel>
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_21rem]">
                <Panel flush title="Ledger" actions={
                    <div className="flex flex-wrap gap-2">
                        <select aria-label="Direction" className="field w-auto" value={direction} onChange={(e) => setDirection(e.target.value)}><option value="">In and out</option><option value="in">Money in</option><option value="out">Money out</option></select>
                        <select aria-label="Category" className="field w-auto" value={category} onChange={(e) => setCategory(e.target.value)}><option value="">All categories</option>{categoryOptions.map((c) => <option key={c}>{c}</option>)}</select>
                    </div>
                }>
                    <div className="max-h-[60vh] overflow-auto">
                        <table className="data-table min-w-[680px]">
                            <thead><tr><th>Date</th><th>Category</th><th>Party</th><th>Amount</th><th>Recorded by</th><th /></tr></thead>
                            <tbody>
                                {rows.map((e) => (
                                    <tr key={e.id} className={cx(e.reversed && 'text-ink-faint')}>
                                        <td className="whitespace-nowrap">{e.on}</td>
                                        <td>{e.category}{e.isReversal ? <Chip className="ml-1 bg-cobalt-100 text-cobalt-800">Reversal</Chip> : null}</td>
                                        <td className="max-w-48 truncate">{e.counterparty ?? '—'}</td>
                                        <td className={cx('font-semibold whitespace-nowrap', !e.reversed && (e.direction === 'in' ? 'text-ok-700' : 'text-cobalt-900'))}>{e.direction === 'in' ? '+' : '−'}{pkr(e.amount)}</td>
                                        <td className="text-sm text-ink-soft">{e.by}</td>
                                        <td>{e.reversed ? <span className="text-sm">Reversed</span> : e.isReversal ? null : <button type="button" className="text-sm font-semibold text-turq-800 hover:underline" onClick={() => router.post(`/finance/entries/${e.id}/reverse`, {}, { preserveScroll: true })}>Reverse</button>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
                <Panel title="Record an entry">
                    <form onSubmit={(e) => { e.preventDefault(); form.post('/finance/entries', { preserveScroll: true, onSuccess: () => form.reset('amount', 'counterparty', 'description') }); }} className="space-y-3">
                        <Field label="Type"><select className="field" value={form.data.direction} onChange={(e) => form.setData('direction', e.target.value)}><option value="out">Money out</option><option value="in">Money in</option></select></Field>
                        <Field label="Category"><select className="field" value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>{categoryOptions.map((c) => <option key={c}>{c}</option>)}</select></Field>
                        <Field label="Amount (PKR)" error={form.errors.amount}><input inputMode="decimal" className="field" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} /></Field>
                        <Field label="Supplier or payer"><input className="field" value={form.data.counterparty} onChange={(e) => form.setData('counterparty', e.target.value)} /></Field>
                        <Field label="Date" error={form.errors.occurred_on}><input type="date" className="field" value={form.data.occurred_on} onChange={(e) => form.setData('occurred_on', e.target.value)} /></Field>
                        <Button type="submit" className="w-full" disabled={form.processing}>Save entry</Button>
                    </form>
                </Panel>
            </div>
        </>
    );
}
