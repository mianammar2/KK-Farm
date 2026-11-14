import { useForm } from '@inertiajs/react';
import { Button, Field } from './ui';

/**
 * D-311: correct a record from the Operations Log: the record itself changes, so every page, report and total that
 * reads it shows the corrected value; the old values stay in the goat's history with the reason.
 */
type Fix = Record<string, string | number | null> & { id: number };
const today = () => new Date().toISOString().slice(0, 10);

const URL: Record<string, (id: number) => string> = {
    weight: (id) => `/weights/${id}`, height: (id) => `/heights/${id}`, checkup: (id) => `/health/checkups/${id}`,
};

export default function CorrectRecord({ op, goat, fix, onDone }: { op: string; goat: string | null; fix: Fix; onDone: () => void }) {
    const { id, ...start } = fix;
    const form = useForm<Record<string, string>>({ ...Object.fromEntries(Object.entries(start).map(([k, v]) => [k, v === null ? '' : String(v)])), reason: '' });
    const e = form.errors as Record<string, string>;
    const input = (key: string, label: string, props: Record<string, unknown> = {}) => (
        <Field label={label} error={e[key]}><input className="field" value={form.data[key] ?? ''} onChange={(x) => form.setData(key, x.target.value)} {...props} /></Field>
    );
    const save = (ev: React.FormEvent) => {
        ev.preventDefault();
        form.transform((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v])));
        form.post(URL[op](id), { preserveScroll: true, onSuccess: onDone });
    };
    const date = op === 'checkup' ? 'checked_on' : 'measured_on';
    return (
        <form onSubmit={save} className="space-y-3">
            <div>
                <h2 className="text-lg text-cobalt-900">Correct {op === 'checkup' ? 'checkup' : op}{goat ? ` · ${goat}` : ''}</h2>
                <p className="text-sm text-ink-soft">The record itself is corrected, so every page and report shows the new value. The old value stays in the goat's history.</p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                {input(date, 'Date', { type: 'date', max: today(), required: true })}
                {op === 'weight' ? <>{input('weight_kg', 'Weight (KG)', { inputMode: 'decimal', required: true })}{input('body_score', 'Body score (1–5)', { type: 'number', min: 1, max: 5 })}</> : null}
                {op === 'height' ? input('height_in', 'Height (in)', { inputMode: 'decimal', required: true }) : null}
                {op === 'checkup' ? <>
                    {input('temperature_f', 'Temperature (°F)', { inputMode: 'decimal' })}
                    {input('weight_kg', 'Weight (KG)', { inputMode: 'decimal' })}
                    {input('body_score', 'Body score (1–5)', { type: 'number', min: 1, max: 5 })}
                    <Field label="Result" error={e.outcome}><select className="field" value={form.data.outcome} onChange={(x) => form.setData('outcome', x.target.value)}><option value="healthy">Healthy</option><option value="monitor">Watch</option><option value="sick">Sick</option></select></Field>
                    <div className="sm:col-span-2">{input('findings', 'Findings')}</div>
                </> : null}
                <div className="sm:col-span-2">{input('reason', 'Why the correction', { placeholder: 'e.g. typed 27 instead of 72' })}</div>
            </div>
            <div className="flex gap-2"><Button type="submit" disabled={form.processing}>Save correction</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}
