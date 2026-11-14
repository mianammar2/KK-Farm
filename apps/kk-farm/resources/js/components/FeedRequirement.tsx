import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useFive } from '../lib/useFive';
import { Panel } from './ui';

/** D-079/D-121: the feed requirement by shed, pen and goat; on Feeding → Requirement (the dashboard shows a summary). */

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

export type Balance = 'over' | 'short' | 'ok' | null;
export type ReqGoat = { cp: number | null; cpKg: number; cpStandard: string | null; id: string; number: string; group: string | null; weight: number | null; state: 'planned' | 'milk' | 'no_ration' | 'no_weight'; rule: string; kgDay: number; kgMonth: number; min: number; max: number; feeds: string };
export type ReqPen = { id: number | null; name: string; kgDay: number; cpDay: number; kgMonth: number; givenToday: number; balance: Balance; diff: number; cost: number; count: number; unplanned: number; goats: ReqGoat[] };
export type ReqShed = { id: number | null; name: string; typeName: string | null; kgDay: number; cpDay: number; kgMonth: number; cost: number; count: number; feeds: Array<{ name: string; kg: number }>; pens: ReqPen[] };
export type Supply = { cpDay: number | null; cpMonth: number | null; cpMissing: number; needDay: number; needWeek: number; inStore: number; month: string; monthDays: number; daysLeft: number; needMonth: number; needLeft: number; shortMonth: number;
    days: number | null; unsplit: number; feeds: Array<{ name: string; needDay: number; stock: number; days: number | null; shortWeek: number; needMonth: number; shortMonth: number }> };
export type Requirement = { supply: Supply; tracking: boolean; kgDay: number; cost: number; kgMonth: number; costMonth: number; feeds: Array<{ name: string; kg: number }>; planned: number; milk: number; missing: number; sheds: ReqShed[] };

export function BalanceTag({ balance, diff, className = '' }: { balance: Balance; diff: number; className?: string }) {
    if (balance !== 'over' && balance !== 'short') return null;
    const kgText = `${Math.abs(Math.round(diff * 100) / 100)} KG`;
    return <span className={`inline-block rounded-[4px] px-1.5 py-0.5 text-xs font-semibold ${balance === 'over' ? 'bg-crit-100 text-crit-700' : 'bg-watch-100 text-watch-700'} ${className}`}>{balance === 'over' ? `Over by ${kgText}` : `Short ${kgText}`}</span>;
}


const REQ_STATE: Record<ReqGoat['state'], string> = { planned: '', milk: 'bg-cobalt-100 text-cobalt-800', no_ration: 'bg-crit-100 text-crit-700', no_weight: 'bg-watch-100 text-watch-700' };

/** D-079: what every goat needs to eat today, and where it is: shed → pen → goat, with totals and cost. */
export default function FeedRequirement({ req, focusPen = null }: { req: Requirement; focusPen?: number | null }) {
    // D-121: opened from a pen on the dashboard: its shed is open and the pen is scrolled into view.
    const focusShed = focusPen === null ? -1 : req.sheds.findIndex((s) => s.pens.some((p) => p.id === focusPen));
    const [open, setOpen] = useState<number | null | 'none'>(focusShed >= 0 ? req.sheds[focusShed].id : req.sheds.length === 1 ? req.sheds[0].id : 'none');
    const [sheds, moreSheds] = useFive(req.sheds, 5, focusShed >= 5);
    useEffect(() => { if (focusPen !== null) setTimeout(() => document.getElementById(`req-pen-${focusPen}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 150); }, [focusPen]);
    return (
        <Panel className="mt-4" title="Feed requirement today" description={`${req.kgDay} KG for ${plural(req.planned, 'goat', 'goats')}${req.cost ? ` · PKR ${req.cost.toLocaleString('en-PK')} a day` : ''} · ${req.kgMonth} KG${req.costMonth ? ` (PKR ${req.costMonth.toLocaleString('en-PK')})` : ''} in ${req.supply.month}${req.milk ? ` · ${req.milk} kids on milk` : ''}${req.missing ? ` · ${req.missing} not planned` : ''}`}
            actions={<Link href="/feeding?tab=rations" className="text-sm font-semibold text-turq-800 hover:underline">Rations</Link>} flush>
            <SupplyStrip s={req.supply} tracking={req.tracking} />
            {req.sheds.length ? (
                <ul className="divide-y divide-rule">
                    {sheds.map((s) => {
                        const isOpen = open === s.id;
                        return (
                            <li key={s.id ?? 'none'}>
                                <button type="button" onClick={() => setOpen(isOpen ? 'none' : s.id)} aria-expanded={isOpen} className="flex w-full flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-left hover:bg-glaze">
                                    <span className="min-w-0 flex-1">
                                        <span className="font-mono font-semibold text-cobalt-900">{s.name}</span>
                                        <span className="block text-xs text-ink-soft">{s.typeName ? `${s.typeName} · ` : ''}{plural(s.count, 'goat', 'goats')} · {s.pens.length} PEN{s.pens.length === 1 ? '' : 's'}</span>
                                    </span>
                                    <span className="hidden flex-wrap gap-1 md:flex">{s.feeds.slice(0, 3).map((f) => <span key={f.name} className="rounded-[4px] bg-glaze px-1.5 py-0.5 text-xs text-ink-soft">{f.name} {f.kg} KG</span>)}</span>
                                    <span className="text-right"><span className="block font-bold text-cobalt-900">{s.kgDay} KG/day</span><span className="block text-xs text-ink-soft">{req.supply.cpDay !== null ? `CP ${s.cpDay} KG/day · ` : ''}{s.kgMonth} KG this month{s.cost ? ` · PKR ${s.cost.toLocaleString('en-PK')}/day` : ''}</span></span>
                                    <ChevronDown size={16} className={`text-ink-faint transition-transform ${isOpen ? 'rotate-180' : ''}`} aria-hidden />
                                </button>
                                {isOpen ? <ShedPens pens={s.pens} focusPen={focusPen} /> : null}
                            </li>
                        );
                    })}
                </ul>
            ) : <p className="p-4 text-sm text-ink-soft">No goats on the farm yet.</p>}
            {moreSheds}
        </Panel>
    );
}

/** D-119: a shed's pens, the first 5 and the rest on a tap. */
function ShedPens({ pens, focusPen }: { pens: ReqPen[]; focusPen: number | null }) {
    const [shown, more] = useFive(pens, 5, pens.findIndex((p) => p.id === focusPen) >= 5);
    return <div className="space-y-3 bg-glaze/50 px-4 pb-3">{shown.map((p) => <ReqPenCard key={p.id ?? 'none'} pen={p} />)}{more}</div>;
}

function ReqPenCard({ pen }: { pen: ReqPen }) {
    const [all, setAll] = useState(false);
    const rows = all ? pen.goats : pen.goats.slice(0, 5);
    return (
        <div id={pen.id ? `req-pen-${pen.id}` : undefined} className="scroll-mt-20 rounded-tile border border-rule bg-tile">
            <div className="flex flex-wrap items-baseline justify-between gap-2 border-b border-rule px-3 py-2">
                <span className="font-mono text-sm font-semibold text-cobalt-900">{pen.name}</span>
                <span className="text-sm text-ink-soft">{plural(pen.count, 'goat', 'goats')}{pen.unplanned ? ` · ${pen.unplanned} not planned` : ''} · <strong className="text-cobalt-900">{pen.kgDay} KG/day</strong>{pen.cpDay ? ` · CP ${pen.cpDay} KG` : ''} · given today {pen.givenToday} KG <BalanceTag balance={pen.balance} diff={pen.diff} /> · {pen.kgMonth} KG this month{pen.cost ? ` · PKR ${pen.cost.toLocaleString('en-PK')}` : ''}</span>
            </div>
            <div className="overflow-x-auto">
                <table className="w-full min-w-[46rem] text-sm">
                    <thead className="text-left text-xs text-ink-soft"><tr><th className="px-3 py-1.5 font-semibold">Goat</th><th className="px-3 font-semibold">Group</th><th className="px-3 font-semibold">Weight</th><th className="px-3 font-semibold">Rule</th><th className="px-3 font-semibold">Per Day</th><th className="px-3 font-semibold">CP / Day</th><th className="px-3 font-semibold">This Month</th><th className="px-3 font-semibold">Feeds (KG)</th></tr></thead>
                    <tbody className="divide-y divide-rule">
                        {rows.map((g) => (
                            <tr key={g.id}>
                                <td className="px-3 py-1.5"><Link href={`/goats/${g.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{g.number}</Link></td>
                                <td className="px-3 text-ink-soft">{g.group ?? '—'}</td>
                                <td className="px-3">{g.weight ? `${g.weight} KG` : '—'}</td>
                                <td className="px-3">{g.state === 'planned' ? <span className="text-ink-soft">{g.rule}</span> : <span className={`rounded-[4px] px-1.5 py-0.5 text-xs font-semibold ${REQ_STATE[g.state]}`}>{g.rule}</span>}</td>
                                <td className="px-3 font-semibold text-cobalt-900">{g.state === 'planned' ? <>{g.kgDay} KG{g.max > g.min ? <span className="block text-xs font-normal text-ink-faint">{g.min}–{g.max}</span> : null}</> : '—'}</td>
                                <td className="px-3 text-cobalt-900">{g.state === 'planned' && g.cp !== null ? <>{Math.round(g.cpKg * 1000)} g<span className="block text-xs text-ink-faint" title={g.cpStandard ?? undefined}>{g.cp}% CP{g.cpStandard ? ` · ${g.cpStandard}` : ''}</span></> : '—'}</td>
                                <td className="px-3 text-cobalt-900">{g.state === 'planned' ? `${g.kgMonth} KG` : '—'}</td>
                                <td className="px-3 text-xs text-ink-soft">{g.feeds || '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {pen.goats.length > 5 ? <button type="button" onClick={() => setAll(!all)} className="w-full border-t border-rule px-3 py-1.5 text-sm font-semibold text-cobalt-800 hover:bg-glaze">{all ? 'Show less' : `Show ${pen.goats.length - 5} more`}</button> : null}
        </div>
    );
}

/** D-080: what the goats need against what is in the feed store. */
function SupplyStrip({ s, tracking }: { s: Supply; tracking: boolean }) {
    const [feeds, moreFeeds] = useFive(s.feeds);
    const short = tracking && s.days !== null && s.days < 7;
    const box = (label: string, value: string, note?: string, alert?: boolean) => (
        <div className={`rounded-tile px-3 py-2 ${alert ? 'bg-crit-100' : 'bg-glaze'}`}>
            <div className="text-xs text-ink-soft">{label}</div>
            <div className={`text-lg font-bold tabular-nums ${alert ? 'text-crit-700' : 'text-cobalt-900'}`}>{value}</div>
            {note ? <div className="text-xs text-ink-faint">{note}</div> : null}
        </div>
    );
    return (
        <div className="space-y-2 border-b border-rule px-4 py-3">
            <div className={`grid grid-cols-2 gap-2 sm:grid-cols-3 ${tracking ? 'lg:grid-cols-5' : s.cpDay !== null ? 'lg:grid-cols-4' : ''}`}>
                {box('Needed today', `${s.needDay} KG`)}
                {box('Needed this week', `${s.needWeek} KG`)}
                {box(`Needed in ${s.month}`, `${s.needMonth} KG`, `${s.needLeft} KG for the ${s.daysLeft} day${s.daysLeft === 1 ? '' : 's'} left${tracking && s.shortMonth ? ` · short ${s.shortMonth} KG` : ''}`, tracking && s.shortMonth > 0)}
                {s.cpDay !== null ? box('Crude Protein Today', `${s.cpDay} KG`, `${s.cpMonth} KG in ${s.month}${s.cpMissing ? ` · ${s.cpMissing} goats without a CP %` : ''}`) : null}
                {!tracking ? null : <>
                {box('In the feed store', `${s.inStore} KG`, undefined, s.needDay > 0 && s.inStore === 0)}
                {box('Store lasts', s.days === null ? '—' : s.days === 0 ? 'Not today' : `${s.days} day${s.days === 1 ? '' : 's'}`, short ? `Short ${Math.max(0, Math.round(s.needWeek - s.inStore))} KG for a week` : undefined, short)}
                </>}
            </div>
            {short ? <p className="rounded-tile bg-crit-100 px-3 py-2 text-sm font-semibold text-crit-700">{s.inStore === 0 ? 'No feed recorded in the store.' : 'Feed is running short.'} The goats need {s.needDay} KG a day: record a purchase in Feeding → Feed store.</p> : null}
            {tracking && s.unsplit > 0 ? <p className="text-xs text-ink-soft">{s.unsplit} KG a day is not split into feeds yet: set a ration (Feeding → Rations) to see which feed is short.</p> : null}
            {tracking && s.feeds.length ? (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[40rem] text-sm">
                        <thead className="text-left text-xs text-ink-soft"><tr><th className="py-1 font-semibold">Feed</th><th className="font-semibold">Needed a Day</th><th className="font-semibold">In Store</th><th className="font-semibold">Lasts</th><th className="font-semibold">Short for a Week</th><th className="font-semibold">Needed in {s.month}</th><th className="font-semibold">Short This Month</th></tr></thead>
                        <tbody className="divide-y divide-rule">{feeds.map((f) => <tr key={f.name}><td className="py-1 font-medium">{f.name}</td><td>{f.needDay} KG</td><td>{f.stock} KG</td><td className={f.days !== null && f.days < 7 ? 'font-semibold text-crit-700' : ''}>{f.days === null ? '—' : `${f.days} days`}</td><td className={f.shortWeek ? 'font-semibold text-crit-700' : 'text-ink-faint'}>{f.shortWeek ? `${f.shortWeek} KG` : '—'}</td><td>{f.needMonth} KG</td><td className={f.shortMonth ? 'font-semibold text-crit-700' : 'text-ink-faint'}>{f.shortMonth ? `${f.shortMonth} KG` : '—'}</td></tr>)}</tbody>
                    </table>
                    {moreFeeds}
                </div>
            ) : null}
        </div>
    );
}

