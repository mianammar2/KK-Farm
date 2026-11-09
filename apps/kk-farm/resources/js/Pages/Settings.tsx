import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Check } from 'lucide-react';
import { Button, Field, Note, PageHeader, Panel } from '../components/ui';
import type { SharedProps } from '../types';

type Props = { ages: { growerFrom: number; adultFrom: number }; breeds: Array<{ id: number; name: string; code: string; next: string; gestation: number | null; lightBelow: number | null; goats: number }>; matrix: Record<string, string[]>; gates: Record<string, string[]>; environment: Record<string, string> };

const GATE_LABEL: Record<string, string> = { clinical: 'Diagnose and treat', approve_feeding_plan: 'Approve feeding plans', exit_animal: 'Record goat exits', assign_tags: 'Assign RFID tags', manage_sheds: 'Add sheds and pens' };

export default function Settings({ ages, breeds, matrix, gates, environment }: Props) {
    const { roles } = usePage<SharedProps>().props;
    const form = useForm({ name: '', code: '' });
    const ageForm = useForm({ grower_from_months: ages.growerFrom, adult_from_months: ages.adultFrom });
    const roleKeys = Object.keys(roles);
    const cells = (allowed: string[]) => roleKeys.map((r) => <td key={r} className="text-center">{allowed.includes(r) ? <Check size={15} className="mx-auto text-turq-700" aria-label="Allowed" /> : <span className="text-ink-faint" aria-label="Not allowed">–</span>}</td>);

    return (
        <>
            <Head title="Settings" />
            <PageHeader title="Settings" description="Breeds and the permission model for this preview." />
            <div className="grid gap-4 xl:grid-cols-[22rem_minmax(0,1fr)]">
                <div className="space-y-4">
                    <Panel title="Breeds" flush>
                        <ul className="divide-y divide-rule text-sm">{breeds.map((b) => <li key={b.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2"><span className="font-medium">{b.name} <span className="ml-1 rounded-[4px] bg-cobalt-100 px-1.5 py-0.5 font-mono text-xs text-cobalt-800">{b.code}</span></span><span className="text-right text-sm text-ink-soft">{b.goats} goats · next {b.next}</span><LightLine id={b.id} value={b.lightBelow} /></li>)}</ul>
                        <p className="border-t border-rule px-4 py-2 text-xs text-ink-soft">Light Below: a goat with no teeth recorded is aged from its first weighing, about 7 months under this weight and about 10 months at or over it. Leave empty for no estimate.</p>
                        <form onSubmit={(e) => { e.preventDefault(); form.post('/settings/breeds', { preserveScroll: true, onSuccess: () => form.reset() }); }} className="flex items-end gap-2 border-t border-rule p-4">
                            <div className="flex-1"><Field label="Add a breed" error={form.errors.name}><input className="field" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field></div>
                            <div className="w-24"><Field label="Tag code" error={form.errors.code}><input className="field font-mono uppercase" maxLength={8} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} placeholder="Auto" /></Field></div>
                            <Button type="submit" disabled={form.processing}>Add</Button>
                        </form>
                    </Panel>
                    <Panel title="Age categories" description="Worked out from each goat's birth date">
                        <form onSubmit={(e) => { e.preventDefault(); ageForm.post('/settings/ages', { preserveScroll: true }); }} className="space-y-3">
                            <div className="grid grid-cols-2 gap-2">
                                <Field label="Grower from (months)" error={ageForm.errors.grower_from_months}><input className="field" type="number" min={1} max={24} inputMode="numeric" value={ageForm.data.grower_from_months} onChange={(e) => ageForm.setData('grower_from_months', Number(e.target.value))} /></Field>
                                <Field label="Adult from (months)" error={ageForm.errors.adult_from_months}><input className="field" type="number" min={2} max={60} inputMode="numeric" value={ageForm.data.adult_from_months} onChange={(e) => ageForm.setData('adult_from_months', Number(e.target.value))} /></Field>
                            </div>
                            <p className="text-sm text-ink-soft">Kid: under {ageForm.data.grower_from_months} months · Grower: {ageForm.data.grower_from_months}–{ageForm.data.adult_from_months} months · Adult: {ageForm.data.adult_from_months} months and older</p>
                            <Button type="submit" variant="secondary" disabled={ageForm.processing || !ageForm.isDirty}>Save age categories</Button>
                        </form>
                    </Panel>
                    <Panel title="Users and sign-in" description="Who can sign in, and with which role">
                        <p className="mb-3 text-sm text-ink-soft">Add staff and vets, reset passwords, or disable an account.</p>
                        <Link href="/settings/users"><Button variant="secondary">Manage users</Button></Link>
                    </Panel>
                    <Panel title="Goat register fields" description="The registration form, from the KK Farms workbook">
                        <p className="mb-3 text-sm text-ink-soft">Rename fields, edit dropdown options, or add your own fields.</p>
                        <Link href="/settings/fields"><Button variant="secondary">Edit register fields</Button></Link>
                    </Panel>
                    <Panel title="This preview">
                        <dl className="space-y-1 text-sm">
                            {Object.entries(environment).map(([k, v]) => <div key={k} className="flex justify-between gap-2"><dt className="text-ink-soft capitalize">{k}</dt><dd className="font-medium">{v}</dd></div>)}
                        </dl>
                        <div className="mt-3"><Note>Local preview with synthetic data. Roles are candidates until the farm visit confirms them.</Note></div>
                    </Panel>
                </div>
                <div className="space-y-4">
                    <Panel title="Who can open what" flush description="Checked on the server for every request">
                        <div className="overflow-x-auto">
                            <table className="data-table min-w-[720px]">
                                <thead><tr><th>Area</th>{roleKeys.map((r) => <th key={r} className="text-center">{roles[r].split(' ')[0]}</th>)}</tr></thead>
                                <tbody>{Object.entries(matrix).map(([area, allowed]) => <tr key={area}><td className="font-medium capitalize">{area}</td>{cells(allowed)}</tr>)}</tbody>
                            </table>
                        </div>
                    </Panel>
                    <Panel title="Protected actions" flush description="These cannot be granted by any override">
                        <div className="overflow-x-auto">
                            <table className="data-table min-w-[720px]">
                                <thead><tr><th>Action</th>{roleKeys.map((r) => <th key={r} className="text-center">{roles[r].split(' ')[0]}</th>)}</tr></thead>
                                <tbody>{Object.entries(gates).map(([gate, allowed]) => <tr key={gate}><td className="font-medium">{GATE_LABEL[gate] ?? gate}</td>{cells(allowed)}</tr>)}</tbody>
                            </table>
                        </div>
                    </Panel>
                </div>
            </div>
        </>
    );
}

/** D-110: the breed's light weight line, saved when the field is left or Enter is pressed. */
function LightLine({ id, value }: { id: number; value: number | null }) {
    const [kg, setKg] = useState(value === null ? '' : String(value));
    const save = () => {
        if (kg === (value === null ? '' : String(value))) return;
        router.post(`/settings/breeds/${id}`, { young_below_kg: kg === '' ? null : kg }, { preserveScroll: true });
    };
    return (
        <label className="flex w-full items-center justify-end gap-2 text-xs text-ink-soft">
            Light Below
            <input className="field w-20 py-1 text-right" type="number" min={1} max={150} step={0.5} inputMode="decimal" value={kg} placeholder="None" onChange={(e) => setKg(e.target.value)} onBlur={save} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); save(); } }} />
            KG
        </label>
    );
}
