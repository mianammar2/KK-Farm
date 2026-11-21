import { router } from '@inertiajs/react';
import { Check, Plus, ScanLine, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { type Profile } from './GoatProfileCard';
import { doseFill, doseUnit, MedicinePicker, rankMedicines, type PickMedicine, type Suggest } from './StockForms';
import { Button, cx, Field } from './ui';

/*
 * D-368: Medication, from the Veterinary app's button, in one pop card and in order so nothing is missed:
 *   1 Scan the goat (the gun's trigger, or the Tag ID typed)
 *   2 When it was given: date and time
 *   3 Why: one of the goat's open cases, or a new reason (disease / condition) that opens a case
 *   4 Every medicine given at that time: from the store or typed, dose, route, how often, withdrawal (the vet's own)
 *   5 Check and save: all of it on one screen, then saved together
 */

export type MedCase = { id: number; label: string; goatId: string; condition: string; diseaseId: number | null; severity: string; active: boolean; onset: string };
type Med = PickMedicine & { unit: string };
type Item = { medicine_id: string; qty_used: string; product: string; dose: string; route: string; frequency: string; days_prescribed: string; qty_per_dose: string; review_on: string; meat_clear_on: string; milk_clear_on: string; no_withdrawal: boolean; cost: string; notes: string };

const pad = (n: number) => String(n).padStart(2, '0');
const nowDate = () => { const d = new Date(); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`; };
const nowTime = () => { const d = new Date(); return `${pad(d.getHours())}:${pad(d.getMinutes())}`; };
const dmy = (iso: string) => (iso ? iso.split('-').reverse().join('/') : '—');
const blank = (): Item => ({ medicine_id: '', qty_used: '', product: '', dose: '', route: 'injectable', frequency: 'once', days_prescribed: '', qty_per_dose: '', review_on: '', meat_clear_on: '', milk_clear_on: '', no_withdrawal: false, cost: '', notes: '' });
/** D-372: the route from what the medicine is: an injection (CC, or "inj" in the name), by mouth, or on the skin. */
function guessRoute(name: string, unit: string): string | null {
    const n = name.toLowerCase();
    if (/inj|vial|\bla\b/.test(n) || unit === 'ml' || unit === 'vial') return 'injectable';
    if (/tab|bolus|oral|drench|powder|sachet|syrup/.test(n) || ['tablet', 'bolus', 'sachet'].includes(unit)) return 'oral';
    if (/oil|spray|cream|ointment|wound|lotion|gel/.test(n) || unit === 'tube') return 'topical';
    return null;
}

const STEPS = ['Scan', 'When', 'Medicines', 'Check']; // D-370: no Why step: the goat's open case, or one named after the medicines

export default function MedicationForm({ cases, medicines, suggest, routes, frequencies, severities, diseases, onDone }: {
    cases: MedCase[]; medicines: Med[]; suggest: Suggest; routes: Record<string, string>; frequencies: Record<string, string>; severities: Record<string, string>; diseases: Array<{ id: number; name: string }>; onDone: () => void;
}) {
    const [step, setStep] = useState(0);
    const [goat, setGoat] = useState<Profile | null>(null);
    const [code, setCode] = useState('');
    const [finding, setFinding] = useState(false);
    const [scanError, setScanError] = useState<string | null>(null);
    const [date, setDate] = useState(nowDate());
    const [time, setTime] = useState(nowTime()); // D-376: now, changed when the dose was given earlier
    const [caseId, setCaseId] = useState<string>('new');
    const [items, setItems] = useState<Item[]>([blank()]);
    const [problems, setProblems] = useState<string[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const stepRef = useRef(step);
    stepRef.current = step;

    const open = goat ? cases.filter((c) => c.goatId === goat.id && c.active) : [];
    const chosen = open.find((c) => String(c.id) === caseId) ?? null;
    const diseaseId = chosen?.diseaseId ?? null;

    const find = (raw: string) => {
        const c = raw.trim();
        if (!c) return;
        setCode(c); setFinding(true); setScanError(null);
        fetch(`/lookup/goat?code=${encodeURIComponent(c)}`, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((p: Profile) => {
                if (p.status !== 'active') { setScanError(`${p.number} has left the farm. Medication cannot be recorded for it.`); return; }
                setGoat(p);
                const firstOpen = cases.find((x) => x.goatId === p.id && x.active);
                setCaseId(firstOpen ? String(firstOpen.id) : 'new');
                setStep(1);
            })
            .catch(() => setScanError(`No goat on the farm has the tag ${c}. Scan again or type the Tag ID.`))
            .finally(() => setFinding(false));
    };

    // the gun's trigger fills the scan while this card waits for a goat
    useEffect(() => {
        const w = window as unknown as { kkScan?: (code: string) => boolean };
        const before = w.kkScan;
        w.kkScan = (c: string) => { if (stepRef.current === 0) { find(c); return true; } return before ? before(c) : false; };
        return () => { w.kkScan = before; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /** D-369: choosing a medicine fills its standard dose (set by the vet on the medicine); every field stays editable. */
    const pick = (i: number, id: string) => {
        const m = medicines.find((x) => String(x.id) === id);
        const fill = doseFill(m, date) as Partial<Item>;
        set(i, { medicine_id: id, route: guessRoute(m?.name ?? '', m?.unit ?? '') ?? 'injectable', ...fill, ...(fill.qty_used ? { dose: `${fill.qty_used} ${doseUnit(m?.unit)}`.trim() } : {}) });
    };
    const suggested = rankMedicines(medicines, suggest, { diseaseId }).suggested.filter(({ m }) => m.qty > 0).slice(0, 4);
    const addSuggested = (id: number) => {
        const empty = items.findIndex((x) => !x.medicine_id && !x.product.trim());
        if (empty >= 0) { pick(empty, String(id)); return; }
        setItems((l) => [...l, blank()]);
        setTimeout(() => pick(items.length, String(id)), 0);
    };
    const set = (i: number, patch: Partial<Item>) => { setProblems([]); setItems((list) => list.map((x, k) => (k === i ? { ...x, ...patch } : x))); };
    const medOf = (it: Item) => medicines.find((m) => String(m.id) === it.medicine_id);
    const nameOf = (it: Item) => medOf(it)?.name ?? it.product;

    /** What is still missing before the next step: said in words, nothing skipped. */
    const missing = (s: number): string[] => {
        const out: string[] = [];
        if (s === 0 && !goat) out.push('Scan the goat or type its Tag ID.');
        if (s === 1) {
            if (!date) out.push('The date given.');
            if (!time) out.push('The dose time: when the dose was given.');
            if (date && time && new Date(`${date}T${time}`) > new Date()) out.push('The date and time cannot be later than now.');
        }
        if (s === 2) items.forEach((it, i) => { // D-373: only the medicine and the quantity are asked for now
            const n = `Medicine ${i + 1}`;
            if (!it.medicine_id && !it.product.trim()) out.push(`${n}: choose it from the store or type its name.`);
            if (it.medicine_id && !(Number(it.qty_used) > 0)) out.push(`${n}: the quantity.`);
            if (!it.medicine_id && !it.dose.trim()) out.push(`${n}: the quantity (e.g. 5 CC).`);
            if (it.frequency !== 'once' && !(Number(it.days_prescribed) > 0)) out.push(`${n}: set its standard dose days in the store, or it is given once.`);
        });
        return out;
    };
    const next = () => { const m = missing(step); setProblems(m); if (!m.length) setStep(step + 1); };

    const save = () => {
        const all = [0, 1, 2].flatMap(missing);
        if (all.length) { setProblems(all); return; }
        setSaving(true); setErrors({});
        router.post('/health/medications', {
            animal_id: goat!.id, given_on: date, given_time: time,
            case_id: caseId === 'new' ? null : Number(caseId), reason: null, severity: null, symptoms: null, isolated: false,
            items: items.map((it) => ({ ...it, medicine_id: it.medicine_id || null, qty_used: it.qty_used || null, product: it.medicine_id ? null : it.product, frequency: it.frequency || null,
                days_prescribed: it.frequency === 'once' ? null : it.days_prescribed || null, qty_per_dose: it.qty_per_dose || null, review_on: it.review_on || null,
                meat_clear_on: it.no_withdrawal ? null : it.meat_clear_on || null, milk_clear_on: it.no_withdrawal ? null : it.milk_clear_on || null, notes: it.notes || null })),
        }, { preserveScroll: true, onSuccess: onDone, onError: (e) => setErrors(e as Record<string, string>), onFinish: () => setSaving(false) });
    };

    return (
        <div className="space-y-4">
            <div>
                <h2 className="text-lg font-semibold text-cobalt-900">Medication</h2>
                <ol className="mt-2 grid grid-cols-5 gap-1" aria-label="Steps">
                    {STEPS.map((s, i) => (
                        <li key={s}>
                            <button type="button" disabled={i > step} onClick={() => { setProblems([]); setStep(i); }}
                                className={cx('flex w-full flex-col items-center gap-1 rounded-md py-1 text-xs', i === step ? 'font-semibold text-cobalt-900' : i < step ? 'text-ok-700' : 'text-ink-faint')}>
                                <span className={cx('grid size-7 place-items-center rounded-full text-sm', i === step ? 'bg-cobalt-800 text-white' : i < step ? 'bg-ok-100 text-ok-700' : 'bg-glaze text-ink-faint')}>{i < step ? <Check size={14} aria-hidden /> : i + 1}</span>
                                {s}
                            </button>
                        </li>
                    ))}
                </ol>
            </div>

            {goat && step > 0 ? (
                <p className="flex flex-wrap items-center gap-x-2 rounded-tile bg-glaze px-3 py-2 text-sm">
                    <span className="font-mono font-semibold text-cobalt-900">{goat.number}</span>
                    <span className="text-ink-soft">{[goat.breed, goat.sex === 'female' ? 'Female' : 'Male', goat.pen].filter(Boolean).join(' · ')}</span>
                    {step > 1 ? <span className="text-ink-soft">· {dmy(date)} {time}</span> : null}
                </p>
            ) : null}

            {step === 0 ? (
                <div className="space-y-3">
                    <div className="rounded-2xl bg-cobalt-900 px-4 py-6 text-center text-white">
                        <ScanLine size={36} className="mx-auto" aria-hidden />
                        <p className="mt-2 text-lg font-semibold">{finding ? 'Finding the goat…' : 'Scan the goat'}</p>
                        <p className="text-sm text-cobalt-200">Pull the gun's trigger at its ear tag, or type the Tag ID.</p>
                    </div>
                    <form onSubmit={(e) => { e.preventDefault(); find(code); }} className="flex gap-2">
                        <input className="field flex-1 font-mono uppercase" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} placeholder="Tag ID or RFID" autoComplete="off" aria-label="Tag ID or RFID" />
                        <Button type="submit" disabled={finding || !code.trim()}>Find</Button>
                    </form>
                    {scanError ? <p className="text-sm text-crit-700">{scanError}</p> : null}
                </div>
            ) : null}

            {step === 1 && goat ? (
                <div className="space-y-3">{/* D-372: only the date and dose time */}
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Date given"><input type="date" className="field" max={nowDate()} value={date} onChange={(e) => { setDate(e.target.value); setProblems([]); }} /></Field>
                        <Field label="Dose time" hint="Now; change it if the dose was given earlier"><input type="time" className="field" value={time} onChange={(e) => { setTime(e.target.value); setProblems([]); }} /></Field>
                    </div>
                </div>
            ) : null}

            {step === 2 ? (
                <div className="space-y-3">
                    {suggested.length ? (
                        <div className="rounded-tile border border-ok-700/30 bg-ok-100/50 p-3">
                            <p className="mb-2 text-sm font-semibold text-ok-700">Suggested from your medicine store{chosen ? ` for ${chosen.condition}` : ''}. Tap to add it with its standard dose; you decide.</p>
                            <div className="flex flex-wrap gap-2">
                                {suggested.map(({ m, why }) => (
                                    <button key={m.id} type="button" onClick={() => addSuggested(m.id)} disabled={items.some((x) => x.medicine_id === String(m.id))}
                                        className="inline-flex min-h-11 flex-col items-start rounded-tile border border-rule bg-tile px-3 py-1.5 text-left text-sm disabled:opacity-50">
                                        <span className="font-semibold text-cobalt-900"><Plus size={13} className="inline" aria-hidden /> {m.name}</span>
                                        <span className="text-xs text-ink-soft">{why} · {m.qty} {m.unit} in stock</span>
                                    </button>
                                ))}
                            </div>
                        </div>
                    ) : null}
                    {items.map((it, i) => {
                        const med = medOf(it);
                        const e = (k: string) => errors[`items.${i}.${k}`];
                        return (
                            <fieldset key={i} className="space-y-3 rounded-tile border border-rule p-3">
                                <legend className="flex w-full items-center justify-between px-1 text-sm font-semibold text-cobalt-900">
                                    Medicine {i + 1}{nameOf(it) ? `: ${nameOf(it)}` : ''}
                                    {items.length > 1 ? <button type="button" onClick={() => setItems((l) => l.filter((_, k) => k !== i))} className="inline-flex items-center gap-1 text-xs font-normal text-crit-700"><Trash2 size={14} aria-hidden /> Remove</button> : null}
                                </legend>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <Field label="Medicine" error={e('medicine_id')}>
                                        <MedicinePicker medicines={medicines} suggest={suggest} diseaseId={diseaseId} value={it.medicine_id} onChange={(id) => pick(i, id)} />
                                    </Field>
                                    {it.medicine_id ? (
                                        <Field label={`Quantity (${doseUnit(med?.unit)})`} error={e('qty_used') ?? e('dose')} hint="Taken from the store">
                                            <input type="number" inputMode="decimal" min={0} step="0.1" className="field" value={it.qty_used} onChange={(x) => set(i, { qty_used: x.target.value, dose: x.target.value ? `${x.target.value} ${doseUnit(med?.unit)}`.trim() : '' })} />
                                        </Field>
                                    ) : (
                                        <>
                                            <Field label="Medicine name" error={e('product')}><input className="field" value={it.product} onChange={(x) => set(i, { product: x.target.value, route: guessRoute(x.target.value, '') ?? it.route })} /></Field>
                                            <Field label="Quantity" error={e('dose')} hint="e.g. 5 CC, 2 tablets"><input className="field" value={it.dose} onChange={(x) => set(i, { dose: x.target.value })} /></Field>
                                        </>
                                    )}
                                </div>
                            </fieldset>
                        );
                    })}
                    {items.length < 10 ? <Button type="button" variant="secondary" onClick={() => setItems((l) => [...l, blank()])}><Plus size={16} aria-hidden /> Add another medicine</Button> : null}
                </div>
            ) : null}

            {step === 3 && goat ? (
                <div className="space-y-3 text-sm">
                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 rounded-tile border border-rule p-3">
                        <dt className="text-ink-soft">Case</dt><dd>{chosen ? `${chosen.condition} (${chosen.label})` : 'A new case named after these medicines'}</dd>
                    </dl>
                    {items.map((it, i) => (
                        <div key={i} className="rounded-tile border border-rule p-3">
                            <p className="font-semibold text-cobalt-900">{i + 1}. {nameOf(it)}{it.medicine_id ? ` · ${it.qty_used} ${doseUnit(medOf(it)?.unit)} (${it.qty_used} ${medOf(it)?.unit ?? ''} from the store)` : ''}</p>
                            <p>{it.dose} · {routes[it.route] ?? it.route} · {frequencies[it.frequency] ?? it.frequency}{it.frequency !== 'once' ? ` for ${it.days_prescribed} days` : ''}</p>
                            {it.meat_clear_on || it.milk_clear_on ? <p className="text-watch-700">Meat clear {dmy(it.meat_clear_on)} · milk clear {dmy(it.milk_clear_on)} (from the label days set on the medicine)</p> : null}
                            {it.review_on || it.notes ? <p className="text-ink-soft">{[it.review_on ? `review ${dmy(it.review_on)}` : '', it.notes].filter(Boolean).join(' · ')}</p> : null}
                        </div>
                    ))}
                    {Object.keys(errors).length ? <ul className="rounded-tile bg-crit-100 p-3 text-crit-700">{Object.values(errors).map((m, i) => <li key={i}>{m}</li>)}</ul> : null}
                </div>
            ) : null}

            {problems.length ? <ul className="rounded-tile bg-watch-100 p-3 text-sm text-watch-700" role="alert">{problems.map((p) => <li key={p}>{p}</li>)}</ul> : null}

            {step > 0 ? (
                <div className="sticky bottom-0 flex gap-2 bg-glaze pt-2">
                    <Button type="button" variant="secondary" onClick={() => { setProblems([]); setStep(step - 1); }}>Back</Button>
                    {step < 3 ? <Button type="button" className="flex-1" onClick={next}>Next</Button> : <Button type="button" className="flex-1" disabled={saving} onClick={save}>{saving ? 'Saving…' : `Save ${items.length === 1 ? 'medication' : `${items.length} medicines`}`}</Button>}
                </div>
            ) : null}
        </div>
    );
}
