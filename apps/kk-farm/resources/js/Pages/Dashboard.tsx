import { Head, Link, router, usePage, usePoll } from '@inertiajs/react';
import { LIVE_MS } from '../lib/live';
import { inScannerApp } from '../lib/native';
import { ChevronDown } from 'lucide-react';
import { useMemo, useState, type ReactNode } from 'react';
import Chart from '../components/LazyChart';
import { axis, FARM, GOAT_PATH } from '../lib/chartTheme';
import { CountUp, Empty, GoatLabel, monthLabel, PageHeader, Panel, pkrShort } from '../components/ui';
import type { Health, SharedProps } from '../types';
import { titleCase } from '../lib/titleCase';
import { useFive } from '../lib/useFive';
import { BalanceTag, type Balance, type Requirement } from '../components/FeedRequirement';
import FarmScene, { EarTag, GoatFigure } from '../components/FarmScene';
import { FarmStat, FarmStatRow } from '../components/FarmStats';
import FeedScene from '../components/FeedScene';

type Meal = 'morning' | 'midday' | 'evening';
type FeedStatus = 'done' | 'late' | 'missed' | 'due' | 'upcoming' | 'none';
type Feeding = { meal: Meal; meals: Record<Meal, string>; times: Record<Meal, string>; plannedKg: number; givenKg: number; cost: number; lowStock: string[]; overPens: number;
    pens: Array<{ id: number; name: string; goats: number; onMilk: number; dayPlanned: number; dayGiven: number; over: boolean; diff: number; meals: Record<Meal, { status: FeedStatus; planned: number; given: number; balance: Balance; diff: number }> }> };
type ActionSummary = { key: string; label: string; count: number; severity: 'critical' | 'warning' | 'info' };
type HealthLinks = { checkupsDue: number; openReports: number; canCheck: boolean; canCase: boolean; canCare: boolean };
type Props = { pensSummary: PensSummary | null; requirement: FeedSummary | null; feeding: Feeding | null; herd: Array<{ key: string; label: string; value: number }>; herdAge: HerdAge;
    stats: Record<string, number>;
    attention: Array<GoatRef & { title: string | null; on: string | null }>;
    missing: Array<GoatRef & { missing: string[]; source: string }>;
    kiddings: Array<{ goatId: string; number: string; name: string | null; on: string; days: number }>;
    breeds: Array<{ name: string; value: number }>;
    growth: Array<{ month: string; kg: number }>;
    finance: Array<{ month: string; in: number; out: number }> | null;
};

type GoatRef = { goatId: string; number: string; name: string | null; coat: string; sex: 'male' | 'female'; health: Health; flag: string | null };

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;
const FLAG: Record<string, string> = { monitor: 'On watch', sick: 'Sick', quarantine: 'In quarantine', recovering: 'Recovering' };

/** D-071: lists show 5 items, then "Show more". */
function ShowMore<T>({ items, render, empty, limit = 5 }: { items: T[]; render: (item: T) => ReactNode; empty?: ReactNode; limit?: number }) {
    const [open, setOpen] = useState(false);
    if (!items.length) return <>{empty ?? null}</>;
    const shown = open ? items : items.slice(0, limit);
    return (
        <>
            {shown.map(render)}
            {items.length > limit ? (
                <li className="list-none">
                    <button type="button" onClick={() => setOpen(!open)} className="flex w-full items-center justify-center gap-1 px-4 py-2 text-sm font-semibold text-cobalt-800 hover:bg-glaze">
                        <ChevronDown size={15} className={open ? 'rotate-180' : ''} aria-hidden />{open ? 'Show Less' : `Show ${items.length - limit} More`}
                    </button>
                </li>
            ) : null}
        </>
    );
}


/**
 * D-070: the dashboard shows what needs a decision today. Headline numbers use the same rules as the categories,
 * empty boxes and charts with too little data are left out, and the per-goat herd wall lives on the Sheds page.
 */
export default function Dashboard({ pensSummary, requirement, stats, feeding, herd, herdAge, attention, missing, kiddings, breeds, growth, finance }: Props) {
    const { auth } = usePage<SharedProps>().props;
    // 30 s keeps the dashboard current without keeping the gun's processor busy.
    usePoll(LIVE_MS, { only: ['pensSummary', 'herdAge', 'requirement', 'stats', 'herd', 'feeding', 'attention', 'missing', 'kiddings'] });

    const growthChart = useMemo(() => (growth.length >= 2 ? {
        tooltip: { trigger: 'axis', valueFormatter: (v: number) => `${v} KG` },
        xAxis: axis.category(growth.map((g) => monthLabel(g.month))),
        yAxis: { ...axis.value((v) => `${v} KG`), min: 0 }, // D-105: weight graphs start at 0 KG
        // D-139: an earth-brown line over green grass
        series: [{ type: 'line', data: growth.map((g) => g.kg), smooth: true, symbol: 'circle', symbolSize: 8, itemStyle: { color: FARM.earth, borderColor: '#fff8e6', borderWidth: 2 }, lineStyle: { width: 3, color: FARM.earth },
            areaStyle: { color: { type: 'linear', x: 0, y: 0, x2: 0, y2: 1, colorStops: [{ offset: 0, color: 'rgba(122,166,74,0.55)' }, { offset: 1, color: 'rgba(95,143,58,0.12)' }] } } }],
    } : null), [growth]);

    const breedChart = useMemo(() => {
        if (breeds.length < 2) return null;
        const rows = [...breeds].reverse();
        return {
            tooltip: { trigger: 'axis', axisPointer: { type: 'none' } },
            grid: { left: 4, right: 30, top: 4, bottom: 4, containLabel: true },
            xAxis: { type: 'value', show: false },
            yAxis: { ...axis.category(rows.map((b) => b.name)), axisLine: { show: false } },
            // D-139: each breed is a row of goats; a longer row is more goats
            series: [{ type: 'pictorialBar', symbol: GOAT_PATH, symbolRepeat: true, symbolSize: [22, 18], symbolMargin: 2, symbolClip: false, barCategoryGap: '30%',
                data: rows.map((b, i) => ({ value: b.value, itemStyle: { color: i % 2 ? FARM.earth : '#a0703f' } })),
                label: { show: true, position: 'right', color: FARM.ink, fontWeight: 700 } }],
        };
    }, [breeds]);

    const financeChart = useMemo(() => (finance && finance.length ? {
        tooltip: { trigger: 'axis', valueFormatter: (v: number) => pkrShort(v) },
        legend: { bottom: 0, itemWidth: 12, itemHeight: 10 },
        grid: { left: 8, right: 12, top: 14, bottom: 30, containLabel: true },
        xAxis: axis.category(finance.map((f) => monthLabel(f.month))),
        yAxis: axis.value((v) => `${v / 1000}k`),
        series: [
            { name: 'Income', type: 'bar', data: finance.map((f) => f.in), itemStyle: { color: FARM.grass, borderRadius: [4, 4, 0, 0], borderColor: '#3f6a26', borderWidth: 1 } },
            { name: 'Spending', type: 'bar', data: finance.map((f) => f.out), itemStyle: { color: FARM.wheat, borderRadius: [4, 4, 0, 0], borderColor: '#9a7020', borderWidth: 1 } },
        ],
    } : null), [finance]);

    const total = herd.find((h) => h.key === 'total');
    const charts = [growthChart, breedChart, financeChart].filter(Boolean).length;
    // D-088: on the RFID gun charts wait for a tap; drawing them is the heaviest work on its processor.
    const [showCharts, setShowCharts] = useState(() => !inScannerApp());

    return (
        <>
            <Head title="Dashboard" />
            <div className="max-sm:hidden"><PageHeader title="Herd today" description={`Signed in as ${auth?.roleLabel}. Updates by itself every ${LIVE_MS / 1000} seconds.`} /></div>

            {/* D-121: actions are in the notification bell; health + buttons on Veterinary & Health. */}

            <FarmStatRow>
                {/* D-119: a number that is zero is not shown. */}
                <FarmStat icon="herd" href="/goats" label="Goats on farm" value={stats.goats} note={stats.noPen ? `${plural(stats.noPen, 'goat', 'goats')} not in a pen` : [stats.does ? plural(stats.does, 'female', 'females') : null, stats.bucks ? plural(stats.bucks, 'male', 'males') : null].filter(Boolean).join(', ')} tone={stats.noPen ? 'alert' : undefined} />
                {stats.unwell || stats.overdueCare ? <FarmStat icon="attention" href="/goats?show=attention" label="Need attention" value={stats.unwell} note={stats.overdueCare ? `${plural(stats.overdueCare, 'goat', 'goats')} overdue for care` : 'Sick or on watch'} tone="alert" /> : null}
                {stats.pregnant ? <FarmStat icon="pregnant" href="/goats?show=pregnant" label="Pregnant" value={stats.pregnant} note="Does" /> : null}
                {feeding ? <FarmStat icon="meals" href="/feeding" label="Meals fed today" value={`${stats.feedingDone}/${stats.feedingTotal}`} note={stats.feedingTotal ? 'Pen meals due so far' : 'None due yet'} tone={stats.feedingTotal && stats.feedingDone < stats.feedingTotal ? 'alert' : undefined} /> : null}
                {stats.tasksTotal ? <FarmStat icon="tasks" href="/tasks" label="Tasks today" value={`${stats.tasksDone}/${stats.tasksTotal}`} note="Done" /> : null}
            </FarmStatRow> {/* D-136: wooden signs */}

            {/* D-118: the herd by age and gender (and by breed); health is in Needs Attention. */}
            {total && total.value ? <HerdByAge h={herdAge} /> : <div className="mt-4"><Empty title="No goats yet">Scan a tag or open the Goat Register to add the first goat.</Empty></div>}

            {pensSummary && pensSummary.sheds.length ? <PensAtAGlance data={pensSummary} /> : null}

            {feeding ? <div className="mt-4">
                {feeding ? <FeedingToday feeding={feeding} /> : null}
                
            </div> : null}

            {requirement && requirement.sheds.length ? <FeedAtAGlance req={requirement} /> : null}

            {/* D-072: charts, activity and kiddings share one two-column grid, so no panel stretches alone across a wide screen. */}
            {missing.length || charts || kiddings.length ? (
                <div className="mt-4 grid items-start gap-4 lg:grid-cols-2 lg:[&>*:last-child:nth-child(odd)]:col-span-2">
                    {missing.length ? (
                <Panel title="Missing data" description="No pen, no weight or a wrong birth date · where each came from" flush
                    actions={<Link href="/goats?show=missing" className="text-sm font-semibold text-turq-800 hover:underline">Open as table</Link>}>
                    {/* D-138: the farm's to-fix clipboard: yellow ear tags, red pencil circles, a wooden Fix button. */}
                    <div className="kk-clipboard">
                        <span className="kk-clip" aria-hidden />
                        <ul className="kk-lined">
                            <ShowMore items={missing} render={(m) => (
                                <li key={m.goatId} className="flex flex-wrap items-center gap-x-3 gap-y-1.5 py-2">
                                    <Link href={`/goats/${m.goatId}`} className="flex min-w-fit flex-1 items-center gap-2.5">
                                        <EarTag number={m.number} />
                                        <span className="min-w-0">
                                            {m.name ? <span className="block truncate font-semibold text-[#3b2e1a]">{m.name}</span> : null}
                                            <span className="block truncate text-xs text-[#6b5434]">From: {m.source}</span>
                                        </span>
                                    </Link>
                                    <span className="flex flex-wrap gap-1.5">{m.missing.map((x) => <span key={x} className="kk-pencil">{x}</span>)}</span>
                                    <Link href={`/goats/${m.goatId}`} className="kk-fix kk-lift">Fix</Link>
                                </li>
                            )} />
                        </ul>
                    </div>
                </Panel>
                    ) : null}
                    {charts && !showCharts ? <Panel title="Charts" description="Average weight, herd by breed, income and spending"><button type="button" onClick={() => setShowCharts(true)} className="kk-fix kk-lift w-full py-2.5">Show Charts</button></Panel> : null}
                    {growthChart && showCharts ? <Panel title="Average weight" description="All weighings, by month"><div className="kk-graph"><Chart option={growthChart} label="Average herd weight by month" height={220} onSelect={() => router.visit('/weights')} /></div></Panel> : null}
                    {breedChart && showCharts ? <Panel title="Herd by breed" description="Goats on the farm · tap a bar to see them"><div className="kk-graph"><Chart option={breedChart} label="Goats by breed" height={Math.max(110, breeds.length * 36 + 24)} onSelect={(name) => router.visit(`/goats?breed=${encodeURIComponent(name)}`)} /></div></Panel> : null}
                    {financeChart && showCharts ? <Panel title="Income and spending" description="Last 6 months"><div className="kk-graph"><Chart option={financeChart} label="Monthly income and spending" height={220} onSelect={() => router.visit('/finance')} /></div></Panel> : null}
                    {kiddings.length ? (
                        <Panel title="Next kiddings" flush description="Mating date + gestation days">
                            {/* D-140: the barn's kidding calendar: a tear-off date, the doe's ear tag and a countdown with a newborn kid. */}
                            <ul className="kk-kidding-board">
                                <ShowMore items={kiddings} render={(k) => {
                                    const [day, ...rest] = k.on.split(' ');
                                    const soon = k.days >= 0 && k.days <= 10;
                                    return (
                                        <li key={k.goatId}>
                                            <Link href={`/goats/${k.goatId}`} className="kk-kidding-row kk-lift">
                                                <span className={`kk-cal ${k.days < 0 ? 'kk-cal-over' : soon ? 'kk-cal-soon' : ''}`}><span className="kk-cal-month">{rest.join(' ') || ' '}</span><span className="kk-cal-day">{day}</span></span>
                                                <span className="flex min-w-fit flex-1 items-center gap-2"><EarTag number={k.number} />{k.name ? <span className="truncate font-semibold text-[#3b2e1a]">{k.name}</span> : null}</span>
                                                <span className={`kk-countdown ${k.days < 0 ? 'kk-countdown-over' : soon ? 'kk-countdown-soon' : ''}`}>
                                                    <span className="kk-kid" aria-hidden><GoatFigure female flag={false} delay={0} flip={false} /></span>
                                                    {k.days < 0 ? `${-k.days} days over` : k.days === 0 ? 'Today' : `In ${k.days} days`}
                                                </span>
                                            </Link>
                                        </li>
                                    );
                                }} />
                            </ul>
                        </Panel>
                    ) : null}
                </div>
            ) : null}
        </>
    );
}


/** D-137: a meal's status written on the feeding chalkboard. */
function ChalkMark({ status }: { status: FeedStatus }) {
    const label = FEED_STATUS[status].label;
    const mark = status === 'done' ? <svg viewBox="0 0 20 16" className="h-4 w-5"><path d="M2 9l5 5L18 2" stroke="#a8e07a" strokeWidth="2.6" fill="none" strokeLinecap="round" strokeLinejoin="round" /></svg>
        : status === 'missed' ? <svg viewBox="0 0 16 16" className="size-4"><path d="M3 3l10 10M13 3L3 13" stroke="#ff8a7a" strokeWidth="2.6" strokeLinecap="round" /></svg>
            : status === 'late' ? <svg viewBox="0 0 16 16" className="size-4"><circle cx="8" cy="8" r="6" stroke="#ffd36b" strokeWidth="2" fill="none" /><path d="M8 4.5V8l2.5 1.5" stroke="#ffd36b" strokeWidth="2" fill="none" strokeLinecap="round" /></svg>
                : null;
    return (
        <span className={`kk-chalk-mark kk-chalk-${status}`}>{mark}{status === 'none' ? '—' : label}</span>
    );
}

/** D-137: morning sunrise, midday sun, evening sunset, drawn in chalk. */
function MealSun({ meal }: { meal: Meal }) {
    if (meal === 'midday') return <svg viewBox="0 0 20 20" className="size-4 shrink-0" aria-hidden><circle cx="10" cy="10" r="4" fill="#ffe08a" />{[0, 45, 90, 135, 180, 225, 270, 315].map((a) => <line key={a} x1="10" y1="1.5" x2="10" y2="4" stroke="#ffe08a" strokeWidth="1.6" strokeLinecap="round" transform={`rotate(${a} 10 10)`} />)}</svg>;
    return (
        <svg viewBox="0 0 20 20" className="size-4 shrink-0" aria-hidden>
            <path d="M4 14a6 6 0 0 1 12 0Z" fill={meal === 'morning' ? '#ffd27a' : '#ff9e6b'} />
            {[-60, -30, 0, 30, 60].map((a) => <line key={a} x1="10" y1="3" x2="10" y2="5.5" stroke={meal === 'morning' ? '#ffd27a' : '#ff9e6b'} strokeWidth="1.5" strokeLinecap="round" transform={`rotate(${a} 10 14)`} />)}
            <line x1="1.5" y1="14.5" x2="18.5" y2="14.5" stroke="#f3f0e6" strokeWidth="1.4" strokeLinecap="round" />
        </svg>
    );
}

const FEED_STATUS: Record<FeedStatus, { label: string; className: string }> = {
    done: { label: 'Fed', className: 'bg-ok-100 text-ok-700' },
    late: { label: 'Late', className: 'bg-watch-100 text-watch-700' },
    missed: { label: 'Missed', className: 'bg-crit-100 text-crit-700' },
    due: { label: 'Due', className: 'bg-cobalt-100 text-cobalt-800' },
    upcoming: { label: 'Later', className: 'bg-glaze text-ink-soft' },
    none: { label: '—', className: 'bg-glaze text-ink-faint' },
};

/** D-069: today's feeding on the dashboard — only pens that have goats, each meal's status and KG given of planned. */
function FeedingToday({ feeding }: { feeding: Feeding }) {
    const meals = Object.keys(feeding.meals) as Meal[];
    const kg = (n: number) => `${Math.round(n * 10) / 10} KG`;
    const [all, setAll] = useState(false);
    const pens = all ? feeding.pens : feeding.pens.slice(0, 5);

    return (
        <Panel title="Feeding today" description={`${kg(feeding.givenKg)} given of ${kg(feeding.plannedKg)} planned · PKR ${feeding.cost.toLocaleString('en-PK')}`}
            actions={<Link href="/feeding" className="text-sm font-semibold text-turq-800 hover:underline">Feed now</Link>}>
            {feeding.lowStock.length ? <p className="kk-paper-note mb-3 text-sm">Low or out of stock: {feeding.lowStock.join(', ')}</p> : null}
            {feeding.overPens ? <p className="kk-paper-note kk-paper-alert mb-3 text-sm font-semibold">{plural(feeding.overPens, 'pen was', 'pens were')} given more feed today than its goats need now. Plans follow the goats, so a move after feeding shows here.</p> : null}
            {feeding.pens.length ? (
                <div className="kk-chalkboard"> {/* D-137: the farm's feeding chalkboard */}
                {/* Phones and the gun: one row per pen with its three meals side by side. */}
                <ul className="kk-chalk-rows sm:hidden">
                    {pens.map((p) => (
                        <li key={p.id} className="py-2.5">
                            <div className="flex items-baseline justify-between gap-2">
                                <Link href="/feeding" className="kk-chalk-pen">{p.name}</Link>
                                <span className="kk-chalk-soft text-xs">{p.goats} goats{p.onMilk ? ` · ${p.onMilk} on milk` : ''} · {kg(p.dayGiven)} / {kg(p.dayPlanned)} today</span>
                            </div>
                            <div className="mt-1.5 grid grid-cols-3 gap-1.5">
                                {meals.map((m) => {
                                    const x = p.meals[m];
                                    return (
                                        <div key={m} className={`kk-chalk-cell ${m === feeding.meal ? 'kk-chalk-now' : ''}`}>
                                            <div className="flex items-center gap-1 text-[11px] font-semibold"><MealSun meal={m} />{feeding.meals[m]}</div>
                                            <ChalkMark status={x.status} />
                                            <div className="kk-chalk-soft text-[11px]">{x.planned || x.given ? `${kg(x.given)} / ${kg(x.planned)}` : '—'}</div>
                                            <BalanceTag balance={x.balance} diff={x.diff} className="mt-0.5 !px-1 !text-[10px]" />
                                        </div>
                                    );
                                })}
                            </div>
                        </li>
                    ))}
                </ul>
                <div className="hidden overflow-x-auto sm:block">
                    <table className="w-full min-w-[34rem] text-sm">
                        <thead className="text-left">
                            <tr>
                                <th className="py-1.5 pr-3 font-semibold">Pen</th>
                                {meals.map((m) => <th key={m} className={`px-2 py-1.5 font-semibold ${m === feeding.meal ? 'kk-chalk-now-head' : ''}`}><span className="inline-flex items-center gap-1.5"><MealSun meal={m} />{feeding.meals[m]} <span className="kk-chalk-soft font-normal">{feeding.times[m]}</span></span></th>)}
                            </tr>
                        </thead>
                        <tbody className="kk-chalk-body">
                            {pens.map((p) => (
                                <tr key={p.id}>
                                    <td className="py-2 pr-3">
                                        <Link href="/feeding" className="kk-chalk-pen hover:underline">{p.name}</Link>
                                        <div className="kk-chalk-soft text-xs">{p.goats} goats{p.onMilk ? ` · ${p.onMilk} on milk` : ''} · {kg(p.dayGiven)} / {kg(p.dayPlanned)} today</div>
                                    </td>
                                    {meals.map((m) => {
                                        const x = p.meals[m];
                                        return (
                                            <td key={m} className="px-2 py-2">
                                                <ChalkMark status={x.status} />
                                                {x.planned || x.given ? <div className="kk-chalk-soft mt-0.5 text-xs">{kg(x.given)} / {kg(x.planned)}</div> : null}
                                                <BalanceTag balance={x.balance} diff={x.diff} className="mt-0.5" />
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {feeding.pens.length > 5 ? (
                    <button type="button" onClick={() => setAll(!all)} className="kk-chalk-more mt-1 flex w-full items-center justify-center gap-1 rounded-tile py-2 text-sm font-semibold">
                        <ChevronDown size={15} className={all ? 'rotate-180' : ''} aria-hidden />{all ? 'Show less' : `Show ${feeding.pens.length - 5} more pens`}
                    </button>
                ) : null}
                </div>
            ) : <p className="text-sm text-ink-soft">No goats in any pen yet.</p>}
        </Panel>
    );
}

type HerdAge = {
    rows: Array<{ key: string; label: string; range: string; female: number; male: number; total: number }>; female: number; male: number; total: number;
    classes: Array<{ key: string; label: string; local: string | null; female: number; male: number; total: number }>;
};

/** D-135: how big the goat beside each age row is drawn. */
const AGE_SCALE: Record<string, number> = { kid: 0.62, grower: 0.82, adult: 1, age_unknown: 0.82 };

/** D-118: Kids, Growers and Adults by gender, from each goat's age today; every number opens those goats. */
function HerdByAge({ h }: { h: HerdAge }) {
    const [view, setView] = useState<'age' | 'class'>('age');
    const cell = (key: string, n: number, strong = false) => n
        ? <Link href={`/goats?show=${key}`} className={`block rounded-[4px] px-2 py-1 tabular-nums hover:bg-glaze hover:underline ${strong ? 'font-bold text-cobalt-900' : 'font-semibold text-cobalt-800'}`}>{n}</Link>
        : <span className="block px-2 py-1" aria-label="none" />;
    const pct = (n: number) => (h.total ? Math.round((n / h.total) * 100) : 0);
    // D-119: rows and columns with nothing in them are not shown.
    const rows = h.rows.filter((r) => r.total > 0);
    const [classes, moreClasses] = useFive(h.classes);
    const showF = h.female > 0;
    const showM = h.male > 0;
    return (
        <Panel title={view === 'age' ? 'Herd by Age and Gender' : 'Herd by Classification'} description={`${plural(h.total, 'goat', 'goats')} on the farm${showF && showM ? ` · ${h.female} female, ${h.male} male` : showF ? ', all female' : ', all male'}`} className="mt-4"
            actions={<div className="flex gap-1" role="group" aria-label="View">{(['age', 'class'] as const).map((v) => <button key={v} type="button" onClick={() => setView(v)} className={`rounded-tile px-2.5 py-1 text-sm font-semibold ${view === v ? 'bg-cobalt-800 text-white' : 'text-cobalt-800 hover:bg-glaze'}`}>{v === 'age' ? 'By Age' : 'By Classification'}</button>)}</div>}>
            {/* D-135: the herd on the farm's notice board: goat figures sized by age, white for female, brown for male. */}
            <div className="kk-noticeboard overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="text-left text-xs text-[#4a3a22]"><tr><th className="py-1.5 pr-3 font-semibold">{view === 'age' ? 'Age' : 'Classification'}</th>{showF ? <th className="px-2 text-right font-semibold"><span className="inline-flex items-center gap-1"><span className="kk-th-goat"><GoatFigure female flag={false} delay={0} flip={false} /></span>Female</span></th> : null}{showM ? <th className="px-2 text-right font-semibold"><span className="inline-flex items-center gap-1"><span className="kk-th-goat"><GoatFigure female={false} flag={false} delay={0} flip={false} /></span>Male</span></th> : null}<th className="px-2 text-right font-semibold">Total</th><th className="hidden w-1/3 pl-3 font-semibold sm:table-cell">Share</th></tr></thead>
                    <tbody className="divide-y divide-rule">
                        {view === 'age' ? rows.map((r) => (
                            <tr key={r.key}>
                                <td className="py-1.5 pr-3"><span className="flex items-center gap-2"><span className="kk-age-goat" style={{ ['--s' as string]: AGE_SCALE[r.key] ?? 1 }} aria-hidden><GoatFigure female={r.female >= r.male} flag={false} delay={0} flip={false} /></span><span><span className="font-semibold text-cobalt-900">{r.label}</span><span className="block text-xs text-ink-soft">{r.range}</span></span></span></td>
                                {showF ? <td className="text-right">{cell(`${r.key}_female`, r.female)}</td> : null}
                                {showM ? <td className="text-right">{cell(`${r.key}_male`, r.male)}</td> : null}
                                <td className="text-right">{cell(r.key, r.total, true)}</td>
                                <td className="hidden pl-3 sm:table-cell"><div className="kk-rail" aria-hidden><div className="kk-grow kk-rail-f" style={{ width: `${pct(r.female)}%` }} /><div className="kk-grow kk-rail-m" style={{ width: `${pct(r.male)}%` }} /></div><span className="text-xs text-ink-soft">{pct(r.total)}%</span></td>
                            </tr>
                        )) : classes.map((c) => (
                            <tr key={c.key}>
                                <td className="py-1.5 pr-3"><span className="font-semibold text-cobalt-900">{c.label}</span>{c.local ? <span className="block text-xs text-ink-soft">{c.local}</span> : null}</td>
                                {showF ? <td className="text-right">{cell(`${c.key}`, c.female)}</td> : null}
                                {showM ? <td className="text-right">{cell(`${c.key}`, c.male)}</td> : null}
                                <td className="text-right">{cell(c.key, c.total, true)}</td>
                                <td className="hidden pl-3 sm:table-cell"><div className="kk-rail" aria-hidden><div className="kk-grow kk-rail-f" style={{ width: `${pct(c.female)}%` }} /><div className="kk-grow kk-rail-m" style={{ width: `${pct(c.male)}%` }} /></div><span className="text-xs text-ink-soft">{pct(c.total)}%</span></td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot className="border-t-2 border-[#b8935c] text-sm"><tr><td className="py-1.5 pr-3 font-bold text-cobalt-900">All Goats</td>{showF ? <td className="text-right">{cell('female', h.female, true)}</td> : null}{showM ? <td className="text-right">{cell('male', h.male, true)}</td> : null}<td className="text-right">{cell('total', h.total, true)}</td><td className="hidden sm:table-cell" /></tr></tfoot>
                </table>
                {view === 'class' ? moreClasses : null}
            </div>
        </Panel>
    );
}

type PensSummary = {
    sheds: Array<{ id: number; name: string; typeName: string | null; goats: number; pens: Array<{ id: number; name: string; code: string; capacity: number | null; goats: number; female: number; male: number; attention: number }> }>;
    noPen: number;
};

/** D-120: sheds, then their pens with how many goats; a pen opens its goats. Moving goats is on the Sheds page. */
function PensAtAGlance({ data }: { data: PensSummary }) {
    return (
        <Panel title="Sheds and Pens" description={`${plural(data.sheds.reduce((n, s) => n + s.goats, 0), 'goat', 'goats')} in ${plural(data.sheds.reduce((n, s) => n + s.pens.length, 0), 'pen', 'pens')}${data.noPen ? ` · ${data.noPen} not in a pen` : ''}`} className="mt-4" flush
            actions={<Link href="/sheds" className="text-sm font-semibold text-turq-800 hover:underline">Move Goats</Link>}>
            <FarmScene sheds={data.sheds} /> {/* D-133: drawn like the real farm */}
            {data.noPen ? <Link href="/goats?show=no_pen" className="block border-t border-rule px-4 py-2.5 text-sm font-semibold text-crit-700 hover:bg-glaze">{plural(data.noPen, 'goat', 'goats')} not in a pen →</Link> : null}
        </Panel>
    );
}


type FeedSummary = Omit<Requirement, 'sheds' | 'feeds'> & {
    sheds: Array<{ id: number | null; name: string; typeName: string | null; kgDay: number; cpDay: number; count: number;
        pens: Array<{ id: number | null; name: string; count: number; kgDay: number; cpDay: number; unplanned: number; balance: Balance; diff: number }> }>;
};

/** D-121: feed needed today like Sheds and Pens: totals, then sheds and pen cards; a pen opens its goats on Feeding → Requirement. */
function FeedAtAGlance({ req }: { req: FeedSummary }) {
    const s = req.supply;
    const pens = req.sheds.reduce((n, x) => n + x.pens.length, 0);
    return (
        <Panel className="mt-4" flush title="Feed Requirement Today" description={`${plural(req.planned, 'goat', 'goats')} in ${plural(pens, 'pen', 'pens')}${req.milk ? ` · ${req.milk} kids on milk` : ''}${req.missing ? ` · ${req.missing} not planned` : ''}`}
            actions={<Link href="/feeding?tab=requirement" className="text-sm font-semibold text-turq-800 hover:underline">Each Goat</Link>}>
            {/* D-134: drawn like the farm's feed store */}
            <FeedScene tracking={req.tracking} sheds={req.sheds} sacks={[
                { label: 'Today', value: `${s.needDay} KG`, note: req.cost ? `PKR ${req.cost.toLocaleString('en-PK')}` : undefined },
                { label: 'This Week', value: `${s.needWeek} KG` },
                { label: s.month, value: `${s.needMonth} KG`, note: `${s.needLeft} KG for ${s.daysLeft} days left` },
                ...(s.cpDay !== null ? [{ label: 'Crude Protein', value: `${s.cpDay} KG`, note: `${s.cpMonth} KG in ${s.month}`, tone: 'green' as const }] : []),
            ]} />
        </Panel>
    );
}

