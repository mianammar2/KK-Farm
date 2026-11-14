import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ChevronDown, ChevronRight, Lock, RotateCcw, Search } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import { Button, Note, PageHeader, Panel } from '../../components/ui';
import type { SharedProps } from '../../types';

type PageRow = {
    key: string; label: string; module: string; group: string; lock: 'admin' | 'vet' | null; menus: string[]; href: string;
    default: Record<string, boolean>; value: Record<string, boolean>;
};
type Props = {
    modules: Record<string, string[]>; gates: Record<string, string[]>; moduleLabels: Record<string, string>; gateLabels: Record<string, string>; protected: string[];
    overrides: Array<{ id: number; name: string; count: number }>; canEdit: boolean; pages: PageRow[];
};
const GROUPS = ['Overview', 'Operations', 'Records', 'Reporting', 'Configuration'];

/** D-215 / D-243: master access rights: apps, their pages (expand a row) and actions per role. People's own ticks go on top. */
export default function Access(p: Props) {
    const { roles } = usePage<SharedProps>().props;
    const roleKeys = Object.keys(roles);
    const form = useForm({ modules: p.modules, gates: p.gates, pages: Object.fromEntries(p.pages.map((r) => [r.key, r.value])) as Record<string, Record<string, boolean>> });
    const [open, setOpen] = useState<Record<string, boolean>>({});
    const [filter, setFilter] = useState('');
    const [preview, setPreview] = useState(false);

    const toggle = (kind: 'modules' | 'gates', key: string, role: string) => {
        const cur = form.data[kind][key] ?? [];
        form.setData(kind, { ...form.data[kind], [key]: cur.includes(role) ? cur.filter((r) => r !== role) : [...cur, role] });
    };
    const modOn = (data: Record<string, string[]>, m: string, role: string) => (data[m] ?? []).includes(role);
    type Data = { modules: Record<string, string[]>; pages: Record<string, Record<string, boolean>> };
    const pageOn = (r: PageRow, role: string, d: Data = form.data) => modOn(d.modules, r.module, role) && (d.pages[r.key]?.[role] ?? r.value[role]);
    const tickPage = (r: PageRow, role: string) => form.setData('pages', { ...form.data.pages, [r.key]: { ...form.data.pages[r.key], [role]: !form.data.pages[r.key][role] } });
    const resetModule = (m: string) => {
        const next = { ...form.data.pages };
        p.pages.filter((r) => r.module === m && !r.lock).forEach((r) => { next[r.key] = { ...r.default }; });
        form.setData('pages', next);
    };

    const q = filter.trim().toLowerCase();
    const byModule = useMemo(() => {
        const out: Record<string, PageRow[]> = {};
        p.pages.filter((r) => !q || r.label.toLowerCase().includes(q) || r.href.toLowerCase().includes(q)).forEach((r) => { (out[r.module] ??= []).push(r); });
        return out;
    }, [p.pages, q]);

    // Preview: what each role gains and loses (apps, pages, actions), from what is saved now to what is ticked.
    const before: Data = useMemo(() => ({ modules: p.modules, pages: Object.fromEntries(p.pages.map((r) => [r.key, r.value])) }), [p]);
    const changes = roleKeys.map((role) => {
        const gain: string[] = []; const lose: string[] = [];
        Object.keys(p.moduleLabels).forEach((m) => {
            const a = modOn(before.modules, m, role); const b = modOn(form.data.modules, m, role);
            if (a !== b) (b ? gain : lose).push(`${p.moduleLabels[m]} (app)`);
        });
        p.pages.forEach((r) => {
            const a = pageOn(r, role, before); const b = pageOn(r, role);
            if (a !== b && modOn(before.modules, r.module, role) === modOn(form.data.modules, r.module, role)) (b ? gain : lose).push(r.label);
        });
        Object.keys(p.gateLabels).forEach((g) => {
            const a = modOn(p.gates, g, role); const b = modOn(form.data.gates, g, role);
            if (a !== b) (b ? gain : lose).push(`${p.gateLabels[g]} (action)`);
        });
        return { role, gain, lose };
    }).filter((c) => c.gain.length || c.lose.length);

    const head = <thead><tr><th className="text-left">{' '}</th>{roleKeys.map((r) => <th key={r} className="text-center text-xs">{roles[r]}</th>)}</tr></thead>;
    const moduleBox = (m: string, role: string) => (
        <td key={role} className="text-center">
            <input type="checkbox" className="size-4.5 accent-cobalt-800" aria-label={`${roles[role]}: ${p.moduleLabels[m]}`} disabled={!p.canEdit || (m === 'settings' && role === 'ceo')}
                checked={modOn(form.data.modules, m, role)} onChange={() => toggle('modules', m, role)} />
        </td>
    );
    const pageBox = (r: PageRow, role: string) => {
        const mod = modOn(form.data.modules, r.module, role);
        const val = form.data.pages[r.key]?.[role] ?? r.value[role];
        const custom = !r.lock && val !== r.default[role];
        return (
            <td key={role} className="text-center">
                <span className="inline-flex items-center gap-1">
                    <input type="checkbox" className="size-4 accent-cobalt-800 disabled:opacity-40" aria-label={`${roles[role]}: ${r.label}`}
                        disabled={!p.canEdit || !!r.lock || !mod} checked={mod && val} onChange={() => tickPage(r, role)}
                        title={r.lock === 'vet' ? 'Vet-locked: give it to one person on their own page' : r.lock === 'admin' ? 'Administrator only' : !mod ? `${roles[role]} does not have this app` : undefined} />
                    {custom && mod ? <span className="rounded-[3px] bg-watch-100 px-1 text-xs font-bold text-watch-700" title={`Default: ${r.default[role] ? 'open' : 'closed'}`}>Custom</span> : null}
                </span>
            </td>
        );
    };
    const modulesShown = Object.keys(p.moduleLabels).filter((m) => !q || byModule[m]?.length || p.moduleLabels[m].toLowerCase().includes(q));

    return (
        <>
            <Head title="Access Rights by Role" />
            <PageHeader title="Access Rights by Role" description="The master setting: tick what each role can open and do. Expand an app to tick its pages one by one. A person follows their role unless you tick or untick something for them on their own page (Users → the person)."
                actions={<><Link href="/settings"><Button variant="secondary"><ArrowLeft size={16} aria-hidden /> Settings</Button></Link><Link href="/settings/users"><Button variant="secondary">Users</Button></Link><Link href="/settings/access/tools"><Button variant="secondary">History, Why? and health</Button></Link></>} />
            {p.overrides.length ? <p className="mb-3 text-sm text-ink-soft">Own ticks on top of their role: {p.overrides.map((o, i) => <span key={o.id}>{i ? ', ' : ''}<Link href={`/settings/users/${o.id}`} className="font-semibold text-cobalt-800 hover:underline">{o.name}</Link> ({o.count})</span>)}</p> : null}
            <div className="space-y-4">
                <Panel flush title="Apps and areas" description="Opening an app, and inside it each page (expand the row). A page needs its app: without the app its boxes are off and grey. Ticking an app gives the role its default pages. The owner always keeps Settings."
                    actions={<label className="relative block w-56"><Search size={15} aria-hidden className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-ink-faint" /><input className="field pl-8" placeholder="Filter pages" value={filter} onChange={(e) => setFilter(e.target.value)} aria-label="Filter pages" /></label>}>
                    <div className="overflow-x-auto"><table className="data-table min-w-[860px]">{head}<tbody>{modulesShown.map((m) => {
                        const rows = byModule[m] ?? [];
                        const expanded = !!open[m] || (!!q && rows.length > 0);
                        const anyCustom = rows.some((r) => !r.lock && roleKeys.some((role) => (form.data.pages[r.key]?.[role] ?? r.value[role]) !== r.default[role]));
                        return (
                            <Fragment key={m}>
                                <tr>
                                    <td className="font-medium">
                                        {rows.length ? (
                                            <button type="button" className="inline-flex items-center gap-1 text-left hover:text-cobalt-800" aria-expanded={expanded} onClick={() => setOpen({ ...open, [m]: !open[m] })}>
                                                {expanded ? <ChevronDown size={15} aria-hidden /> : <ChevronRight size={15} aria-hidden />}{p.moduleLabels[m]}
                                                <span className="text-xs font-normal text-ink-faint">{rows.length} {rows.length === 1 ? 'page' : 'pages'}</span>
                                            </button>
                                        ) : <span className="pl-5">{p.moduleLabels[m]}</span>}
                                        {anyCustom ? <span className="ml-1.5 rounded-[3px] bg-watch-100 px-1 text-xs font-bold text-watch-700">Custom</span> : null}
                                    </td>
                                    {roleKeys.map((role) => moduleBox(m, role))}
                                </tr>
                                {expanded ? GROUPS.map((g) => {
                                    const list = rows.filter((r) => r.group === g);
                                    if (!list.length) return null;
                                    return (
                                        <Fragment key={g}>
                                            <tr className="bg-glaze/60"><td colSpan={roleKeys.length + 1} className="py-1 pl-7 text-xs font-semibold tracking-wide text-ink-soft uppercase">{g}</td></tr>
                                            {list.map((r) => (
                                                <tr key={r.key} className="bg-glaze/30">
                                                    <td className="pl-9 text-sm">
                                                        <span className="font-medium text-cobalt-900">{r.label}</span>
                                                        {r.lock ? <Lock size={12} className="ml-1 inline text-ink-faint" aria-label={r.lock === 'vet' ? 'Vet-locked' : 'Administrator only'} /> : null}
                                                        {r.lock === 'vet' ? <span className="ml-1 text-xs text-watch-700">vet-locked</span> : r.lock === 'admin' ? <span className="ml-1 text-xs text-ink-soft">Administrator only</span> : null}
                                                        <span className="block font-mono text-xs text-ink-faint">{r.href}</span>
                                                        {r.menus.length > 1 ? <span className="block text-xs text-ink-soft">Also appears in: {r.menus.join(', ')}</span> : null}
                                                    </td>
                                                    {roleKeys.map((role) => pageBox(r, role))}
                                                </tr>
                                            ))}
                                        </Fragment>
                                    );
                                }) : null}
                                {expanded && p.canEdit && rows.some((r) => !r.lock) ? (
                                    <tr className="bg-glaze/30"><td colSpan={roleKeys.length + 1} className="py-1.5 pl-9">
                                        <button type="button" className="inline-flex items-center gap-1 text-xs font-semibold text-cobalt-800 hover:underline" onClick={() => resetModule(m)}><RotateCcw size={12} aria-hidden /> Reset {p.moduleLabels[m]} pages to defaults</button>
                                    </td></tr>
                                ) : null}
                            </Fragment>
                        );
                    })}</tbody></table></div>
                </Panel>
                <Panel flush title="Actions" description="What a role may do inside the apps. Locked rows stay with the vet: the system records, vets decide.">
                    <div className="overflow-x-auto"><table className="data-table min-w-[760px]">{head}<tbody>{Object.keys(p.gateLabels).map((g) => {
                        const locked = p.protected.includes(g);
                        return <tr key={g}><td className="font-medium">{p.gateLabels[g]} {locked ? <Lock size={13} className="inline text-ink-faint" aria-label="Locked" /> : null}</td>{roleKeys.map((r) => (
                            <td key={r} className="text-center"><input type="checkbox" className="size-4.5 accent-cobalt-800" aria-label={`${roles[r]}: ${p.gateLabels[g]}`} disabled={!p.canEdit || locked} checked={modOn(form.data.gates, g, r)} onChange={() => toggle('gates', g, r)} /></td>
                        ))}</tr>;
                    })}</tbody></table></div>
                </Panel>
            </div>
            {p.canEdit ? <div className="sticky bottom-4 mt-4 flex justify-end"><Button disabled={form.processing || !form.isDirty} onClick={() => setPreview(true)}>Review and save</Button></div> : null}
            {preview ? (
                <div className="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-label="Changes to save">
                    <div className="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-tile bg-tile p-5 shadow-tile">
                        <h2 className="text-lg font-bold text-cobalt-900">Save these changes?</h2>
                        {changes.length ? <ul className="mt-3 space-y-3">{changes.map((c) => (
                            <li key={c.role} className="text-sm">
                                <span className="font-semibold text-cobalt-900">{roles[c.role]}</span>
                                {c.gain.length ? <span className="block text-ok-700">will gain: {c.gain.join(', ')}</span> : null}
                                {c.lose.length ? <span className="block text-crit-700">will lose: {c.lose.join(', ')}</span> : null}
                            </li>
                        ))}</ul> : <div className="mt-3"><Note>Nothing changes for anyone.</Note></div>}
                        <p className="mt-3 text-xs text-ink-soft">People with their own ticks keep them. Every change is written to the Activity Log.</p>
                        <div className="mt-4 flex justify-end gap-2">
                            <Button variant="secondary" onClick={() => setPreview(false)}>Back</Button>
                            <Button disabled={form.processing} onClick={() => form.post('/settings/access', { preserveScroll: true, onSuccess: () => setPreview(false) })}>Save access rights</Button>
                        </div>
                    </div>
                </div>
            ) : null}
        </>
    );
}
