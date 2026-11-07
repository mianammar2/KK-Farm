import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Lock } from 'lucide-react';
import { Button, Chip, Field, Note, PageHeader, Panel } from '../../components/ui';

type Def = { id: string; key: string; label: string; type: string; section: string; help: string | null; builtin: boolean; active: boolean; structural: boolean; required: boolean; options: string };
type Props = { fields: Def[]; sections: string[]; types: string[] };

const TYPE_LABEL: Record<string, string> = { text: 'Short text', long_text: 'Long text', number: 'Number', date: 'Date', yes_no: 'Yes / No', select: 'Dropdown', lookup: 'List from the farm', scan: 'RFID scan' };

export default function Fields({ fields, sections, types }: Props) {
    const add = useForm({ label: '', type: 'text', options: '', section: 'Custom', required: false, help: '' });
    const addSection = [...new Set([...sections, 'Custom'])];

    return (
        <>
            <Head title="Register fields" />
            <PageHeader title="Goat register fields" description="The fields shown when a goat is registered. Rename them, edit dropdown options, or add your own." actions={<Link href="/settings"><Button variant="secondary"><ArrowLeft size={16} aria-hidden /> Settings</Button></Link>} />
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem]">
                <div className="space-y-4">
                    {sections.map((section) => (
                        <Panel key={section} title={section} flush>
                            <ul className="divide-y divide-rule">
                                {fields.filter((f) => f.section === section).map((f) => <FieldRow key={`${f.id}-${f.label}-${f.options}-${f.active}-${f.required}`} field={f} />)}
                            </ul>
                        </Panel>
                    ))}
                </div>
                <div className="xl:sticky xl:top-4 xl:self-start">
                    <Panel title="Add a custom field" description="Appears on the goat register form and is saved with each goat">
                        <form onSubmit={(e) => { e.preventDefault(); add.post('/settings/fields', { preserveScroll: true, onSuccess: () => add.reset('label', 'options', 'help', 'required') }); }} className="space-y-3">
                            <Field label="Field name" error={add.errors.label}><input className="field" value={add.data.label} onChange={(e) => add.setData('label', e.target.value)} placeholder="e.g. Horn status" /></Field>
                            <Field label="Type" error={add.errors.type}>
                                <select className="field" value={add.data.type} onChange={(e) => add.setData('type', e.target.value)}>{types.map((t) => <option key={t} value={t}>{TYPE_LABEL[t]}</option>)}</select>
                            </Field>
                            {add.data.type === 'select' ? <Field label="Dropdown options" error={add.errors.options} hint="One option per line"><textarea className="field min-h-24" value={add.data.options} onChange={(e) => add.setData('options', e.target.value)} /></Field> : null}
                            <Field label="Section" error={add.errors.section}>
                                <input className="field" list="sections" value={add.data.section} onChange={(e) => add.setData('section', e.target.value)} />
                                <datalist id="sections">{addSection.map((s) => <option key={s} value={s} />)}</datalist>
                            </Field>
                            <Field label="Help text (optional)" error={add.errors.help}><input className="field" value={add.data.help} onChange={(e) => add.setData('help', e.target.value)} /></Field>
                            <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="size-5" checked={add.data.required} onChange={(e) => add.setData('required', e.target.checked)} /> Required when registering a goat</label>
                            <Button type="submit" disabled={add.processing || !add.data.label.trim()}>Add field</Button>
                        </form>
                        <div className="mt-3"><Note>Existing goats keep their data. A field that is switched off is hidden, not deleted.</Note></div>
                    </Panel>
                </div>
            </div>
        </>
    );
}

function FieldRow({ field }: { field: Def }) {
    const form = useForm({ label: field.label, help: field.help ?? '', options: field.options, required: field.required, active: field.active });

    return (
        <li className={`px-4 py-3 ${field.active ? '' : 'bg-tile/60'}`}>
            <form onSubmit={(e) => { e.preventDefault(); form.post(`/settings/fields/${field.id}`, { preserveScroll: true }); }} className="grid gap-2 md:grid-cols-[minmax(0,1fr)_auto] md:items-start">
                <div className="space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <input aria-label={`Name of ${field.label}`} className="field max-w-72 font-semibold" value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} />
                        <Chip className="bg-cobalt-100 text-cobalt-800">{TYPE_LABEL[field.type] ?? field.type}</Chip>
                        {field.builtin ? <Chip className="bg-tile text-ink-soft">Standard</Chip> : <Chip className="bg-turq-100 text-turq-800">Custom</Chip>}
                        {field.structural ? <span className="inline-flex items-center gap-1 text-sm text-ink-faint"><Lock size={13} aria-hidden /> Always required</span> : null}
                    </div>
                    {field.type === 'select' ? <Field label="Dropdown options" error={form.errors.options} hint="One option per line"><textarea className="field min-h-24" value={form.data.options} onChange={(e) => form.setData('options', e.target.value)} /></Field> : null}
                    {form.errors.label ? <p className="text-sm font-medium text-crit-700">{form.errors.label}</p> : null}
                </div>
                <div className="flex flex-wrap items-center gap-3 md:justify-end">
                    {!field.structural ? (
                        <>
                            <label className="flex min-h-11 items-center gap-1.5 text-sm"><input type="checkbox" className="size-5" checked={form.data.required} onChange={(e) => form.setData('required', e.target.checked)} /> Required</label>
                            <label className="flex min-h-11 items-center gap-1.5 text-sm"><input type="checkbox" className="size-5" checked={form.data.active} onChange={(e) => form.setData('active', e.target.checked)} /> Shown</label>
                        </>
                    ) : null}
                    <Button type="submit" variant="secondary" disabled={!form.isDirty || form.processing}>Save</Button>
                </div>
            </form>
        </li>
    );
}
