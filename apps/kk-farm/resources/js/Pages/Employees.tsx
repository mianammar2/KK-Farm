import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Field, Note, PageHeader, Panel } from '../components/ui';
import AddButton from '../components/AddButton';
import Sheet from '../components/Sheet';

type Props = { employees: Array<{ id: number; code: string; name: string; role: string; shift: string; active: boolean; open: number; done: number }>; roles: string[] };

export default function Employees({ employees, roles }: Props) {
    const [filter, setFilter] = useState('');
    const [adding, setAdding] = useState(false); // D-127
    const form = useForm({ name: '', role: roles[3] ?? '', shift: 'Day' });
    const rows = employees.filter((e) => !filter || e.role === filter);

    return (
        <>
            <Head title="Employees" />
            <PageHeader title="Employees" description={`${employees.length} people. Work today comes from Tasks & Daily Work.`} actions={<>
                <select aria-label="Filter by role" className="field w-auto" value={filter} onChange={(e) => setFilter(e.target.value)}><option value="">All roles</option>{roles.map((r) => <option key={r}>{r}</option>)}</select>
                <AddButton label="Add an employee" onClick={() => setAdding(true)} /></>
            } />
            {adding ? <Sheet onClose={() => setAdding(false)}>
                <Panel title="Add an employee">
                    <form onSubmit={(e) => { e.preventDefault(); form.post('/employees', { preserveScroll: true, onSuccess: () => { form.reset('name'); setAdding(false); } }); }} className="space-y-3">
                        <Field label="Name" error={form.errors.name} hint="Use a made-up name in this preview"><input className="field" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field>
                        <Field label="Role"><select className="field" value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>{roles.map((r) => <option key={r}>{r}</option>)}</select></Field>
                        <Field label="Shift"><select className="field" value={form.data.shift} onChange={(e) => form.setData('shift', e.target.value)}>{['Day', 'Morning', 'Evening', 'Night', 'On call'].map((s) => <option key={s}>{s}</option>)}</select></Field>
                        <Button type="submit" className="w-full" disabled={form.processing}>Add employee</Button>
                    </form>
                </Panel>
            </Sheet> : null}
            <div className="mb-4"><Note>Demo staff only. Real employee details are never stored in this preview.</Note></div>
            <div>
                <Panel flush>
                    <div className="overflow-x-auto">
                        <table className="data-table min-w-[620px]">
                            <thead><tr><th>Code</th><th>Name</th><th>Role</th><th>Shift</th><th>Today</th><th>Status</th></tr></thead>
                            <tbody>
                                {rows.map((e) => (
                                    <tr key={e.id}>
                                        <td className="font-semibold text-cobalt-800">{e.code}</td>
                                        <td>{e.name}</td>
                                        <td>{e.role}</td>
                                        <td>{e.shift}</td>
                                        <td>{e.done} done, {e.open} open</td>
                                        <td><Chip className="bg-ok-100 text-ok-700">{e.active ? 'Active' : 'Inactive'}</Chip></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            </div>
        </>
    );
}
