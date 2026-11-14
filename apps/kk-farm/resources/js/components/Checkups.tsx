import { Link, useForm } from '@inertiajs/react';
import { CalendarClock, Pencil, Plus, Stethoscope, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { readingWarnings, type Weighing } from '../lib/readings';
import { Button, Chip, cx, Field, Panel } from './ui';

type Goat = { id: string; number: string; name: string | null; sex: 'male' | 'female'; pen: string | null; repro: string | null; last: { on: string; outcome: string; by: string } | null; due: boolean; next: string | null; recheck: string | null; matedOn?: string | null; weights?: Weighing[] };
type Recent = { id: number; on: string; goat: string; goatId: string; outcome: string; temp: number | null; score: number | null; weight: number | null; ultrasound: string | null; fetuses: number | null; flags: string[]; findings: string | null; caseId: number | null; by: string;
    dateIso: string; days: number | null; matedOn: string | null; followUp: string | null; followUpNote: string | null; sex: 'male' | 'female'; canEdit: boolean; edits: number };
export type CheckupData = {
    canEntry?: boolean; // D-252: New health entry
    goats: Goat[]; recent: Recent[]; interval: number; normal: Record<'temperature_f', [number, number]>;
    sires: Array<{ id: string; number: string }>; canCheck: boolean; canOpenCase: boolean; gestation: number; conditions?: string[];
    ultrasound?: Record<string, string>; viability?: Record<string, string>; // D-167
};

/** D-167: the KK Farms ultrasound code → the simple result the checkup keeps (pregnant / not pregnant / not clear). */
export const coarseUltrasound = (code: string) => (['PCF', 'VPG', 'MPG', 'SFL'].includes(code) ? 'pregnant' : ['NPG', 'HYD', 'CPL'].includes(code) ? 'not_pregnant' : code ? 'inconclusive' : '');
const CODE_OF: Record<string, string> = { pregnant: 'PCF', not_pregnant: 'NPG', inconclusive: 'INC' };

/** D-167: ultrasound result (12 KK Farms statuses) and fetal viability, used by both checkup forms. */
export function UltrasoundPick({ data, code, viability, onCode, onViability, emptyLabel }: { data: CheckupData; code: string; viability: string; onCode: (v: string) => void; onViability: (v: string) => void; emptyLabel: string }) {
    const statuses = data.ultrasound ?? { PCF: 'Pregnancy Confirmed', NPG: 'Not Pregnant (Open)', INC: 'Inconclusive Scan' };
    return (
        <>
            <Field label="Ultrasound result"><select className="field" value={code} onChange={(x) => onCode(x.target.value)}><option value="">{emptyLabel}</option>{Object.entries(statuses).map(([k, v]) => <option key={k} value={k}>{v} ({k})</option>)}</select></Field>
            {code && data.viability && !['NPG', 'HYD'].includes(code) ? (
                <Field label="Fetal viability"><select className="field" value={viability} onChange={(x) => onViability(x.target.value)}>{Object.entries(data.viability).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            ) : null}
        </>
    );
}

const TODAY = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const OUTCOME: Record<string, { label: string; chip: string }> = {
    healthy: { label: 'Healthy', chip: 'bg-ok-100 text-ok-700' }, monitor: { label: 'Watch', chip: 'bg-watch-100 text-watch-700' }, sick: { label: 'Sick', chip: 'bg-crit-100 text-crit-700' },
};
const SOUND: Record<string, string> = { pregnant: 'Pregnant', not_pregnant: 'Not pregnant', inconclusive: 'Not clear' };

/**
 * D-095: routine health checkups. Every goat with its last checkup and whether one is due (every N days); "+" opens
 * the checkup form for that goat. A pregnant ultrasound updates Breeding and the dashboard; a finding can open a case.
 */
export default function Checkups({ data }: { data: CheckupData }) {
    const [date, setDate] = useState(TODAY);
    const [pen, setPen] = useState('');
    const [dueOnly, setDueOnly] = useState(true);
    const [open, setOpen] = useState<string | null>(null);
    const [editing, setEditing] = useState<Recent | null>(null);
    const pens = useMemo(() => [...new Set(data.goats.map((g) => g.pen).filter(Boolean))] as string[], [data.goats]);
    const rows = data.goats.filter((g) => (!pen || g.pen === pen) && (!dueOnly || g.due));
    const due = data.goats.filter((g) => g.due).length;

    return (
        <div className="space-y-3">
            <Panel>
                <div className="flex flex-wrap items-end gap-2">
                    <Field label="Checkup date" hint="Can be an earlier day, e.g. 21 Oct"><input type="date" className="field w-auto" max={TODAY} value={date} onChange={(e) => setDate(e.target.value)} /></Field>
                    <Field label="Pen"><select className="field w-auto" value={pen} onChange={(e) => setPen(e.target.value)}><option value="">All pens</option>{pens.map((p) => <option key={p}>{p}</option>)}</select></Field>
                    <label className="flex min-h-11 items-center gap-2 text-sm font-semibold text-cobalt-900"><input type="checkbox" className="size-5" checked={dueOnly} onChange={(e) => setDueOnly(e.target.checked)} /> Due only</label>
                    <p className="ml-auto text-sm text-ink-soft"><strong className="text-cobalt-900">{due}</strong> of {data.goats.length} due · every {data.interval} days</p>
                </div>
            </Panel>

            <Panel flush title="Goats" description={data.canCheck ? 'Tap + to check a goat. Readings outside normal are flagged; you decide.' : 'A vet, manager or the administrator records checkups.'}>
                {rows.length ? (
                    <ul className="divide-y divide-rule">
                        {rows.map((g) => (
                            <li key={g.id} className={cx(open === g.id && 'bg-glaze/60')}>
                                <div className="flex items-center gap-3 px-4 py-2.5">
                                    <span className={cx('size-2.5 shrink-0 rounded-full', g.due ? 'bg-watch-400' : 'bg-ok-700')} title={g.due ? 'Due' : 'Checked'} />
                                    <div className="min-w-0 flex-1">
                                        <Link href={`/goats/${g.id}`} className="font-mono font-semibold text-cobalt-900 hover:underline">{g.number}</Link>
                                        <span className="ml-2 text-sm text-ink-soft">{g.sex === 'female' ? '♀' : '♂'}{g.name ? ` ${g.name}` : ''}</span>
                                        {g.matedOn ? <span className="ml-2 rounded-full bg-manganese-100 px-2 py-0.5 text-xs font-semibold text-manganese-600">Pregnant · day {Math.round((new Date(`${date}T12:00:00`).getTime() - new Date(`${g.matedOn}T12:00:00`).getTime()) / 86400000)}</span> : null}
                                        <div className="truncate text-xs text-ink-soft">{g.pen ?? 'No pen'} · {g.last ? <>last {g.last.on} by {g.last.by} <Chip className={OUTCOME[g.last.outcome]?.chip}>{OUTCOME[g.last.outcome]?.label}</Chip></> : 'never checked'}</div>
                                        {g.next || g.recheck ? <div className="text-xs text-ink-soft">{g.recheck ? <span className="font-semibold text-watch-700">Recheck {g.recheck} · </span> : null}{g.next ? `next routine ${g.next}` : ''}</div> : null}
                                    </div>
                                    {data.canCheck ? (
                                        <button type="button" onClick={() => setOpen(open === g.id ? null : g.id)} aria-expanded={open === g.id} aria-label={`Check ${g.number}`}
                                            className={cx('grid size-11 shrink-0 place-items-center rounded-full', open === g.id ? 'bg-glaze text-ink-soft' : 'bg-cobalt-800 text-white hover:bg-cobalt-900')}>
                                            {open === g.id ? <X size={18} aria-hidden /> : <Plus size={20} aria-hidden />}
                                        </button>
                                    ) : null}
                                </div>
                                {open === g.id ? <CheckupForm goat={g} date={date} data={data} onDone={() => setOpen(null)} /> : null}
                            </li>
                        ))}
                    </ul>
                ) : <p className="p-4 text-sm text-ink-soft">{dueOnly ? 'No goats due. Untick "Due only" to check any goat.' : 'No goats here.'}</p>}
            </Panel>

            {editing ? (
                <Panel title={`Correct the checkup of ${editing.goat}`} description={`Done ${editing.on} by ${editing.by}. Every change is kept with your name and the reason.`}
                    actions={<button type="button" onClick={() => setEditing(null)} aria-label="Close" className="grid size-9 place-items-center rounded-full hover:bg-glaze"><X size={16} aria-hidden /></button>}>
                    <CheckupForm key={editing.id} existing={editing} goat={{ id: editing.goatId, number: editing.goat, name: null, sex: editing.sex, pen: null, repro: null, last: null, due: false, next: null, recheck: null, matedOn: editing.matedOn }} date={editing.dateIso} data={data} onDone={() => setEditing(null)} />
                </Panel>
            ) : null}
            <Panel flush title="Recent checkups">
                {data.recent.length ? (
                    <div className="overflow-x-auto"><table className="data-table min-w-[600px]">
                        <thead><tr><th>Date</th><th>Goat</th><th>Result</th><th>°F</th><th>Body</th><th>KG</th><th>Ultrasound</th><th>Pregnancy Day</th><th>Recheck</th><th>By</th><th /></tr></thead>
                        <tbody>{data.recent.map((r) => (
                            <tr key={r.id}>
                                <td className="whitespace-nowrap">{r.on}</td>
                                <td><Link href={`/goats/${r.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{r.goat}</Link></td>
                                <td><Chip className={OUTCOME[r.outcome]?.chip}>{OUTCOME[r.outcome]?.label}</Chip>{r.caseId ? <Chip className="ml-1 bg-crit-100 text-crit-700">case</Chip> : null}{r.flags.length ? <span className="block text-xs text-crit-700">{r.flags.join(', ')}</span> : null}</td>
                                <td>{r.temp ?? '—'}</td><td>{r.score ?? '—'}</td><td>{r.weight ?? '—'}</td>
                                <td>{r.ultrasound ? `${SOUND[r.ultrasound]}${r.fetuses ? ` (${r.fetuses})` : ''}` : '—'}</td>
                                <td>{r.days ? <span className="font-semibold text-manganese-600">Day {r.days}</span> : '—'}</td>
                                <td className="whitespace-nowrap text-sm">{r.followUp ? new Date(`${r.followUp}T12:00:00`).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit' }) : '—'}</td>
                                <td className="text-sm text-ink-soft">{r.by}{r.edits ? <Chip className="ml-1 bg-cobalt-100 text-cobalt-800">corrected{r.edits > 1 ? ` ×${r.edits}` : ''}</Chip> : null}</td>
                                <td>{r.canEdit ? <button type="button" onClick={() => { setEditing(r); window.scrollTo({ top: 0, behavior: 'smooth' }); }} className="inline-flex items-center gap-1 text-sm font-semibold text-turq-800 hover:underline"><Pencil size={14} aria-hidden /> Edit</button> : null}</td>
                            </tr>
                        ))}</tbody>
                    </table></div>
                ) : <p className="p-4 text-sm text-ink-soft">No checkups yet.</p>}
            </Panel>
        </div>
    );
}

function CheckupForm({ goat, date, data, onDone, existing }: { goat: Goat; date: string; data: CheckupData; onDone: () => void; existing?: Recent }) {
    const str = (v: number | string | null | undefined) => (v === null || v === undefined ? '' : String(v));
    const form = useForm({
        animal_id: goat.id, checked_on: date, temperature_f: str(existing?.temp), body_score: str(existing?.score), weight_kg: str(existing?.weight),
        ultrasound: existing?.ultrasound ?? '', ultrasound_status: existing?.ultrasound ? CODE_OF[existing.ultrasound] ?? '' : '', viability: 'HBD', days_pregnant: str(existing?.days), fetuses: str(existing?.fetuses), sire_id: '', findings: existing?.findings ?? '', outcome: existing?.outcome ?? 'healthy',
        open_case: false, condition: '', severity: 'moderate', isolated: false,
        follow_up_on: existing?.followUp ?? '', follow_up_note: existing?.followUpNote ?? '', reason: '',
    });
    // When the checkup date changes on a correction, the form follows the date typed here (existing) or the page's date (new).
    const [own, setOwn] = useState(existing ? existing.dateIso : '');
    const checkDate = existing ? own : date;
    // D-100: a doe already pregnant: her day of pregnancy on this date is counted from the mating.
    const counted = goat.matedOn ? Math.round((new Date(`${checkDate}T12:00:00`).getTime() - new Date(`${goat.matedOn}T12:00:00`).getTime()) / 86400000) : null;
    const setUltrasound = (code: string) => form.setData((d) => { const v = coarseUltrasound(code); return { ...d, ultrasound_status: code, ultrasound: v, days_pregnant: v === 'pregnant' && !d.days_pregnant && counted !== null && counted > 0 ? String(counted) : d.days_pregnant }; });
    // D-104: live warnings (not when correcting an old checkup)
    const { warnings, weightNote } = readingWarnings({ weights: existing ? [] : goat.weights, date: checkDate, weight: form.data.weight_kg, temp: form.data.temperature_f, normal: data.normal.temperature_f, pregnant: Boolean(goat.matedOn) || form.data.ultrasound === 'pregnant' });
    const [picked, setPicked] = useState(Boolean(existing));
    useEffect(() => { if (warnings.length && !picked && form.data.outcome === 'healthy') form.setData('outcome', 'monitor'); }, [warnings.length]); // eslint-disable-line react-hooks/exhaustive-deps
    const recheckIn = (days: number) => { const d = new Date(`${checkDate}T12:00:00`); d.setDate(d.getDate() + days); form.setData('follow_up_on', d.toISOString().slice(0, 10)); };
    const e = form.errors as Record<string, string>;
    /** Live check against the normal range; the vet decides what it means. */
    const range = (key: keyof CheckupData['normal'], value: string) => {
        if (value === '' || Number.isNaN(Number(value))) return null;
        const [low, high] = data.normal[key];
        const v = Number(value);
        return v > high ? <span className="text-xs font-semibold text-crit-700">High (normal {low}–{high})</span> : v < low ? <span className="text-xs font-semibold text-watch-700">Low (normal {low}–{high})</span> : <span className="text-xs font-semibold text-ok-700">Normal</span>;
    };
    const num = (key: 'temperature_f' | 'weight_kg' | 'days_pregnant' | 'fetuses', label: string, unit: string) => (
        <Field label={`${label} (${unit})`} error={e[key]}>
            <input className="field" inputMode="decimal" value={form.data[key]} onChange={(x) => form.setData(key, x.target.value)} />
            {key in data.normal ? range(key as keyof CheckupData['normal'], form.data[key]) : null}
            {key === 'weight_kg' && weightNote ? <span className={cx('text-xs font-semibold', weightNote.bad ? 'text-crit-700' : 'text-ink-soft')}>{weightNote.text}</span> : null}
        </Field>
    );

    return (
        <form className={cx('space-y-3', !existing && 'border-t border-rule px-4 pt-3 pb-4')} onSubmit={(x) => {
            x.preventDefault();
            form.transform((d) => ({ ...d, checked_on: checkDate }));
            form.post(existing ? `/health/checkups/${existing.id}` : '/health/checkups', { preserveScroll: true, onSuccess: onDone });
        }}>
            {existing ? (
                <Field label="Checkup date" error={e.checked_on}><input type="date" className="field w-auto" max={TODAY} value={own} onChange={(x) => setOwn(x.target.value)} /></Field>
            ) : <p className="flex items-center gap-2 text-sm font-semibold text-cobalt-900"><Stethoscope size={16} aria-hidden /> Checkup of {goat.number} on {new Date(`${date}T12:00:00`).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' })}</p>}
            <div className="grid gap-3 sm:grid-cols-3">
                {num('temperature_f', 'Temperature', '°F')}
                <Field label="Body condition (1–5, optional)"><select className="field" value={form.data.body_score} onChange={(x) => form.setData('body_score', x.target.value)}><option value="">Not set</option>{['1 · Very thin', '2 · Thin', '3 · Good', '4 · Fat', '5 · Very fat'].map((l, i) => <option key={l} value={i + 1}>{l}</option>)}</select></Field>
                {num('weight_kg', 'Weight', 'KG')}
                {goat.sex === 'female' ? (
                    <UltrasoundPick data={data} code={form.data.ultrasound_status} viability={form.data.viability} onCode={setUltrasound} onViability={(v) => form.setData('viability', v)} emptyLabel="Not done" />
                ) : null}
            </div>
            {form.data.ultrasound === 'pregnant' ? (
                <div className="grid gap-3 rounded-tile bg-manganese-100/60 p-3 sm:grid-cols-3">
                    <div>
                        {num('days_pregnant', 'Days pregnant (about)', 'days')}
                        {/* D-098: counted in days; expected kidding = checkup date − days pregnant + gestation days */}
                        {Number(form.data.days_pregnant) > 0 ? (() => {
                            const kid = new Date(`${checkDate}T12:00:00`);
                            kid.setDate(kid.getDate() - Number(form.data.days_pregnant) + data.gestation);
                            return <p className="mt-1 text-xs font-semibold text-manganese-600">Day {form.data.days_pregnant} of about {data.gestation} · kidding about {kid.toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' })}</p>;
                        })() : null}
                    </div>
                    {num('fetuses', 'Fetuses seen', 'count')}
                    <Field label="Sire (if known)"><select className="field" value={form.data.sire_id} onChange={(x) => form.setData('sire_id', x.target.value)}><option value="">Unknown</option>{data.sires.map((s) => <option key={s.id} value={s.id}>{s.number}</option>)}</select></Field>
                    <p className="text-xs text-manganese-600 sm:col-span-3">She becomes Pregnant in Breeding with an expected kidding date, and the dashboard counts her.</p>
                </div>
            ) : null}
            <Field label="Findings and other notes" error={e.findings}><textarea className="field min-h-20" value={form.data.findings} onChange={(x) => form.setData('findings', x.target.value)} placeholder="Eyes, coat, feet, udder, appetite, droppings…" /></Field>
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm font-semibold text-cobalt-900">Result</span>
                {Object.entries(OUTCOME).map(([k, o]) => (
                    <button key={k} type="button" aria-pressed={form.data.outcome === k} onClick={() => { setPicked(true); form.setData('outcome', k); }} className={cx('kk-pill rounded-full border px-3 py-1.5 text-sm font-semibold', form.data.outcome === k ? `${o.chip} border-current` : 'border-rule bg-tile text-cobalt-900')}>{o.label}</button>
                ))}
            </div>
            {/* D-099: what happens after the checkup */}
            <div className="rounded-tile border border-rule p-3">
                <div className="flex flex-wrap items-center gap-2 text-sm font-semibold text-cobalt-900"><CalendarClock size={16} aria-hidden /> After this checkup: recheck
                    {[3, 7, 14].map((d) => <button key={d} type="button" onClick={() => recheckIn(d)} className="rounded-full border border-rule bg-tile px-2.5 py-1 text-xs font-semibold hover:border-turq-600">in {d} days</button>)}
                    {form.data.follow_up_on ? <button type="button" onClick={() => form.setData('follow_up_on', '')} className="text-xs font-semibold text-ink-soft hover:underline">No recheck</button> : null}
                </div>
                <div className="mt-2 grid gap-3 sm:grid-cols-[12rem_minmax(0,1fr)]">
                    <Field label="Recheck on" error={e.follow_up_on}><input type="date" className="field" min={checkDate} value={form.data.follow_up_on} onChange={(x) => form.setData('follow_up_on', x.target.value)} /></Field>
                    <Field label="What to check then"><input className="field" value={form.data.follow_up_note} onChange={(x) => form.setData('follow_up_note', x.target.value)} placeholder="e.g. Temperature again, confirm pregnancy" /></Field>
                </div>
                <p className="mt-1 text-xs text-ink-soft">A recheck becomes a task for the vets on that day. The next routine checkup is {data.interval} days after this one.</p>
            </div>
            {data.canOpenCase && !existing ? (
                <div className="rounded-tile border border-rule p-3">
                    <label className="flex items-center gap-2 text-sm font-semibold text-cobalt-900"><input type="checkbox" className="size-5" checked={form.data.open_case} onChange={(x) => form.setData('open_case', x.target.checked)} /> Diagnosed something: open a health case</label>
                    {form.data.open_case ? (
                        <div className="mt-3 grid gap-3 sm:grid-cols-3">
                            <Field label="Condition" error={e.condition}><input className="field" value={form.data.condition} onChange={(x) => form.setData('condition', x.target.value)} placeholder="e.g. Pneumonia" /></Field>
                            <Field label="Severity"><select className="field" value={form.data.severity} onChange={(x) => form.setData('severity', x.target.value)}><option value="low">Low</option><option value="moderate">Moderate</option><option value="high">High</option><option value="emergency">Emergency</option></select></Field>
                            <label className="flex min-h-11 items-center gap-2 self-end text-sm"><input type="checkbox" className="size-5" checked={form.data.isolated} onChange={(x) => form.setData('isolated', x.target.checked)} /> Isolate</label>
                        </div>
                    ) : null}
                </div>
            ) : null}
            {warnings.length ? (
                <div role="alert" className="flex items-start gap-2 rounded-tile border border-crit-500 bg-crit-100 px-3 py-2 text-sm font-semibold text-crit-700">
                    <span className="kk-beat mt-1.5 size-2.5 shrink-0 rounded-full bg-crit-500" aria-hidden />
                    <span>{warnings.join(' · ')}. Saving opens a health report and gives the vet an urgent task to check {goat.number} today.</span>
                </div>
            ) : null}
            {existing ? <Field label="Why the correction? (optional)"><input className="field" value={form.data.reason} onChange={(x) => form.setData('reason', x.target.value)} placeholder="e.g. Wrong date entered, temperature was missing" /></Field> : null}
            <div className="flex gap-2"><Button type="submit" disabled={form.processing}>{existing ? 'Save correction' : 'Save checkup'}</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}
