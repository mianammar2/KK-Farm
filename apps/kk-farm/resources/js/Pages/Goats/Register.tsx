import { Head, Link, useForm } from '@inertiajs/react';
import { ScanLine, Tag } from 'lucide-react';
import type { ReactNode } from 'react';
import PhotoPicker from '../../components/PhotoPicker';
import { Button, Chip, Field, Note, PageHeader, Panel } from '../../components/ui';
import type { Option } from '../../types';

type Choice = { value: string | number; label: string; next?: string; breed?: number | null; code?: string };
type DamMating = { sireId: string | null; sire: string | null; sireBreed: number | null; status: string; matedOn: string; expected: string | null; expectedLabel: string | null };
type FieldDef = { key: string; label: string; type: string; section: string; help: string | null; builtin: boolean; required: boolean; options: Choice[] | null };
type Props = { epc: string | null; fields: FieldDef[]; lookups: Record<string, Choice[]>; classSex: Record<string, string>; damMatings: Record<string, DamMating>; mixedBreedId: number | null; canAssignTags: boolean; untagged: Option[] };

const TODAY = new Date().toISOString().slice(0, 10);

export default function Register({ epc, fields, lookups, classSex, damMatings, mixedBreedId, canAssignTags, untagged }: Props) {
    const form = useForm<Record<string, any>>({
        rfid_epc: epc ?? '',
        birth_date_is_estimate: false,
        lifecycle_status: 'active',
        from_scan: Boolean(epc),
        custom_fields: {} as Record<string, any>,
        photo: null as File | null,
    });
    const assign = useForm({ tag: epc ?? '', animal_id: '', reason: 'initial' });

    const sections = fields.reduce<Array<{ name: string; fields: FieldDef[] }>>((acc, f) => {
        const group = acc.find((s) => s.name === f.section);
        group ? group.fields.push(f) : acc.push({ name: f.section, fields: [f] });
        return acc;
    }, []);

    const value = (f: FieldDef) => (f.builtin ? form.data[f.key] : form.data.custom_fields[f.key]) ?? (f.type === 'yes_no' ? false : '');
    const set = (f: FieldDef, v: any) => {
        if (!f.builtin) {
            return form.setData('custom_fields', { ...form.data.custom_fields, [f.key]: v });
        }
        if (f.key === 'sex' && classSex[form.data.animal_class] && classSex[form.data.animal_class] !== v) {
            return form.setData((d: Record<string, any>) => ({ ...d, sex: v, animal_class: '' }));
        }
        if (f.key === 'dam_id') {
            return form.setData((d: Record<string, any>) => ({ ...d, ...kidDefaults(d, v) }));
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
        if (!d.animal_class) next.animal_class = 'kid';
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
    const damMating = form.data.dam_id && form.data.origin === 'born_on_farm' ? damMatings[form.data.dam_id] : undefined;
    const error = (f: FieldDef) => (f.builtin ? form.errors[f.key] : form.errors[`custom_fields.${f.key}`]);

    const control = (f: FieldDef): ReactNode => {
        const v = value(f);
        const optional = f.required ? undefined : <option value="">Not set</option>;
        switch (f.type) {
            case 'scan':
                return (
                    <div className="flex items-center gap-2">
                        <input className="field font-mono" value={v} onChange={(e) => set(f, e.target.value.toUpperCase())} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); } }} placeholder="Scan a tag, or type the code" disabled={!canAssignTags} autoComplete="off" spellCheck={false} />
                        {epc && v === epc ? <Chip className="bg-turq-100 text-turq-800">Scanned</Chip> : null}
                    </div>
                );
            case 'long_text':
                return <textarea className="field min-h-24" value={v} onChange={(e) => set(f, e.target.value)} />;
            case 'number':
                return <input className="field" type="number" step="any" inputMode="decimal" value={v} onChange={(e) => set(f, e.target.value)} />;
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
                return <select className="field" value={v} onChange={(e) => set(f, e.target.value)}>{f.required && v === '' ? <option value="">Choose…</option> : optional}{(lookups[f.key] ?? []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>;
            default:
                if (f.key === 'tag_id') {
                    // D-041: the Tag ID follows from the breed. Show what it will be; typing overrides it.
                    const next = (lookups.breed_id ?? []).find((b) => String(b.value) === String(form.data.breed_id ?? ''))?.next;
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
            <Head title="Register goat" />
            <PageHeader
                title="Goat Register"
                description={epc ? 'This tag is not on any goat yet. Fill in the register to add the goat.' : 'Add a goat to the register. Scan its tag first if it has one.'}
                actions={<Link href="/scan"><Button variant="secondary"><ScanLine size={16} aria-hidden /> Scan a tag</Button></Link>}
            />

            {epc ? (
                <div className="mb-4 flex flex-wrap items-center gap-2 rounded-tile border border-turq-600/30 bg-turq-100/60 px-4 py-3">
                    <Tag size={18} aria-hidden className="text-turq-800" />
                    <span className="text-sm text-ink-soft">Scanned tag</span>
                    <span className="font-mono font-semibold text-cobalt-900">{epc}</span>
                </div>
            ) : null}
            {!canAssignTags ? <div className="mb-4"><Note>Your role cannot assign RFID tags, so the tag field is switched off. You can still register the goat.</Note></div> : null}

            <form onSubmit={(e) => { e.preventDefault(); form.post('/goats', { forceFormData: true }); }} className="space-y-4">
                <Panel title="Profile picture" description="Take a photo with the camera, or choose one. Optional.">
                    <PhotoPicker value={form.data.photo} onChange={(file) => form.setData('photo', file)} error={form.errors.photo} />
                </Panel>
                {sections.map((section) => (
                    <Panel key={section.name} title={section.name}>
                        {section.name === 'Parentage' && damMating ? (
                            <div className="mb-3"><Note>From the mating on {damMating.matedOn}{damMating.sire ? ` with ${damMating.sire}` : ''}{damMating.expectedLabel ? `, expected kidding ${damMating.expectedLabel}` : ''}. The sire, breed and kidding are filled in from it.</Note></div>
                        ) : null}
                        {section.name === 'Parentage' && form.data.dam_id && form.data.origin === 'born_on_farm' && !damMating ? (
                            <div className="mb-3"><Note>No mating is recorded for this doe in the last months. Choose the sire yourself.</Note></div>
                        ) : null}
                        <div className="grid gap-x-4 gap-y-3 md:grid-cols-2">
                            {section.fields.map((f) => (
                                <div key={f.key} className={f.type === 'long_text' ? 'md:col-span-2' : undefined}>
                                    <Field label={`${f.label}${f.required ? ' *' : ''}`} error={error(f)} hint={f.help ?? undefined}>{control(f)}</Field>
                                </div>
                            ))}
                        </div>
                    </Panel>
                ))}
                <div className="flex flex-wrap items-center gap-2">
                    <Button type="submit" disabled={form.processing}>{form.processing ? 'Saving' : 'Register goat'}</Button>
                    <Link href="/goats"><Button type="button" variant="secondary">Cancel</Button></Link>
                    {Object.keys(form.errors).length ? <span className="text-sm font-medium text-crit-700">Please fix the highlighted fields.</span> : null}
                </div>
            </form>

            {epc && canAssignTags && untagged.length ? (
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
