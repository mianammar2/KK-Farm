import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { Button, Field, Note, PageHeader, Panel } from '../components/ui';
import type { SharedProps } from '../types';

type Props = { breeds: Array<{ id: number; name: string; gestation: number | null; goats: number }>; matrix: Record<string, string[]>; gates: Record<string, string[]>; environment: Record<string, string> };

const GATE_LABEL: Record<string, string> = { clinical: 'Diagnose and treat', approve_feeding_plan: 'Approve feeding plans', exit_animal: 'Record goat exits', assign_tags: 'Assign RFID tags' };

export default function Settings({ breeds, matrix, gates, environment }: Props) {
    const { roles } = usePage<SharedProps>().props;
    const form = useForm({ name: '' });
    const roleKeys = Object.keys(roles);
    const cells = (allowed: string[]) => roleKeys.map((r) => <td key={r} className="text-center">{allowed.includes(r) ? <Check size={15} className="mx-auto text-turq-700" aria-label="Allowed" /> : <span className="text-ink-faint" aria-label="Not allowed">–</span>}</td>);

    return (
        <>
            <Head title="Settings" />
            <PageHeader title="Settings" description="Breeds and the permission model for this preview." />
            <div className="grid gap-4 xl:grid-cols-[22rem_minmax(0,1fr)]">
                <div className="space-y-4">
                    <Panel title="Breeds" flush>
                        <ul className="divide-y divide-rule text-sm">{breeds.map((b) => <li key={b.id} className="flex justify-between px-4 py-2"><span className="font-medium">{b.name}</span><span className="text-ink-soft">{b.goats} goats</span></li>)}</ul>
                        <form onSubmit={(e) => { e.preventDefault(); form.post('/settings/breeds', { preserveScroll: true, onSuccess: () => form.reset() }); }} className="flex items-end gap-2 border-t border-rule p-4">
                            <div className="flex-1"><Field label="Add a breed" error={form.errors.name}><input className="field" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field></div>
                            <Button type="submit" disabled={form.processing}>Add</Button>
                        </form>
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
