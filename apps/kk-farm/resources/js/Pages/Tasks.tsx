import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { Button, Chip, cx, Empty, Field, PageHeader, Panel, Tabs } from '../components/ui';
import DataGrid from '../components/DataGrid';
import type { SharedProps } from '../types';
import AddButton from '../components/AddButton';
import Sheet from '../components/Sheet';
import { useTabParam } from '../lib/useTabParam';

type Task = {
    id: number; title: string; type: string; priority: string; status: string; open: boolean; pen: string | null; goatId: string | null; goat: string | null; notes: string | null;
    assignee: string | null; department: string | null; mine: boolean; due: string; dueIso: string; alert: 'overdue' | 'soon' | null; completedBy: string | null; completedAt: string | null;
};
type Person = { id: number; name: string; role: string; department: number | null };
type Props = {
    tasks: Task[]; departments: Array<{ id: number; name: string }>; users: Person[]; pens: Array<{ id: number; name: string }>; goats: Array<{ id: string; farm_number: string }>;
    types: Record<string, string>; priorities: Record<string, string>; statuses: Record<string, string>;
};

const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const PRIORITY_CLASS: Record<string, string> = { critical: 'bg-crit-100 text-crit-700', high: 'bg-treat-100 text-treat-700', medium: 'bg-glaze text-ink-soft', low: 'bg-glaze text-ink-faint' };
const STATUS_CLASS: Record<string, string> = { in_progress: 'bg-cobalt-100 text-cobalt-800', blocked: 'bg-crit-100 text-crit-700', cancelled: 'bg-glaze text-ink-faint' };

/** D-078: workbook "Tasks": due dates, related goat or pen, type, priority and status; overdue and due-soon flags. */
export default function Tasks({ tasks, departments, users, pens, goats, types, priorities, statuses }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [tab, setTab] = useTabParam('open'); // D-170
    const [adding, setAdding] = useState(false); // D-127
    const canAssign = ['ceo', 'manager', 'vet'].includes(auth?.role ?? '');
    const open = tasks.filter((t) => t.open);
    const mine = open.filter((t) => t.mine);
    const overdue = open.filter((t) => t.alert === 'overdue');
    const closed = tasks.filter((t) => !t.open);
    const rows = tab === 'open' ? open : tab === 'mine' ? mine : tab === 'overdue' ? overdue : closed;
    const todays = tasks.filter((t) => t.dueIso === today() && t.status !== 'cancelled');
    const percent = todays.length ? Math.round((todays.filter((t) => t.status === 'completed').length / todays.length) * 100) : 0;

    return (
        <>
            <Head title="Tasks & Daily Work" />
            <PageHeader title="Tasks & daily work" description={`${todays.filter((t) => t.status === 'completed').length} of ${todays.length} due today done · ${overdue.length} overdue`} actions={canAssign ? <AddButton label="Assign a task" onClick={() => setAdding(true)} /> : null} />
            <div className="mb-4 h-2 overflow-hidden rounded-sm bg-rule" role="progressbar" aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100} aria-label="Tasks due today completed"><div className="h-full bg-turq-600 transition-[width] duration-300 ease-out" style={{ width: `${percent}%` }} /></div>

            <div>
                <div className="min-w-0">
                    <Tabs value={tab} onChange={setTab} tabs={[{ id: 'open', label: 'To do', count: open.length }, { id: 'mine', label: 'Mine', count: mine.length }, { id: 'overdue', label: 'Overdue', count: overdue.length }, { id: 'closed', label: 'Done this week', count: closed.length }]} />
                    <Panel flush>
                        {rows.length && (tab === 'overdue' || tab === 'closed') ? (
                            // D-273: the delayed and finished lists are reports: a spreadsheet; open a row to act on it
                            <DataGrid<Task> id={`tasks-${tab}`} name={tab === 'overdue' ? 'Delayed tasks' : 'Tasks done this week'} rows={rows} rowKey={(t) => String(t.id)} expand={(t) => <ul className="rounded-tile bg-tile ring-1 ring-rule"><TaskRow t={t} statuses={statuses} priorities={priorities} /></ul>} cols={[
                                { key: 'title', label: 'Task', type: 'text', total: 'count', wrap: true, render: (t) => <b className="text-cobalt-900">{t.title}</b> },
                                { key: 'type', label: 'Type', type: 'enum' },
                                { key: 'priority', label: 'Priority', type: 'enum', value: (t) => priorities[t.priority] ?? t.priority },
                                { key: 'status', label: 'Status', type: 'enum', value: (t) => statuses[t.status] ?? t.status },
                                { key: 'dueIso', label: 'Due', type: 'date', render: (t) => <span className={t.alert === 'overdue' ? 'font-semibold text-crit-700' : ''}>{t.due}</span> },
                                { key: 'late', label: 'Days late', type: 'number', total: 'avg', value: (t) => (t.open ? Math.max(0, Math.round((Date.now() - new Date(`${t.dueIso}T23:59:00`).getTime()) / 86400000)) : null) },
                                { key: 'goat', label: 'Goat', type: 'text' },
                                { key: 'pen', label: 'Pen', type: 'enum' },
                                { key: 'assignee', label: 'Assigned to', type: 'enum' },
                                { key: 'department', label: 'Department', type: 'enum' },
                                { key: 'completedBy', label: 'Done by', type: 'enum' },
                            ]} />
                        ) : rows.length ? (
                            <ul className="divide-y divide-rule">
                                {rows.map((t) => <TaskRow key={t.id} t={t} statuses={statuses} priorities={priorities} />)}
                            </ul>
                        ) : <Empty title={tab === 'closed' ? 'Nothing finished this week' : tab === 'mine' ? 'Nothing for you or your department' : tab === 'overdue' ? 'Nothing overdue' : 'Everything is done'} />}
                    </Panel>
                </div>
                {canAssign && adding ? <Sheet onClose={() => setAdding(false)}><AssignForm departments={departments} users={users} pens={pens} goats={goats} types={types} priorities={priorities} onDone={() => setAdding(false)} /></Sheet> : null}
            </div>
        </>
    );
}

function TaskRow({ t, statuses, priorities }: { t: Task; statuses: Record<string, string>; priorities: Record<string, string> }) {
    const done = t.status === 'completed';
    return (
        <li className="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-2.5 sm:flex-nowrap"> {/* D-143: on a phone the status drops under the task */}
            <button type="button" aria-label={done ? `Mark ${t.title} as not done` : `Mark ${t.title} as done`} onClick={() => router.post(`/tasks/${t.id}/toggle`, {}, { preserveScroll: true })}
                className={cx('grid size-9 shrink-0 place-items-center rounded-tile border transition-colors', done ? 'border-turq-700 bg-turq-700 text-white' : 'border-rule-strong hover:border-turq-600 hover:bg-turq-100')}>
                {done ? <Check size={17} aria-hidden /> : null}
            </button>
            <div className="min-w-0 flex-1 basis-[calc(100%-3rem)] sm:basis-auto">
                <p className={cx('font-semibold', t.open ? 'text-cobalt-900' : 'text-ink-soft line-through decoration-1')}>{t.title}</p>
                <p className="text-sm text-ink-soft sm:truncate">
                    {t.type} · due {t.due} · {t.assignee ?? (t.department ? `anyone in ${t.department}` : 'unassigned')}{t.assignee && t.department ? ` (${t.department})` : ''}
                    {t.goat ? <> · <Link href={`/goats/${t.goatId}`} className="font-mono text-cobalt-800 hover:underline">{t.goat}</Link></> : null}{t.pen ? ` · ${t.pen}` : ''}
                    {t.completedAt ? ` · ${t.status === 'cancelled' ? 'cancelled' : 'done'} ${t.completedAt} by ${t.completedBy}` : ''}
                </p>
                {t.notes ? <p className="text-sm">{t.notes}</p> : null}
            </div>
            <div className="flex flex-wrap items-center gap-1.5 pl-12 sm:justify-end sm:pl-0">
                {t.alert === 'overdue' ? <Chip className="bg-crit-100 text-crit-700">Overdue</Chip> : t.alert === 'soon' ? <Chip className="bg-watch-100 text-watch-700">Due soon</Chip> : null}
                {t.priority !== 'medium' ? <Chip className={PRIORITY_CLASS[t.priority]}>{priorities[t.priority] ?? t.priority}</Chip> : null}
                {t.open ? (
                    <select aria-label={`Status of ${t.title}`} className={cx('rounded-[4px] border border-rule bg-tile px-1.5 py-1 text-xs font-semibold', STATUS_CLASS[t.status])} value={t.status}
                        onChange={(e) => router.post(`/tasks/${t.id}/status`, { status: e.target.value }, { preserveScroll: true })}>
                        {Object.entries(statuses).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
                    </select>
                ) : t.status === 'cancelled' ? <Chip className={STATUS_CLASS.cancelled}>Cancelled</Chip> : null}
            </div>
        </li>
    );
}

function AssignForm({ departments, users, pens, goats, types, priorities, onDone }: Pick<Props, 'departments' | 'users' | 'pens' | 'goats' | 'types' | 'priorities'> & { onDone?: () => void }) {
    const form = useForm({ title: '', type: 'health', department_id: '', user_id: '', pen_id: '', animal_id: '', priority: 'medium', due_on: today(), notes: '' });
    const people = form.data.department_id ? users.filter((u) => String(u.department) === form.data.department_id) : users;
    const e = form.errors as Record<string, string>;
    return (
        <Panel title="Assign a task">
            <form onSubmit={(ev) => { ev.preventDefault(); form.post('/tasks', { preserveScroll: true, onSuccess: () => { form.reset('title', 'user_id', 'animal_id', 'notes'); onDone?.(); } }); }} className="space-y-3">
                <Field label="Task" error={e.title}><input className="field" value={form.data.title} onChange={(x) => form.setData('title', x.target.value)} /></Field>
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Type"><select className="field" value={form.data.type} onChange={(x) => form.setData('type', x.target.value)}>{Object.entries(types).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Field label="Priority"><select className="field" value={form.data.priority} onChange={(x) => form.setData('priority', x.target.value)}>{Object.entries(priorities).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                </div>
                <Field label="Due date" error={e.due_on}><input type="date" className="field" value={form.data.due_on} onChange={(x) => form.setData('due_on', x.target.value)} /></Field>
                <Field label="Department" error={e.department_id}>
                    <select className="field" value={form.data.department_id} onChange={(x) => form.setData((d) => ({ ...d, department_id: x.target.value, user_id: users.some((u) => String(u.id) === d.user_id && (!x.target.value || String(u.department) === x.target.value)) ? d.user_id : '' }))}>
                        <option value="">Any department</option>{departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                    </select>
                </Field>
                <Field label="Assign to" error={e.user_id}>
                    <select className="field" value={form.data.user_id} onChange={(x) => form.setData('user_id', x.target.value)}>
                        <option value="">{form.data.department_id ? 'Everyone in this department' : 'Choose a person'}</option>{people.map((u) => <option key={u.id} value={u.id}>{u.name} · {u.role}</option>)}
                    </select>
                </Field>
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Goat (optional)"><select className="field" value={form.data.animal_id} onChange={(x) => form.setData('animal_id', x.target.value)}><option value="">None</option>{goats.map((g) => <option key={g.id} value={g.id}>{g.farm_number}</option>)}</select></Field>
                    <Field label="Pen (optional)"><select className="field" value={form.data.pen_id} onChange={(x) => form.setData('pen_id', x.target.value)}><option value="">None</option>{pens.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
                </div>
                <Field label="Notes"><textarea rows={2} className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                <Button type="submit" className="w-full" disabled={form.processing}>Assign task</Button>
            </form>
        </Panel>
    );
}
