import { useForm } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
import { Button, cx, Field, Panel } from './ui';
import type { CheckupData } from './Checkups';

const TODAY = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const KINDS = [
    { key: 'checkup', label: 'Regular checkup', who: 'check' },
    { key: 'pregnancy', label: 'Pregnancy check (ultrasound)', who: 'check', female: true },
    { key: 'disease', label: 'Disease / illness', who: 'case' },
    { key: 'injury', label: 'Injury / wound', who: 'case' },
    { key: 'other', label: 'Other problem', who: 'case' },
] as const;

/** Days between two ISO dates (b − a). */
export const daysBetween = (a: string, b: string) => Math.round((new Date(`${b}T12:00:00`).getTime() - new Date(`${a}T12:00:00`).getTime()) / 86400000);
const fmt = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });

/**
 * D-100: one guided entry for every health event, in the order the vet works: the date, the goat, then what it is.
 * A checkup or a pregnancy check becomes a checkup (a pregnancy updates Breeding); a disease, injury or other problem
 * opens a health case. A doe already pregnant gets her day of pregnancy counted from the mating for the chosen date.
 */
export default function HealthEntry({ data, severities, onDone }: { data: CheckupData & { conditions: string[] }; severities: Record<string, string>; onDone: () => void }) {
    const form = useForm({
        date: TODAY, animal_id: '', kind: '',
        temperature_f: '', body_score: '', weight_kg: '', findings: '', outcome: 'healthy', follow_up_on: '', follow_up_note: '',
        ultrasound: '', days_pregnant: '', fetuses: '', sire_id: '',
        condition: '', other_condition: '', symptoms: '', severity: 'moderate', isolated: false, diagnosis: '',
    });
    const e = form.errors as Record<string, string>;
    const goat = data.goats.find((g) => g.id === form.data.animal_id);
    const kinds = KINDS.filter((k) => (k.who === 'check' ? data.canCheck : data.canOpenCase) && (!('female' in k) || goat?.sex === 'female'));
    const isCase = ['disease', 'injury', 'other'].includes(form.data.kind);
    const counted = goat?.matedOn ? daysBetween(goat.matedOn, form.data.date) : null; // day of pregnancy on the chosen date
    const set = (key: keyof typeof form.data, value: string | boolean) => form.setData(key, value as never);

    const chooseGoat = (id: string) => form.setData((d) => ({ ...d, animal_id: id, kind: d.kind === 'pregnancy' && data.goats.find((g) => g.id === id)?.sex !== 'female' ? '' : d.kind }));
    const chooseKind = (k: string) => form.setData((d) => ({
        ...d, kind: k,
        condition: k === 'injury' ? 'Wound / injury' : k === 'disease' ? '' : d.condition,
        ultrasound: k === 'pregnancy' ? (goat?.matedOn ? 'pregnant' : '') : '',
        days_pregnant: k === 'pregnancy' && counted !== null && counted > 0 ? String(counted) : '',
    }));
    const recheckIn = (days: number) => { const d = new Date(`${form.data.date}T12:00:00`); d.setDate(d.getDate() + days); set('follow_up_on', d.toISOString().slice(0, 10)); };

    const submit = (ev: React.FormEvent) => {
        ev.preventDefault();
        if (isCase) {
            form.transform((d) => ({
                animal_id: d.animal_id, onset_on: d.date, condition: d.condition === '__other' || d.kind === 'other' ? d.other_condition : d.condition,
                symptoms: [d.symptoms, d.temperature_f ? `Temperature ${d.temperature_f} °F` : ''].filter(Boolean).join('. '), severity: d.severity, isolated: d.isolated, diagnosis: d.diagnosis,
            }));
            form.post('/health/cases', { preserveScroll: true, onSuccess: onDone });
            return;
        }
        form.transform((d) => ({
            animal_id: d.animal_id, checked_on: d.date, temperature_f: d.temperature_f, body_score: d.body_score, weight_kg: d.weight_kg, findings: d.findings,
            outcome: d.kind === 'pregnancy' ? 'healthy' : d.outcome, follow_up_on: d.follow_up_on, follow_up_note: d.follow_up_note,
            ...(d.kind === 'pregnancy' ? { ultrasound: d.ultrasound, days_pregnant: d.days_pregnant, fetuses: d.fetuses, sire_id: d.sire_id } : {}),
        }));
        form.post('/health/checkups', { preserveScroll: true, onSuccess: onDone });
    };

    const step = (n: number, title: string, done: boolean) => (
        <div className="flex items-center gap-2 text-sm font-semibold text-cobalt-900"><span className={cx('grid size-6 place-items-center rounded-full text-xs', done ? 'bg-ok-700 text-white' : 'bg-cobalt-800 text-white')}>{n}</span>{title}</div>
    );
    const temp = form.data.temperature_f !== '' && !Number.isNaN(Number(form.data.temperature_f)) ? Number(form.data.temperature_f) : null;
    const [low, high] = data.normal.temperature_f;

    return (
        <Panel title="New health entry" description="Date, goat, then what it is. The form shows only what that needs.">
            <form className="space-y-4" onSubmit={submit}>
                <div className="space-y-2">
                    {step(1, 'Date', Boolean(form.data.date))}
                    <input type="date" className="field" max={TODAY} value={form.data.date} onChange={(x) => set('date', x.target.value)} aria-label="Date" />
                </div>
                <div className="space-y-2">
                    {step(2, 'Goat', Boolean(goat))}
                    <select className="field font-mono" value={form.data.animal_id} onChange={(x) => chooseGoat(x.target.value)} aria-label="Goat">
                        <option value="">Choose the goat…</option>
                        {data.goats.map((g) => <option key={g.id} value={g.id}>{g.number} {g.sex === 'female' ? '♀' : '♂'}{g.matedOn ? ` · pregnant, day ${daysBetween(g.matedOn, form.data.date)}` : ''}</option>)}
                    </select>
                    {e.animal_id ? <p className="text-sm text-crit-700">{e.animal_id}</p> : null}
                </div>
                {goat ? (
                    <div className="space-y-2">
                        {step(3, 'What is it?', Boolean(form.data.kind))}
                        <select className="field" value={form.data.kind} onChange={(x) => chooseKind(x.target.value)} aria-label="What is it">
                            <option value="">Choose…</option>
                            {kinds.map((k) => <option key={k.key} value={k.key}>{k.label}</option>)}
                        </select>
                    </div>
                ) : null}

                {form.data.kind === 'pregnancy' ? (
                    <div className="space-y-3 rounded-tile bg-manganese-100/60 p-3">
                        <Field label="Ultrasound result"><select className="field" value={form.data.ultrasound} onChange={(x) => set('ultrasound', x.target.value)}><option value="">Choose…</option><option value="pregnant">Pregnant</option><option value="not_pregnant">Not pregnant</option><option value="inconclusive">Not clear</option></select></Field>
                        {form.data.ultrasound === 'pregnant' ? (
                            <>
                                <Field label="Days pregnant" error={e.days_pregnant} hint={counted !== null ? `Counted: day ${counted} on ${fmt(form.data.date)}, from her earlier check. Change it only if the vet measured differently.` : 'Days since mating, as the vet estimates. Later checks count on from this.'}>
                                    <input className="field" inputMode="numeric" value={form.data.days_pregnant} onChange={(x) => set('days_pregnant', x.target.value.replace(/[^0-9]/g, ''))} />
                                </Field>
                                {Number(form.data.days_pregnant) > 0 ? (() => {
                                    const kid = new Date(`${form.data.date}T12:00:00`);
                                    kid.setDate(kid.getDate() - Number(form.data.days_pregnant) + data.gestation);
                                    return <p className="text-xs font-semibold text-manganese-600">Day {form.data.days_pregnant} of about {data.gestation} · kidding about {kid.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}</p>;
                                })() : null}
                                <div className="grid grid-cols-2 gap-2">
                                    <Field label="Fetuses seen"><input className="field" inputMode="numeric" value={form.data.fetuses} onChange={(x) => set('fetuses', x.target.value.replace(/[^0-9]/g, ''))} /></Field>
                                    <Field label="Sire (if known)"><select className="field" value={form.data.sire_id} onChange={(x) => set('sire_id', x.target.value)}><option value="">Unknown</option>{data.sires.map((s) => <option key={s.id} value={s.id}>{s.number}</option>)}</select></Field>
                                </div>
                            </>
                        ) : null}
                    </div>
                ) : null}

                {form.data.kind === 'checkup' || form.data.kind === 'pregnancy' ? (
                    <>
                        <div className="grid grid-cols-2 gap-2">
                            <Field label="Weight (KG)" error={e.weight_kg}><input className="field" inputMode="decimal" value={form.data.weight_kg} onChange={(x) => set('weight_kg', x.target.value)} /></Field>
                            <Field label="Temperature (°F)" error={e.temperature_f}>
                                <input className="field" inputMode="decimal" value={form.data.temperature_f} onChange={(x) => set('temperature_f', x.target.value)} />
                                {temp !== null ? <span className={cx('text-xs font-semibold', temp > high ? 'text-crit-700' : temp < low ? 'text-watch-700' : 'text-ok-700')}>{temp > high ? `High (normal ${low}–${high})` : temp < low ? `Low (normal ${low}–${high})` : 'Normal'}</span> : null}
                            </Field>
                        </div>
                        <Field label="Body condition (optional)"><select className="field" value={form.data.body_score} onChange={(x) => set('body_score', x.target.value)}><option value="">Not set</option>{['1 · Very thin', '2 · Thin', '3 · Good', '4 · Fat', '5 · Very fat'].map((l, i) => <option key={l} value={i + 1}>{l}</option>)}</select></Field>
                        <Field label="Findings / notes"><textarea className="field min-h-16" value={form.data.findings} onChange={(x) => set('findings', x.target.value)} /></Field>
                        {form.data.kind === 'checkup' ? (
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-sm font-semibold text-cobalt-900">Result</span>
                                {[['healthy', 'Healthy', 'bg-ok-100 text-ok-700'], ['monitor', 'Watch', 'bg-watch-100 text-watch-700'], ['sick', 'Sick', 'bg-crit-100 text-crit-700']].map(([k, l, c]) => (
                                    <button key={k} type="button" aria-pressed={form.data.outcome === k} onClick={() => set('outcome', k)} className={cx('rounded-full border px-3 py-1.5 text-sm font-semibold', form.data.outcome === k ? `${c} border-current` : 'border-rule bg-tile text-cobalt-900')}>{l}</button>
                                ))}
                            </div>
                        ) : null}
                        <div className="rounded-tile border border-rule p-3">
                            <div className="flex flex-wrap items-center gap-2 text-sm font-semibold text-cobalt-900"><CalendarClock size={16} aria-hidden /> Next check
                                {[7, 14].map((d) => <button key={d} type="button" onClick={() => recheckIn(d)} className="rounded-full border border-rule bg-tile px-2.5 py-1 text-xs font-semibold">in {d} days</button>)}
                            </div>
                            <div className="mt-2 grid grid-cols-2 gap-2">
                                <input type="date" className="field" min={form.data.date} value={form.data.follow_up_on} onChange={(x) => set('follow_up_on', x.target.value)} aria-label="Next check date" />
                                <input className="field" value={form.data.follow_up_note} onChange={(x) => set('follow_up_note', x.target.value)} placeholder="What to check" aria-label="What to check" />
                            </div>
                        </div>
                    </>
                ) : null}

                {isCase ? (
                    <div className="space-y-3">
                        {form.data.kind === 'disease' ? (
                            <Field label="Condition" error={e.condition}>
                                <select className="field" value={form.data.condition} onChange={(x) => set('condition', x.target.value)}>
                                    <option value="">Choose…</option>
                                    {data.conditions.filter((c) => !['Wound / injury', 'Fracture'].includes(c)).map((c) => <option key={c}>{c}</option>)}
                                    <option value="__other">Something else (type it)</option>
                                </select>
                            </Field>
                        ) : null}
                        {form.data.kind === 'injury' ? <Field label="Injury"><select className="field" value={form.data.condition} onChange={(x) => set('condition', x.target.value)}><option>Wound / injury</option><option>Fracture</option><option value="__other">Something else (type it)</option></select></Field> : null}
                        {form.data.kind === 'other' || form.data.condition === '__other' ? <Field label="What is it?" error={e.condition}><input className="field" value={form.data.other_condition} onChange={(x) => set('other_condition', x.target.value)} /></Field> : null}
                        <Field label="Symptoms / what you saw"><textarea className="field min-h-16" value={form.data.symptoms} onChange={(x) => set('symptoms', x.target.value)} /></Field>
                        <div className="grid grid-cols-2 gap-2">
                            <Field label="Severity"><select className="field" value={form.data.severity} onChange={(x) => set('severity', x.target.value)}>{Object.entries(severities).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                            <Field label="Temperature (°F, optional)"><input className="field" inputMode="decimal" value={form.data.temperature_f} onChange={(x) => set('temperature_f', x.target.value)} /></Field>
                        </div>
                        <Field label="Diagnosis / test (optional)"><input className="field" value={form.data.diagnosis} onChange={(x) => set('diagnosis', x.target.value)} /></Field>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="size-5" checked={form.data.isolated} onChange={(x) => set('isolated', x.target.checked)} /> Isolated from the herd</label>
                    </div>
                ) : null}

                {form.data.kind ? <Button type="submit" className="w-full" disabled={form.processing}>{isCase ? 'Open health case' : form.data.kind === 'pregnancy' ? 'Save pregnancy check' : 'Save checkup'}</Button> : null}
            </form>
        </Panel>
    );
}
