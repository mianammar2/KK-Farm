import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import Chart from '../components/LazyChart';
import { axis, SERIES } from '../lib/chartTheme';
import { Button, cx, Field, Note, PageHeader, Panel, Stat, StatRow } from '../components/ui';

type Period = { days: number; kg: number; value: number; feed: number; net: number };
type Summary = {
    goats: number; weighed: number; kg: number; avgKg: number | null; value: number; valued: number; buy: number; bought: number; feed: number; cost: number;
    costPerKg: number | null; gain: number | null; gainGoats: number; feedDay: number; feedKgDay: number; periods: Record<'day' | 'week' | 'month', Period>;
};
type Props = {
    rate: { now: number | null; weekAgo: number | null; byPurpose: Record<string, number>; history: Record<string, Record<string, number>>; recent: Array<{ on: string; purpose: string | null; rate: number; by: string; note: string | null }> };
    total: Summary;
    purposes: Array<Summary & { key: string; label: string }>;
    batches: Array<Summary & { batch: string; breed: string; buyRate: number | null }>;
    goats: Array<{ id: string; number: string; batch: string; purpose: string | null; weight: number | null; rate: number | null; value: number | null; buy: number | null; feed: number; gain: number | null; week: number | null }>;
    feedPrice: { farm: number | null; used: number | null; priced: number };
    purposeOptions: Record<string, string>;
    tradeOptions: Record<string, string>;
    allBatches: Array<{ batch: string; goats: number; breed: string; buyRate: number | null; purposes: Record<string, number> }>;
    excluded: { breeding: number; none: number };
    can: { manage: boolean };
};

const money = (n: number | null | undefined) => (n === null || n === undefined ? '—' : `PKR ${Math.round(n).toLocaleString('en-PK')}`);
const signed = (n: number | null) => (n === null ? '—' : `${n > 0 ? '+' : n < 0 ? '−' : ''}PKR ${Math.abs(Math.round(n)).toLocaleString('en-PK')}`);
const tone = (n: number | null) => (n === null || n === 0 ? 'text-ink-soft' : n > 0 ? 'text-ok-700' : 'text-crit-700');
const PERIOD_LABEL = { day: 'Today', week: 'Last 7 Days', month: 'Last 30 Days' } as const;

/** D-113: the herd priced by live weight like a market, against what it cost to buy and feed. */
export default function HerdValue(props: Props) {
    const { rate, total, purposes, batches, goats, feedPrice, purposeOptions, tradeOptions, allBatches, excluded, can } = props;
    const [batchPick, setBatchPick] = useState(batches[0]?.batch ?? allBatches[0]?.batch ?? '');
    const change = rate.now !== null && rate.weekAgo !== null && rate.weekAgo > 0 ? ((rate.now - rate.weekAgo) / rate.weekAgo) * 100 : null;

    return (
        <>
            <Head title="Herd Value" />
            <PageHeader title="Herd Value" description="Goats kept to sell (Fattening / Qurbani, Sale, Meat Sale): live weight × the market rate per KG, against what each cost to buy and to feed. Breeding goats are not priced." />
            {excluded.breeding || excluded.none ? <div className="mb-4"><Note>{[excluded.breeding ? `${excluded.breeding} breeding, replacement or milk goats are not priced` : null, excluded.none ? `${excluded.none} goats have no purpose yet` : null].filter(Boolean).join(' · ')}. {can.manage ? 'Give a batch the purpose Fattening / Qurbani, Sale or Meat Sale in Batch Purchase Rate to include it.' : 'The administrator sets each batch\'s purpose.'}</Note></div> : null}
            {rate.now === null ? <div className="mb-4"><Note>No market rate yet. {can.manage ? 'Enter today\'s live-weight rate per KG on the right to value the herd.' : 'The administrator enters the live-weight rate per KG.'}</Note></div> : null}

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="min-w-0 space-y-4">
                    <Ticker rate={rate} change={change} costPerKg={total.costPerKg} purposeOptions={tradeOptions} />

                    <StatRow>
                        <Stat label="Herd Value" value={money(total.value)} note={`${total.valued} of ${total.goats} goats priced`} />
                        <Stat label="Live Weight" value={`${total.kg.toLocaleString('en-PK')} KG`} note={`${total.weighed} weighed · average ${total.avgKg ?? '—'} KG`} />
                        <Stat label="Cost So Far" value={money(total.cost)} note={`Purchase ${money(total.buy)} · feed ${money(total.feed)}`} />
                        <Stat label="Gain / Loss" value={signed(total.gain)} tone={total.gain === null ? undefined : total.gain >= 0 ? 'good' : 'alert'} note={total.gain === null ? 'Needs purchase rates' : `${total.gainGoats} goats with a purchase price`} />
                        <Stat label="Feed Cost per Day" value={money(total.feedDay)} note={`${total.feedKgDay} KG of feed a day`} />
                    </StatRow>

                    <Panel title="Gain by Period" description="Value now against the same goats then (weight × rate), less the feed they needed in between. A goat bought in the period counts from its purchase price." flush>
                        <PeriodTable periods={total.periods} feedDay={total.feedDay} />
                    </Panel>

                    <Panel title="By Purpose" description="Breeding, Fattening / Qurbani and Sale goats, each at its own rate when one is set" flush>
                        <SummaryTable rows={purposes.map((p) => ({ ...p, name: p.label }))} />
                    </Panel>

                    <Panel title="By Batch" description="Goats bought together share a tag start, e.g. MC241026. Purchase cost = first weight × the batch's rate per KG." flush>
                        <SummaryTable rows={batches.map((b) => ({ key: b.batch, name: b.batch, sub: [b.breed, b.buyRate !== null ? `bought at PKR ${b.buyRate.toLocaleString('en-PK')}/KG` : 'no purchase rate'].filter(Boolean).join(' · '), ...b }))}
                            onPick={can.manage ? (k) => { setBatchPick(k); document.getElementById('batch-form')?.scrollIntoView({ behavior: 'smooth', block: 'center' }); } : undefined} />
                    </Panel>

                    <GoatTable goats={goats} />
                </div>

                {can.manage ? (
                    <div className="space-y-4">
                        <RateForm purposeOptions={tradeOptions} />
                        <BatchForm batches={allBatches} purposeOptions={purposeOptions} batch={batchPick} setBatch={setBatchPick} />
                        <FeedPriceForm feedPrice={feedPrice} goats={total.goats} />
                        <RecentRates recent={rate.recent} purposeOptions={purposeOptions} />
                    </div>
                ) : <RecentRates recent={rate.recent} purposeOptions={purposeOptions} />}
            </div>
        </>
    );
}

function Ticker({ rate, change, costPerKg, purposeOptions }: { rate: Props['rate']; change: number | null; costPerKg: number | null; purposeOptions: Record<string, string> }) {
    const option = useMemo(() => {
        const days = Array.from(new Set(Object.values(rate.history).flatMap((h) => Object.keys(h)))).sort();
        if (!days.length) return null;
        const series = Object.entries(rate.history).map(([key, h], i) => {
            let last: number | null = null;
            return { name: key === 'all' ? 'All Goats' : purposeOptions[key] ?? key, type: 'line', step: 'end', symbolSize: 6, color: SERIES[i % SERIES.length],
                data: days.map((d) => { if (h[d] !== undefined) last = h[d]; return last; }) };
        });
        const fmt = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
        return {
            grid: { left: 56, right: 16, top: 30, bottom: 28 }, tooltip: { trigger: 'axis', valueFormatter: (v: number) => (v === null ? '—' : `PKR ${v.toLocaleString('en-PK')}/KG`) },
            legend: { top: 0, textStyle: { color: '#5e6a5f' } },
            xAxis: axis.category(days.map(fmt)), yAxis: { ...axis.value((v) => v.toLocaleString('en-PK')), scale: true }, series,
        };
    }, [rate.history, purposeOptions]);
    const Arrow = change === null || Math.abs(change) < 0.05 ? Minus : change > 0 ? ArrowUpRight : ArrowDownRight;

    return (
        <Panel title="Live-Weight Rate" description="PKR per KG of live weight, as entered by the administrator">
            <div className="flex flex-wrap items-end gap-x-6 gap-y-2">
                <div>
                    <div className="text-4xl font-bold tabular-nums tracking-tight text-cobalt-900">{rate.now === null ? '—' : `PKR ${rate.now.toLocaleString('en-PK')}`}<span className="ml-1 text-base font-semibold text-ink-soft">/KG</span></div>
                    <div className={cx('mt-1 flex items-center gap-1 text-sm font-semibold', change === null ? 'text-ink-soft' : change >= 0 ? 'text-ok-700' : 'text-crit-700')}>
                        <Arrow size={16} aria-hidden />{change === null ? 'No rate a week ago' : `${change >= 0 ? '+' : ''}${change.toFixed(1)}% in 7 days (from PKR ${rate.weekAgo?.toLocaleString('en-PK')})`}
                    </div>
                </div>
                {Object.keys(rate.byPurpose).length ? <div className="flex flex-wrap gap-2">{Object.entries(rate.byPurpose).map(([k, v]) => <span key={k} className="rounded-tile bg-glaze px-3 py-1.5 text-sm"><span className="text-ink-soft">{purposeOptions[k]}</span> <strong className="tabular-nums text-cobalt-900">PKR {v.toLocaleString('en-PK')}</strong></span>)}</div> : null}
                {costPerKg !== null ? (
                    <div className="ml-auto text-right">
                        <div className="text-xs text-ink-soft">Our Cost per KG (Break-Even)</div>
                        <div className={cx('text-xl font-bold tabular-nums', rate.now !== null && rate.now < costPerKg ? 'text-crit-700' : 'text-cobalt-900')}>PKR {costPerKg.toLocaleString('en-PK')}</div>
                        {rate.now !== null ? <div className={cx('text-xs font-semibold', rate.now >= costPerKg ? 'text-ok-700' : 'text-crit-700')}>{rate.now >= costPerKg ? `Market is PKR ${(rate.now - costPerKg).toLocaleString('en-PK')} above` : `Market is PKR ${(costPerKg - rate.now).toLocaleString('en-PK')} below`}</div> : null}
                    </div>
                ) : null}
            </div>
            {option ? <div className="mt-3"><Chart option={option} label="Live-weight rate per KG over time" height={220} /></div> : null}
        </Panel>
    );
}

function PeriodTable({ periods, feedDay }: { periods: Summary['periods']; feedDay: number }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[34rem] text-sm">
                <thead className="text-left text-xs text-ink-soft"><tr><th className="px-4 py-2 font-semibold">Period</th><th className="px-3 font-semibold">Weight Gained</th><th className="px-3 font-semibold">Value Change</th><th className="px-3 font-semibold">Feed Cost</th><th className="px-3 font-semibold">Net</th></tr></thead>
                <tbody className="divide-y divide-rule">
                    {(['day', 'week', 'month'] as const).map((k) => {
                        const p = periods[k];
                        return (
                            <tr key={k}>
                                <td className="px-4 py-2 font-semibold text-cobalt-900">{PERIOD_LABEL[k]}</td>
                                <td className={cx('px-3 tabular-nums', tone(p.kg))}>{p.kg > 0 ? '+' : ''}{p.kg} KG</td>
                                <td className={cx('px-3 tabular-nums', tone(p.value))}>{signed(p.value)}</td>
                                <td className="px-3 tabular-nums text-ink-soft">{money(p.feed)}</td>
                                <td className={cx('px-3 font-bold tabular-nums', tone(p.net))}>{signed(p.net)}</td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
            <p className="border-t border-rule px-4 py-2 text-xs text-ink-soft">Feed at today's need: {money(feedDay)} a day · {money(feedDay * 7)} a week · {money(feedDay * 30)} a month.</p>
        </div>
    );
}

type Row = Summary & { key: string; name: string; sub?: string };

function SummaryTable({ rows, onPick }: { rows: Row[]; onPick?: (key: string) => void }) {
    if (!rows.length) return <p className="p-4 text-sm text-ink-soft">No goats yet.</p>;
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[52rem] text-sm">
                <thead className="text-left text-xs text-ink-soft"><tr><th className="px-4 py-2 font-semibold">Group</th><th className="px-3 font-semibold">Goats</th><th className="px-3 font-semibold">Live Weight</th><th className="px-3 font-semibold">Value</th><th className="px-3 font-semibold">Purchase</th><th className="px-3 font-semibold">Feed So Far</th><th className="px-3 font-semibold">Cost per KG</th><th className="px-3 font-semibold">Gain / Loss</th><th className="px-3 font-semibold">Last 7 Days</th>{onPick ? <th /> : null}</tr></thead>
                <tbody className="divide-y divide-rule">
                    {rows.map((r) => (
                        <tr key={r.key}>
                            <td className="px-4 py-2"><span className="font-mono font-semibold text-cobalt-900">{r.name}</span>{r.sub ? <span className="block text-xs text-ink-soft">{r.sub}</span> : null}</td>
                            <td className="px-3 tabular-nums">{r.goats}{r.weighed < r.goats ? <span className="block text-xs text-watch-700">{r.goats - r.weighed} not weighed</span> : null}</td>
                            <td className="px-3 tabular-nums">{r.kg.toLocaleString('en-PK')} KG<span className="block text-xs text-ink-faint">avg {r.avgKg ?? '—'} KG</span></td>
                            <td className="px-3 font-semibold tabular-nums text-cobalt-900">{money(r.value)}</td>
                            <td className="px-3 tabular-nums">{r.bought ? money(r.buy) : <span className="text-ink-faint">Not set</span>}</td>
                            <td className="px-3 tabular-nums text-ink-soft">{money(r.feed)}</td>
                            <td className="px-3 tabular-nums">{r.costPerKg !== null ? `PKR ${r.costPerKg.toLocaleString('en-PK')}` : '—'}</td>
                            <td className={cx('px-3 font-bold tabular-nums', tone(r.gain))}>{signed(r.gain)}</td>
                            <td className={cx('px-3 tabular-nums', tone(r.periods.week.net))}>{signed(r.periods.week.net)}</td>
                            {onPick ? <td className="px-3"><button type="button" className="text-sm font-semibold text-cobalt-800 hover:underline" onClick={() => onPick(r.key)}>Set Rate</button></td> : null}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function GoatTable({ goats }: { goats: Props['goats'] }) {
    const [all, setAll] = useState(false);
    const [sort, setSort] = useState<'number' | 'gain' | 'week' | 'value'>('number');
    const sorted = useMemo(() => [...goats].sort((a, b) => (sort === 'number' ? a.number.localeCompare(b.number) : (b[sort] ?? -Infinity) - (a[sort] ?? -Infinity))), [goats, sort]);
    const rows = all ? sorted : sorted.slice(0, 10);
    const head = (key: typeof sort, label: string) => <th className="px-3 font-semibold"><button type="button" className={cx('hover:underline', sort === key && 'text-cobalt-900')} onClick={() => setSort(key)}>{label}{sort === key ? ' ↓' : ''}</button></th>;

    return (
        <Panel title="Each Goat" description="Sort by gain or by last week to see which goats are earning their feed" flush>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[50rem] text-sm">
                    <thead className="text-left text-xs text-ink-soft"><tr>{head('number', 'Goat')}<th className="px-3 font-semibold">Purpose</th><th className="px-3 font-semibold">Weight</th>{head('value', 'Value')}<th className="px-3 font-semibold">Purchase</th><th className="px-3 font-semibold">Feed So Far</th>{head('gain', 'Gain / Loss')}{head('week', 'Last 7 Days')}</tr></thead>
                    <tbody className="divide-y divide-rule">
                        {rows.map((g) => (
                            <tr key={g.id}>
                                <td className="px-3 py-1.5"><Link href={`/goats/${g.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{g.number}</Link></td>
                                <td className="px-3 text-ink-soft">{g.purpose ?? '—'}</td>
                                <td className="px-3 tabular-nums">{g.weight !== null ? `${g.weight} KG` : <span className="text-watch-700">Not weighed</span>}</td>
                                <td className="px-3 font-semibold tabular-nums text-cobalt-900">{money(g.value)}</td>
                                <td className="px-3 tabular-nums">{money(g.buy)}</td>
                                <td className="px-3 tabular-nums text-ink-soft">{money(g.feed)}</td>
                                <td className={cx('px-3 font-bold tabular-nums', tone(g.gain))}>{signed(g.gain)}</td>
                                <td className={cx('px-3 tabular-nums', tone(g.week))}>{signed(g.week)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {goats.length > 10 ? <button type="button" onClick={() => setAll(!all)} className="w-full border-t border-rule px-3 py-2 text-sm font-semibold text-cobalt-800 hover:bg-glaze">{all ? 'Show Less' : `Show ${goats.length - 10} More`}</button> : null}
        </Panel>
    );
}

function RateForm({ purposeOptions }: { purposeOptions: Record<string, string> }) {
    const today = new Date().toLocaleDateString('en-CA');
    const form = useForm({ rate_on: today, purpose: '', rate: '', note: '' });
    return (
        <Panel title="Enter Market Rate" description="Today's mandi rate per KG of live weight. A new entry for the same day replaces it on the chart; nothing is deleted.">
            <form onSubmit={(e) => { e.preventDefault(); form.post('/value/rates', { preserveScroll: true, onSuccess: () => form.reset('rate', 'note') }); }} className="space-y-3">
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Date" error={form.errors.rate_on}><input className="field" type="date" max={today} value={form.data.rate_on} onChange={(e) => form.setData('rate_on', e.target.value)} /></Field>
                    <Field label="PKR per KG" error={form.errors.rate}><input className="field" type="number" inputMode="decimal" min={1} step="1" value={form.data.rate} onChange={(e) => form.setData('rate', e.target.value)} placeholder="e.g. 1400" /></Field>
                </div>
                <Field label="For" error={form.errors.purpose} hint="A purpose rate (e.g. Qurbani) overrides the general rate for those goats"><select className="field" value={form.data.purpose} onChange={(e) => form.setData('purpose', e.target.value)}><option value="">All goats</option>{Object.entries(purposeOptions).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Note" error={form.errors.note}><input className="field" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} placeholder="e.g. Lahore mandi" /></Field>
                <Button type="submit" disabled={form.processing}>Save Rate</Button>
            </form>
        </Panel>
    );
}

function BatchForm({ batches, purposeOptions, batch, setBatch }: { batches: Props['allBatches']; purposeOptions: Record<string, string>; batch: string; setBatch: (b: string) => void }) {
    const current = batches.find((b) => b.batch === batch);
    const form = useForm({ batch, rate: '', purpose: '' });
    const pick = (b: string) => setBatch(b);
    useEffect(() => { form.setData('batch', batch); }, [batch]); // eslint-disable-line react-hooks/exhaustive-deps
    return (
        <div id="batch-form">
            <Panel title="Batch Purchase Rate" description="What was paid per KG of live weight for a batch. Each goat's purchase cost = its first weight × this rate.">
                <form onSubmit={(e) => { e.preventDefault(); form.post('/value/batch', { preserveScroll: true, onSuccess: () => form.reset('rate', 'purpose') }); }} className="space-y-3">
                    <Field label="Batch" error={form.errors.batch}><select className="field font-mono" value={batch} onChange={(e) => pick(e.target.value)}>{batches.map((b) => <option key={b.batch} value={b.batch}>{b.batch} ({b.goats} goats){b.buyRate !== null ? ` · ${b.buyRate}/KG` : ''}</option>)}</select></Field>
                    {current ? <p className="text-xs text-ink-soft">{current.breed} · {Object.entries(current.purposes).map(([p, n]) => `${n} ${p}`).join(', ')}{current.buyRate !== null ? ` · bought at PKR ${current.buyRate.toLocaleString('en-PK')}/KG` : ' · no purchase rate yet'}</p> : null}
                    <Field label="Paid PKR per KG" error={form.errors.rate}><input className="field" type="number" inputMode="decimal" min={1} value={form.data.rate} onChange={(e) => form.setData('rate', e.target.value)} placeholder={current?.buyRate ? String(current.buyRate) : 'e.g. 1200'} /></Field>
                    <Field label="Purpose (optional)" error={form.errors.purpose} hint="Sets the purpose of every goat in the batch"><select className="field" value={form.data.purpose} onChange={(e) => form.setData('purpose', e.target.value)}><option value="">Keep as is</option>{Object.entries(purposeOptions).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Button type="submit" disabled={form.processing || !batch}>Save Batch</Button>
                </form>
            </Panel>
        </div>
    );
}

function FeedPriceForm({ feedPrice, goats }: { feedPrice: Props['feedPrice']; goats: number }) {
    const form = useForm({ price: feedPrice.farm === null ? '' : String(feedPrice.farm) });
    return (
        <Panel title="Feed Price" description="PKR per KG of feed, used for goats whose ration has no priced feeds in the Feed Store.">
            <form onSubmit={(e) => { e.preventDefault(); form.post('/value/feed-price', { preserveScroll: true }); }} className="space-y-3">
                <Field label="PKR per KG of feed" error={form.errors.price}><input className="field" type="number" inputMode="decimal" min={1} value={form.data.price} onChange={(e) => form.setData('price', e.target.value)} placeholder="e.g. 85" /></Field>
                <p className="text-xs text-ink-soft">{feedPrice.priced} of {goats} goats have a feed price{feedPrice.used !== null ? ` (PKR ${feedPrice.used}/KG)` : ''}. Feed need = weight × the intake % for each goat's age (Feeding → Settings).</p>
                <Button type="submit" disabled={form.processing}>Save Feed Price</Button>
            </form>
        </Panel>
    );
}

function RecentRates({ recent, purposeOptions }: { recent: Props['rate']['recent']; purposeOptions: Record<string, string> }) {
    return (
        <Panel title="Rate History" flush>
            {recent.length ? <ul className="divide-y divide-rule text-sm">{recent.map((r, i) => <li key={i} className="flex flex-wrap gap-x-2 px-4 py-2"><span className="w-24 text-ink-faint">{r.on}</span><span className="font-semibold tabular-nums text-cobalt-900">PKR {r.rate.toLocaleString('en-PK')}/KG</span><span className="text-ink-soft">{r.purpose ? purposeOptions[r.purpose] : 'All goats'}</span><span className="w-full text-xs text-ink-faint">{[r.note, r.by].filter(Boolean).join(' · ')}</span></li>)}</ul>
                : <p className="p-4 text-sm text-ink-soft">No rates entered yet.</p>}
        </Panel>
    );
}
