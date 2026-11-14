import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, LibIcon, PageHeader, Panel, Tabs } from '../components/ui';
import DataGrid from '../components/DataGrid';
import { useTabParam } from '../lib/useTabParam';

type Reg = { id: number; name: string; code: string; role: string; shift: string; status: string | null; in: string | null; out: string | null; onLeave: boolean };
type Props = {
    day: string; dayLabel: string; register: Reg[];
    leave: Array<{ id: number; employee: string | null; from: string; to: string; days: number; kind: string; reason: string | null; status: string; by: string; decidedBy: string | null }>;
    report: Array<{ name: string; shift: string; present: number; half: number; absent: number; leave: number }>;
    employees: Array<{ id: number; name: string }>; options: { status: Record<string, string>; kinds: Record<string, string> };
};

const STATUS_CLS: Record<string, string> = { present: 'bg-ok-100 text-ok-700', half_day: 'bg-watch-100 text-watch-700', absent: 'bg-crit-100 text-crit-700', leave: 'bg-cobalt-100 text-cobalt-800' };
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

/** D-181: Employees & HR: attendance register, shift roster, leave requests and the 30-day attendance report. */
export default function Hr(p: Props) {
    const [tab, setTab] = useTabParam('attendance');
    const pending = p.leave.filter((l) => l.status === 'pending').length;
    return (
        <>
            <Head title="Attendance & Leave" />
            <PageHeader title="Attendance & Leave" description="Mark who is at work, see the shift roster and decide leave." />
            <Tabs value={tab} onChange={setTab} tabs={[
                { id: 'attendance', label: 'Attendance Register', count: p.register.filter((r) => !r.status).length || undefined },
                { id: 'roster', label: 'Shift Roster' }, { id: 'leave', label: 'Leave', count: pending || undefined }, { id: 'report', label: 'Attendance Report' },
            ]} />
            {tab === 'attendance' ? <Register {...p} /> : null}
            {tab === 'roster' ? <Roster {...p} /> : null}
            {tab === 'leave' ? <Leave {...p} /> : null}
            {tab === 'report' ? (
                <Panel flush title="Last 30 Days">
                    {p.report.length ? <DataGrid id="hr-attendance" name="Attendance last 30 days" rows={p.report} rowKey={(r) => r.name} cols={[
                        { key: 'name', label: 'Employee', type: 'text', total: 'count', render: (r) => <b>{r.name}</b> },
                        { key: 'shift', label: 'Shift', type: 'enum' },
                        { key: 'present', label: 'Present', type: 'number', total: 'sum' },
                        { key: 'half', label: 'Half day', type: 'number', total: 'sum' },
                        { key: 'absent', label: 'Absent', type: 'number', total: 'sum', render: (r) => <span className={r.absent ? 'font-semibold text-crit-700' : ''}>{r.absent}</span> },
                        { key: 'leave', label: 'Leave', type: 'number', total: 'sum' },
                    ]} /> : <Empty title="No employees yet" />}
                </Panel>
            ) : null}
        </>
    );
}

function Register({ day, dayLabel, register, options }: Props) {
    const mark = (r: Reg, status: string, extra: Record<string, string | null> = {}) => router.post('/hr/attendance', { employee_id: r.id, work_on: day, status, check_in: r.in?.slice(0, 5) ?? null, check_out: r.out?.slice(0, 5) ?? null, ...extra }, { preserveScroll: true, preserveState: true });
    const nowTime = () => new Date().toTimeString().slice(0, 5);
    return (
        <Panel flush title={dayLabel} description="Tap a status for each employee, or mark everyone present at the start of the shift."
            actions={<span className="flex items-center gap-2">
                <input type="date" max={today()} className="field !w-40 !py-1.5" value={day} onChange={(e) => router.get('/hr', { tab: 'attendance', day: e.target.value }, { preserveScroll: true })} />
                <Button onClick={() => router.post('/hr/attendance/all-present', { work_on: day }, { preserveScroll: true })}><LibIcon name="ic-active" size={16} /> All Present</Button>
            </span>}>
            {register.length ? <ul className="divide-y divide-rule">{register.map((r) => (
                <li key={r.id} className="flex flex-wrap items-center gap-2 px-4 py-2.5 text-sm">
                    <span className="min-w-40 flex-1"><strong className="text-cobalt-900">{r.name}</strong> <span className="text-ink-soft">· {r.role} · {r.shift}</span>{r.onLeave ? <Chip className="ml-1 bg-cobalt-100 text-cobalt-800">Approved leave</Chip> : null}</span>
                    {Object.entries(options.status).map(([k, v]) => (
                        <button key={k} type="button" onClick={() => mark(r, k)} className={`rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ${r.status === k ? `${STATUS_CLS[k]} ring-transparent` : 'bg-white text-ink-soft ring-rule'}`}>{v}</button>
                    ))}
                    {r.status === 'present' || r.status === 'half_day' ? (
                        <span className="flex items-center gap-1 text-xs">
                            <Button variant="quiet" onClick={() => mark(r, r.status!, { check_in: nowTime() })}>In {r.in?.slice(0, 5) ?? '—'}</Button>
                            <Button variant="quiet" onClick={() => mark(r, r.status!, { check_out: nowTime() })}>Out {r.out?.slice(0, 5) ?? '—'}</Button>
                        </span>
                    ) : null}
                </li>
            ))}</ul> : <Empty title="No employees yet">Add them under Employees.</Empty>}
        </Panel>
    );
}

function Roster({ register, options }: Props) {
    const shifts = [...new Set(register.map((r) => r.shift))];
    return (
        <div className="grid gap-3 lg:grid-cols-3">
            {shifts.map((s) => (
                <Panel key={s} flush title={`${s} · ${register.filter((r) => r.shift === s).length}`}>
                    <ul className="divide-y divide-rule">{register.filter((r) => r.shift === s).map((r) => (
                        <li key={r.id} className="flex items-center gap-2 px-4 py-2 text-sm"><span className="flex-1">{r.name} <span className="text-ink-soft">· {r.role}</span></span>{r.status ? <Chip className={STATUS_CLS[r.status]}>{options.status[r.status]}</Chip> : <Chip className="bg-glaze text-ink-soft">Not marked</Chip>}</li>
                    ))}</ul>
                </Panel>
            ))}
        </div>
    );
}

function Leave({ leave, employees, options }: Props) {
    const [adding, setAdding] = useState(false);
    const form = useForm({ employee_id: String(employees[0]?.id ?? ''), from_on: today(), to_on: today(), kind: 'casual', reason: '' });
    const e = form.errors as Record<string, string>;
    return (
        <div className="space-y-3">
            {adding ? (
                <Panel title="Leave Request">
                    <form className="grid gap-3 p-4 sm:grid-cols-3" onSubmit={(ev) => { ev.preventDefault(); form.post('/hr/leave', { preserveScroll: true, onSuccess: () => setAdding(false) }); }}>
                        <Field label="Employee" error={e.employee_id}><select className="field" value={form.data.employee_id} onChange={(x) => form.setData('employee_id', x.target.value)}>{employees.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}</select></Field>
                        <Field label="From" error={e.from_on}><input type="date" className="field" value={form.data.from_on} onChange={(x) => form.setData('from_on', x.target.value)} /></Field>
                        <Field label="To" error={e.to_on}><input type="date" min={form.data.from_on} className="field" value={form.data.to_on} onChange={(x) => form.setData('to_on', x.target.value)} /></Field>
                        <Field label="Type" error={e.kind}><select className="field" value={form.data.kind} onChange={(x) => form.setData('kind', x.target.value)}>{Object.entries(options.kinds).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                        <Field label="Reason" error={e.reason}><input className="field" value={form.data.reason} onChange={(x) => form.setData('reason', x.target.value)} /></Field>
                        <div className="flex items-end gap-2"><Button type="submit" disabled={form.processing}>Save</Button><Button type="button" variant="secondary" onClick={() => setAdding(false)}>Cancel</Button></div>
                    </form>
                </Panel>
            ) : null}
            <Panel flush title="Leave Requests" description="Pending first; approved leave fills the attendance register" actions={<Button onClick={() => setAdding(true)}>+ Leave Request</Button>}>
                {leave.length ? <ul className="divide-y divide-rule">{leave.map((l) => (
                    <li key={l.id} className="flex flex-wrap items-center gap-2 px-4 py-2.5 text-sm">
                        <strong className="text-cobalt-900">{l.employee}</strong>
                        <span className="text-ink-soft">{options.kinds[l.kind]} · {l.from}–{l.to} ({l.days} day{l.days === 1 ? '' : 's'}){l.reason ? ` · ${l.reason}` : ''}</span>
                        <Chip className={l.status === 'approved' ? 'bg-ok-100 text-ok-700' : l.status === 'rejected' ? 'bg-crit-100 text-crit-700' : 'bg-watch-100 text-watch-700'}>{l.status === 'pending' ? 'Pending' : `${l.status === 'approved' ? 'Approved' : 'Rejected'} by ${l.decidedBy}`}</Chip>
                        {l.status === 'pending' ? <span className="ml-auto flex gap-1.5">
                            <Button onClick={() => router.post(`/hr/leave/${l.id}`, { decision: 'approved' }, { preserveScroll: true })}>Approve</Button>
                            <Button variant="secondary" onClick={() => router.post(`/hr/leave/${l.id}`, { decision: 'rejected' }, { preserveScroll: true })}>Reject</Button>
                        </span> : null}
                    </li>
                ))}</ul> : <Empty title="No leave requests" />}
            </Panel>
        </div>
    );
}
