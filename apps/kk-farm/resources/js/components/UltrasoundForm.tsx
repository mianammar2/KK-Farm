import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Field, LibIcon } from './ui';

/** D-166: choices sent by the server (PregnancyCheck::formOptions). */
export type UltrasoundOptions = { ultrasound: Record<string, string>; viability: Record<string, string>; checkMethods: Record<string, string>; repeat: string[] };

const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const plusDays = (n: number) => new Date(Date.now() + n * 86400000 - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

const PREGNANT = ['PCF', 'VPG', 'MPG'];

export const fetalLabel = (n: string) => ({ '': 'Fetal Count Not Confirmed', '0': 'No fetus detected', '1': 'Single Pregnancy', '2': 'Twin Pregnancy', '3': 'Triplet Pregnancy' } as Record<string, string>)[n] ?? 'Multiple Pregnancy';

/** Recommended ultrasound activity for the day after mating (KK Farms timing). */
export const scanWindow = (day: number) => day < 20 ? 'Awaiting Pregnancy Examination' : day < 30 ? 'Early examination only when indicated'
    : day < 45 ? 'Initial Pregnancy Ultrasound' : day <= 85 ? 'Pregnancy Confirmation & Fetal Count' : 'Follow-up examination if clinically needed';

/** The next action the server will also record (PregnancyCheck::action). */
function nextAction(status: string, fetuses: string) {
    if (PREGNANT.includes(status) && (status === 'MPG' || Number(fetuses || 0) >= 2)) return 'Multiple pregnancy: extra nutrition and closer monitoring';
    return ({
        PCF: 'Routine Pregnancy Monitoring', VPG: 'Routine Pregnancy Monitoring', NPG: 'Breeding Reassessment', SPG: 'Repeat Ultrasound', INC: 'Repeat Ultrasound',
        FHN: 'Veterinary Reassessment', SFL: 'Veterinary Alert: suspected fetal loss', CPL: 'Pregnancy Loss Follow-up', HYD: 'Veterinary Investigation and Treatment Plan', ABN: 'Veterinary Investigation',
    } as Record<string, string>)[status] ?? '';
}

/** What saving will do, in plain words. */
const EFFECT: Record<string, string> = {
    PCF: 'Marks her pregnant.', VPG: 'Marks her pregnant.', MPG: 'Marks her pregnant and adds a nutrition and monitoring task.',
    NPG: 'Marks her open and adds a breeding reassessment task.', SPG: 'Adds a repeat ultrasound task.', INC: 'Adds a repeat ultrasound task.',
    FHN: 'Adds a repeat ultrasound task to confirm the heartbeat.', SFL: 'Raises a vet alert. The pregnancy stays open until the vet confirms.',
    CPL: 'Records a pregnancy loss: she moves to Maternity Isolation for 3 days of monitoring, with vet and biosecurity tasks.',
    HYD: 'Marks her not pregnant and adds a vet investigation task.', ABN: 'Adds a vet investigation task.',
};

/**
 * D-166: KK Farms Goat Pregnancy Ultrasound Record: date, result (12 statuses), fetal count, fetal viability, the vet's
 * estimated day of pregnancy (moves the expected kidding date), repeat date, with the timing guide for the day after
 * mating and an Ultrasound Summary that can be copied. Recorded by the examining veterinarian.
 */
export default function UltrasoundForm({ matingId, damNumber, matedOn, options, onDone }: { matingId: number; damNumber: string; matedOn: string; options: UltrasoundOptions; onDone: () => void }) {
    const form = useForm({ ultrasound_status: 'PCF', method: 'ultrasound', checked_on: today(), fetuses: '', viability: 'HBD', estimated_day: '', next_check_on: '', notes: '' });
    const [copied, setCopied] = useState(false);
    const d = form.data;
    const e = form.errors as Record<string, string>;
    const day = Math.max(0, Math.round((Date.parse(d.checked_on) - Date.parse(matedOn)) / 86400000));
    const repeat = options.repeat.includes(d.ultrasound_status);
    const action = nextAction(d.ultrasound_status, d.fetuses);
    const summary = [
        `KK Farms – Ultrasound – ${damNumber} – ${d.checked_on} (day ${day} after mating)`,
        options.ultrasound[d.ultrasound_status],
        `Fetal count: ${d.fetuses === '' ? 'not confirmed' : d.fetuses} • ${options.viability[d.viability] ?? 'Viability not assessed'}`,
        `Next Action: ${action}`,
    ].join('\n');

    return (
        <form onSubmit={(ev) => { ev.preventDefault(); form.transform((x) => ({ ...x, fetuses: x.fetuses === '' ? null : x.fetuses, next_check_on: repeat ? (x.next_check_on || plusDays(14)) : x.next_check_on || null })); form.post(`/breeding/matings/${matingId}/check`, { preserveScroll: true, onSuccess: onDone }); }}
            className="space-y-4 rounded-tile bg-glaze p-3 sm:p-4">
            <div className="flex items-center gap-2">
                <LibIcon name="ic-pregnant" size={30} />
                <div>
                    <h3 className="font-bold text-cobalt-900">Goat Pregnancy Ultrasound Record</h3>
                    <p className="text-xs text-ink-soft">{damNumber} · day {day} after mating · <strong className="text-cobalt-800">{scanWindow(day)}</strong></p>
                </div>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Ultrasound date" error={e.checked_on}><input type="date" min={matedOn} max={today()} className="field" value={d.checked_on} onChange={(x) => form.setData('checked_on', x.target.value)} /></Field>
                <Field label="Ultrasound result" error={e.ultrasound_status}>
                    <select className="field" value={d.ultrasound_status} onChange={(x) => form.setData('ultrasound_status', x.target.value)}>
                        {Object.entries(options.ultrasound).map(([k, v]) => <option key={k} value={k}>{v} ({k})</option>)}
                    </select>
                </Field>
                <Field label="Fetal count" error={e.fetuses}>
                    <select className="field" value={d.fetuses} onChange={(x) => form.setData('fetuses', x.target.value)}>
                        {['', '0', '1', '2', '3', '4', '5'].map((n) => <option key={n} value={n}>{n === '' ? 'Unknown – not confirmed' : `${n === '5' ? '5+' : n} · ${fetalLabel(n)}`}</option>)}
                    </select>
                </Field>
                <Field label="Fetal viability" error={e.viability}>
                    <select className="field" value={d.viability} onChange={(x) => form.setData('viability', x.target.value)}>
                        {Object.entries(options.viability).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                </Field>
                {PREGNANT.includes(d.ultrasound_status) ? (
                    <Field label="Estimated day of pregnancy" error={e.estimated_day} hint="Optional: from the scan; moves the expected kidding date"><input type="number" min={20} max={160} className="field" value={d.estimated_day} onChange={(x) => form.setData('estimated_day', x.target.value)} /></Field>
                ) : null}
                {repeat ? (
                    <Field label="Repeat ultrasound on" error={e.next_check_on} hint="Empty: in 14 days"><input type="date" min={plusDays(1)} className="field" value={d.next_check_on} onChange={(x) => form.setData('next_check_on', x.target.value)} /></Field>
                ) : null}
                <Field label="Method" error={e.method}>
                    <select className="field" value={d.method} onChange={(x) => form.setData('method', x.target.value)}>
                        {Object.entries(options.checkMethods).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                </Field>
                <Field label="Notes" error={e.notes}><input className="field" value={d.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
            </div>

            <div className="rounded-tile bg-white p-3 ring-1 ring-rule">
                <div className="mb-1.5 flex items-center justify-between gap-2">
                    <span className="text-xs font-bold uppercase tracking-wide text-ink-soft">Ultrasound Summary</span>
                    <button type="button" className="text-xs font-semibold text-cobalt-800 hover:underline" onClick={() => { navigator.clipboard?.writeText(summary).then(() => { setCopied(true); setTimeout(() => setCopied(false), 1500); }).catch(() => {}); }}>{copied ? 'Copied' : 'Copy Ultrasound Record'}</button>
                </div>
                <p className="text-base font-extrabold text-cobalt-900">{options.ultrasound[d.ultrasound_status]}</p>
                <p className="text-sm text-ink-soft">Fetal count: {d.fetuses === '' ? 'not confirmed' : d.fetuses} · {fetalLabel(d.fetuses)} · {options.viability[d.viability]}</p>
                <p className="mt-1 text-sm"><span className="font-semibold text-cobalt-900">Next Action:</span> {action}</p>
                <p className="mt-1 text-xs text-ink-soft">{EFFECT[d.ultrasound_status]} Results are recorded by the examining veterinarian.</p>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <Button type="submit" disabled={form.processing}>Save ultrasound</Button>
                <Button type="button" variant="secondary" onClick={onDone}>Cancel</Button>
            </div>
        </form>
    );
}
