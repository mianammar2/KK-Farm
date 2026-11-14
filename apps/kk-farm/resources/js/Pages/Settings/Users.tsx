import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, KeyRound, LogOut, MoreHorizontal, Pencil, Plus, RefreshCw, Search, SlidersHorizontal, Trash2, Users as UsersIcon, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useConfirm } from '../../components/Confirm';
import { Button, Field, Note } from '../../components/ui';
import type { SharedProps } from '../../types';

/* D-244: Users and Sign-In. One list with a summary, search and filters; a drawer per person; bulk changes; departments as a tab.
   Every string the page shows is in T, so it can be translated (Urdu) in one place; the layout uses logical sides (start/end) for RTL. */
const T = {
    title: 'Users and Sign-In',
    help: 'Each person signs in with their own username and password, so every record shows who made it.',
    accessByRole: 'Access by role', addUser: 'Add a User', people: 'People', departments: 'Departments',
    total: 'Total users', active: 'Active', disabled: "Can't sign in", never: 'Never signed in', custom: 'With custom access',
    search: 'Search name, username or email', allRoles: 'All roles', allDepts: 'All departments', anyStatus: 'Any status', filters: 'Filters',
    sortName: 'Sort: name', sortLast: 'Sort: last sign-in', sortRole: 'Sort: role', clear: 'Clear filters', noMatch: 'No users match your filters',
    statusActive: 'Active', statusDisabled: 'Disabled', statusNever: 'Never signed in', customAccess: 'Custom access', you: 'You',
    changeDept: 'Change department', enable: 'Allow sign-in', disable: 'Block sign-in', selected: (n: number) => `${n} selected`, undo: 'Undo',
    profile: 'Profile', access: 'Access', security: 'Security', activity: 'Activity',
    name: 'Full name', username: 'Username', email: 'Email (optional)', role: 'Role', department: 'Department', auto: "Automatic (the role's department)", none: 'None',
    save: 'Save', cancel: 'Cancel', create: 'Create user', tempPassword: 'Temporary password', generate: 'Generate',
    canSignIn: 'Can sign in', lastSignIn: 'Last sign-in', resetPassword: 'Reset password', signOutAll: 'Sign out everywhere',
    openAccess: 'Open access rights', newDept: 'New department', add: 'Add', rename: 'Rename', del: 'Delete', members: (n: number) => `${n} ${n === 1 ? 'person' : 'people'}`,
    tasks: (n: number) => `${n} open ${n === 1 ? 'task' : 'tasks'}`,
};

type U = {
    id: number; name: string; login: string; email: string | null; role: string; active: boolean; department: number | null; mustChange: boolean;
    lastLogin: string | null; me: boolean; own: number; diffs: Record<string, { gain: string[]; lose: string[] }>;
};
type D = { id: number; name: string; members: Array<{ id: number; name: string; active: boolean }>; openTasks: number };
type Filters = { q: string; role: string; dept: string; status: string; sort: string };
type Props = {
    users: U[]; departments: D[]; filters: Filters; roleApps: Record<string, string[]>; roleHome: Record<string, string>;
    summary: { total: number; active: number; disabled: number; never: number; custom: number; activeAdmins: number };
    tempPassword: { id: number; name: string; login: string; password: string } | null;
};

const ROLE_TONE: Record<string, string> = {
    ceo: 'bg-cobalt-100 text-cobalt-800', manager: 'bg-turq-100 text-turq-800', vet: 'bg-treat-100 text-treat-700', caretaker: 'bg-leaf-100 text-ok-700',
    feeding: 'bg-watch-100 text-watch-700', cleaning: 'bg-manganese-100 text-manganese-600', finance: 'bg-crit-100 text-crit-700',
};
const initials = (n: string) => n.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase()).join('') || '?';
function ago(iso: string | null) {
    if (!iso) return null;
    const s = (Date.now() - new Date(iso).getTime()) / 1000;
    if (s < 60) return 'just now';
    const steps: Array<[number, string]> = [[60, 'minute'], [3600, 'hour'], [86400, 'day'], [604800, 'week'], [2592000, 'month'], [31536000, 'year']];
    let unit = 'minute'; let v = Math.floor(s / 60);
    for (const [sec, name] of steps) if (s >= sec) { unit = name; v = Math.floor(s / sec); }
    return `${v} ${unit}${v === 1 ? '' : 's'} ago`;
}
const exact = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');

function Avatar({ name, role, size = 36 }: { name: string; role: string; size?: number }) {
    return <span className={`grid shrink-0 place-items-center rounded-full font-bold ${ROLE_TONE[role] ?? 'bg-glaze text-cobalt-900'}`} style={{ width: size, height: size, fontSize: size * 0.38 }} aria-hidden>{initials(name)}</span>;
}
function RoleBadge({ role }: { role: string }) {
    const { roles } = usePage<SharedProps>().props;
    return <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold ${ROLE_TONE[role] ?? ''}`}>{roles[role] ?? role}</span>;
}
function Status({ u }: { u: U }) {
    const [label, tone] = !u.active ? [T.statusDisabled, 'bg-crit-100 text-crit-700'] : !u.lastLogin ? [T.statusNever, 'bg-watch-100 text-watch-700'] : [T.statusActive, 'bg-ok-100 text-ok-700'];
    return <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${tone}`}><span className="size-1.5 rounded-full bg-current" aria-hidden />{label}</span>;
}

/** Why a person's role or sign-in cannot be changed (shown as the tooltip on the locked control). */
function lockReason(u: U, activeAdmins: number): string | null {
    if (u.me) return 'You cannot disable yourself or remove your own Administrator role.';
    if (u.role === 'ceo' && u.active && activeAdmins <= 1) return 'At least one active Administrator must remain.';
    return null;
}

export default function Users(p: Props) {
    const { roles, flash } = usePage<SharedProps>().props;
    const [tab, setTabState] = useState(() => new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '').get('tab') === 'departments' ? 'departments' : 'people');
    const [openId, setOpenId] = useState<number | 'new' | null>(null);
    const [selected, setSelected] = useState<number[]>([]);
    const [loading, setLoading] = useState(false);
    const [toast, setToast] = useState<{ text: string; undo?: () => void } | null>(null);
    const [sheet, setSheet] = useState(false);
    const [q, setQ] = useState(p.filters.q);
    const search = useRef<HTMLInputElement>(null);
    const [ask, confirmBox] = useConfirm();
    const deptName = (id: number | null) => p.departments.find((d) => d.id === id)?.name ?? null;

    const setTab = (t: string) => {
        setTabState(t);
        const u = new URL(window.location.href); u.searchParams.set('tab', t); window.history.replaceState(window.history.state, '', u.toString());
    };
    // Filters live in the address (shareable); the server filters, so counts and lists always agree.
    const apply = useCallback((next: Partial<Filters>) => {
        const f = { ...p.filters, ...next };
        const query = Object.fromEntries(Object.entries(f).filter(([k, v]) => v && !(k === 'sort' && v === 'name')));
        router.get('/settings/users', query, { preserveState: true, preserveScroll: true, replace: true, only: ['users', 'filters'] });
    }, [p.filters]);
    useEffect(() => { const t = setTimeout(() => { if (q !== p.filters.q) apply({ q }); }, 250); return () => clearTimeout(t); }, [q]); // eslint-disable-line react-hooks/exhaustive-deps
    useEffect(() => {
        const a = router.on('start', () => setLoading(true)); const b = router.on('finish', () => setLoading(false));
        return () => { a(); b(); };
    }, []);
    useEffect(() => { setSelected((s) => s.filter((id) => p.users.some((u) => u.id === id))); }, [p.users]);
    useEffect(() => { if (flash) setToast((t) => (t?.undo ? t : { text: flash })); }, [flash]);
    useEffect(() => { if (!toast) return; const t = setTimeout(() => setToast(null), toast.undo ? 8000 : 4000); return () => clearTimeout(t); }, [toast]);
    // Keyboard: / searches, arrows move between rows (Enter opens), Esc closes the drawer (in the drawer).
    useEffect(() => {
        const key = (e: KeyboardEvent) => {
            const typing = (e.target as HTMLElement)?.closest('input, textarea, select, [contenteditable]');
            if (e.key === '/' && !typing && openId === null) { e.preventDefault(); search.current?.focus(); }
            if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && !typing && openId === null) {
                const rows = [...document.querySelectorAll<HTMLElement>('[data-user-row]')].filter((r) => r.offsetParent);
                const i = rows.indexOf(document.activeElement as HTMLElement);
                const n = rows[e.key === 'ArrowDown' ? Math.min(rows.length - 1, i + 1) : Math.max(0, i - 1)];
                if (n) { e.preventDefault(); n.focus(); }
            }
        };
        window.addEventListener('keydown', key);
        return () => window.removeEventListener('keydown', key);
    }, [openId]);

    const bulk = (ids: number[], action: 'department' | 'enable' | 'disable', department_id?: number | null, undo?: () => void, text?: string) =>
        router.post('/settings/users/bulk', { ids, action, department_id }, {
            preserveScroll: true, preserveState: true,
            onSuccess: () => { setSelected([]); setToast({ text: text ?? `${ids.length} updated.`, undo }); },
            onError: (e) => setToast({ text: e.bulk ?? Object.values(e)[0] ?? 'Not saved.' }),
        });
    /** Department and sign-in changes can be undone from the toast: each person goes back to what they had. */
    const changeDept = (ids: number[], to: number) => {
        const before = p.users.filter((u) => ids.includes(u.id)).map((u) => [u.id, u.department] as const);
        const undo = () => {
            const groups = new Map<number | null, number[]>();
            before.forEach(([id, d]) => groups.set(d, [...(groups.get(d) ?? []), id]));
            groups.forEach((g, d) => { if (d) bulk(g, 'department', d, undefined, 'Department change undone.'); });
        };
        bulk(ids, 'department', to, undo, `${ids.length === 1 ? p.users.find((u) => u.id === ids[0])?.name : `${ids.length} people`} moved to ${deptName(to)}.`);
    };
    const changeStatus = async (ids: number[], on: boolean) => {
        if (!on && !(await ask({ title: ids.length === 1 ? `Block sign-in for ${p.users.find((u) => u.id === ids[0])?.name}?` : `Block sign-in for ${ids.length} people?`, message: 'They will be signed out immediately on every device and cannot sign in until you allow it again.', confirm: T.disable }))) return;
        const before = p.users.filter((u) => ids.includes(u.id));
        const undo = () => {
            const back = before.filter((u) => u.active !== on).map((u) => u.id);
            if (back.length) bulk(back, on ? 'disable' : 'enable', undefined, undefined, 'Sign-in change undone.');
        };
        bulk(ids, on ? 'enable' : 'disable', undefined, undo, on ? 'Sign-in allowed.' : 'Sign-in blocked; signed out everywhere.');
    };

    const filtersOn = !!(p.filters.q || p.filters.role || p.filters.dept || p.filters.status);
    const clear = () => { setQ(''); apply({ q: '', role: '', dept: '', status: '', sort: p.filters.sort }); };
    const open = openId === 'new' ? null : p.users.find((u) => u.id === openId) ?? null;
    const allChecked = p.users.length > 0 && p.users.every((u) => selected.includes(u.id));

    const summary: Array<[string, number, string]> = [['', p.summary.total, T.total], ['active', p.summary.active, T.active], ['disabled', p.summary.disabled, T.disabled], ['never', p.summary.never, T.never], ['custom', p.summary.custom, T.custom]];
    const filterControls = (
        <>
            <select className="field" aria-label={T.role} value={p.filters.role} onChange={(e) => apply({ role: e.target.value })}><option value="">{T.allRoles}</option>{Object.entries(roles).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
            <select className="field" aria-label={T.department} value={p.filters.dept} onChange={(e) => apply({ dept: e.target.value })}><option value="">{T.allDepts}</option>{p.departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</select>
            <select className="field" aria-label="Status" value={p.filters.status} onChange={(e) => apply({ status: e.target.value })}><option value="">{T.anyStatus}</option><option value="active">{T.statusActive}</option><option value="disabled">{T.disabled}</option><option value="never">{T.never}</option><option value="custom">{T.custom}</option></select>
            <select className="field" aria-label="Sort" value={p.filters.sort} onChange={(e) => apply({ sort: e.target.value })}><option value="name">{T.sortName}</option><option value="last">{T.sortLast}</option><option value="role">{T.sortRole}</option></select>
        </>
    );

    return (
        <>
            <Head title={T.title} />
            <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0"><h1 className="text-2xl font-bold text-cobalt-900">{T.title}</h1><p className="mt-1 text-ink-soft">{T.help}</p></div>
                <div className="flex gap-2">
                    <Link href="/settings/access"><Button variant="secondary">{T.accessByRole}</Button></Link>
                    <span className="max-sm:hidden"><Button onClick={() => setOpenId('new')}><Plus size={16} aria-hidden /> {T.addUser}</Button></span>
                </div>
            </div>

            <div role="tablist" aria-label="Users and departments" className="mb-4 flex gap-1 border-b border-rule">
                {[['people', T.people, p.summary.total], ['departments', T.departments, p.departments.length]].map(([id, label, n]) => (
                    <button key={id} role="tab" type="button" aria-selected={tab === id} onClick={() => setTab(String(id))}
                        className={`relative -mb-px min-h-11 px-3 text-sm font-semibold ${tab === id ? 'border-b-2 border-turq-600 text-cobalt-900' : 'text-ink-soft hover:text-cobalt-800'}`}>{label} <span className="text-ink-faint">{n}</span></button>
                ))}
            </div>

            {tab === 'people' ? (
                <>
                    {/* Summary: each number is also a filter */}
                    <div className="-mx-4 mb-3 flex gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:grid sm:grid-cols-5 sm:overflow-visible sm:px-0 sm:pb-0">{summary.map(([status, n, label]) => {
                        const on = p.filters.status === status && (status !== '' || !filtersOn);
                        return (
                            <button key={label} type="button" aria-pressed={on} onClick={() => apply({ status })}
                                className={`min-h-11 min-w-[8.5rem] shrink-0 rounded-tile border px-3 py-2 text-start shadow-tile transition-colors sm:min-w-0 ${on ? 'border-turq-600 bg-turq-100/50' : 'border-rule bg-tile hover:border-cobalt-200'}`}>
                                <span className="block text-xl font-bold tabular-nums text-cobalt-900">{n}</span><span className="text-xs text-ink-soft">{label}</span>
                            </button>
                        );
                    })}</div>

                    {/* Toolbar */}
                    <div className="mb-3 flex flex-wrap items-center gap-2">
                        <label className="relative min-w-0 flex-1 sm:max-w-sm">
                            <Search size={16} aria-hidden className="pointer-events-none absolute start-3 top-1/2 -translate-y-1/2 text-ink-faint" />
                            <input ref={search} className="field ps-9" placeholder={T.search} aria-label={T.search} value={q} onChange={(e) => setQ(e.target.value)} />
                            <kbd className="pointer-events-none absolute end-2 top-1/2 hidden -translate-y-1/2 rounded border border-rule px-1.5 text-xs text-ink-faint sm:block">/</kbd>
                        </label>
                        <div className="hidden flex-wrap gap-2 sm:flex [&>select]:w-auto">{filterControls}</div>
                        <button type="button" className="inline-flex min-h-11 items-center gap-1.5 rounded-tile border border-rule bg-tile px-3 text-sm font-semibold text-cobalt-800 sm:hidden" onClick={() => setSheet(true)}>
                            <SlidersHorizontal size={16} aria-hidden /> {T.filters}{filtersOn ? ' •' : ''}
                        </button>
                        {filtersOn ? <button type="button" className="min-h-11 px-2 text-sm font-semibold text-cobalt-800 hover:underline" onClick={clear}>{T.clear}</button> : null}
                    </div>

                    {/* Bulk actions */}
                    {selected.length ? (
                        <div className="sticky top-2 z-20 mb-2 flex flex-wrap items-center gap-2 rounded-tile bg-cobalt-900 px-3 py-2 text-sm text-white shadow-float" role="region" aria-label="Bulk actions">
                            <span className="font-semibold">{T.selected(selected.length)}</span>
                            <select className="field !min-h-9 w-auto !bg-white text-cobalt-900" aria-label={T.changeDept} value="" onChange={(e) => e.target.value && changeDept(selected, Number(e.target.value))}>
                                <option value="">{T.changeDept}…</option>{p.departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                            </select>
                            <button type="button" className="min-h-9 rounded-tile bg-white/10 px-3 font-semibold hover:bg-white/20" onClick={() => changeStatus(selected, true)}>{T.enable}</button>
                            <button type="button" className="min-h-9 rounded-tile bg-white/10 px-3 font-semibold hover:bg-white/20" onClick={() => changeStatus(selected, false)}>{T.disable}</button>
                            <button type="button" className="ms-auto grid size-9 place-items-center rounded-full hover:bg-white/10" aria-label="Clear selection" onClick={() => setSelected([])}><X size={16} aria-hidden /></button>
                        </div>
                    ) : null}

                    {/* The list: a table on a computer, cards on a phone */}
                    <div className="overflow-hidden rounded-tile border border-rule bg-tile shadow-tile">
                        <table className="w-full text-sm" aria-label="Users" aria-busy={loading}>
                            <thead className="hidden border-b border-rule bg-glaze/60 text-start text-xs text-ink-soft md:table-header-group">
                                <tr>
                                    <th className="w-10 px-3 py-2"><input type="checkbox" className="size-4.5 accent-cobalt-800" aria-label="Select all shown" checked={allChecked} onChange={() => setSelected(allChecked ? [] : p.users.map((u) => u.id))} /></th>
                                    <th className="py-2 text-start font-semibold">Person</th><th className="text-start font-semibold">{T.role}</th><th className="text-start font-semibold">{T.department}</th>
                                    <th className="text-start font-semibold">Status</th><th className="text-start font-semibold">{T.lastSignIn}</th><th className="w-12"><span className="sr-only">Menu</span></th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-rule">
                                {loading && !p.users.length ? [0, 1, 2, 3].map((i) => (
                                    <tr key={i}><td colSpan={7} className="px-3 py-3"><div className="flex animate-pulse items-center gap-3"><span className="size-9 rounded-full bg-glaze" /><span className="h-3 w-48 rounded bg-glaze" /><span className="ms-auto h-3 w-24 rounded bg-glaze" /></div></td></tr>
                                )) : null}
                                {p.users.map((u) => (
                                    <UserRow key={u.id} u={u} dept={deptName(u.department)} checked={selected.includes(u.id)} dim={loading}
                                        onCheck={() => setSelected((s) => (s.includes(u.id) ? s.filter((x) => x !== u.id) : [...s, u.id]))}
                                        onOpen={() => setOpenId(u.id)} onStatus={(on) => changeStatus([u.id], on)} lock={lockReason(u, p.summary.activeAdmins)} />
                                ))}
                            </tbody>
                        </table>
                        {!p.users.length && !loading ? (
                            <div className="px-4 py-10 text-center">
                                <UsersIcon size={28} className="mx-auto text-ink-faint" aria-hidden />
                                <p className="mt-2 font-semibold text-cobalt-900">{filtersOn ? T.noMatch : 'No users yet'}</p>
                                {filtersOn ? <button type="button" className="mt-2 min-h-11 text-sm font-semibold text-cobalt-800 hover:underline" onClick={clear}>{T.clear}</button> : null}
                            </div>
                        ) : null}
                    </div>
                </>
            ) : <Departments departments={p.departments} />}

            {/* Phone: the main action floats */}
            <button type="button" onClick={() => setOpenId('new')} className="fixed end-4 bottom-[calc(5rem+env(safe-area-inset-bottom))] z-30 inline-flex min-h-12 items-center gap-2 rounded-full bg-cobalt-800 px-5 font-semibold text-white shadow-float sm:hidden">
                <Plus size={18} aria-hidden /> {T.addUser}
            </button>

            {sheet ? (
                <div className="fixed inset-0 z-50 flex items-end bg-cobalt-950/40 sm:hidden" role="dialog" aria-modal="true" aria-label={T.filters} onClick={() => setSheet(false)}>
                    <div className="kk-rise w-full space-y-2 rounded-t-3xl bg-glaze p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]" onClick={(e) => e.stopPropagation()}>
                        <div className="mb-1 flex items-center justify-between"><h2 className="font-bold text-cobalt-900">{T.filters}</h2><button type="button" aria-label="Close" className="grid size-11 place-items-center" onClick={() => setSheet(false)}><X size={18} aria-hidden /></button></div>
                        <div className="grid gap-2">{filterControls}</div>
                        <div className="flex gap-2 pt-1"><Button variant="secondary" onClick={() => { clear(); setSheet(false); }}>{T.clear}</Button><Button onClick={() => setSheet(false)}>Done</Button></div>
                    </div>
                </div>
            ) : null}

            {openId !== null ? (
                <Drawer key={String(openId)} user={open} isNew={openId === 'new'} p={p} onClose={() => setOpenId(null)} lock={open ? lockReason(open, p.summary.activeAdmins) : null}
                    onStatus={(on) => open && changeStatus([open.id], on)} />
            ) : null}
            {p.tempPassword ? <TempPassword t={p.tempPassword} /> : null}

            {toast ? (
                <div className="fixed inset-x-4 bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-[55] mx-auto flex max-w-md items-center gap-3 rounded-tile bg-cobalt-900 px-4 py-3 text-sm text-white shadow-float sm:bottom-6" role="status" aria-live="polite">
                    <Check size={16} aria-hidden className="shrink-0" /><span className="flex-1">{toast.text}</span>
                    {toast.undo ? <button type="button" className="min-h-9 rounded-tile px-2 font-semibold text-turq-400 hover:bg-white/10" onClick={() => { toast.undo?.(); setToast(null); }}>{T.undo}</button> : null}
                </div>
            ) : null}
            {confirmBox}
        </>
    );
}

function UserRow({ u, dept, checked, dim, onCheck, onOpen, onStatus, lock }: { u: U; dept: string | null; checked: boolean; dim: boolean; onCheck: () => void; onOpen: () => void; onStatus: (on: boolean) => void; lock: string | null }) {
    const [menu, setMenu] = useState(false);
    const login = u.login !== u.name.toLowerCase() ? u.login : null;
    return (
        <tr data-user-row tabIndex={0} onClick={onOpen} onKeyDown={(e) => { if (e.key === 'Enter' && e.target === e.currentTarget) onOpen(); }}
            aria-label={`${u.name}, open details`}
            className={`cursor-pointer outline-none hover:bg-glaze/50 focus-visible:bg-glaze/70 focus-visible:ring-2 focus-visible:ring-turq-600 focus-visible:ring-inset max-md:flex max-md:flex-wrap max-md:items-center max-md:gap-x-2 max-md:gap-y-1 max-md:px-3 max-md:py-3 ${dim ? 'opacity-60' : ''}`}>
            <td className="w-10 px-3 max-md:order-first max-md:w-auto max-md:px-0" onClick={(e) => e.stopPropagation()}>
                <input type="checkbox" className="size-4.5 accent-cobalt-800" aria-label={`Select ${u.name}`} checked={checked} onChange={onCheck} />
            </td>
            <td className="py-2.5 max-md:flex-1 max-md:py-0">
                <span className="flex items-center gap-3">
                    <Avatar name={u.name} role={u.role} />
                    <span className="min-w-0">
                        <span className="flex items-center gap-1.5 font-semibold text-cobalt-900">{u.name}{u.me ? <span className="rounded-full bg-cobalt-100 px-1.5 text-xs text-cobalt-800">{T.you}</span> : null}</span>
                        <span className="block truncate font-mono text-xs text-ink-soft">{login ?? u.login}{u.email ? ` · ${u.email}` : ''}</span>
                    </span>
                </span>
            </td>
            <td className="max-md:ps-12"><RoleBadge role={u.role} /></td>
            <td>{dept ? <span className="inline-flex rounded-[4px] border border-rule bg-glaze px-1.5 py-0.5 text-xs text-ink">{dept}</span> : <span className="text-xs text-ink-faint">—</span>}</td>
            <td><span className="flex flex-wrap items-center gap-1"><Status u={u} />{u.own ? <span className="rounded-full bg-watch-100 px-2 py-0.5 text-xs font-semibold text-watch-700" title={`${u.own} own ticks on top of the role`}>{T.customAccess}</span> : null}</span></td>
            <td className="text-xs text-ink-soft max-md:ms-auto" title={exact(u.lastLogin)}>{ago(u.lastLogin) ?? T.never}</td>
            <td className="relative w-12 pe-2 text-end max-md:hidden" onClick={(e) => e.stopPropagation()}>
                <button type="button" aria-label={`More for ${u.name}`} aria-haspopup="menu" aria-expanded={menu} className="grid size-11 place-items-center rounded-full text-ink-soft hover:bg-glaze hover:text-cobalt-900" onClick={() => setMenu(!menu)}><MoreHorizontal size={18} aria-hidden /></button>
                {menu ? (
                    <div role="menu" className="absolute end-2 top-11 z-30 w-52 rounded-tile border border-rule bg-tile py-1 text-start shadow-float" onMouseLeave={() => setMenu(false)}>
                        <button role="menuitem" type="button" className="block min-h-10 w-full px-3 text-start hover:bg-glaze" onClick={() => { setMenu(false); onOpen(); }}>Open details</button>
                        <Link role="menuitem" href={`/settings/users/${u.id}`} className="block min-h-10 content-center px-3 hover:bg-glaze">Access rights</Link>
                        <button role="menuitem" type="button" disabled={!!lock && u.active} title={u.active && lock ? lock : undefined} className="block min-h-10 w-full px-3 text-start hover:bg-glaze disabled:opacity-50" onClick={() => { setMenu(false); onStatus(!u.active); }}>{u.active ? T.disable : T.enable}</button>
                    </div>
                ) : null}
            </td>
        </tr>
    );
}

/** The right-side drawer (full screen on a phone): Profile, Access, Security, Activity; or a new person. */
function Drawer({ user, isNew, p, onClose, lock, onStatus }: { user: U | null; isNew: boolean; p: Props; onClose: () => void; lock: string | null; onStatus: (on: boolean) => void }) {
    const { roles } = usePage<SharedProps>().props;
    const [tab, setTab] = useState<'profile' | 'access' | 'security' | 'activity'>('profile');
    const [ask, confirmBox] = useConfirm();
    const start = { name: user?.name ?? '', login: user?.login ?? '', email: user?.email ?? '', role: user?.role ?? 'caretaker', department_id: user?.department ? String(user.department) : '', password: '' };
    const form = useForm(start);
    const dirty = isNew ? !!(form.data.name || form.data.login) : (['name', 'login', 'email', 'role', 'department_id'] as const).some((k) => form.data[k] !== start[k]);
    const [login, setLogin] = useState<{ valid: boolean; free: boolean } | null>(null);
    useEffect(() => {
        if (!form.data.login || form.data.login === start.login) { setLogin(null); return; }
        const t = setTimeout(() => fetch(`/settings/users/check-login?login=${encodeURIComponent(form.data.login)}&except=${user?.id ?? 0}`, { headers: { Accept: 'application/json' } })
            .then((r) => r.json()).then(setLogin).catch(() => setLogin(null)), 300);
        return () => clearTimeout(t);
    }, [form.data.login]); // eslint-disable-line react-hooks/exhaustive-deps
    const close = useCallback(async () => {
        if (dirty && !(await ask({ title: 'Discard your changes?', message: 'What you changed here is not saved yet.', confirm: 'Discard' }))) return;
        onClose();
    }, [dirty, ask, onClose]);
    useEffect(() => {
        const key = (e: KeyboardEvent) => { if (e.key === 'Escape' && !document.querySelector('[role="alertdialog"]')) close(); };
        window.addEventListener('keydown', key);
        return () => window.removeEventListener('keydown', key);
    }, [close]);
    const gen = () => form.setData('password', Array.from(crypto.getRandomValues(new Uint32Array(3))).map((n) => n.toString(36).slice(-4)).join('-'));
    const roleChanged = !isNew && user && form.data.role !== user.role;
    const diff = roleChanged ? user!.diffs[form.data.role] : null;
    const save = () => {
        if (isNew) {
            form.transform((d) => ({ ...d, department_id: d.department_id || null }));
            form.post('/settings/users', { preserveScroll: true, onSuccess: () => onClose() });
            return;
        }
        const changed = Object.fromEntries((['name', 'login', 'email', 'role', 'department_id'] as const).filter((k) => form.data[k] !== start[k]).map((k) => [k, k === 'department_id' ? (form.data[k] || null) : form.data[k]]));
        form.transform(() => changed);
        form.post(`/settings/users/${user!.id}`, { preserveScroll: true, onSuccess: () => onClose() });
    };
    const tabs = isNew ? [['profile', T.profile]] : [['profile', T.profile], ['access', T.access], ['security', T.security], ['activity', T.activity]];

    return (
        <div className="fixed inset-0 z-50 flex justify-end bg-cobalt-950/40" onClick={close}>
            <aside role="dialog" aria-modal="true" aria-labelledby="drawer-title" className="kk-rise flex h-full w-full flex-col bg-glaze shadow-float sm:w-[34rem]" onClick={(e) => e.stopPropagation()}>
                <header className="flex items-center gap-3 border-b border-rule bg-tile px-4 py-3">
                    {user ? <Avatar name={user.name} role={user.role} size={44} /> : <span className="grid size-11 place-items-center rounded-full bg-cobalt-100 text-cobalt-800"><Plus size={20} aria-hidden /></span>}
                    <div className="min-w-0 flex-1">
                        <h2 id="drawer-title" className="truncate text-lg font-bold text-cobalt-900">{isNew ? T.addUser : user?.name}</h2>
                        {user ? <span className="flex flex-wrap items-center gap-1.5"><RoleBadge role={user.role} /><Status u={user} /></span> : <span className="text-sm text-ink-soft">They choose their own password at the first sign-in.</span>}
                    </div>
                    <button type="button" aria-label="Close" className="grid size-11 place-items-center rounded-full hover:bg-glaze" onClick={close}><X size={20} aria-hidden /></button>
                </header>
                <div role="tablist" aria-label="Person" className="flex gap-1 border-b border-rule bg-tile px-3">
                    {tabs.map(([id, label]) => <button key={id} role="tab" type="button" aria-selected={tab === id} onClick={() => setTab(id as typeof tab)} className={`-mb-px min-h-11 px-3 text-sm font-semibold ${tab === id ? 'border-b-2 border-turq-600 text-cobalt-900' : 'text-ink-soft'}`}>{label}</button>)}
                </div>
                <div className="flex-1 overflow-y-auto p-4" role="tabpanel">
                    {tab === 'profile' ? (
                        <div className="space-y-3">
                            <Field label={T.name} error={form.errors.name}><input className="field" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus={isNew} /></Field>
                            <Field label={T.username} error={form.errors.login} hint="Letters, numbers and . _ @ + -">
                                <div className="relative">
                                    <input className="field pe-24 font-mono" value={form.data.login} autoCapitalize="none" spellCheck={false} onChange={(e) => form.setData('login', e.target.value.trim())} aria-describedby="login-check" />
                                    {login ? <span id="login-check" className={`absolute end-3 top-1/2 -translate-y-1/2 text-xs font-semibold ${login.valid && login.free ? 'text-ok-700' : 'text-crit-700'}`}>{!login.valid ? 'Not allowed' : login.free ? 'Available' : 'Taken'}</span> : null}
                                </div>
                            </Field>
                            <Field label={T.email} error={form.errors.email}><input className="field" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} /></Field>
                            <Field label={T.role} error={form.errors.role}>
                                <select className="field" value={form.data.role} disabled={!!lock} title={lock ?? undefined} onChange={(e) => form.setData('role', e.target.value)}>{Object.entries(roles).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select>
                            </Field>
                            {lock ? <p className="-mt-2 text-xs text-ink-soft">{lock}</p> : null}
                            {diff ? (
                                <Note>As <b>{roles[form.data.role]}</b>, {user!.name} will {diff.gain.length ? <>gain <b>{diff.gain.join(', ')}</b></> : 'gain nothing'} and {diff.lose.length ? <>lose <b>{diff.lose.join(', ')}</b></> : 'lose nothing'}. They will land on <b>{p.roleHome[form.data.role]}</b> after signing in. Their own ticks stay.</Note>
                            ) : null}
                            <Field label={T.department} error={form.errors.department_id}>
                                <select className="field" value={form.data.department_id} onChange={(e) => form.setData('department_id', e.target.value)}><option value="">{isNew ? T.auto : T.none}</option>{p.departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</select>
                            </Field>
                            {isNew ? (
                                <>
                                    <Field label={T.tempPassword} error={form.errors.password} hint="At least 8 characters. Give it to the person; they must change it when they first sign in.">
                                        <div className="flex gap-2"><input className="field font-mono" value={form.data.password} autoComplete="new-password" onChange={(e) => form.setData('password', e.target.value)} /><Button variant="secondary" onClick={gen}><RefreshCw size={15} aria-hidden /> {T.generate}</Button></div>
                                    </Field>
                                    <div className="rounded-tile border border-rule bg-tile p-3 text-sm">
                                        <p className="font-semibold text-cobalt-900">{roles[form.data.role]} can open</p>
                                        <p className="mt-1 text-ink-soft">{(p.roleApps[form.data.role] ?? []).join(' · ') || 'Nothing yet'}</p>
                                        <p className="mt-1 text-xs text-ink-faint">Lands on {p.roleHome[form.data.role]}. Change pages for one person later under Access rights.</p>
                                    </div>
                                </>
                            ) : null}
                        </div>
                    ) : null}
                    {tab === 'access' && user ? <AccessTab user={user} /> : null}
                    {tab === 'security' && user ? <SecurityTab user={user} lock={lock} onStatus={onStatus} /> : null}
                    {tab === 'activity' && user ? <ActivityTab user={user} /> : null}
                </div>
                {tab === 'profile' && (dirty || isNew) ? (
                    <footer className="flex justify-end gap-2 border-t border-rule bg-tile px-4 py-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))]">
                        <Button variant="secondary" onClick={() => (isNew ? close() : form.setData(start))}>{T.cancel}</Button>
                        <Button disabled={form.processing || (login !== null && !(login.valid && login.free)) || (isNew && (!form.data.name || !form.data.login || form.data.password.length < 8))} onClick={save}>{isNew ? T.create : T.save}</Button>
                    </footer>
                ) : null}
            </aside>
            {confirmBox}
        </div>
    );
}

type Details = { apps: string[]; actions: string[]; pagesOpen: number; pagesTotal: number; custom: Array<{ kind: string; label: string; given: boolean }>; roleApps: number; roleActions: number; activity: Array<{ id: number; action: string; url: string | null; at: string | null }> };
function useDetails(id: number) {
    const [d, setD] = useState<Details | null>(null);
    useEffect(() => { fetch(`/settings/users/${id}/details`, { headers: { Accept: 'application/json' } }).then((r) => r.json()).then(setD).catch(() => setD(null)); }, [id]);
    return d;
}
const Skeleton = () => <div className="space-y-2" aria-busy="true">{[0, 1, 2].map((i) => <div key={i} className="h-10 animate-pulse rounded-tile bg-tile" />)}</div>;

function AccessTab({ user }: { user: U }) {
    const d = useDetails(user.id);
    if (!d) return <Skeleton />;
    return (
        <div className="space-y-3 text-sm">
            <div className="grid grid-cols-3 gap-2">
                {[[d.apps.length, 'apps'], [d.actions.length, 'actions'], [`${d.pagesOpen}/${d.pagesTotal}`, 'pages']].map(([v, l]) => <div key={String(l)} className="rounded-tile border border-rule bg-tile p-3"><span className="block text-xl font-bold tabular-nums text-cobalt-900">{v}</span><span className="text-xs text-ink-soft">{l}</span></div>)}
            </div>
            <div className="rounded-tile border border-rule bg-tile p-3"><p className="font-semibold text-cobalt-900">Opens</p><p className="mt-1 text-ink-soft">{d.apps.join(' · ') || 'Nothing'}</p></div>
            <div className="rounded-tile border border-rule bg-tile p-3">
                <p className="font-semibold text-cobalt-900">Different from the role</p>
                {d.custom.length ? <ul className="mt-1 space-y-1">{d.custom.map((c) => <li key={`${c.kind}${c.label}`} className="flex items-center gap-2"><span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${c.given ? 'bg-ok-100 text-ok-700' : 'bg-crit-100 text-crit-700'}`}>{c.given ? 'Given' : 'Refused'}</span><span>{c.label}</span><span className="text-xs text-ink-faint">{c.kind}</span></li>)}</ul> : <p className="mt-1 text-ink-soft">Nothing: follows the role exactly.</p>}
            </div>
            <Link href={`/settings/users/${user.id}`}><Button variant="secondary">{T.openAccess} →</Button></Link>
        </div>
    );
}

function SecurityTab({ user, lock, onStatus }: { user: U; lock: string | null; onStatus: (on: boolean) => void }) {
    const [ask, confirmBox] = useConfirm();
    const reset = async () => {
        if (!(await ask({ title: `Reset the password of ${user.name}?`, message: 'A new temporary password is made and shown to you once. Their old password stops working and they are signed out everywhere. They must choose their own at the next sign-in.', confirm: T.resetPassword }))) return;
        router.post(`/settings/users/${user.id}/reset-password`, {}, { preserveScroll: true, preserveState: true });
    };
    const signOut = async () => {
        if (!(await ask({ title: `Sign ${user.name} out everywhere?`, message: 'Every device they are signed in on is signed out now. They can sign in again.', confirm: T.signOutAll, danger: false }))) return;
        router.post(`/settings/users/${user.id}/sign-out`, {}, { preserveScroll: true, preserveState: true });
    };
    const switchLocked = !!lock && user.active;
    return (
        <div className="space-y-3 text-sm">
            <div className="flex items-center gap-3 rounded-tile border border-rule bg-tile p-3">
                <div className="flex-1"><p className="font-semibold text-cobalt-900">{T.canSignIn}</p><p className="text-xs text-ink-soft">{switchLocked ? lock : user.active ? 'Blocking signs them out on every device at once.' : 'They cannot sign in until you allow it.'}</p></div>
                <button type="button" role="switch" aria-checked={user.active} aria-label={T.canSignIn} disabled={switchLocked} title={switchLocked ? lock ?? undefined : undefined} onClick={() => onStatus(!user.active)}
                    className={`relative h-7 w-12 shrink-0 rounded-full transition-colors disabled:opacity-50 ${user.active ? 'bg-ok-700' : 'bg-cobalt-200'}`}>
                    <span className={`absolute top-1 size-5 rounded-full bg-white shadow transition-all ${user.active ? 'start-6' : 'start-1'}`} />
                </button>
            </div>
            <div className="rounded-tile border border-rule bg-tile p-3"><p className="font-semibold text-cobalt-900">{T.lastSignIn}</p><p className="text-ink-soft">{user.lastLogin ? `${exact(user.lastLogin)} (${ago(user.lastLogin)})` : T.never}{user.mustChange ? ' · must choose a new password' : ''}</p></div>
            <div className="flex flex-wrap gap-2">
                <Button variant="secondary" onClick={reset}><KeyRound size={15} aria-hidden /> {T.resetPassword}</Button>
                {!user.me ? <Button variant="secondary" onClick={signOut}><LogOut size={15} aria-hidden /> {T.signOutAll}</Button> : null}
            </div>
            {confirmBox}
        </div>
    );
}

function ActivityTab({ user }: { user: U }) {
    const d = useDetails(user.id);
    if (!d) return <Skeleton />;
    return d.activity.length ? (
        <ol className="space-y-1 text-sm">{d.activity.map((a) => (
            <li key={a.id} className="rounded-tile border border-rule bg-tile px-3 py-2">
                {a.url ? <Link href={a.url} className="font-medium text-cobalt-900 hover:underline">{a.action}</Link> : <span className="font-medium text-cobalt-900">{a.action}</span>}
                <span className="block text-xs text-ink-soft" title={exact(a.at)}>{ago(a.at)}</span>
            </li>
        ))}</ol>
    ) : <p className="text-sm text-ink-soft">No recorded actions yet.</p>;
}

/** The new temporary password, shown once with Copy. It is not kept anywhere after this. */
function TempPassword({ t }: { t: NonNullable<Props['tempPassword']> }) {
    const [shown, setShown] = useState(true);
    const [copied, setCopied] = useState(false);
    if (!shown) return null;
    return (
        <div className="fixed inset-0 z-[60] grid place-items-center bg-cobalt-950/50 p-4" role="alertdialog" aria-modal="true" aria-labelledby="temp-title">
            <div className="kk-rise w-full max-w-sm rounded-tile bg-tile p-5 shadow-float">
                <h2 id="temp-title" className="text-lg font-bold text-cobalt-900">New temporary password for {t.name}</h2>
                <p className="mt-1 text-sm text-ink-soft">Username <span className="font-mono">{t.login}</span>. Give this to them now: it is shown only once. They must choose their own at the next sign-in.</p>
                <div className="mt-3 flex items-center gap-2 rounded-tile border border-rule bg-glaze px-3 py-2"><span className="flex-1 font-mono text-lg tracking-wide text-cobalt-900 select-all">{t.password}</span>
                    <Button variant="secondary" onClick={() => { navigator.clipboard?.writeText(t.password).then(() => setCopied(true)).catch(() => {}); }}>{copied ? <Check size={15} aria-hidden /> : <Copy size={15} aria-hidden />} {copied ? 'Copied' : 'Copy'}</Button></div>
                <div className="mt-4 flex justify-end"><Button onClick={() => setShown(false)}>Done</Button></div>
            </div>
        </div>
    );
}

function Departments({ departments }: { departments: D[] }) {
    const add = useForm({ name: '' });
    const [ask, confirmBox] = useConfirm();
    const [editing, setEditing] = useState<number | null>(null);
    const rename = useForm({ name: '' });
    const { errors } = usePage<SharedProps & { errors: Record<string, string> }>().props;
    return (
        <>
            {errors?.department ? <div className="mb-3"><Note>{errors.department}</Note></div> : null}
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                {departments.map((d) => {
                    const blocked = d.members.length > 0 || d.openTasks > 0;
                    return (
                        <section key={d.id} className="rounded-tile border border-rule bg-tile p-4 shadow-tile" aria-label={d.name}>
                            {editing === d.id ? (
                                <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); rename.post(`/settings/departments/${d.id}`, { preserveScroll: true, onSuccess: () => setEditing(null) }); }}>
                                    <input className="field" aria-label={`New name for ${d.name}`} value={rename.data.name} onChange={(e) => rename.setData('name', e.target.value)} autoFocus />
                                    <Button type="submit" disabled={rename.processing || !rename.data.name.trim()}>{T.save}</Button>
                                    <Button variant="secondary" onClick={() => setEditing(null)}>{T.cancel}</Button>
                                </form>
                            ) : <h3 className="text-lg font-bold text-cobalt-900">{d.name}</h3>}
                            {rename.errors.name && editing === d.id ? <p className="mt-1 text-xs text-crit-700">{rename.errors.name}</p> : null}
                            <p className="mt-1 text-sm text-ink-soft">{T.members(d.members.length)} · {T.tasks(d.openTasks)}</p>
                            <div className="mt-3 flex -space-x-2 rtl:space-x-reverse" aria-label="Members">
                                {d.members.slice(0, 7).map((m) => <span key={m.id} title={m.name} className={`grid size-9 place-items-center rounded-full border-2 border-tile text-xs font-bold ${m.active ? 'bg-cobalt-100 text-cobalt-800' : 'bg-glaze text-ink-faint'}`}>{initials(m.name)}</span>)}
                                {d.members.length > 7 ? <span className="grid size-9 place-items-center rounded-full border-2 border-tile bg-glaze text-xs font-bold text-ink-soft">+{d.members.length - 7}</span> : null}
                                {!d.members.length ? <span className="text-xs text-ink-faint">Nobody yet</span> : null}
                            </div>
                            <div className="mt-3 flex flex-wrap items-center gap-3 border-t border-rule pt-2">
                                <button type="button" className="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-cobalt-800 hover:underline" onClick={() => { rename.setData('name', d.name); setEditing(d.id); }}><Pencil size={14} aria-hidden /> {T.rename}</button>
                                <button type="button" disabled={blocked} title={blocked ? 'Move its people and open tasks first' : undefined}
                                    className="inline-flex min-h-11 items-center gap-1 text-sm font-semibold text-crit-700 hover:underline disabled:text-ink-faint disabled:no-underline"
                                    onClick={async () => { if (await ask({ title: `Delete ${d.name}?`, message: 'The department is removed. Finished tasks keep their history.', confirm: T.del })) router.delete(`/settings/departments/${d.id}`, { preserveScroll: true }); }}>
                                    <Trash2 size={14} aria-hidden /> {T.del}
                                </button>
                                {blocked ? <span className="text-xs text-ink-soft">Can't delete: {[d.members.length ? T.members(d.members.length) : null, d.openTasks ? T.tasks(d.openTasks) : null].filter(Boolean).join(' and ')}. Move them first.</span> : null}
                            </div>
                        </section>
                    );
                })}
                <form className="rounded-tile border border-dashed border-cobalt-200 p-4" onSubmit={(e) => { e.preventDefault(); add.post('/settings/departments', { preserveScroll: true, onSuccess: () => add.reset() }); }}>
                    <Field label={T.newDept} error={add.errors.name}><input className="field" placeholder="e.g. Dairy, Security" value={add.data.name} onChange={(e) => add.setData('name', e.target.value)} /></Field>
                    <div className="mt-2"><Button type="submit" disabled={add.processing || !add.data.name.trim()}><Plus size={15} aria-hidden /> {T.add}</Button></div>
                </form>
            </div>
            {confirmBox}
        </>
    );
}
