import { Head, Link, router, useForm } from '@inertiajs/react';
import { Copy, ScanLine, Sparkles, Tag } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { Fragment, useState, type ReactNode } from 'react';
import PhotoPicker from '../../components/PhotoPicker';
import { Button, Chip, Field, Note, PageHeader, Panel } from '../../components/ui';
import type { Option } from '../../types';

type Choice = { value: string | number; label: string; next?: string; nextNumber?: number; breed?: number | null; code?: string; shed?: number; shedName?: string };
type DamMating = { sireId: string | null; sire: string | null; sireBreed: number | null; status: string; matedOn: string; expected: string | null; expectedLabel: string | null };
type FieldDef = { key: string; label: string; type: string; section: string; help: string | null; builtin: boolean; required: boolean; options: Choice[] | null };
type Classify = { categories: Record<string, string>; statuses: Record<string, string>; purposes: Record<string, string>; kidStages: Record<string, string>; growerFrom: number; adultFrom: number };
type Props = { epc: string | null; fields: FieldDef[]; lookups: Record<string, Choice[]>; classSex: Record<string, string>; classification: Classify; damMatings: Record<string, DamMating>; mixedBreedId: number | null; canAssignTags: boolean; untagged: Option[];
    goat?: { id: string; number: string; values: Record<string, any> } };

/** D-091: measurements and arrival answers are taken once, when registering; later they are new weighings or health records. */
const REGISTER_ONLY = ['rfid_epc', 'latest_weight_kg', 'height_in', 'body_score', 'temperature_f', 'pregnant_at_arrival', 'pregnancy_months', 'lifecycle_status'];
/** D-091: aging by teeth; same as Classification::TEETH_MONTHS. */
const TEETH_MONTHS: Record<string, number> = { milk: 8, '2': 15, '4': 21, '6': 32, '8': 42, worn: 66 };
/** D-093: goat body temperature in °F; same as GoatRegistration::TEMP_NORMAL_F. */
const TEMP_LOW = 101.3;
const TEMP_HIGH = 103.5;

const TODAY = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10); // the device's local date

export default function Register({ epc, fields, lookups, classSex, classification, damMatings, mixedBreedId, canAssignTags, untagged, goat }: Props) {
    const editing = Boolean(goat);
    const [penShed, setPenShed] = useState<number | undefined>(undefined);
    const [estMonths, setEstMonths] = useState('');
    const form = useForm<Record<string, any>>(goat ? { ...goat.values, custom_fields: goat.values.custom_fields ?? {} } : {
        rfid_epc: epc ?? '',
        birth_date_is_estimate: false,
        lifecycle_status: 'active',
        from_scan: Boolean(epc),
        custom_fields: {} as Record<string, any>,
        photo: null as File | null,
    });
    const assign = useForm({ tag: epc ?? '', animal_id: '', reason: 'initial' });

    // D-058: the form asks Origin Type first. A farm-born kid's Birth Date takes the place of the purchase date,
    // right after the breed, so the Tag ID always comes after the date.
    const farmBorn = form.data.origin === 'born_on_farm';
    const ordered = (() => {
        if (!farmBorn) {
            // D-063: a bought goat's birthday is rarely known, so ask its estimated age just before the birth date.
            const at = fields.findIndex((f) => f.key === 'birth_date');
            if (at < 0 || !form.data.origin) return fields;
            const age: FieldDef = { key: 'estimated_age', label: 'Estimated Age (months)', type: 'age', section: fields[at].section, help: null, builtin: true, required: false, options: null };
            return [...fields.slice(0, at), age, ...fields.slice(at)];
        }
        const birth = fields.find((f) => f.key === 'birth_date');
        const at = fields.findIndex((f) => f.key === 'breed_id');
        if (!birth || at < 0) return fields;
        const rest = fields.filter((f) => f.key !== 'birth_date');
        rest.splice(rest.findIndex((f) => f.key === 'breed_id') + 1, 0, { ...birth, section: fields[at].section });
        return rest;
    })();
    const sections = ordered.reduce<Array<{ name: string; fields: FieldDef[] }>>((acc, f) => {
        const group = acc.find((s) => s.name === f.section);
        group ? group.fields.push(f) : acc.push({ name: f.section, fields: [f] });
        return acc;
    }, []);
    const started = Boolean(form.data.origin);
    const shown = started ? sections : sections.filter((s) => s.fields.some((f) => f.key === 'origin')).map((s) => ({ ...s, fields: s.fields.filter((f) => f.key === 'origin') }));
    const labelFor = (f: FieldDef) => (f.key === 'acquired_on' && form.data.origin === 'transferred_in' && f.label === 'Purchase Date' ? 'Arrival Date' : f.label);

    // D-046: Female Status only for females, Kid Stage only for kids.
    // D-053: only a farm-born goat must have a birth date.
    const pregnant = form.data.pregnant_at_arrival === 'yes' || form.data.repro_status === 'pregnant';
    const isRequired = (f: FieldDef) => (f.key === 'birth_date' ? form.data.origin === 'born_on_farm'
        : f.key === 'temperature_f' ? pregnant && !editing // D-093: required for a pregnant goat
            : f.key === 'pregnancy_months' ? form.data.pregnant_at_arrival === 'yes' : f.required);
    // D-067: with a birth date the age category is automatic (and moves on as the goat ages); otherwise chosen by hand.
    const age = form.data.birth_date ? ageCategory(form.data.birth_date, classification) : form.data.age_category;
    const isKid = age === 'kid' || ['male_kid', 'female_kid'].includes(form.data.animal_class);
    const hidden = (f: FieldDef) => (editing && REGISTER_ONLY.includes(f.key))
        || ((f.key === 'pregnant_at_arrival' || f.key === 'pregnancy_months') && (form.data.sex !== 'female' || farmBorn))
        || (f.key === 'pregnancy_months' && form.data.pregnant_at_arrival !== 'yes')
        || (f.key === 'teeth' && farmBorn && !editing)
        || (f.key === 'repro_status' && form.data.sex !== 'female') || (f.key === 'kid_stage' && !isKid)
        || f.key === 'birth_date_is_estimate'
        || (f.key === 'age_category' && Boolean(form.data.birth_date)) // D-065: set automatically (estimate for bought goats, exact for farm-born)
        || (farmBorn && ['acquired_on', 'source_farm', 'purchase_cost_pkr'].includes(f.key))
        || (!farmBorn && f.key === 'birth_weight_kg')
        // D-064: parentage is known only for goats born on the farm.
        || (!farmBorn && ['sire_id', 'dam_id', 'breed_purity', 'cross_breeds'].includes(f.key))
        || (form.data.origin === 'transferred_in' && f.key === 'purchase_cost_pkr');
    const suggested = suggestCategory(form.data.sex, age, form.data.animal_purpose);
    const category = form.data.animal_class || suggested;
    const breedName = (lookups.breed_id ?? []).find((b) => String(b.value) === String(form.data.breed_id ?? ''))?.label;
    const summary = [
        category ? (isKid && form.data.kid_stage ? `${classification.kidStages[form.data.kid_stage]} ${classification.categories[category]}` : classification.categories[category]) : null,
        breedName,
        classification.purposes[form.data.animal_purpose],
        form.data.sex === 'female' ? classification.statuses[form.data.repro_status] : null,
    ].filter(Boolean).join(' · ');
    const value = (f: FieldDef) => (f.builtin ? form.data[f.key] : form.data.custom_fields[f.key]) ?? (f.type === 'yes_no' ? false : '');
    const set = (f: FieldDef, v: any) => {
        if (!f.builtin) {
            return form.setData('custom_fields', { ...form.data.custom_fields, [f.key]: v });
        }
        if (f.key === 'sex') {
            return form.setData((d: Record<string, any>) => ({
                ...d, sex: v,
                animal_class: classSex[d.animal_class] && classSex[d.animal_class] !== v ? '' : d.animal_class,
                repro_status: v === 'female' ? d.repro_status : '',
            }));
        }
        if (f.key === 'dam_id') {
            return form.setData((d: Record<string, any>) => ({ ...d, ...kidDefaults(d, v) }));
        }
        // D-091: the teeth give the estimated age of a bought goat, and from it the estimated birth date.
        if (f.key === 'teeth' && !farmBorn && TEETH_MONTHS[v]) {
            const months = TEETH_MONTHS[v];
            setEstMonths(String(months));
            const d = new Date(`${TODAY}T12:00:00`);
            d.setMonth(d.getMonth() - months);
            return form.setData((data: Record<string, any>) => ({ ...data, teeth: v, birth_date: d.toISOString().slice(0, 10) }));
        }
        if (f.key === 'pregnant_at_arrival' && v === 'yes') {
            return form.setData((d: Record<string, any>) => ({ ...d, pregnant_at_arrival: v, repro_status: 'pregnant' }));
        }
        form.setData(f.key, v);
    };

    /**
     * D-042: choosing the dam of a farm-born kid fills in what the farm already knows: the sire from her
     * mating, the breed (the parents' breed, or Mixed when they differ), the class Kid, and the birth date.
     * Anything the user already chose is kept.
     */
    const kidDefaults = (d: Record<string, any>, damId: string): Record<string, any> => {
        const next: Record<string, any> = { dam_id: damId };
        const mating = damMatings[damId];
        if (!damId || (d.origin && d.origin !== 'born_on_farm')) {
            return next;
        }
        next.origin = 'born_on_farm';
        // Age and category are left to the birth date (D-047), so the kid grows into a grower and adult by itself.
        if (!d.kid_stage) next.kid_stage = 'newborn';
        if (!mating) return next;
        if (!d.sire_id && mating.sireId) next.sire_id = mating.sireId;
        if (!d.birth_date) next.birth_date = mating.expected && mating.expected < TODAY ? mating.expected : TODAY;
        const damBreed = lookups.dam_id?.find((o) => String(o.value) === damId)?.breed;
        const sireBreed = mating.sireBreed;
        if (!d.breed_id && damBreed) next.breed_id = String(sireBreed && sireBreed !== damBreed ? mixedBreedId ?? damBreed : damBreed);
        return next;
    };
    /**
     * D-043: Sire Tag / Dam Tag as in the workbook. Pick a registered goat from the list, or type the ear tag of
     * a parent that is not registered (a purchased goat's parents). That tag links up automatically later.
     */
    const parentInput = (f: FieldDef): ReactNode => {
        const tagKey = f.key === 'sire_id' ? 'sire_tag' : 'dam_tag';
        const options = lookups[f.key] ?? [];
        const picked = options.find((o) => String(o.value) === String(form.data[f.key] ?? ''));
        const text = picked?.code ?? form.data[tagKey] ?? '';
        const typed = !picked && text !== '';
        const change = (raw: string) => {
            const value = raw.toUpperCase().trim();
            const match = options.find((o) => o.code === value);
            if (f.key === 'dam_id' && match) {
                return form.setData((d: Record<string, any>) => ({ ...d, ...kidDefaults(d, String(match.value)), dam_tag: '' }));
            }
            form.setData((d: Record<string, any>) => ({ ...d, [f.key]: match ? String(match.value) : '', [tagKey]: match ? '' : value }));
        };
        return (
            <div>
                <input className="field font-mono" list={`${f.key}-list`} value={text} onChange={(e) => change(e.target.value)} placeholder={options.length ? 'Pick or type the ear tag' : 'Type the ear tag'} autoComplete="off" spellCheck={false} />
                <datalist id={`${f.key}-list`}>{options.map((o) => <option key={o.value} value={o.code}>{o.label}</option>)}</datalist>
                {picked ? <span className="mt-1 inline-flex"><Chip className="bg-turq-100 text-turq-800">Registered: {picked.label}</Chip></span> : null}
                {typed ? <span className="mt-1 block text-sm text-ink-soft">Not registered yet. Saved as a tag and linked automatically when {text} is registered.</span> : null}
                {form.errors[tagKey] ? <span className="mt-1 block text-sm font-medium text-crit-700">{form.errors[tagKey]}</span> : null}
            </div>
        );
    };
    /** Shed / Pen as two linked dropdowns: pick the shed, then only that shed's pens are listed. */
    const penInput = (f: FieldDef): ReactNode => {
        const pens = lookups.pen_id ?? [];
        const sheds = [...new Map(pens.map((p) => [p.shed, p.shedName ?? 'Shed'])).entries()];
        const pickedPen = pens.find((p) => String(p.value) === String(form.data.pen_id ?? ''));
        const currentShed = pickedPen?.shed ?? penShed ?? (sheds.length === 1 ? sheds[0][0] : undefined);
        const shedPens = pens.filter((p) => p.shed === currentShed);
        const chooseShed = (id: string) => {
            const shed = id ? Number(id) : undefined;
            setPenShed(shed);
            const only = pens.filter((p) => p.shed === shed);
            set(f, only.length === 1 ? String(only[0].value) : '');
        };
        if (!pens.length) return <p className="text-sm text-ink-soft">No pens yet. Add a shed and its pens on the Sheds page first.</p>;
        return (
            <div className="grid grid-cols-2 gap-2">
                <select className="field" aria-label="Shed" value={currentShed ?? ''} onChange={(e) => chooseShed(e.target.value)}>
                    {currentShed === undefined ? <option value="">Choose shed…</option> : null}
                    {sheds.map(([id, name]) => <option key={id} value={id}>{name}</option>)}
                </select>
                <select className="field" aria-label="Pen" value={String(form.data.pen_id ?? '')} disabled={currentShed === undefined} onChange={(e) => set(f, e.target.value)}>
                    {!pickedPen ? <option value="">{currentShed === undefined ? 'Choose a shed first' : 'Choose pen…'}</option> : null}
                    {shedPens.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
                </select>
            </div>
        );
    };
    const damMating = form.data.dam_id && form.data.origin === 'born_on_farm' ? damMatings[form.data.dam_id] : undefined;
    const error = (f: FieldDef) => (f.builtin ? form.errors[f.key] : form.errors[`custom_fields.${f.key}`]);

    const control = (f: FieldDef): ReactNode => {
        const v = value(f);
        const optional = f.required ? undefined : <option value="">Not set</option>;
        switch (f.type) {
            case 'age': {
                // D-065: estimated age in months → estimated birth date (today minus the months).
                const change = (raw: string) => {
                    const months = raw.replace(/[^0-9]/g, '').slice(0, 3);
                    setEstMonths(months);
                    if (!Number(months)) return;
                    const d = new Date(`${TODAY}T12:00:00`);
                    d.setMonth(d.getMonth() - Number(months));
                    form.setData((data: Record<string, any>) => ({ ...data, birth_date: d.toISOString().slice(0, 10) }));
                };
                return (
                    <label className="flex items-center gap-2">
                        <input className="field" inputMode="numeric" maxLength={3} value={estMonths} onChange={(e) => change(e.target.value)} placeholder="e.g. 8" aria-label="Estimated age in months" />
                        <span className="text-sm text-ink-soft">months</span>
                    </label>
                );
            }
            case 'scan':
                return (
                    <div className="flex items-center gap-2">
                        <input className="field font-mono" value={v} onChange={(e) => set(f, e.target.value.toUpperCase())} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); } }} placeholder="Scan a tag, or type the code" disabled={!canAssignTags} autoComplete="off" spellCheck={false} />
                        {epc && v === epc ? <Chip className="bg-turq-100 text-turq-800">Scanned</Chip> : null}
                    </div>
                );
            case 'long_text':
                return <textarea className="field min-h-24" value={v} onChange={(e) => set(f, e.target.value)} />;
            case 'number': {
                const input = <input className="field" type="number" step="any" inputMode="decimal" value={v} onChange={(e) => set(f, e.target.value)} />;
                if (f.key !== 'temperature_f' || v === '' || Number.isNaN(Number(v))) return input;
                // D-093: alarm as soon as the temperature is outside normal.
                const t = Number(v);
                const hot = t > TEMP_HIGH;
                const cold = t < TEMP_LOW;
                return (
                    <div>
                        {input}
                        {hot || cold ? (
                            <div role="alert" className={`mt-2 flex items-start gap-2 rounded-tile border px-3 py-2 text-sm font-semibold ${hot ? 'border-crit-500 bg-crit-100 text-crit-700' : 'border-watch-400 bg-watch-100 text-watch-700'}`}>
                                <span className={`kk-beat mt-1.5 size-2.5 shrink-0 rounded-full ${hot ? 'bg-crit-500' : 'bg-watch-400'}`} aria-hidden />
                                <span>{hot ? `Fever: ${t} °F is above normal (${TEMP_LOW}–${TEMP_HIGH} °F).` : `Low temperature: ${t} °F is below normal (${TEMP_LOW}–${TEMP_HIGH} °F).`} When you save, the goat gets an open health report and the vets get an urgent task.</span>
                            </div>
                        ) : <span className="mt-1 inline-flex text-sm font-semibold text-ok-700">Normal</span>}
                    </div>
                );
            }
            case 'date':
                return <input className="field" type="date" max={f.key === 'birth_date' || f.key === 'acquired_on' ? TODAY : undefined} value={v} onChange={(e) => set(f, e.target.value)} />;
            case 'yes_no':
                return <span className="flex min-h-11 items-center gap-2"><input type="checkbox" className="size-5" checked={Boolean(v)} onChange={(e) => set(f, e.target.checked)} /><span className="text-sm text-ink-soft">Yes</span></span>;
            case 'select': {
                // Animal Class only offers classes that fit the chosen sex (a male cannot be a Breeding Doe).
                const sex = form.data.sex;
                const options = f.key === 'animal_class' && sex ? f.options?.filter((o) => !classSex[String(o.value)] || classSex[String(o.value)] === sex) : f.options;
                return <select className="field" value={v} onChange={(e) => set(f, e.target.value)}>{f.required && v === '' ? <option value="">Choose…</option> : optional}{options?.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>;
            }
            case 'lookup':
                if (f.key === 'sire_id' || f.key === 'dam_id') {
                    return parentInput(f);
                }
                if (f.key === 'pen_id') {
                    return penInput(f);
                }
                return <select className="field" value={v} onChange={(e) => set(f, e.target.value)}>{f.required && v === '' ? <option value="">Choose…</option> : optional}{(lookups[f.key] ?? []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>;
            default:
                if (f.key === 'tag_id') {
                    // D-041: the Tag ID follows from the breed. Show what it will be; typing overrides it.
                    // D-059: breed code, the purchase date (birth date for farm-born, today if empty) as DD-MM-YY, the breed's number.
                    const breed = (lookups.breed_id ?? []).find((b) => String(b.value) === String(form.data.breed_id ?? ''));
                    const iso: string = (farmBorn ? form.data.birth_date : form.data.acquired_on) || TODAY;
                    const letter = ({ purchased: 'P', transferred_in: 'T', born_on_farm: 'B' } as Record<string, string>)[form.data.origin]; // D-060
                    // D-062: TD-141026P-03 (date and origin joined)
                    const next = breed?.code && breed.nextNumber ? `${breed.code}-${iso.slice(8, 10)}${iso.slice(5, 7)}${iso.slice(2, 4)}${letter ?? ''}-${String(breed.nextNumber).padStart(2, '0')}` : breed?.next;
                    return (
                        <div>
                            <input className="field font-mono" value={v} onChange={(e) => set(f, e.target.value.toUpperCase())} placeholder={next ? `${next} (automatic)` : 'Choose a breed first'} autoComplete="off" />
                            {next && !v ? <span className="mt-1 inline-flex"><Chip className="bg-turq-100 text-turq-800">Will be {next}</Chip></span> : null}
                        </div>
                    );
                }
                return <input className="field" value={v} onChange={(e) => set(f, e.target.value)} />;
        }
    };

    return (
        <>
            <Head title={goat ? `Edit ${goat.number}` : 'Register goat'} />
            <PageHeader
                title={goat ? `Edit ${goat.number}` : 'Goat Register'}
                description={goat ? 'Change any detail. Each change is kept in the goat\'s history with who made it and when. Weight, temperature and the RFID tag are added as new records on the goat page.' : epc ? 'This tag is not on any goat yet. Fill in the register to add the goat.' : 'Add a goat to the register. Scan its tag first if it has one.'}
                actions={goat ? <Link href={`/goats/${goat.id}`}><Button variant="secondary">Back to goat</Button></Link> : <Link href="/scan"><Button variant="secondary"><ScanLine size={16} aria-hidden /> Scan a tag</Button></Link>}
            />

            {epc ? (
                <div className="mb-4 flex flex-wrap items-center gap-2 rounded-tile border border-turq-600/30 bg-turq-100/60 px-4 py-3">
                    <Tag size={18} aria-hidden className="text-turq-800" />
                    <span className="text-sm text-ink-soft">Scanned tag</span>
                    <span className="font-mono font-semibold text-cobalt-900">{epc}</span>
                </div>
            ) : null}
            {!canAssignTags && !editing ? <div className="mb-4"><Note>Your role cannot assign RFID tags, so the tag field is switched off. You can still register the goat.</Note></div> : null}

            <form onSubmit={(e) => {
                e.preventDefault();
                // Parentage hidden for a bought or transferred goat: never send values left over from an earlier choice.
                // D-065: a farm-born goat's birth date is exact; a bought goat's is an estimate.
                form.transform((d: Record<string, any>) => (d.origin === 'born_on_farm'
                    ? { ...d, birth_date_is_estimate: false }
                    : { ...d, birth_date_is_estimate: Boolean(d.birth_date), sire_id: '', dam_id: '', sire_tag: '', dam_tag: '', breed_purity: '', cross_breeds: '' }));
                if (goat) {
                    form.post(`/goats/${goat.id}/edit`, { preserveScroll: true });
                } else {
                    form.post('/goats', { forceFormData: true });
                }
            }} className="space-y-4">
                {/* D-094: the profile picture first, as a small round photo instead of a whole card. */}
                {!editing ? (
                    <div className="rounded-tile border border-rule bg-tile px-4 py-3 shadow-tile">
                        <PhotoPicker compact value={form.data.photo} onChange={(file) => form.setData('photo', file)} error={form.errors.photo} />
                    </div>
                ) : null}
                {shown.filter((section) => section.fields.some((f) => !hidden(f))).map((section, index) => (
                    <Fragment key={section.name}>
                    <Panel title={section.name === 'Birth' && !farmBorn ? 'Aging' : section.name}>
                        {section.name === 'Parentage' && damMating ? (
                            <div className="mb-3"><Note>From the mating on {damMating.matedOn}{damMating.sire ? ` with ${damMating.sire}` : ''}{damMating.expectedLabel ? `, expected kidding ${damMating.expectedLabel}` : ''}. The sire, breed and kidding are filled in from it.</Note></div>
                        ) : null}
                        {section.name === 'Parentage' && form.data.dam_id && form.data.origin === 'born_on_farm' && !damMating ? (
                            <div className="mb-3"><Note>No mating is recorded for this doe in the last months. Choose the sire yourself.</Note></div>
                        ) : null}
                        <div className="grid gap-x-4 gap-y-3 md:grid-cols-2">
                            {section.fields.filter((f) => !hidden(f)).map((f) => (
                                <div key={f.key} className={f.type === 'long_text' ? 'md:col-span-2' : undefined}>
                                    <Field
                                        label={`${labelFor(f)}${isRequired(f) ? ' *' : ''}`}
                                        error={error(f)}
                                        hint={f.key === 'estimated_age' ? 'The birth date is worked out from it and saved as an estimate.' : f.key === 'birth_date' && form.data.origin && form.data.origin !== 'born_on_farm' ? (form.data.birth_date ? 'Estimated from the age. Change it if you know a better date.' : 'Optional. Enter the estimated age, or a date.') : f.key === 'animal_class' && !form.data.animal_class && suggested ? `Suggested: ${classification.categories[suggested]}` : f.key === 'age_category' && !form.data.age_category && age ? `From the birth date: ${age === 'kid' ? 'Kid' : age === 'grower' ? 'Grower' : 'Adult'}` : f.help ?? undefined}
                                    >{control(f)}</Field>
                                </div>
                            ))}
                        </div>
                        {!started ? <p className="mt-3 text-sm text-ink-soft">Choose how the goat came to the farm. The rest of the form follows from it.</p> : null}
                        {section.name === 'Classification' ? <GeneratedClassification text={summary} /> : null}
                    </Panel>
                    </Fragment>
                ))}
                <div className="flex flex-wrap items-center gap-2">
                    <Button type="submit" disabled={form.processing || !started}>{form.processing ? 'Saving' : goat ? 'Save changes' : 'Register goat'}</Button>
                    <Button type="button" variant="secondary" onClick={() => router.visit(goat ? `/goats/${goat.id}` : '/goats')}>Cancel</Button>
                    {Object.keys(form.errors).length ? <span className="text-sm font-medium text-crit-700">Please fix the highlighted fields.</span> : null}
                </div>
            </form>

            {epc && canAssignTags && untagged.length && !editing ? (
                <Panel title="Or put this tag on a goat that is already registered" description="For goats entered before they had an RFID tag" className="mt-6">
                    <form onSubmit={(e) => { e.preventDefault(); assign.post('/scan/assign'); }} className="grid gap-2 sm:grid-cols-[1fr_auto] sm:items-end">
                        <Field label="Goat without a tag" error={assign.errors.tag ?? assign.errors.animal_id}>
                            <select className="field" value={assign.data.animal_id} onChange={(e) => assign.setData('animal_id', e.target.value)}>
                                <option value="">Choose a goat…</option>
                                {untagged.map((g) => <option key={g.id} value={g.id}>{g.farm_number}{g.name ? ` · ${g.name}` : ''}</option>)}
                            </select>
                        </Field>
                        <Button type="submit" variant="secondary" disabled={assign.processing || !assign.data.animal_id}>Assign tag</Button>
                    </form>
                </Panel>
            ) : null}
        </>
    );
}

/** Same rules as App\Support\Classification::ageCategory (months from the birth date). */
function ageCategory(birth: string | undefined, c: { growerFrom: number; adultFrom: number }): string | null {
    if (!birth) return null;
    const b = new Date(birth);
    const now = new Date();
    const months = (now.getFullYear() - b.getFullYear()) * 12 + (now.getMonth() - b.getMonth()) - (now.getDate() < b.getDate() ? 1 : 0);
    return months < c.growerFrom ? 'kid' : months < c.adultFrom ? 'grower' : 'adult';
}

/** Same rules as App\Support\Classification::suggestCategory. */
function suggestCategory(sex: string | undefined, age: string | null, purpose: string | undefined): string | null {
    if ((sex !== 'male' && sex !== 'female') || !age) return null;
    const m = sex === 'male';
    if (age === 'kid') return m ? 'male_kid' : 'female_kid';
    if (purpose === 'fattening' && m) return 'fattening_male';
    if (age === 'grower') return purpose === 'breeding' || purpose === 'replacement' ? (m ? 'replacement_male' : 'replacement_female') : m ? 'growing_male' : 'growing_female';
    if (purpose === 'replacement') return m ? 'replacement_male' : 'replacement_female';
    if (!purpose || purpose === 'breeding' || purpose === 'milk') return m ? 'breeding_male' : 'breeding_female';
    return m ? 'growing_male' : 'growing_female';
}

function GeneratedClassification({ text }: { text: string }) {
    const [copied, setCopied] = useState(false);
    if (!text) return null;
    return (
        <motion.div layout initial={{ opacity: 0, y: 8 }} animate={{ opacity: 1, y: 0 }} className="mt-4 rounded-2xl bg-cobalt-900 p-4 text-white">
            <div className="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-turq-400 uppercase"><Sparkles size={14} aria-hidden /> Generated classification</div>
            <AnimatePresence mode="wait">
                <motion.p key={text} initial={{ opacity: 0, y: 4 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -4 }} transition={{ duration: 0.15 }} className="mt-1 text-lg font-bold leading-snug">{text}</motion.p>
            </AnimatePresence>
            <button type="button" onClick={() => { void navigator.clipboard?.writeText(text); setCopied(true); setTimeout(() => setCopied(false), 1500); }} className="mt-2 inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1.5 text-sm font-semibold active:scale-95">
                <Copy size={14} aria-hidden /> {copied ? 'Copied' : 'Copy classification'}
            </button>
        </motion.div>
    );
}
