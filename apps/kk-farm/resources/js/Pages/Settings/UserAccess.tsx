import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ChevronDown, ChevronRight, Lock } from 'lucide-react';
import { useState } from 'react';
import { Button, Chip, Field, Note, PageHeader, Panel } from '../../components/ui';
import type { SharedProps } from '../../types';

type Row = { key: string; label: string; role: boolean; own: boolean | null; effective: boolean; locked: boolean; vetLocked?: boolean };
type PageRow = { key: string; label: string; module: string; group: string; lock: 'admin' | 'vet' | null; menus: string[]; role: boolean; own: boolean | null; effective: boolean; moduleOn: boolean; editable: boolean };
const GROUPS = ['Overview', 'Operations', 'Records', 'Reporting', 'Configuration'];
type Props = {
    user: { id: number; name: string; login: string; role: string; active: boolean; department: number | null; me: boolean; lastLogin: string | null };
    modules: Row[]; gates: Row[]; departments: Array<{ id: number; name: string }>; canEdit: boolean; pages: PageRow[];
};

/** D-215: one person, Odoo style: details, then a tick box for every app and action. Untouched boxes follow the role. */
export default function UserAccess(p: Props) {
    const { roles } = usePage<SharedProps>().props;
    const u = p.user;
    const details = useForm({ role: u.role, active: u.active, department_id: u.department ? String(u.department) : '', password: '' });
    const [reset, setReset] = useState(false);
    const access = useForm({
        modules: Object.fromEntries(p.modules.map((m) => [m.key, m.own])) as Record<string, boolean | null>,
        gates: Object.fromEntries(p.gates.map((g) => [g.key, g.own])) as Record<string, boolean | null>,
        pages: Object.fromEntries(p.pages.map((g) => [g.key, g.own])) as Record<string, boolean | null>,
    });
    const [openMod, setOpenMod] = useState<Record<string, boolean>>({});
    const [filter, setFilter] = useState('');
    const modLabel = Object.fromEntries(p.modules.map((m) => [m.key, m.label]));
    // D-243: an app ticked or unticked here (not saved yet) turns its pages on or off for this person
    const appOn = (m: string) => { const r = p.modules.find((x) => x.key === m); return r ? (r.locked ? r.effective : (access.data.modules[m] ?? r.role)) : false; };
    const tickPage = (r: PageRow) => {
        const now = access.data.pages[r.key] ?? r.role;
        const next = !now;
        access.setData('pages', { ...access.data.pages, [r.key]: next === r.role ? null : next });
    };
    const fq = filter.trim().toLowerCase();
    const pagesByModule = p.pages.filter((r) => !fq || r.label.toLowerCase().includes(fq)).reduce<Record<string, PageRow[]>>((o, r) => { (o[r.module] ??= []).push(r); return o; }, {});
    const tick = (kind: 'modules' | 'gates', r: Row) => {
        const now = access.data[kind][r.key] ?? r.role; // what the box shows now
        const next = !now;
        access.setData(kind, { ...access.data[kind], [r.key]: next === r.role ? null : next }); // back to the role's value clears the person's own tick
    };
    const list = (kind: 'modules' | 'gates', rows: Row[]) => (
        <ul className="grid gap-x-6 sm:grid-cols-2">{rows.map((r) => {
            const own = access.data[kind][r.key];
            const on = r.locked ? r.effective : (own ?? r.role);
            return (
                <li key={r.key} className="flex items-center gap-2 border-b border-rule py-2 text-sm">
                    <input type="checkbox" className="size-4.5 accent-cobalt-800" disabled={!p.canEdit || r.locked || (u.me && r.key === 'settings')} checked={on} onChange={() => tick(kind, r)} aria-label={r.label} />
                    <span className="flex-1">{r.label}{r.vetLocked ? <span className="ml-1.5 text-xs text-watch-700">vet-locked: tick only to give this one person the vet's right</span> : null}</span>
                    {r.locked ? <Lock size={13} className="text-ink-faint" aria-label="Locked" /> : own === null || own === undefined ? <span className="text-xs text-ink-faint">from role</span> : <Chip className={own ? 'bg-ok-100 text-ok-700' : 'bg-crit-100 text-crit-700'}>Custom: {own ? 'given' : 'refused'}</Chip>}
                </li>
            );
        })}</ul>
    );
    return (
        <>
            <Head title={u.name} />
            <PageHeader title={u.name} description={`${u.login} · ${u.lastLogin ? `last sign-in ${u.lastLogin}` : 'never signed in'}`}
                actions={<><Link href="/settings/users"><Button variant="secondary"><ArrowLeft size={16} aria-hidden /> Users</Button></Link><Link href="/settings/access"><Button variant="secondary">Access by role</Button></Link></>} />
            <div className="space-y-4">
                <Panel title="Details">
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="w-56"><Field label="Role" error={details.errors.role}><select className="field" value={details.data.role} disabled={u.me || !p.canEdit} onChange={(e) => details.setData('role', e.target.value)}>{Object.entries(roles).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field></div>
                        <div className="w-48"><Field label="Department" error={details.errors.department_id}><select className="field" value={details.data.department_id} disabled={!p.canEdit} onChange={(e) => details.setData('department_id', e.target.value)}><option value="">None</option>{p.departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</select></Field></div>
                        {!u.me ? <label className="flex min-h-11 items-center gap-1.5 text-sm"><input type="checkbox" className="size-5" disabled={!p.canEdit} checked={details.data.active} onChange={(e) => details.setData('active', e.target.checked)} /> Can sign in</label> : null}
                        {reset ? <div className="w-56"><Field label="New temporary password" error={details.errors.password}><input className="field" type="text" autoComplete="off" value={details.data.password} onChange={(e) => details.setData('password', e.target.value)} /></Field></div> : !u.me && p.canEdit ? <button type="button" className="min-h-11 text-sm font-semibold text-cobalt-800 hover:underline" onClick={() => setReset(true)}>Reset password</button> : null}
                        {p.canEdit ? <Button variant="secondary" disabled={details.processing || !details.isDirty} onClick={() => details.post(`/settings/users/${u.id}`, { preserveScroll: true, onSuccess: () => { details.setData('password', ''); setReset(false); } })}>Save details</Button> : null}
                    </div>
                    {reset ? <div className="mt-2"><Note>Give this temporary password to {u.name}. They must choose a new one when they sign in.</Note></div> : null}
                    <p className="mt-2 text-xs text-ink-soft">Changing the role changes every box marked "from role" below.</p>
                </Panel>
                <Panel title="Apps" description="Tick to give, untick to take away. 'From role' follows Access Rights by Role.">{list('modules', p.modules)}</Panel>
                <Panel title="Actions" description="Vet-locked actions follow the vet role; the administrator may give one to a single person here.">{list('gates', p.gates)}</Panel>
                <Panel flush title="Pages" description="Each app's pages, pre-filled from the role. Tick or untick a page for this person only. A page needs its app."
                    actions={<input className="field w-52" placeholder="Filter pages" value={filter} onChange={(e) => setFilter(e.target.value)} aria-label="Filter pages" />}>
                    <ul className="divide-y divide-rule">{Object.entries(pagesByModule).map(([m, rows]) => {
                        const expanded = !!openMod[m] || !!fq;
                        const on = appOn(m);
                        const custom = rows.filter((r) => access.data.pages[r.key] !== null && access.data.pages[r.key] !== undefined).length;
                        return (
                            <li key={m}>
                                <button type="button" className="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm font-semibold text-cobalt-900 hover:bg-glaze" aria-expanded={expanded} onClick={() => setOpenMod({ ...openMod, [m]: !openMod[m] })}>
                                    {expanded ? <ChevronDown size={15} aria-hidden /> : <ChevronRight size={15} aria-hidden />}{modLabel[m] ?? m}
                                    <span className="text-xs font-normal text-ink-faint">{rows.length} pages{on ? '' : ' · app off'}</span>
                                    {custom ? <Chip className="bg-watch-100 text-watch-700">Custom ({custom})</Chip> : null}
                                </button>
                                {expanded ? GROUPS.map((g) => {
                                    const list = rows.filter((r) => r.group === g);
                                    if (!list.length) return null;
                                    return (
                                        <div key={g} className="px-4 pb-2">
                                            <div className="pl-6 text-[11px] font-semibold tracking-wide text-ink-soft uppercase">{g}</div>
                                            <ul className="grid gap-x-6 pl-6 sm:grid-cols-2">{list.map((r) => {
                                                const own = access.data.pages[r.key];
                                                const val = r.lock === 'admin' ? r.effective : on && (own ?? r.role);
                                                return (
                                                    <li key={r.key} className="flex items-center gap-2 border-b border-rule py-1.5 text-sm">
                                                        <input type="checkbox" className="size-4 accent-cobalt-800 disabled:opacity-40" aria-label={r.label} checked={val} disabled={!p.canEdit || !r.editable || !on} onChange={() => tickPage(r)} />
                                                        <span className="flex-1">{r.label}{r.lock === 'vet' ? <span className="ml-1 text-[11px] text-watch-700">vet-locked: tick to give this person</span> : null}{r.menus.length > 1 ? <span className="block text-[11px] text-ink-soft">Also appears in: {r.menus.join(', ')}</span> : null}</span>
                                                        {!r.editable ? <Lock size={12} className="text-ink-faint" aria-label="Locked" /> : own === null || own === undefined ? <span className="text-[11px] text-ink-faint">from role</span> : <Chip className={own ? 'bg-ok-100 text-ok-700' : 'bg-crit-100 text-crit-700'}>Custom: {own ? 'given' : 'refused'}</Chip>}
                                                    </li>
                                                );
                                            })}</ul>
                                        </div>
                                    );
                                }) : null}
                            </li>
                        );
                    })}</ul>
                </Panel>
            </div>
            {p.canEdit ? (
                <div className="sticky bottom-4 mt-4 flex justify-end gap-2">
                    <Button variant="secondary" disabled={access.processing} onClick={() => { access.setData({ modules: Object.fromEntries(p.modules.map((m) => [m.key, null])), gates: Object.fromEntries(p.gates.map((g) => [g.key, null])), pages: Object.fromEntries(p.pages.map((g) => [g.key, null])) }); }}>Follow role only</Button>
                    <Button disabled={access.processing || !access.isDirty} onClick={() => access.post(`/settings/users/${u.id}/access`, { preserveScroll: true })}>Save access</Button>
                </div>
            ) : null}
        </>
    );
}
