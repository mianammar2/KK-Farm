import { Head, Link, useForm, usePage } from '@inertiajs/react';
import DeleteButton from '../../components/DeleteButton';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { Button, Chip, Field, Note, PageHeader, Panel } from '../../components/ui';
import type { SharedProps } from '../../types';
import AddButton from '../../components/AddButton';
import Sheet from '../../components/Sheet';

type D = { id: number; name: string; users: number };
type U = { id: number; name: string; login: string; role: string; active: boolean; department: number | null; mustChange: boolean; lastLogin: string | null; me: boolean; own: number; diffs: Record<string, { gain: string[]; lose: string[] }> };

/** The owner decides who can sign in and with which role (D-044). */
export default function Users({ users, departments }: { users: U[]; departments: D[] }) {
    const { roles } = usePage<SharedProps>().props;
    const add = useForm({ name: '', login: '', role: 'caretaker', department_id: '', password: '' });
    const [adding, setAdding] = useState(false); // D-127

    return (
        <>
            <Head title="Users" />
            <PageHeader title="Users and sign-in" description="Each person signs in with their own username and password, so every record shows who made it." actions={<><Link href="/settings"><Button variant="secondary"><ArrowLeft size={16} aria-hidden /> Settings</Button></Link><Link href="/settings/access"><Button variant="secondary">Access by role</Button></Link><AddButton label="Add a user" onClick={() => setAdding(true)} /></>} />
            {adding ? <Sheet onClose={() => setAdding(false)}>
                    <Panel title="Add a user">
                        <form onSubmit={(e) => { e.preventDefault(); add.post('/settings/users', { preserveScroll: true, onSuccess: () => { add.reset(); setAdding(false); } }); }} className="space-y-3">
                            <Field label="Name" error={add.errors.name}><input className="field" value={add.data.name} onChange={(e) => add.setData('name', e.target.value)} /></Field>
                            <Field label="Username or email" error={add.errors.login} hint="Letters, numbers and . _ @ + -"><input className="field" autoCapitalize="none" spellCheck={false} value={add.data.login} onChange={(e) => add.setData('login', e.target.value)} /></Field>
                            <Field label="Role" error={add.errors.role}><select className="field" value={add.data.role} onChange={(e) => add.setData('role', e.target.value)}>{Object.entries(roles).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                            <Field label="Department" error={add.errors.department_id} hint="Leave on automatic to use the role's usual department."><select className="field" value={add.data.department_id} onChange={(e) => add.setData('department_id', e.target.value)}><option value="">Automatic from role</option>{departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</select></Field>
                            <Field label="Temporary password" error={add.errors.password} hint="At least 8 characters. They choose their own on first sign-in."><input className="field" type="text" autoComplete="off" value={add.data.password} onChange={(e) => add.setData('password', e.target.value)} /></Field>
                            <Button type="submit" disabled={add.processing}>Add user</Button>
                        </form>
                    </Panel>
            </Sheet> : null}
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem]">
                <Panel flush>
                    <ul className="divide-y divide-rule">{users.map((u) => <UserRow key={`${u.id}-${u.role}-${u.active}-${u.department}`} user={u} roles={roles} departments={departments} />)}</ul>
                </Panel>
                <div className="xl:sticky xl:top-4 xl:self-start">
                    <Departments departments={departments} />
                </div>
            </div>
        </>
    );
}

/** D-052: the farm's departments. Tasks can go to a whole department or to one person in it. */
function Departments({ departments }: { departments: D[] }) {
    const form = useForm({ name: '' });
    const [editing, setEditing] = useState<D | null>(null);
    const rename = useForm({ name: '' });

    return (
        <div>
            <Panel title="Departments" description="Tasks can be given to a whole department or to one person in it.">
                <ul className="mb-3 divide-y divide-rule">
                    {departments.map((d) => (
                        <li key={d.id} className="flex items-center gap-2 py-2">
                            {editing?.id === d.id ? (
                                <form className="flex flex-1 items-start gap-2" onSubmit={(e) => { e.preventDefault(); rename.post(`/settings/departments/${d.id}`, { preserveScroll: true, onSuccess: () => setEditing(null) }); }}>
                                    <div className="flex-1"><Field label="Name" error={rename.errors.name}><input className="field" autoFocus value={rename.data.name} onChange={(e) => rename.setData('name', e.target.value)} /></Field></div>
                                    <Button type="submit" className="mt-6" disabled={rename.processing}>Save</Button>
                                </form>
                            ) : (
                                <>
                                    <span className="flex-1 font-medium text-cobalt-900">{d.name}</span>
                                    <span className="text-sm text-ink-soft">{d.users} {d.users === 1 ? 'person' : 'people'}</span>
                                    <button type="button" className="text-sm font-semibold text-cobalt-800 hover:underline" onClick={() => { setEditing(d); rename.setData('name', d.name); rename.clearErrors(); }}>Rename</button>
                                    <DeleteButton type="department" id={d.id} name={d.name} />
                                </>
                            )}
                        </li>
                    ))}
                </ul>
                <form onSubmit={(e) => { e.preventDefault(); form.post('/settings/departments', { preserveScroll: true, onSuccess: () => form.reset() }); }} className="flex items-start gap-2">
                    <div className="flex-1"><Field label="New department" error={form.errors.name}><input className="field" placeholder="e.g. Dairy, Security" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field></div>
                    <Button type="submit" className="mt-6" disabled={form.processing || !form.data.name.trim()}>Add</Button>
                </form>
            </Panel>
        </div>
    );
}

function UserRow({ user, roles, departments }: { user: U; roles: Record<string, string>; departments: D[] }) {
    const form = useForm<{ role: string; active: boolean; password: string; department_id: string }>({ role: user.role, active: user.active, password: '', department_id: user.department ? String(user.department) : '' });
    const [reset, setReset] = useState(false);
    const save = () => form.post(`/settings/users/${user.id}`, { preserveScroll: true, onSuccess: () => { form.setData('password', ''); setReset(false); } });

    return (
        <li className={`px-4 py-3 ${user.active ? '' : 'bg-tile/60'}`}>
            <div className="flex flex-wrap items-center gap-2">
                <Link href={`/settings/users/${user.id}`} className="font-semibold text-cobalt-900 hover:underline">{user.name}</Link>
                <span className="font-mono text-sm text-ink-soft">{user.login}</span>
                {user.me ? <Chip className="bg-cobalt-100 text-cobalt-800">You</Chip> : null}
                {!user.active ? <Chip className="bg-crit-100 text-crit-700">Disabled</Chip> : null}
                {user.mustChange ? <Chip className="bg-watch-100 text-watch-700">Temporary password</Chip> : null}
                <span className="ml-auto text-sm text-ink-faint">{user.lastLogin ? `Last sign-in ${user.lastLogin}` : 'Never signed in'}</span>
            </div>
            <div className="mt-2 flex flex-wrap items-end gap-3">
                <div className="w-52"><Field label="Role" error={form.errors.role}><select className="field" value={form.data.role} disabled={user.me} onChange={(e) => form.setData('role', e.target.value)}>{Object.entries(roles).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field></div>
                <div className="w-44"><Field label="Department" error={form.errors.department_id}><select className="field" value={form.data.department_id} onChange={(e) => form.setData('department_id', e.target.value)}><option value="">None</option>{departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</select></Field></div>
                {!user.me ? <label className="flex min-h-11 items-center gap-1.5 text-sm"><input type="checkbox" className="size-5" checked={form.data.active} onChange={(e) => form.setData('active', e.target.checked)} /> Can sign in</label> : null}
                {reset ? <div className="w-56"><Field label="New temporary password" error={form.errors.password}><input className="field" type="text" autoComplete="off" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} /></Field></div> : !user.me ? <button type="button" className="min-h-11 text-sm font-semibold text-cobalt-800 hover:underline" onClick={() => setReset(true)}>Reset password</button> : null}
                <Button variant="secondary" disabled={form.processing || !form.isDirty} onClick={save}>Save</Button>
                <Link href={`/settings/users/${user.id}`} className="min-h-11 content-center text-sm font-semibold text-cobalt-800 hover:underline">Access rights{user.own ? ` (${user.own} own)` : ''} →</Link>
            </div>
            {form.data.role !== user.role && user.diffs[form.data.role] ? ( // D-241 (RBAC §11): what the new role changes, before saving
                <div className="mt-2"><Note>As {roles[form.data.role]}, {user.name} will {user.diffs[form.data.role].gain.length ? <>gain <b>{user.diffs[form.data.role].gain.join(', ')}</b></> : 'gain nothing'} and {user.diffs[form.data.role].lose.length ? <>lose <b>{user.diffs[form.data.role].lose.join(', ')}</b></> : 'lose nothing'}. Their own ticks stay.</Note></div>
            ) : null}
            {reset ? <div className="mt-2"><Note>Give this temporary password to {user.name}. They must choose a new one when they sign in.</Note></div> : null}
        </li>
    );
}
