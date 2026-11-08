import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { Button, Chip, cx, Empty, Field, PageHeader, Panel, Tabs } from '../components/ui';
import type { SharedProps } from '../types';

type Task = { id: number; title: string; type: string; priority: string; status: string; pen: string | null; assignee: string | null; department: string | null; mine: boolean; due: string; completedBy: string | null; completedAt: string | null };
type Person = { id: number; name: string; role: string; department: number | null };
type Props = { tasks: Task[]; departments: Array<{ id: number; name: string }>; users: Person[]; pens: Array<{ id: number; name: string }> };

export default function Tasks({ tasks, departments, users, pens }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [tab, setTab] = useState('open');
    const canAssign = ['ceo', 'manager', 'vet'].includes(auth?.role ?? '');
    const form = useForm({ title: '', type: 'observation', department_id: '', user_id: '', pen_id: '', priority: 'normal' });
    // D-052: people shown under "Assign to" follow the chosen department; with no department, everyone is listed.
    const people = form.data.department_id ? users.filter((u) => String(u.department) === form.data.department_id) : users;

    const open = tasks.filter((t) => t.status === 'open');
    const done = tasks.filter((t) => t.status === 'done');
    const mine = open.filter((t) => t.mine);
    const rows = tab === 'open' ? open : tab === 'mine' ? mine : done;
    const percent = tasks.length ? Math.round((done.length / tasks.length) * 100) : 0;

    return (
        <>
            <Head title="Tasks & Daily Work" />
            <PageHeader title="Tasks & daily work" description={`${done.length} of ${tasks.length} done today.`} />
            <div className="mb-4 h-2 overflow-hidden rounded-sm bg-rule" role="progressbar" aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100} aria-label="Tasks completed today"><div className="h-full bg-turq-600 transition-[width] duration-300 ease-out" style={{ width: `${percent}%` }} /></div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_21rem]">
                <div>
                    <Tabs value={tab} onChange={setTab} tabs={[{ id: 'open', label: 'To do', count: open.length }, { id: 'mine', label: 'Mine', count: mine.length }, { id: 'done', label: 'Done', count: done.length }]} />
                    <Panel flush>
                        {rows.length ? (
                            <ul className="divide-y divide-rule">
                                {rows.map((t) => (
                                    <li key={t.id} className="flex items-center gap-3 px-4 py-2.5">
                                        <button
                                            type="button"
                                            aria-label={t.status === 'done' ? `Mark ${t.title} as not done` : `Mark ${t.title} as done`}
                                            onClick={() => router.post(`/tasks/${t.id}/toggle`, {}, { preserveScroll: true })}
                                            className={cx('grid size-9 shrink-0 place-items-center rounded-tile border transition-colors', t.status === 'done' ? 'border-turq-700 bg-turq-700 text-white' : 'border-rule-strong hover:border-turq-600 hover:bg-turq-100')}
                                        >
                                            {t.status === 'done' ? <Check size={17} aria-hidden /> : null}
                                        </button>
                                        <div className="min-w-0 flex-1">
                                            <p className={cx('font-semibold', t.status === 'done' ? 'text-ink-soft' : 'text-cobalt-900')}>{t.title}</p>
                                            <p className="truncate text-sm text-ink-soft">{t.assignee ?? (t.department ? `Anyone in ${t.department}` : 'Unassigned')}{t.assignee && t.department ? ` · ${t.department}` : ''}{t.pen ? ` · ${t.pen}` : ''}{t.completedAt ? ` · done ${t.completedAt}` : ''}</p>
                                        </div>
                                        {t.priority === 'high' && t.status === 'open' ? <Chip className="bg-treat-100 text-treat-700">Urgent</Chip> : null}
                                    </li>
                                ))}
                            </ul>
                        ) : <Empty title={tab === 'done' ? 'Nothing finished yet' : tab === 'mine' ? 'Nothing for you or your department' : 'Everything is done'} />}
                    </Panel>
                </div>
                {canAssign ? (
                    <Panel title="Assign a task">
                        <form onSubmit={(e) => { e.preventDefault(); form.post('/tasks', { preserveScroll: true, onSuccess: () => form.reset('title', 'user_id') }); }} className="space-y-3">
                            <Field label="Task" error={form.errors.title}><input className="field" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} /></Field>
                            <Field label="Type"><select className="field" value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}><option value="observation">Observation</option><option value="feeding">Feeding</option><option value="cleaning">Cleaning</option><option value="weighing">Weighing</option><option value="other">Other</option></select></Field>
                            <Field label="Department" error={form.errors.department_id}>
                                <select className="field" value={form.data.department_id} onChange={(e) => form.setData((d) => ({ ...d, department_id: e.target.value, user_id: users.some((u) => String(u.id) === d.user_id && (!e.target.value || String(u.department) === e.target.value)) ? d.user_id : '' }))}>
                                    <option value="">Any department</option>
                                    {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                                </select>
                            </Field>
                            <Field label="Assign to" error={form.errors.user_id}>
                                <select className="field" value={form.data.user_id} onChange={(e) => form.setData('user_id', e.target.value)}>
                                    <option value="">{form.data.department_id ? 'Everyone in this department' : 'Choose a person'}</option>
                                    {people.map((u) => <option key={u.id} value={u.id}>{u.name} · {u.role}</option>)}
                                </select>
                            </Field>
                            <Field label="Pen (optional)"><select className="field" value={form.data.pen_id} onChange={(e) => form.setData('pen_id', e.target.value)}><option value="">None</option>{pens.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
                            <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="size-4 accent-turq-700" checked={form.data.priority === 'high'} onChange={(e) => form.setData('priority', e.target.checked ? 'high' : 'normal')} /> Urgent</label>
                            <Button type="submit" className="w-full" disabled={form.processing}>Assign task</Button>
                        </form>
                    </Panel>
                ) : null}
            </div>
        </>
    );
}
