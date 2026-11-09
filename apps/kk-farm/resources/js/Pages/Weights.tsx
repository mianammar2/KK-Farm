import { Head, useForm } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import Chart from '../components/LazyChart';
import { axis } from '../lib/chartTheme';
import { Button, cx, Empty, Field, GoatLabel, monthLabel, PageHeader, Panel, Stat, StatRow } from '../components/ui';
import type { GoatCard } from '../types';

type Row = GoatCard & { adg: number | null; previous: number | null; weighedOn: string | null; daysAgo: number | null; bodyScore: number | null };
type LogRow = { id: number; goatId: string; goat: string; on: string; kg: number; previous: number | null; days: number | null; gain: number | null; adg: number | null; score: number | null; heightIn: number | null; context: string | null; by: string; dateIso: string; canEdit: boolean };
type Props = { goats: Row[]; log: LogRow[]; byBreed: Array<{ breed: string; month: string; kg: number }> };

export default function Weights({ goats, log, byBreed }: Props) {
    const [query, setQuery] = useState('');
    const [view, setView] = useState('all');
    const form = useForm({ animal_id: goats[0]?.id ?? '', weight_kg: '', body_score: '', height_in: '', measured_on: new Date().toLocaleDateString('en-CA') });
    const [logShown, setLogShown] = useState(10);
    const [fixing, setFixing] = useState<number | null>(null); // D-099: correcting a weighing

    const losing = goats.filter((g) => (g.adg ?? 0) < 0);
    const stale = goats.filter((g) => (g.daysAgo ?? 0) > 45);
    const withAdg = goats.filter((g) => g.adg !== null);
    const averageAdg = withAdg.length ? Math.round(withAdg.reduce((sum, g) => sum + (g.adg ?? 0), 0) / withAdg.length) : 0;

    const rows = useMemo(() => {
        const q = query.toLowerCase();
        const pool = view === 'losing' ? losing : view === 'stale' ? stale : goats;
        return pool.filter((g) => !q || g.number.toLowerCase().includes(q) || g.name?.toLowerCase().includes(q));
    }, [goats, query, view]);

    const chart = useMemo(() => {
        const months = [...new Set(byBreed.map((r) => r.month))];
        const breeds = [...new Set(byBreed.map((r) => r.breed))];
        return {
            tooltip: { trigger: 'axis', valueFormatter: (v: number) => (v ? `${v} KG` : '—') },
            legend: { bottom: 0, itemWidth: 12, itemHeight: 8, type: 'scroll' },
            grid: { left: 8, right: 12, top: 14, bottom: 32, containLabel: true },
            xAxis: axis.category(months.map(monthLabel)),
            yAxis: { ...axis.value((v) => `${v} KG`), min: 0 }, // D-105: weight graphs start at 0 KG
            series: breeds.map((breed) => ({ name: breed, type: 'line', smooth: true, symbolSize: 5, data: months.map((m) => byBreed.find((r) => r.breed === breed && r.month === m)?.kg ?? null) })),
        };
    }, [byBreed]);

    return (
        <>
            <Head title="Weight & Growth" />
            <PageHeader title="Weight & growth" description="Average daily gain is calculated between the two latest weighings." />
            <StatRow>
                <Stat label="Average daily gain" value={`${averageAdg} g`} note={`${withAdg.length} goats with two weighings`} />
                <Stat label="Losing weight" value={losing.length} tone={losing.length ? 'alert' : undefined} note="Since last weighing" />
                <Stat label="Not weighed in 45 days" value={stale.length} />
            </StatRow>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem]">
                <Panel flush title="All goats" actions={
                    <div className="flex flex-wrap gap-2">
                        <select aria-label="Show" className="field w-auto" value={view} onChange={(e) => setView(e.target.value)}><option value="all">All goats</option><option value="losing">Losing weight</option><option value="stale">Not weighed recently</option></select>
                        <input aria-label="Find goat" placeholder="Find goat" className="field w-40" value={query} onChange={(e) => setQuery(e.target.value)} />
                    </div>
                }>
                    {rows.length ? (
                        <div className="max-h-[62vh] overflow-auto">
                            <table className="data-table min-w-[560px]">
                                <thead><tr><th>Goat</th><th>Latest</th><th>Daily gain</th><th>Body score</th><th>Weighed</th></tr></thead>
                                <tbody>
                                    {rows.map((g) => (
                                        <tr key={g.id}>
                                            <td><GoatLabel goat={g} size={34} /></td>
                                            <td className="font-semibold whitespace-nowrap">{g.weight ? `${g.weight} KG` : '—'}</td>
                                            <td className={cx('whitespace-nowrap', (g.adg ?? 0) < 0 ? 'font-semibold text-crit-700' : 'text-ok-700')}>
                                                {g.adg === null ? '—' : <span className="inline-flex items-center gap-1">{g.adg < 0 ? <ArrowDownRight size={14} aria-hidden /> : <ArrowUpRight size={14} aria-hidden />}{g.adg} g/day</span>}
                                            </td>
                                            <td>{g.bodyScore ?? '—'}</td>
                                            <td className="text-ink-soft">{g.weighedOn}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : <Empty title="No goats in this view" />}
                </Panel>

                <div className="space-y-4">
                    <Panel title="Record a weight">
                        <form onSubmit={(e) => { e.preventDefault(); form.post('/weights', { preserveScroll: true, onSuccess: () => form.reset('weight_kg', 'body_score', 'height_in') }); }} className="space-y-3">
                            <Field label="Goat" error={form.errors.animal_id}><select className="field" value={form.data.animal_id} onChange={(e) => form.setData('animal_id', e.target.value)}>{goats.map((g) => <option key={g.id} value={g.id}>{g.number}{g.name ? ` · ${g.name}` : ''}</option>)}</select></Field>
                            <Field label="Weight (KG)" error={form.errors.weight_kg}><input inputMode="decimal" className="field" value={form.data.weight_kg} onChange={(e) => form.setData('weight_kg', e.target.value)} /></Field>
                            <div className="grid grid-cols-2 gap-2">
                                <Field label="Body score 1–5" error={form.errors.body_score} hint="1 thin · 3 good · 5 fat"><select className="field" value={form.data.body_score} onChange={(e) => form.setData('body_score', e.target.value)}><option value="">Not scored</option>{[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}</select></Field>
                                <Field label="Height (inches)" error={form.errors.height_in}><input inputMode="decimal" className="field" value={form.data.height_in} onChange={(e) => form.setData('height_in', e.target.value)} /></Field>
                            </div>
                            <Field label="Date" error={form.errors.measured_on}><input type="date" className="field" value={form.data.measured_on} onChange={(e) => form.setData('measured_on', e.target.value)} /></Field>
                            <Button type="submit" className="w-full" disabled={form.processing}>Save weight</Button>
                        </form>
                    </Panel>
                    <Panel title="Average weight by breed"><Chart option={chart} label="Average weight by breed and month" height={270} /></Panel>
                </div>
            </div>

            {/* D-078: workbook "Weight Log": previous weight, days, gain and ADG are worked out, never typed. */}
            <Panel flush title="Weight log" description="Every weighing, newest first" className="mt-4">
                {log.length ? (
                    <div className="overflow-x-auto">
                        <table className="data-table min-w-[720px]">
                            <thead><tr><th>Date</th><th>Goat</th><th>Weight</th><th>Previous</th><th>Days</th><th>Gain</th><th>ADG g/day</th><th>Body score</th><th>Height</th><th>By</th><th /></tr></thead>
                            <tbody>
                                {log.slice(0, logShown).map((w) => (
                                    <Fragment key={w.id}>
                                    <tr>
                                        <td className="whitespace-nowrap">{w.on}</td><td><a href={`/goats/${w.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{w.goat}</a></td>
                                        <td className="font-semibold">{w.kg} KG</td><td>{w.previous !== null ? `${w.previous} KG` : '—'}</td><td>{w.days ?? '—'}</td>
                                        <td className={cx((w.gain ?? 0) < 0 && 'font-semibold text-crit-700')}>{w.gain !== null ? `${w.gain > 0 ? '+' : ''}${w.gain} KG` : '—'}</td>
                                        <td className={cx((w.adg ?? 0) < 0 ? 'font-semibold text-crit-700' : 'text-ok-700')}>{w.adg ?? '—'}</td>
                                        <td>{w.score ?? '—'}</td><td>{w.heightIn !== null ? `${w.heightIn} in` : '—'}</td><td className="text-ink-soft">{w.by}{w.context && w.context !== 'routine' ? ` · ${w.context}` : ''}</td>
                                        <td>{w.canEdit ? <button type="button" onClick={() => setFixing(fixing === w.id ? null : w.id)} className="text-sm font-semibold text-turq-800 hover:underline">{fixing === w.id ? 'Close' : 'Edit'}</button> : null}</td>
                                    </tr>
                                    {fixing === w.id ? <tr><td colSpan={11} className="bg-glaze"><FixWeight w={w} onDone={() => setFixing(null)} /></td></tr> : null}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                        {log.length > logShown ? <button type="button" onClick={() => setLogShown(logShown + 25)} className="w-full px-4 py-2 text-sm font-semibold text-cobalt-800 hover:bg-glaze">Show more</button> : null}
                    </div>
                ) : <p className="p-4 text-sm text-ink-soft">Weighings appear here.</p>}
            </Panel>
        </>
    );
}

/** D-099: correct a weighing (the person who weighed, or the administrator). The old values stay in the goat's history. */
function FixWeight({ w, onDone }: { w: LogRow; onDone: () => void }) {
    const form = useForm({ measured_on: w.dateIso, weight_kg: String(w.kg), body_score: w.score ? String(w.score) : '', reason: '' });
    const e = form.errors as Record<string, string>;
    return (
        <form className="flex flex-wrap items-end gap-2 py-1" onSubmit={(x) => { x.preventDefault(); form.post(`/weights/${w.id}`, { preserveScroll: true, onSuccess: onDone }); }}>
            <Field label="Date" error={e.measured_on}><input type="date" className="field w-auto" value={form.data.measured_on} onChange={(x) => form.setData('measured_on', x.target.value)} /></Field>
            <Field label="Weight KG" error={e.weight_kg}><input className="field w-24" inputMode="decimal" value={form.data.weight_kg} onChange={(x) => form.setData('weight_kg', x.target.value)} /></Field>
            <Field label="Body score"><select className="field w-auto" value={form.data.body_score} onChange={(x) => form.setData('body_score', x.target.value)}><option value="">Not set</option>{[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}</select></Field>
            <Field label="Why? (optional)"><input className="field w-56" value={form.data.reason} onChange={(x) => form.setData('reason', x.target.value)} placeholder="e.g. Typed 81 instead of 18" /></Field>
            <Button type="submit" disabled={form.processing}>Save correction</Button>
        </form>
    );
}
