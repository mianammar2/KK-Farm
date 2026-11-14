import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Field, LibIcon } from './ui';

/** D-161: the choices sent by the server (Kidding::formOptions). */
export type OutcomeOptions = { outcomes: Record<string, string>; deliveryMethods: Record<string, string>; mother: Record<string, string>; losses: string[] };

type Kid = { sex: 'female' | 'male'; weak: boolean; weight_kg: string };

const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const LITTER = ['None', 'Single', 'Twin', 'Triplet', 'Quadruplet'];

/** The usual delivery method and mother status for an outcome; the user can change both. */
const METHOD_FOR: Record<string, string> = { NKD: 'NRM', AKD: 'AST', CSD: 'CSE', PKD: 'NRM', STB: 'NRM', MBO: 'NRM', ABT: 'NAP', EEL: 'NAP', UNK: 'NAP' };

/**
 * D-161: KK Farms Kidding / Pregnancy Outcome entry. Pregnancy outcome → delivery method → each live kid (sex, weak,
 * birth weight) and stillborn kids by sex → mother's status after delivery, with the vet follow-up. Live kids are
 * registered as goats on save. An abortion asks for the suspected cause and raises a vet review and a biosecurity task.
 */
export default function PregnancyOutcomeForm({ matingId, damNumber, matedOn, options, onDone }: { matingId: number; damNumber: string; matedOn?: string; options: OutcomeOptions; onDone: () => void }) {
    const form = useForm({
        outcome: 'NKD', kidded_on: today(), delivery_method: 'NRM', premature: false,
        kids: [{ sex: 'female', weak: false, weight_kg: '' }] as Kid[], still_male: '0', still_female: '0',
        mother_status: 'LCT', complications: '', abortion_cause: '', diagnosis: '', vet_officer: '', follow_up_on: '', attendant: '', notes: '',
    });
    const [copied, setCopied] = useState(false);
    const d = form.data;
    const e = form.errors as Record<string, string>;
    const loss = options.losses.includes(d.outcome);
    const alive = loss ? 0 : d.kids.length;
    const still = loss ? 0 : Number(d.still_male || 0) + Number(d.still_female || 0);
    const total = alive + still;
    const gestation = matedOn ? Math.round((Date.parse(d.kidded_on) - Date.parse(matedOn)) / 86400000) : null;

    const setOutcome = (o: string) => {
        const isLoss = options.losses.includes(o);
        form.setData((x) => ({
            ...x, outcome: o, delivery_method: METHOD_FOR[o] ?? x.delivery_method, premature: o === 'PKD',
            kids: isLoss || o === 'STB' ? [] : x.kids.length ? x.kids : [{ sex: 'female', weak: false, weight_kg: '' }],
            still_male: isLoss ? '0' : o === 'STB' || o === 'MBO' ? (Number(x.still_male) + Number(x.still_female) ? x.still_male : '1') : x.still_male,
            mother_status: isLoss ? 'PAR' : o === 'STB' ? 'OBS' : x.mother_status === 'PAR' ? 'LCT' : x.mother_status,
        }));
    };
    const setKid = (i: number, k: Partial<Kid>) => form.setData('kids', d.kids.map((x, j) => (j === i ? { ...x, ...k } : x)));
    const summary = [
        `KK Farms – ${options.outcomes[d.outcome]} – ${damNumber}`,
        `Date: ${d.kidded_on}${gestation !== null ? ` (day ${gestation} of pregnancy)` : ''}`,
        loss ? `Suspected cause: ${d.abortion_cause || '—'}` : `Delivery: ${options.deliveryMethods[d.delivery_method]}`,
        `Total kids recorded: ${total} · Live born: ${alive}${d.kids.some((k) => k.weak) && !loss ? ` (${d.kids.filter((k) => k.weak).length} weak)` : ''} · Stillborn: ${still}`,
        `Mother: ${options.mother[d.mother_status]}`,
    ].join('\n');

    return (
        <form onSubmit={(ev) => { ev.preventDefault(); form.transform((x) => ({ ...x, kids: loss ? [] : x.kids, still_male: loss ? 0 : x.still_male, still_female: loss ? 0 : x.still_female })); form.post(`/breeding/matings/${matingId}/kidding`, { preserveScroll: true, onSuccess: onDone }); }}
            className="space-y-4 rounded-tile bg-glaze p-3 sm:p-4">
            <div className="flex items-center gap-2">
                <LibIcon name={loss ? 'ic-sick' : 'ic-kidding'} size={30} />
                <div>
                    <h3 className="font-bold text-cobalt-900">Kidding / Pregnancy Outcome</h3>
                    <p className="text-xs text-ink-soft">{damNumber}{matedOn ? ` · mated ${matedOn}` : ''} · live kids are registered as goats when you save</p>
                </div>
            </div>

            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Pregnancy outcome" error={e.outcome}>
                    <select className="field" value={d.outcome} onChange={(x) => setOutcome(x.target.value)}>
                        {Object.entries(options.outcomes).map(([k, v]) => <option key={k} value={k}>{v} ({k})</option>)}
                    </select>
                </Field>
                <Field label={loss ? 'Pregnancy loss date' : 'Delivery date'} error={e.kidded_on}><input type="date" max={today()} min={matedOn} className="field" value={d.kidded_on} onChange={(x) => form.setData('kidded_on', x.target.value)} /></Field>
                {loss ? null : (
                    <Field label="Delivery method" error={e.delivery_method}>
                        <select className="field" value={d.delivery_method} onChange={(x) => form.setData('delivery_method', x.target.value)}>
                            {Object.entries(options.deliveryMethods).map(([k, v]) => <option key={k} value={k}>{v} ({k})</option>)}
                        </select>
                    </Field>
                )}
            </div>

            {loss ? (
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Suspected abortion cause" error={e.abortion_cause} hint="What was seen; the vet confirms the cause"><input className="field" value={d.abortion_cause} onChange={(x) => form.setData('abortion_cause', x.target.value)} /></Field>
                    <Field label="Veterinary diagnosis" error={e.diagnosis} hint="Leave empty until the vet decides"><input className="field" value={d.diagnosis} onChange={(x) => form.setData('diagnosis', x.target.value)} /></Field>
                    <p className="flex items-start gap-2 rounded-tile bg-crit-100 px-3 py-2 text-sm text-crit-700 sm:col-span-2"><LibIcon name="ic-sick" size={18} />Saving raises a vet review and a biosecurity task: isolate her, keep the aborted material for the vet and wear gloves.</p>
                </div>
            ) : (
                <div className="space-y-2">
                    {d.outcome === 'STB' ? null : (
                        <div className="rounded-tile bg-white p-2.5 ring-1 ring-rule">
                            <div className="mb-2 flex items-center justify-between gap-2">
                                <span className="flex items-center gap-1.5 text-sm font-semibold text-cobalt-900"><LibIcon name="cls-kid" size={22} />Live Born Kids · {d.kids.length}</span>
                                <span className="flex gap-1.5">
                                    {d.kids.length < 6 ? <Button type="button" variant="secondary" onClick={() => form.setData('kids', [...d.kids, { sex: 'female', weak: false, weight_kg: '' }])}>+ Kid</Button> : null}
                                    {d.kids.length > 1 ? <Button type="button" variant="quiet" onClick={() => form.setData('kids', d.kids.slice(0, -1))}>− Kid</Button> : null}
                                </span>
                            </div>
                            <ul className="space-y-1.5">
                                {d.kids.map((k, i) => (
                                    <li key={i} className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="w-12 font-semibold text-ink-soft">Kid {i + 1}</span>
                                        <span className="inline-flex overflow-hidden rounded-full ring-1 ring-rule">
                                            {(['female', 'male'] as const).map((sx) => (
                                                <button key={sx} type="button" onClick={() => setKid(i, { sex: sx })} className={`inline-flex items-center gap-1 px-2.5 py-1 text-xs font-bold ${k.sex === sx ? (sx === 'female' ? 'bg-pink-100 text-pink-700' : 'bg-sky-100 text-sky-700') : 'bg-white text-ink-soft'}`}>
                                                    <LibIcon name={sx === 'female' ? 'ic-female' : 'ic-male'} size={14} className="bg-transparent" />{sx === 'female' ? 'Female' : 'Male'}
                                                </button>
                                            ))}
                                        </span>
                                        <label className="inline-flex items-center gap-1 text-xs font-semibold"><input type="checkbox" checked={k.weak} onChange={(x) => setKid(i, { weak: x.target.checked })} /> Born weak</label>
                                        <input type="number" step="0.1" min={0.3} max={15} placeholder="Birth weight KG" className="field !w-36 !py-1" value={k.weight_kg} onChange={(x) => setKid(i, { weight_kg: x.target.value })} />
                                        {e[`kids.${i}.weight_kg`] ? <span className="text-xs text-crit-700">{e[`kids.${i}.weight_kg`]}</span> : null}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Stillborn male" error={e.still_male}><input type="number" min={0} max={6} className="field" value={d.still_male} onChange={(x) => form.setData('still_male', x.target.value)} /></Field>
                        <Field label="Stillborn female" error={e.still_female}><input type="number" min={0} max={6} className="field" value={d.still_female} onChange={(x) => form.setData('still_female', x.target.value)} /></Field>
                        <label className="flex items-end gap-2 pb-2 text-sm font-semibold"><input type="checkbox" checked={d.premature} onChange={(x) => form.setData('premature', x.target.checked)} /> Premature (before term)</label>
                    </div>
                </div>
            )}

            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Mother's current status" error={e.mother_status}>
                    <select className="field" value={d.mother_status} onChange={(x) => form.setData('mother_status', x.target.value)}>
                        {Object.entries(options.mother).map(([k, v]) => <option key={k} value={k}>{v} ({k})</option>)}
                    </select>
                </Field>
                <Field label="Veterinary officer" error={e.vet_officer}><input className="field" value={d.vet_officer} onChange={(x) => form.setData('vet_officer', x.target.value)} /></Field>
                <Field label="Follow-up date" error={e.follow_up_on}><input type="date" min={d.kidded_on} className="field" value={d.follow_up_on} onChange={(x) => form.setData('follow_up_on', x.target.value)} /></Field>
                {loss ? null : <Field label="Complications" error={e.complications}><input className="field" value={d.complications} onChange={(x) => form.setData('complications', x.target.value)} /></Field>}
                <Field label="Attendant" error={e.attendant} hint="Leave empty for yourself"><input className="field" value={d.attendant} onChange={(x) => form.setData('attendant', x.target.value)} /></Field>
                <Field label="Notes" error={e.notes}><input className="field" value={d.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
            </div>

            {/* Event summary */}
            <div className="rounded-tile bg-white p-3 ring-1 ring-rule">
                <div className="mb-2 flex items-center justify-between gap-2">
                    <span className="text-xs font-bold uppercase tracking-wide text-ink-soft">Event Summary</span>
                    <button type="button" className="text-xs font-semibold text-cobalt-800 hover:underline" onClick={() => { navigator.clipboard?.writeText(summary).then(() => { setCopied(true); setTimeout(() => setCopied(false), 1500); }).catch(() => {}); }}>{copied ? 'Copied' : 'Copy Event Summary'}</button>
                </div>
                <div className="grid grid-cols-3 gap-2 text-center">
                    {[
                        ['Total Kids', total], ['Live Born', alive], ['Stillborn', still],
                        ['Litter', LITTER[total] ?? '5+'], ['Gestation', gestation !== null ? `${gestation} days` : '—'],
                    ].map(([l, v]) => (
                        <div key={l as string} className="rounded-lg bg-glaze px-2 py-1.5"><div className="text-base font-extrabold text-cobalt-900">{v}</div><div className="text-xs text-ink-soft">{l}</div></div>
                    ))}
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <Button type="submit" disabled={form.processing}>{loss ? `Save ${options.outcomes[d.outcome]}` : alive ? `Save and register ${alive} kid${alive === 1 ? '' : 's'}` : 'Save outcome'}</Button>
                <Button type="button" variant="secondary" onClick={onDone}>Cancel</Button>
            </div>
        </form>
    );
}
