import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { Button, Chip, Field, Note, PageHeader, Panel } from '../../components/ui';
import type { SharedProps } from '../../types';

type U = { id: number; name: string; login: string; role: string; active: boolean; mustChange: boolean; lastLogin: string | null; me: boolean };

/** The owner decides who can sign in and with which role (D-044). */
export default function Users({ users }: { users: U[] }) {
    const { roles } = usePage<SharedProps>().props;
    const add = useForm({ name: '', login: '', role: 'caretaker', password: '' });

    return (
        <>
            <Head title="Users" />
            <PageHeader title="Users and sign-in" description="Each person signs in with their own username and password, so every record shows who made it." actions={<Link href="/settings"><Button variant="secondary"><ArrowLeft size={16} aria-hidden /> Settings</Button></Link>} />
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem]">
                <Panel flush>
                    <ul className="divide-y divide-rule">{users.map((u) => <UserRow key={`${u.id}-${u.role}-${u.active}`} user={u} roles={roles} />)}</ul>
                </Panel>
                <div className="xl:sticky xl:top-4 xl:self-start">
                    <Panel title="Add a user">
                        <form onSubmit={(e) => { e.preventDefault(); add.post('/settings/users', { preserveScroll: true, onSuccess: () => add.reset() }); }} className="space-y-3">
                            <Field label="Name" error={add.errors.name}><input className="field" value={add.data.name} onChange={(e) => add.setData('name', e.target.value)} /></Field>
                            <Field label="Username or email" error={add.errors.login} hint="Letters, numbers and . _ @ + -"><input className="field" autoCapitalize="none" spellCheck={false} value={add.data.login} onChange={(e) => add.setData('login', e.target.value)} /></Field>
                            <Field label="Role" error={add.errors.role}><select className="field" value={add.data.role} onChange={(e) => add.setData('role', e.target.value)}>{Object.entries(roles).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                            <Field label="Temporary password" error={add.errors.password} hint="At least 8 characters. They choose their own on first sign-in."><input className="field" type="text" autoComplete="off" value={add.data.password} onChange={(e) => add.setData('password', e.target.value)} /></Field>
                            <Button type="submit" disabled={add.processing}>Add user</Button>
                        </form>
                    </Panel>
                </div>
            </div>
        </>
    );
}

function UserRow({ user, roles }: { user: U; roles: Record<string, string> }) {
    const form = useForm<{ role: string; active: boolean; password: string }>({ role: user.role, active: user.active, password: '' });
    const [reset, setReset] = useState(false);
    const save = () => form.post(`/settings/users/${user.id}`, { preserveScroll: true, onSuccess: () => { form.setData('password', ''); setReset(false); } });

    return (
        <li className={`px-4 py-3 ${user.active ? '' : 'bg-tile/60'}`}>
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-semibold text-cobalt-900">{user.name}</span>
                <span className="font-mono text-sm text-ink-soft">{user.login}</span>
                {user.me ? <Chip className="bg-cobalt-100 text-cobalt-800">You</Chip> : null}
                {!user.active ? <Chip className="bg-crit-100 text-crit-700">Disabled</Chip> : null}
                {user.mustChange ? <Chip className="bg-watch-100 text-watch-700">Temporary password</Chip> : null}
                <span className="ml-auto text-sm text-ink-faint">{user.lastLogin ? `Last sign-in ${user.lastLogin}` : 'Never signed in'}</span>
            </div>
            <div className="mt-2 flex flex-wrap items-end gap-3">
                <div className="w-52"><Field label="Role" error={form.errors.role}><select className="field" value={form.data.role} disabled={user.me} onChange={(e) => form.setData('role', e.target.value)}>{Object.entries(roles).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field></div>
                {!user.me ? <label className="flex min-h-11 items-center gap-1.5 text-sm"><input type="checkbox" className="size-5" checked={form.data.active} onChange={(e) => form.setData('active', e.target.checked)} /> Can sign in</label> : null}
                {reset ? <div className="w-56"><Field label="New temporary password" error={form.errors.password}><input className="field" type="text" autoComplete="off" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} /></Field></div> : !user.me ? <button type="button" className="min-h-11 text-sm font-semibold text-cobalt-800 hover:underline" onClick={() => setReset(true)}>Reset password</button> : null}
                <Button variant="secondary" disabled={form.processing || !form.isDirty} onClick={save}>Save</Button>
            </div>
            {reset ? <div className="mt-2"><Note>Give this temporary password to {user.name}. They must choose a new one when they sign in.</Note></div> : null}
        </li>
    );
}
