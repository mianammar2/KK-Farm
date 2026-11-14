import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import DataGrid from '../components/DataGrid';

const money = (v: number | null | undefined) => (v === null || v === undefined ? '—' : pkr(v));
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type PenRow = { id: number; name: string; cost: number; offered: number; refusedPct: number | null; dm: number; goats: number; weighed: number; days: number; costHeadDay: number | null; gain: number; costPerKg: number | null; fcr: number | null; adg: number | null; margin: number | null; sick: number; issues: string[] };
type GoatRow = { id: string; number: string | null; breed: string | null; days: number; cost: number; costDay: number | null; dm: number; start: number | null; end: number | null; gain: number | null; adg: number | null; costPerKg: number | null; fcr: number | null; valueGain: number | null; margin: number | null; sick: boolean };
type Totals = { cost: number; unallocated: number; offered: number; gain: number; costPerKg: number | null; fcr: number | null; refusedPct: number | null; cpCompliance: number | null; stockValue: number; shrinkKg: number; shrinkValue: number; shrinkPct: number | null; margin: number | null; fcrAlert: number };
type SupplyFeed = { name: string; needDay: number; stock: number; days: number | null; shortWeek: number; needMonth: number; shortMonth: number };
type Props = {
    pens: PenRow[]; goats: GoatRow[]; totals: Totals; period: { from: string; to: string }; assumptions: string[];
    supply: { needDay: number; inStore: number; days: number | null; feeds: SupplyFeed[] };
    items: Array<{ id: number; name: string; book: number; cost: number; supplier: string | null }>;
    counts: Array<{ id: number; on: string; item: string | null; book: number; counted: number; diff: number; value: number; by: string; notes: string | null }>;
    tmr: Array<{ no: string; on: string; formula: string | null; input: number; output: number | null; shrink: number | null; cost: number }>;
    suppliers: Array<{ id: number; name: string }>; fcrAlert: number; canCount: boolean; canPurchase: boolean; canReview: boolean;
};

const pkr = (n: number | null) => (n === null ? '—' : `PKR ${Math.round(n).toLocaleString()}`);
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const FLOW = ['Group & CP target', 'Pen forecast', 'Stock OK? / Purchase', 'TMR mix (batch)', '3 meals: offered KG', 'Refusals', 'Issue & value', 'Cost to goats', 'ADG / health check', 'Cost per KG gain & margin'];

/** D-192: V3 cross-app journey C, Feed-to-True-Cost (flow 26). */
export default function FeedCost(p: Props) {
    const [tab, setTab] = useTabParam('pens');
    const t = p.totals;
    const flagged = p.pens.filter((x) => x.issues.length).length;
    const go = (from: string, to: string) => router.get('/feed-cost', { from, to, tab }, { preserveState: true, preserveScroll: true });
    return (
        <>
            <Head title="Feed True Cost" />
            <PageHeader title="Feed True Cost" description="What the feed really cost per goat and per KG gained: meals offered and refused, cost split to the goats in each pen, weight gain, feed conversion and margin." />
            <ol className="mb-3 flex flex-wrap items-center gap-1 text-xs font-semibold">{FLOW.map((s, i) => <li key={s} className="flex items-center gap-1"><span className="rounded-full bg-glaze px-2 py-0.5 text-ink-soft ring-1 ring-rule">{s}</span>{i < FLOW.length - 1 ? <span className="text-ink-faint">→</span> : null}</li>)}</ol>
            <div className="mb-3 flex flex-wrap items-end gap-2">
                <Field label="From"><input type="date" className="field" value={p.period.from} max={p.period.to} onChange={(e) => go(e.target.value, p.period.to)} /></Field>
                <Field label="To"><input type="date" className="field" value={p.period.to} max={today()} onChange={(e) => go(p.period.from, e.target.value)} /></Field>
                <span className="pb-2 text-xs text-ink-soft">Up to 3 months at a time.</span>
            </div>
            <StatRow>
                <Stat label="Feed cost" value={pkr(t.cost)} note={t.unallocated ? `${pkr(t.unallocated)} not split (empty pen)` : `${t.offered.toLocaleString()} KG offered`} />
                <Stat label="Cost per KG gained" value={pkr(t.costPerKg)} note={`${t.gain} KG gained`} />
                <Stat label="Feed conversion" value={t.fcr ?? '—'} note="KG DM per KG gain" tone={t.fcr !== null && t.fcr > t.fcrAlert ? 'alert' : undefined} />
                <Stat label="Refused / wasted" value={t.refusedPct === null ? '—' : `${t.refusedPct}%`} />
                <Stat label="CP compliance" value={t.cpCompliance === null ? '—' : `${t.cpCompliance}%`} note="Rations meeting CP target" />
                <Stat label="Margin over feed" value={pkr(t.margin)} note="Value of gain − feed" tone={t.margin !== null && t.margin < 0 ? 'alert' : 'good'} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'pens', label: 'Pens', count: flagged || undefined }, { id: 'goats', label: 'Per Goat' }, { id: 'stock', label: 'Stock, Purchase & Shrinkage' }, { id: 'rules', label: 'How It Is Worked Out' }]} />
            {tab === 'pens' ? <Pens {...p} /> : null}
            {tab === 'goats' ? <Goats {...p} /> : null}
            {tab === 'stock' ? <Stock {...p} /> : null}
            {tab === 'rules' ? <Rules {...p} /> : null}
        </>
    );
}

function Pens({ pens, canReview, totals }: Props) {
    const review = (pen: PenRow) => router.post(`/feed-cost/pens/${pen.id}/review`, { issues: pen.issues.join('; ') }, { preserveScroll: true });
    return (
        <Panel flush title="Pens" description={`Poor response (feed conversion above ${totals.fcrAlert}, no gain, over 10% refused or sick goats) needs a review of intake, ration and illness.`}>
            {pens.length ? <DataGrid id="feedcost-pens" name="Feed cost by pen" rows={pens} rowKey={(x) => String(x.id)} cols={[
                { key: 'name', label: 'Pen', type: 'enum', total: 'count', render: (x) => <b>{x.name}</b> },
                { key: 'goats', label: 'Goats', type: 'number', total: 'sum', render: (x) => <>{x.goats}{x.weighed < x.goats ? <span className="text-ink-soft"> ({x.weighed} weighed)</span> : null}</> },
                { key: 'cost', label: 'Feed cost (PKR)', type: 'number', total: 'sum', render: (x) => money(x.cost) },
                { key: 'costHeadDay', label: 'Cost / head / day', type: 'number', total: 'avg' },
                { key: 'refusedPct', label: 'Refused %', type: 'number', total: 'avg' },
                { key: 'gain', label: 'Gain KG', type: 'number', total: 'sum' },
                { key: 'adg', label: 'ADG g', type: 'number', total: 'avg' },
                { key: 'costPerKg', label: 'Cost / KG gain', type: 'number', total: 'avg', render: (x) => money(x.costPerKg) },
                { key: 'fcr', label: 'FCR', type: 'number', total: 'avg' },
                { key: 'margin', label: 'Margin (PKR)', type: 'number', total: 'sum', render: (x) => <span className={x.margin !== null && x.margin < 0 ? 'text-crit-700' : ''}>{money(x.margin)}</span> },
                { key: 'response', label: 'Response', type: 'enum', value: (x) => (x.issues.length ? 'Poor response' : 'Acceptable'), wrap: true, render: (x) => (x.issues.length ? <div className="space-y-1"><Chip className="bg-crit-100 text-crit-700">Poor response</Chip><div className="text-ink-soft">{x.issues.join(' · ')}</div>{canReview ? <Button variant="secondary" onClick={() => review(x)}>Review Intake, Ration & Illness</Button> : null}</div> : <Chip className="bg-ok-100 text-ok-700">Acceptable</Chip>) },
            ]} /> : <Empty title="No feeding recorded in this period" />}
        </Panel>
    );
}

function Goats({ goats }: Props) {
    return (
        <Panel flush title="Feed Cost per Goat" description="Each meal's cost split among the goats in the pen at that meal.">
            {goats.length ? <DataGrid id="feedcost-goats" name="Feed cost per goat" rows={goats} rowKey={(g) => g.id} cols={[
                { key: 'number', label: 'Goat', type: 'text', total: 'count', render: (g) => <span className="inline-flex items-center gap-1"><Link href={`/goats/${g.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{g.number}</Link>{g.sick ? <Chip className="bg-treat-100 text-treat-700">Sick</Chip> : null}</span> },
                { key: 'breed', label: 'Breed', type: 'enum' },
                { key: 'days', label: 'Days fed', type: 'number', total: 'avg' },
                { key: 'cost', label: 'Feed cost (PKR)', type: 'number', total: 'sum', render: (g) => money(g.cost) },
                { key: 'costDay', label: 'Per day', type: 'number', total: 'avg' },
                { key: 'start', label: 'Start KG', type: 'number', total: 'avg' },
                { key: 'end', label: 'End KG', type: 'number', total: 'avg' },
                { key: 'gain', label: 'Gain KG', type: 'number', total: 'sum' },
                { key: 'adg', label: 'ADG g', type: 'number', total: 'avg' },
                { key: 'costPerKg', label: 'Cost / KG gain', type: 'number', total: 'avg', render: (g) => money(g.costPerKg) },
                { key: 'fcr', label: 'FCR', type: 'number', total: 'avg' },
                { key: 'valueGain', label: 'Value of gain', type: 'number', total: 'sum', render: (g) => money(g.valueGain) },
                { key: 'margin', label: 'Margin (PKR)', type: 'number', total: 'sum', render: (g) => <span className={g.margin !== null && g.margin < 0 ? 'text-crit-700' : ''}>{money(g.margin)}</span> },
            ]} /> : <Empty title="No feed cost in this period" />}
        </Panel>
    );
}

function Stock(p: Props) {
    const short = p.supply.feeds.filter((f) => f.shortWeek > 0);
    const po = useForm({ supplier_id: '', lines: short.map((f) => ({ feed_item_id: String(p.items.find((i) => i.name === f.name)?.id ?? ''), qty: String(Math.ceil(f.shortWeek)) })) });
    const [counting, setCounting] = useState(false);
    const count = useForm({ counted_on: today(), notes: '', counts: p.items.map((i) => ({ feed_item_id: String(i.id), counted_kg: '' })) });
    return (
        <div className="space-y-3">
            <Panel title="Stock vs Forecast" description={`The herd needs ${p.supply.needDay} KG a day; ${p.supply.inStore} KG in store${p.supply.days !== null ? ` (about ${p.supply.days} days)` : ''}.`}>
                {p.supply.feeds.length ? <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Feed</th><th>Need / day</th><th>In store</th><th>Days left</th><th>Short this week</th></tr></thead><tbody>
                    {p.supply.feeds.map((f) => <tr key={f.name}><td>{f.name}</td><td>{f.needDay}</td><td>{f.stock}</td><td className={f.days !== null && f.days < 7 ? 'font-semibold text-crit-700' : ''}>{f.days ?? '—'}</td><td>{f.shortWeek || '—'}</td></tr>)}
                </tbody></table></div> : <Empty title="No ration splits the need by feed yet" />}
                {short.length && p.canPurchase ? <div className="flex flex-wrap items-end gap-2 border-t border-rule p-4">
                    <Field label="Supplier" error={po.errors.supplier_id}><select className="field" value={po.data.supplier_id} onChange={(x) => po.setData('supplier_id', x.target.value)}><option value="">Choose…</option>{p.suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
                    <Button disabled={po.processing} onClick={() => po.post('/feed-cost/purchase', { preserveScroll: true })}>Draft Purchase Order for the Shortfall</Button>
                    <span className="text-xs text-ink-soft">{short.map((f) => `${f.name} ${Math.ceil(f.shortWeek)} KG`).join(', ')}</span>
                </div> : null}
            </Panel>
            <Panel title="Stock Count & Shrinkage" description="Count what is in the store. The difference to the book is posted once as an adjustment; a loss is shrinkage."
                actions={p.canCount ? <Button onClick={() => setCounting(!counting)}>{counting ? 'Close' : 'Count Stock'}</Button> : undefined}>
                <div className="flex flex-wrap gap-4 px-4 pt-3 text-sm"><span>Stock value: <strong>{pkr(p.totals.stockValue)}</strong></span><span>Shrinkage in period: <strong>{p.totals.shrinkKg} KG · {pkr(p.totals.shrinkValue)}</strong>{p.totals.shrinkPct !== null ? ` (${p.totals.shrinkPct}% of counted book)` : ''}</span></div>
                {counting ? <div className="space-y-2 p-4">
                    <div className="grid gap-2 sm:grid-cols-3"><Field label="Counted on"><input type="date" max={today()} className="field" value={count.data.counted_on} onChange={(x) => count.setData('counted_on', x.target.value)} /></Field><Field label="Notes"><input className="field" value={count.data.notes} onChange={(x) => count.setData('notes', x.target.value)} /></Field></div>
                    <table className="data-table"><thead><tr><th>Feed</th><th>Book KG</th><th>Counted KG</th><th>Difference</th></tr></thead><tbody>
                        {p.items.map((it, i) => { const c = count.data.counts[i]; const d = c.counted_kg === '' ? null : Math.round((Number(c.counted_kg) - it.book) * 10) / 10; return <tr key={it.id}><td>{it.name}</td><td>{it.book}</td>
                            <td><input type="number" step="0.1" className="field w-28" value={c.counted_kg} onChange={(x) => count.setData('counts', count.data.counts.map((q, j) => (j === i ? { ...q, counted_kg: x.target.value } : q)))} /></td>
                            <td className={d !== null && d < 0 ? 'text-crit-700' : ''}>{d ?? '—'}</td></tr>; })}
                    </tbody></table>
                    {Object.keys(count.errors).length ? <p className="text-sm text-crit-700">{Object.values(count.errors)[0]}</p> : null}
                    <Button disabled={count.processing} onClick={() => { count.transform((d) => ({ ...d, counts: d.counts.filter((c) => c.counted_kg !== '') })); count.post('/feed-cost/count', { preserveScroll: true, onSuccess: () => setCounting(false) }); }}>Save Count</Button>
                </div> : null}
                {p.counts.length ? <div className="overflow-x-auto"><table className="data-table mt-2"><thead><tr><th>Date</th><th>Feed</th><th>Book</th><th>Counted</th><th>Difference</th><th>Value</th><th>By</th></tr></thead><tbody>
                    {p.counts.map((c) => <tr key={c.id}><td>{c.on}</td><td>{c.item}</td><td>{c.book}</td><td>{c.counted}</td><td className={c.diff < 0 ? 'text-crit-700' : ''}>{c.diff}</td><td>{pkr(c.value)}</td><td>{c.by}</td></tr>)}
                </tbody></table></div> : <p className="p-4 text-sm text-ink-soft">No counts yet.</p>}
            </Panel>
            <Panel flush title="TMR Batches (lot traceability)" description="Weigh the TMR after mixing to capture mixing loss; the batch cost is then spread over what came out.">
                {p.tmr.length ? <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Batch</th><th>Date</th><th>Formula</th><th>Ingredients KG</th><th>Weighed KG</th><th>Mixing loss</th><th>Cost</th></tr></thead><tbody>
                    {p.tmr.map((b) => <tr key={b.no}><td className="font-mono">{b.no}</td><td>{b.on}</td><td>{b.formula}</td><td>{b.input}</td><td>{b.output ?? 'Not weighed'}</td><td>{b.shrink === null ? '—' : `${b.shrink}%`}</td><td>{pkr(b.cost)}</td></tr>)}
                </tbody></table></div> : <Empty title="No TMR batches yet" />}
            </Panel>
        </div>
    );
}

function Rules({ assumptions, fcrAlert, canReview }: Props) {
    const form = useForm({ fcr_alert: String(fcrAlert) });
    return (
        <Panel title="How the Figures Are Worked Out" description="Allocation assumptions, shown so the numbers can be checked.">
            <ol className="list-decimal space-y-1.5 px-8 py-4 text-sm">{assumptions.map((a) => <li key={a}>{a}</li>)}</ol>
            {canReview ? <div className="flex items-end gap-2 border-t border-rule p-4"><Field label="Feed conversion alert (KG DM per KG gain)" error={form.errors.fcr_alert}><input type="number" step="0.5" className="field w-32" value={form.data.fcr_alert} onChange={(x) => form.setData('fcr_alert', x.target.value)} /></Field><Button disabled={form.processing} onClick={() => form.post('/feed-cost/settings', { preserveScroll: true })}>Save</Button></div> : null}
        </Panel>
    );
}
