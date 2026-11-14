import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { Fragment, useState } from 'react';
import { usePaged } from '../../components/Pager';
import { cx, Empty, PageHeader, Panel } from '../../components/ui';

/** D-270: field-work reports: one row per date (how many goats and the day's numbers); open a date to see every goat. */
type Kind = 'weight' | 'temp' | 'height' | 'move';
type Day = { on: string; goats: number; by: string[]; avg: number | null; min: number | null; max: number | null; up: number; down: number; avgChange: number | null; fever: number | null; routes: Record<string, number> | null };
type Row = { on: string; goatId: string; number: string; value: number | null; change: number | null; days: number | null; by: string | null; bcs?: number | null; fever?: boolean; result?: string; from?: string | null; to?: string | null; reason?: string | null };
type Props = { kind: Kind; kinds: Record<string, string>; days: Day[] };

const UNIT: Record<Kind, string> = { weight: 'KG', temp: '°F', height: 'in', move: '' };
const TITLE: Record<Kind, string> = { weight: 'Weighings Report', temp: 'Temperature Report', height: 'Height Report', move: 'Movements Report' };
const ABOUT: Record<Kind, string> = {
    weight: 'Each day goats were weighed: how many, the average, and how many gained or lost since their weighing before.',
    temp: 'Each day temperatures were taken: how many goats, the average, and how many were outside the normal 101.3–103.5 °F.',
    height: 'Each day heights were measured: how many goats, the average, and how many grew.',
    move: 'Each day goats were moved between pens: how many, and the main moves.',
};
const date = (on: string) => new Date(`${on}T12:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
const signed = (n: number, unit: string) => `${n > 0 ? '+' : ''}${n} ${unit}`;

export default function Records({ kind, kinds, days }: Props) {
    const { rows, pager } = usePaged(days, { size: 25, id: `records-${kind}` });
    const [open, setOpen] = useState<string | null>(null);
    const [goats, setGoats] = useState<Record<string, Row[] | 'loading'>>({});
    const unit = UNIT[kind];
    const toggle = (on: string) => {
        setOpen(open === on ? null : on);
        if (!goats[on]) {
            setGoats((g) => ({ ...g, [on]: 'loading' }));
            fetch(`/reports/records/day?kind=${kind}&on=${on}`, { headers: { Accept: 'application/json' } }).then((r) => r.json()).then((d) => setGoats((g) => ({ ...g, [on]: d.rows })));
        }
    };
    const total = days.reduce((s, d) => s + d.goats, 0);
    const th = 'px-3 py-2.5 text-xs font-semibold whitespace-nowrap text-ink-soft';
    const td = 'px-3 py-3 align-middle';
    return (
        <>
            <Head title={TITLE[kind]} />
            <PageHeader title={TITLE[kind]} description={ABOUT[kind]} />
            <div className="mb-4 inline-flex flex-wrap overflow-hidden rounded-tile border border-rule" role="tablist" aria-label="Report">
                {Object.entries(kinds).map(([k, label]) => (
                    <button key={k} type="button" role="tab" aria-selected={k === kind} onClick={() => router.get('/reports/records', { kind: k })}
                        className={cx('min-h-10 px-4 text-sm font-semibold', k === kind ? 'bg-cobalt-800 text-white' : 'bg-tile text-cobalt-800 hover:bg-glaze')}>{label}</button>
                ))}
            </div>
            <Panel flush title={`${days.length} ${days.length === 1 ? 'day' : 'days'} · ${total.toLocaleString('en-PK')} ${kind === 'move' ? 'moves' : 'records'}`} description="Click a date to see every goat that day.">
                {!days.length ? <Empty title="Nothing recorded yet" /> : (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[640px] border-collapse text-sm">
                            <thead className="border-b border-rule bg-glaze/60"><tr>
                                <th className={`${th} text-start`}>Date</th><th className={`${th} text-end`}>Goats</th>
                                {kind !== 'move' ? <><th className={`${th} text-end`}>Average</th><th className={`${th} text-end`}>Range</th></> : <th className={`${th} text-start`}>Main moves</th>}
                                {kind === 'weight' || kind === 'height' ? <><th className={`${th} text-end`}>{kind === 'weight' ? 'Gained' : 'Grew'}</th><th className={`${th} text-end`}>{kind === 'weight' ? 'Lost' : 'Shorter'}</th><th className={`${th} text-end`}>Average change</th></> : null}
                                {kind === 'temp' ? <th className={`${th} text-end`}>Outside normal</th> : null}
                                <th className={`${th} text-start`}>By</th>
                            </tr></thead>
                            <tbody className="divide-y divide-rule">{rows.map((d) => (
                                <Fragment key={d.on}>
                                    <tr className={cx('cursor-pointer hover:bg-glaze/40', open === d.on && 'bg-glaze/50')} onClick={() => toggle(d.on)} aria-expanded={open === d.on}>
                                        <td className={`${td} font-semibold whitespace-nowrap text-cobalt-900`}><span className="inline-flex items-center gap-1.5">{open === d.on ? <ChevronDown size={16} aria-hidden /> : <ChevronRight size={16} aria-hidden />}{date(d.on)}</span></td>
                                        <td className={`${td} text-end font-semibold tabular-nums`}>{d.goats}</td>
                                        {kind !== 'move' ? <>
                                            <td className={`${td} text-end tabular-nums`}>{d.avg !== null ? `${d.avg} ${unit}` : '—'}</td>
                                            <td className={`${td} text-end text-ink-soft tabular-nums whitespace-nowrap`}>{d.min !== null && d.max !== null ? (d.min === d.max ? `${d.min}` : `${d.min} – ${d.max}`) : '—'}</td>
                                        </> : <td className={`${td} text-ink-soft`}>{Object.entries(d.routes ?? {}).map(([r, n]) => `${r} (${n})`).join(', ')}</td>}
                                        {kind === 'weight' || kind === 'height' ? <>
                                            <td className={`${td} text-end font-semibold text-ok-700 tabular-nums`}>{d.up ? `▲ ${d.up}` : '—'}</td>
                                            <td className={`${td} text-end font-semibold text-crit-700 tabular-nums`}>{d.down ? `▼ ${d.down}` : '—'}</td>
                                            <td className={cx(td, 'text-end tabular-nums', d.avgChange !== null && d.avgChange > 0 ? 'text-ok-700' : d.avgChange !== null && d.avgChange < 0 ? 'text-crit-700' : 'text-ink-soft')}>{d.avgChange !== null ? signed(d.avgChange, unit) : '—'}</td>
                                        </> : null}
                                        {kind === 'temp' ? <td className={`${td} text-end`}>{d.fever ? <span className="rounded bg-crit-100 px-1.5 py-0.5 text-xs font-semibold text-crit-700">{d.fever}</span> : '—'}</td> : null}
                                        <td className={`${td} text-ink-soft`}>{d.by.join(', ')}</td>
                                    </tr>
                                    {open === d.on ? <tr><td colSpan={9} className="bg-glaze/30 px-3 pt-1 pb-4"><DayGoats kind={kind} rows={goats[d.on]} /></td></tr> : null}
                                </Fragment>
                            ))}</tbody>
                        </table>
                    </div>
                )}
                {pager}
            </Panel>
        </>
    );
}

function DayGoats({ kind, rows }: { kind: Kind; rows: Row[] | 'loading' | undefined }) {
    if (!rows || rows === 'loading') return <p className="py-3 text-sm text-ink-soft">Loading…</p>;
    const unit = UNIT[kind];
    const th = 'px-3 py-2 text-xs font-semibold whitespace-nowrap text-ink-soft';
    return (
        <table className="mt-2 w-full border-collapse rounded-tile bg-tile text-sm ring-1 ring-rule">
            <thead className="border-b border-rule"><tr>
                <th className={`${th} text-start`}>Goat</th>
                {kind === 'move' ? <><th className={`${th} text-start`}>From → To</th><th className={`${th} text-start`}>Reason</th></> : <><th className={`${th} text-end`}>{kind === 'weight' ? 'Weight' : kind === 'temp' ? 'Temperature' : 'Height'}</th><th className={`${th} text-end`}>Change</th></>}
                {kind === 'temp' ? <th className={`${th} text-start`}>Result</th> : null}
                <th className={`${th} text-start`}>By</th>
            </tr></thead>
            <tbody className="divide-y divide-rule">{rows.map((r, i) => (
                <tr key={`${r.goatId}-${i}`}>
                    <td className="px-3 py-2"><Link href={`/goats/${r.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{r.number}</Link></td>
                    {kind === 'move' ? <><td className="px-3 py-2">{r.from ?? '—'} → <b>{r.to ?? '—'}</b></td><td className="px-3 py-2 text-ink-soft">{r.reason ?? '—'}</td></> : <>
                        <td className="px-3 py-2 text-end font-semibold tabular-nums">{r.value !== null ? <span className={r.fever ? 'rounded bg-crit-100 px-1.5 py-0.5 text-crit-700' : ''}>{r.value} {unit}</span> : '—'}</td>
                        <td className={cx('px-3 py-2 text-end tabular-nums', kind === 'temp' ? 'text-ink' : r.change !== null && r.change > 0 ? 'text-ok-700' : r.change !== null && r.change < 0 ? 'text-crit-700' : 'text-ink-soft')}>{r.change !== null ? <>{r.change > 0 ? '▲ ' : r.change < 0 ? '▼ ' : ''}{signed(r.change, unit)}<span className="text-xs text-ink-soft"> in {r.days} {r.days === 1 ? 'day' : 'days'}</span></> : 'First'}</td>
                    </>}
                    {kind === 'temp' ? <td className="px-3 py-2">{r.result ?? '—'}</td> : null}
                    <td className="px-3 py-2 text-ink-soft">{r.by ?? '—'}</td>
                </tr>
            ))}</tbody>
        </table>
    );
}
