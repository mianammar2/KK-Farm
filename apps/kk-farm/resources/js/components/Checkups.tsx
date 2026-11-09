import { Link, useForm } from '@inertiajs/react';
import { Plus, Stethoscope, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button, Chip, cx, Field, Panel } from './ui';

type Goat = { id: string; number: string; name: string | null; sex: 'male' | 'female'; pen: string | null; repro: string | null; last: { on: string; outcome: string; by: string } | null; due: boolean };
type Recent = { id: number; on: string; goat: string; goatId: string; outcome: string; temp: number | null; heart: number | null; breath: number | null; score: number | null; weight: number | null; ultrasound: string | null; fetuses: number | null; flags: string[]; findings: string | null; caseId: number | null; by: string };
export type CheckupData = {
    goats: Goat[]; recent: Recent[]; interval: number; normal: Record<'temperature_f' | 'heart_rate' | 'respiration_rate', [number, number]>;
    sires: Array<{ id: string; number: string }>; canCheck: boolean; canOpenCase: boolean;
};

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
                                        <span className="ml-2 text-sm text-ink-soft">{g.sex === 'female' ? '♀' : '♂'}{g.name ? ` ${g.name}` : ''}{g.repro === 'pregnant' ? ' · pregnant' : ''}</span>
                                        <div className="truncate text-xs text-ink-soft">{g.pen ?? 'No pen'} · {g.last ? <>last {g.last.on} by {g.last.by} <Chip className={OUTCOME[g.last.outcome]?.chip}>{OUTCOME[g.last.outcome]?.label}</Chip></> : 'never checked'}</div>
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

            <Panel flush title="Recent checkups">
                {data.recent.length ? (
                    <div className="overflow-x-auto"><table className="data-table min-w-[720px]">
                        <thead><tr><th>Date</th><th>Goat</th><th>Result</th><th>°F</th><th>Heart</th><th>Breath</th><th>Body</th><th>KG</th><th>Ultrasound</th><th>By</th></tr></thead>
                        <tbody>{data.recent.map((r) => (
                            <tr key={r.id}>
                                <td className="whitespace-nowrap">{r.on}</td>
                                <td><Link href={`/goats/${r.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{r.goat}</Link></td>
                                <td><Chip className={OUTCOME[r.outcome]?.chip}>{OUTCOME[r.outcome]?.label}</Chip>{r.caseId ? <Chip className="ml-1 bg-crit-100 text-crit-700">case</Chip> : null}{r.flags.length ? <span className="block text-xs text-crit-700">{r.flags.join(', ')}</span> : null}</td>
                                <td>{r.temp ?? '—'}</td><td>{r.heart ?? '—'}</td><td>{r.breath ?? '—'}</td><td>{r.score ?? '—'}</td><td>{r.weight ?? '—'}</td>
                                <td>{r.ultrasound ? `${SOUND[r.ultrasound]}${r.fetuses ? ` (${r.fetuses})` : ''}` : '—'}</td>
                                <td className="text-sm text-ink-soft">{r.by}</td>
                            </tr>
                        ))}</tbody>
                    </table></div>
                ) : <p className="p-4 text-sm text-ink-soft">No checkups yet.</p>}
            </Panel>
        </div>
    );
}

function CheckupForm({ goat, date, data, onDone }: { goat: Goat; date: string; data: CheckupData; onDone: () => void }) {
    const form = useForm({
        animal_id: goat.id, checked_on: date, temperature_f: '', heart_rate: '', respiration_rate: '', body_score: '', weight_kg: '',
        ultrasound: '', months_pregnant: '', fetuses: '', sire_id: '', findings: '', outcome: 'healthy', open_case: false, condition: '', severity: 'moderate', isolated: false,
    });
    const e = form.errors as Record<string, string>;
    /** Live check against the normal range; the vet decides what it means. */
    const range = (key: keyof CheckupData['normal'], value: string) => {
        if (value === '' || Number.isNaN(Number(value))) return null;
        const [low, high] = data.normal[key];
        const v = Number(value);
        return v > high ? <span className="text-xs font-semibold text-crit-700">High (normal {low}–{high})</span> : v < low ? <span className="text-xs font-semibold text-watch-700">Low (normal {low}–{high})</span> : <span className="text-xs font-semibold text-ok-700">Normal</span>;
    };
    const num = (key: 'temperature_f' | 'heart_rate' | 'respiration_rate' | 'weight_kg' | 'months_pregnant' | 'fetuses', label: string, unit: string) => (
        <Field label={`${label} (${unit})`} error={e[key]}>
            <input className="field" inputMode="decimal" value={form.data[key]} onChange={(x) => form.setData(key, x.target.value)} />
            {key in data.normal ? range(key as keyof CheckupData['normal'], form.data[key]) : null}
        </Field>
    );

    return (
        <form className="space-y-3 border-t border-rule px-4 pt-3 pb-4" onSubmit={(x) => { x.preventDefault(); form.transform((d) => ({ ...d, checked_on: date })); form.post('/health/checkups', { preserveScroll: true, onSuccess: onDone }); }}>
            <p className="flex items-center gap-2 text-sm font-semibold text-cobalt-900"><Stethoscope size={16} aria-hidden /> Checkup of {goat.number} on {new Date(`${date}T12:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}</p>
            <div className="grid gap-3 sm:grid-cols-3">
                {num('temperature_f', 'Temperature', '°F')}
                {num('heart_rate', 'Heart rate', 'beats/min')}
                {num('respiration_rate', 'Breathing', 'breaths/min')}
                <Field label="Body condition (1–5)"><select className="field" value={form.data.body_score} onChange={(x) => form.setData('body_score', x.target.value)}><option value="">Not set</option>{['1 · Very thin', '2 · Thin', '3 · Good', '4 · Fat', '5 · Very fat'].map((l, i) => <option key={l} value={i + 1}>{l}</option>)}</select></Field>
                {num('weight_kg', 'Weight', 'KG')}
                {goat.sex === 'female' ? (
                    <Field label="Ultrasound"><select className="field" value={form.data.ultrasound} onChange={(x) => form.setData('ultrasound', x.target.value)}><option value="">Not done</option>{Object.entries(SOUND).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                ) : null}
            </div>
            {form.data.ultrasound === 'pregnant' ? (
                <div className="grid gap-3 rounded-tile bg-manganese-100/60 p-3 sm:grid-cols-3">
                    {num('months_pregnant', 'Months pregnant (about)', 'months')}
                    {num('fetuses', 'Fetuses seen', 'count')}
                    <Field label="Sire (if known)"><select className="field" value={form.data.sire_id} onChange={(x) => form.setData('sire_id', x.target.value)}><option value="">Unknown</option>{data.sires.map((s) => <option key={s.id} value={s.id}>{s.number}</option>)}</select></Field>
                    <p className="text-xs text-manganese-600 sm:col-span-3">She becomes Pregnant in Breeding with an expected kidding date, and the dashboard counts her.</p>
                </div>
            ) : null}
            <Field label="Findings and other notes" error={e.findings}><textarea className="field min-h-20" value={form.data.findings} onChange={(x) => form.setData('findings', x.target.value)} placeholder="Eyes, coat, feet, udder, appetite, droppings…" /></Field>
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm font-semibold text-cobalt-900">Result</span>
                {Object.entries(OUTCOME).map(([k, o]) => (
                    <button key={k} type="button" aria-pressed={form.data.outcome === k} onClick={() => form.setData('outcome', k)} className={cx('kk-pill rounded-full border px-3 py-1.5 text-sm font-semibold', form.data.outcome === k ? `${o.chip} border-current` : 'border-rule bg-tile text-cobalt-900')}>{o.label}</button>
                ))}
            </div>
            {data.canOpenCase ? (
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
            <div className="flex gap-2"><Button type="submit" disabled={form.processing}>Save checkup</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}
