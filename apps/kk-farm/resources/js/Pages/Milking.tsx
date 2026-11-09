import { Head, Link, useForm } from '@inertiajs/react';
import { Fragment, useMemo, useState } from 'react';
import Chart from '../components/LazyChart';
import { axis, SERIES } from '../lib/chartTheme';
import { Button, Chip, cx, Empty, Field, Note, PageHeader, Panel, Stat, StatRow } from '../components/ui';

type Session = 'morning' | 'midday' | 'evening';
type Doe = {
    lactationId: number; id: string; number: string; name: string | null; breed: string | null; pen: string | null; lactation: number; started: string; startedIso: string; source: string;
    dim: number; avg7: number | null; total: number; last: string | null; todayBySession: Partial<Record<Session, number>>; high: boolean; pregnant: boolean;
    withdrawal: string | null; standard: string | null; cp: string | null; dryDue: boolean;
};
type Props = {
    today: string; sessions: Record<Session, string>; reasons: Record<string, string>;
    stats: { inMilk: number; today: number; todayDiscarded: number; weekAvg: number; month: number; monthDiscarded: number; monthName: string; high: number };
    chart: Array<{ day: string; kept: number; discarded: number }>;
    does: Doe[];
    dried: Array<{ id: string; number: string; lactation: number; started: string; dried: string; days: number; reason: string; by: string; total: number }>;
    settings: { highYield: number | null; dryOffDays: number | null };
    can: { record: boolean; manage: boolean };
};

const litres = (n: number | null | undefined) => (n === null || n === undefined ? '—' : `${(Math.round(n * 100) / 100).toLocaleString('en-PK')} L`);
const uuid = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = (Math.random() * 16) | 0; return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16); }));
const defaultSession = (): Session => { const h = new Date().getHours(); return h < 11 ? 'morning' : h < 15 ? 'midday' : 'evening'; };

/** D-117: does in milk after kidding, milk per session, discarded milk under withdrawal, drying off. */
export default function Milking(props: Props) {
    const { stats, does, dried, chart, settings, can } = props;
    const option = useMemo(() => ({
        grid: { left: 44, right: 12, top: 28, bottom: 26 }, tooltip: { trigger: 'axis', valueFormatter: (v: number) => `${v} L` }, legend: { top: 0, textStyle: { color: '#5e6a5f' } },
        xAxis: axis.category(chart.map((c) => c.day)), yAxis: axis.value(),
        series: [
            { name: 'Kept', type: 'bar', stack: 'milk', data: chart.map((c) => c.kept), color: SERIES[0] },
            { name: 'Discarded (withdrawal)', type: 'bar', stack: 'milk', data: chart.map((c) => c.discarded), color: '#c0392b' },
        ],
    }), [chart]);

    return (
        <>
            <Head title="Milking" />
            <PageHeader title="Milking" description="A doe that kids is in milk by herself: she moves to the Lactating Does feeding group and the Lactating nutrition standard. Record milk each session; dry her off when the lactation ends." />
            <StatRow>
                <Stat label="Does in Milk" value={stats.inMilk} note={stats.high ? `${stats.high} high-producing` : undefined} />
                <Stat label="Milk Today" value={litres(stats.today)} note={stats.todayDiscarded ? `${litres(stats.todayDiscarded)} discarded` : undefined} />
                <Stat label="7-Day Average" value={`${litres(stats.weekAvg)}/day`} />
                <Stat label={`Milk in ${stats.monthName}`} value={litres(stats.month)} note={stats.monthDiscarded ? `${litres(stats.monthDiscarded)} discarded (withdrawal)` : undefined} tone={stats.monthDiscarded ? 'alert' : undefined} />
            </StatRow>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="min-w-0 space-y-4">
                    {can.record ? <RecordMilk {...props} /> : null}
                    <DoesInMilk {...props} />
                    {chart.some((c) => c.kept || c.discarded) ? <Panel title="Milk per Day" description="Last 30 days"><Chart option={option} label="Milk per day, last 30 days" height={220} /></Panel> : null}
                    <Panel title="Recently Dried Off" flush>
                        {dried.length ? (
                            <ul className="divide-y divide-rule text-sm">{dried.map((d, i) => (
                                <li key={i} className="flex flex-wrap gap-x-3 px-4 py-2">
                                    <Link href={`/goats/${d.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{d.number}</Link>
                                    <span className="text-ink-soft">Lactation {d.lactation} · {d.started} to {d.dried} · {d.days} days · {litres(d.total)}</span>
                                    <span className="w-full text-xs text-ink-faint">{d.reason} · {d.by}</span>
                                </li>
                            ))}</ul>
                        ) : <p className="p-4 text-sm text-ink-soft">No doe has been dried off yet.</p>}
                    </Panel>
                </div>
                <div className="space-y-4">
                    {can.manage ? <StartForm /> : null}
                    {can.manage ? <SettingsForm settings={settings} /> : <Panel title="Milking Settings"><p className="text-sm text-ink-soft">High-producing from {settings.highYield ?? '—'} L/day · dry-off reminder after {settings.dryOffDays ?? '—'} days.</p></Panel>}
                </div>
            </div>
        </>
    );
}

function RecordMilk({ today, sessions, does }: Props) {
    const [on, setOn] = useState(today);
    const [session, setSession] = useState<Session>(defaultSession());
    const [values, setValues] = useState<Record<string, string>>({});
    const form = useForm<{ milked_on: string; session: Session; rows: Array<{ id: string; animal_id: string; litres: string }> }>({ milked_on: today, session, rows: [] });
    const eligible = does.filter((d) => d.startedIso <= on);
    const filled = eligible.filter((d) => values[d.id] !== undefined && values[d.id] !== '');
    const sum = filled.reduce((a, d) => a + Number(values[d.id] || 0), 0);
    const errors = form.errors as Record<string, string>;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform(() => ({ milked_on: on, session, rows: filled.map((d) => ({ id: uuid(), animal_id: d.id, litres: values[d.id] })) }));
        form.post('/milking/records', { preserveScroll: true, onSuccess: () => setValues({}) });
    };

    if (!does.length) return <Panel title="Record Milk"><Empty title="No does in milk">A doe is in milk from the day she kids. A doe bought in milk is entered when she is registered, or with Start a Lactation.</Empty></Panel>;
    return (
        <Panel title="Record Milk" description="Litres per doe for one session. Saving the same doe, day and session again corrects it; nothing is deleted." flush>
            <form onSubmit={submit}>
                <div className="flex flex-wrap items-end gap-3 border-b border-rule px-4 py-3">
                    <Field label="Date" error={errors.milked_on}><input className="field" type="date" max={today} value={on} onChange={(e) => setOn(e.target.value)} /></Field>
                    <div className="flex gap-1.5" role="group" aria-label="Session">{(Object.keys(sessions) as Session[]).map((s) => (
                        <button key={s} type="button" onClick={() => setSession(s)} className={cx('min-h-11 rounded-tile border px-3 text-sm font-semibold', session === s ? 'border-cobalt-800 bg-cobalt-800 text-white' : 'border-rule bg-tile text-cobalt-900')}>{sessions[s]}</button>
                    ))}</div>
                    <span className="ml-auto text-sm text-ink-soft">{filled.length} of {eligible.length} does · <strong className="text-cobalt-900">{litres(sum)}</strong></span>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[36rem] text-sm">
                        <thead className="text-left text-xs text-ink-soft"><tr><th className="px-4 py-2 font-semibold">Doe</th><th className="px-3 font-semibold">Pen</th><th className="px-3 font-semibold">Day in Milk</th><th className="px-3 font-semibold">7-Day Average</th><th className="px-3 font-semibold">Litres</th></tr></thead>
                        <tbody className="divide-y divide-rule">
                            {eligible.map((d, i) => {
                                const done = on === today ? d.todayBySession[session] : undefined;
                                return (
                                    <tr key={d.id} className={cx(d.withdrawal && 'bg-crit-100/40')}>
                                        <td className="px-4 py-1.5"><span className="font-mono font-semibold text-cobalt-900">{d.number}</span>{d.withdrawal ? <span className="block text-xs font-semibold text-crit-700">Discard: milk withdrawal until {d.withdrawal}</span> : null}</td>
                                        <td className="px-3 text-xs text-ink-soft">{d.pen ?? '—'}</td>
                                        <td className="px-3 tabular-nums">{d.dim}</td>
                                        <td className="px-3 tabular-nums">{litres(d.avg7)}</td>
                                        <td className="px-3 py-1">
                                            <input className="field w-28 text-right tabular-nums" type="number" inputMode="decimal" min={0} max={20} step="0.05" aria-label={`Litres for ${d.number}`}
                                                placeholder={done !== undefined ? `${done} saved` : '0.0'} value={values[d.id] ?? ''} onChange={(e) => setValues({ ...values, [d.id]: e.target.value })} />
                                            {errors[`rows.${i}.litres`] ? <span className="block text-xs text-crit-700">{errors[`rows.${i}.litres`]}</span> : null}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <div className="flex items-center gap-3 border-t border-rule px-4 py-3">
                    <Button type="submit" disabled={form.processing || !filled.length}>Save {sessions[session]} Milk</Button>
                    {errors.rows ? <span className="text-sm text-crit-700">{errors.rows}</span> : null}
                </div>
            </form>
        </Panel>
    );
}

function DoesInMilk({ does, reasons, today, can }: Props) {
    const [drying, setDrying] = useState<number | null>(null);
    return (
        <Panel title="Does in Milk" description="Days in milk count from kidding. High-producing does (7-day average at or over the farm's line) move to the High-Producing Lactating standard by themselves." flush>
            {does.length ? (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[54rem] text-sm">
                        <thead className="text-left text-xs text-ink-soft"><tr><th className="px-4 py-2 font-semibold">Doe</th><th className="px-3 font-semibold">Lactation</th><th className="px-3 font-semibold">In Milk Since</th><th className="px-3 font-semibold">Days</th><th className="px-3 font-semibold">7-Day Average</th><th className="px-3 font-semibold">This Lactation</th><th className="px-3 font-semibold">Nutrition Standard</th>{can.manage ? <th /> : null}</tr></thead>
                        <tbody className="divide-y divide-rule">
                            {does.map((d) => (
                                <Fragment key={d.lactationId}>
                                    <tr>
                                        <td className="px-4 py-2"><Link href={`/goats/${d.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{d.number}</Link>
                                            <span className="mt-0.5 flex flex-wrap gap-1">{d.high ? <Chip className="bg-ok-100 text-ok-700">High-Producing</Chip> : null}{d.pregnant ? <Chip className="bg-cobalt-100 text-cobalt-800">Pregnant</Chip> : null}{d.withdrawal ? <Chip className="bg-crit-100 text-crit-700">Withdrawal to {d.withdrawal}</Chip> : null}{d.dryDue ? <Chip className="bg-watch-100 text-watch-700">Due to Dry Off</Chip> : null}</span></td>
                                        <td className="px-3 tabular-nums">{d.lactation}</td>
                                        <td className="px-3">{d.started}<span className="block text-xs text-ink-soft">{d.source === 'arrival' ? 'In milk on arrival' : 'Kidded'}</span></td>
                                        <td className="px-3 tabular-nums">{d.dim}</td>
                                        <td className="px-3 font-semibold tabular-nums text-cobalt-900">{litres(d.avg7)}</td>
                                        <td className="px-3 tabular-nums">{litres(d.total)}{d.last ? <span className="block text-xs text-ink-soft">last {d.last}</span> : null}</td>
                                        <td className="px-3 text-xs">{d.standard ?? '—'}{d.cp ? <span className="block font-mono text-turq-800">CP {d.cp}</span> : null}</td>
                                        {can.manage ? <td className="px-3"><button type="button" className="text-sm font-semibold text-cobalt-800 hover:underline" onClick={() => setDrying(drying === d.lactationId ? null : d.lactationId)}>Dry Off</button></td> : null}
                                    </tr>
                                    {drying === d.lactationId ? <tr><td colSpan={8} className="bg-glaze/60 px-4 py-3"><DryOffForm doe={d} reasons={reasons} today={today} onDone={() => setDrying(null)} /></td></tr> : null}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : <p className="p-4 text-sm text-ink-soft">No does in milk. A doe is in milk from the day she kids.</p>}
        </Panel>
    );
}

function DryOffForm({ doe, reasons, today, onDone }: { doe: Doe; reasons: Record<string, string>; today: string; onDone: () => void }) {
    const form = useForm({ dried_on: today, reason: 'end', note: '' });
    return (
        <form onSubmit={(e) => { e.preventDefault(); form.post(`/milking/lactations/${doe.lactationId}/dry-off`, { preserveScroll: true, onSuccess: onDone }); }} className="grid gap-3 sm:grid-cols-[10rem_14rem_minmax(0,1fr)_auto] sm:items-end">
            <Field label="Dried Off On" error={form.errors.dried_on}><input className="field" type="date" min={doe.startedIso} max={today} value={form.data.dried_on} onChange={(e) => form.setData('dried_on', e.target.value)} /></Field>
            <Field label="Reason" error={form.errors.reason}><select className="field" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)}>{Object.entries(reasons).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            <Field label="Note" error={form.errors.note}><input className="field" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} /></Field>
            <div className="flex gap-2"><Button type="submit" disabled={form.processing}>Dry Off {doe.number}</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}

function StartForm() {
    const today = new Date().toLocaleDateString('en-CA');
    const form = useForm({ tag: '', started_on: today });
    return (
        <Panel title="Start a Lactation" description="For a doe in milk whose kidding was not recorded. Kidding recorded in Breeding starts it by itself.">
            <form onSubmit={(e) => { e.preventDefault(); form.post('/milking/start', { preserveScroll: true, onSuccess: () => form.reset('tag') }); }} className="space-y-3">
                <Field label="Doe Tag ID" error={form.errors.tag}><input className="field font-mono uppercase" value={form.data.tag} onChange={(e) => form.setData('tag', e.target.value)} placeholder="TD141026-06" /></Field>
                <Field label="In Milk Since (Kidding Day)" error={form.errors.started_on}><input className="field" type="date" max={today} value={form.data.started_on} onChange={(e) => form.setData('started_on', e.target.value)} /></Field>
                <Button type="submit" disabled={form.processing || !form.data.tag.trim()}>Start Lactation</Button>
            </form>
        </Panel>
    );
}

function SettingsForm({ settings }: { settings: Props['settings'] }) {
    const form = useForm({ high_yield_litres: settings.highYield === null ? '' : String(settings.highYield), dry_off_days: settings.dryOffDays === null ? '' : String(settings.dryOffDays) });
    return (
        <Panel title="Milking Settings">
            <form onSubmit={(e) => { e.preventDefault(); form.post('/milking/settings', { preserveScroll: true }); }} className="space-y-3">
                <Field label="High-Producing From (L/day)" error={form.errors.high_yield_litres} hint="7-day average. Such does move to the High-Producing Lactating standard (CP 16–20%). Empty = off."><input className="field" type="number" inputMode="decimal" min={0.2} max={10} step="0.1" placeholder="e.g. 2.0" value={form.data.high_yield_litres} onChange={(e) => form.setData('high_yield_litres', e.target.value)} /></Field>
                <Field label="Remind to Dry Off After (Days in Milk)" error={form.errors.dry_off_days} hint="Shows in the Action Center. Empty = no reminder."><input className="field" type="number" min={30} max={500} placeholder="e.g. 210" value={form.data.dry_off_days} onChange={(e) => form.setData('dry_off_days', e.target.value)} /></Field>
                <Note>Milk from a doe under a medicine's milk withdrawal is still recorded, marked Discard, and not counted as kept milk.</Note>
                <Button type="submit" disabled={form.processing}>Save Settings</Button>
            </form>
        </Panel>
    );
}
