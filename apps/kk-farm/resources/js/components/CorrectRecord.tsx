import { useForm } from '@inertiajs/react';
import { Button, Field } from './ui';

/**
 * D-311: correct a record from the Operations Log: the record itself changes, so every page, report and total that
 * reads it shows the corrected value; the old values stay in the goat's history with the reason.
 */
type Field = { key: string; label: string; type: 'date' | 'number' | 'text'; value: string | number | null };
type Fix = Record<string, unknown> & { id: number; fields?: Field[] };
const today = () => new Date().toISOString().slice(0, 10);

const URL: Record<string, (id: number) => string> = {
    weight: (id) => `/weights/${id}`, height: (id) => `/heights/${id}`, checkup: (id) => `/health/checkups/${id}`,
};

export default function CorrectRecord(props: { op: string; label: string; goat: string | null; fix: Fix; onDone: () => void }) {
    return props.fix.fields ? <Generic {...props} fields={props.fix.fields} /> : <Special {...props} />;
}

/** D-313: any other operation: the fields the log allows, posted to the one correction action. */
function Generic({ op, label, goat, fix, fields, onDone }: { op: string; label: string; goat: string | null; fix: Fix; fields: Field[]; onDone: () => void }) {
    const form = useForm<{ op: string; id: number; reason: string; values: Record<string, string> }>({ op, id: fix.id, reason: '', values: Object.fromEntries(fields.map((f) => [f.key, f.value === null ? '' : String(f.value)])) });
    const e = form.errors as Record<string, string>;
    const save = (ev: React.FormEvent) => {
        ev.preventDefault();
        form.transform((d) => ({ ...d, values: Object.fromEntries(Object.entries(d.values).map(([k, v]) => [k, v === '' ? null : v])) }));
        form.post('/reports/operations/correct', { preserveScroll: true, onSuccess: onDone });
    };
    return (
        <form onSubmit={save} className="space-y-3">
            <div>
                <h2 className="text-lg text-cobalt-900">Correct {label.toLowerCase()}{goat ? ` · ${goat}` : ''}</h2>
                <p className="text-sm text-ink-soft">The record itself is corrected, so every page and report shows the new value. The old value stays in the history.</p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                {fields.map((f) => (
                    <div key={f.key} className={f.type === 'text' ? 'sm:col-span-2' : undefined}>
                        <Field label={f.label} error={e[`values.${f.key}`]}>
                            <input className="field" type={f.type === 'date' ? 'date' : 'text'} inputMode={f.type === 'number' ? 'decimal' : undefined} value={form.data.values[f.key] ?? ''}
                                onChange={(x) => form.setData('values', { ...form.data.values, [f.key]: x.target.value })} />
                        </Field>
                    </div>
                ))}
                <div className="sm:col-span-2"><Field label="Why the correction" error={e.reason}><input className="field" value={form.data.reason} onChange={(x) => form.setData('reason', x.target.value)} /></Field></div>
            </div>
            <div className="flex gap-2"><Button type="submit" disabled={form.processing}>Save correction</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}

function Special({ op, goat, fix, onDone }: { op: string; goat: string | null; fix: Fix; onDone: () => void }) {
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
                {op === 'weight' ? input('weight_kg', 'Weight (KG)', { inputMode: 'decimal', required: true }) : null}
                {op === 'height' ? input('height_in', 'Height (in)', { inputMode: 'decimal', required: true }) : null}
                {op === 'checkup' ? <>
                    {input('temperature_f', 'Temperature (°F)', { inputMode: 'decimal' })}
                    {input('weight_kg', 'Weight (KG)', { inputMode: 'decimal' })}
                    <Field label="Result" error={e.outcome}><select className="field" value={form.data.outcome} onChange={(x) => form.setData('outcome', x.target.value)}><option value="healthy">Healthy</option><option value="monitor">Watch</option><option value="sick">Sick</option></select></Field>
                    <div className="sm:col-span-2">{input('findings', 'Findings')}</div>
                </> : null}
                <div className="sm:col-span-2">{input('reason', 'Why the correction', { placeholder: 'e.g. typed 27 instead of 72' })}</div>
            </div>
            <div className="flex gap-2"><Button type="submit" disabled={form.processing}>Save correction</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}
