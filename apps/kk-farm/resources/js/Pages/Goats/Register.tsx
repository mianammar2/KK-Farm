import { Head, Link, useForm } from '@inertiajs/react';
import { ScanLine, Tag } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button, Chip, Field, Note, PageHeader, Panel } from '../../components/ui';
import type { Option } from '../../types';

type Choice = { value: string | number; label: string; next?: string };
type FieldDef = { key: string; label: string; type: string; section: string; help: string | null; builtin: boolean; required: boolean; options: Choice[] | null };
type Props = { epc: string | null; fields: FieldDef[]; lookups: Record<string, Choice[]>; canAssignTags: boolean; untagged: Option[] };

const TODAY = new Date().toISOString().slice(0, 10);

export default function Register({ epc, fields, lookups, canAssignTags, untagged }: Props) {
    const form = useForm<Record<string, any>>({
        rfid_epc: epc ?? '',
        birth_date_is_estimate: false,
        lifecycle_status: 'active',
        from_scan: Boolean(epc),
        custom_fields: {} as Record<string, any>,
    });
    const assign = useForm({ tag: epc ?? '', animal_id: '', reason: 'initial' });

    const sections = fields.reduce<Array<{ name: string; fields: FieldDef[] }>>((acc, f) => {
        const group = acc.find((s) => s.name === f.section);
        group ? group.fields.push(f) : acc.push({ name: f.section, fields: [f] });
        return acc;
    }, []);

    const value = (f: FieldDef) => (f.builtin ? form.data[f.key] : form.data.custom_fields[f.key]) ?? (f.type === 'yes_no' ? false : '');
    const set = (f: FieldDef, v: any) => (f.builtin ? form.setData(f.key, v) : form.setData('custom_fields', { ...form.data.custom_fields, [f.key]: v }));
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
            case 'select':
                return <select className="field" value={v} onChange={(e) => set(f, e.target.value)}>{f.required && v === '' ? <option value="">Choose…</option> : optional}{f.options?.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>;
            case 'lookup':
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

            <form onSubmit={(e) => { e.preventDefault(); form.post('/goats'); }} className="space-y-4">
                {sections.map((section) => (
                    <Panel key={section.name} title={section.name}>
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
