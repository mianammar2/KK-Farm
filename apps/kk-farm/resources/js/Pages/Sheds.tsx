import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/react';
import { LIVE_MS } from '../lib/live';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import GoatPortrait from '../components/GoatPortrait';
import ShedBoard, { type ShedBoardData } from '../components/ShedBoard';
import { Button, Chip, cx, Empty, Field, PageHeader, Panel } from '../components/ui';
import type { Health, SharedProps } from '../types';

type Goat = { id: string; number: string; name: string | null; coat: string; sex: string; health: Health; repro: string | null };
type Pen = { id: number; name: string; code: string; kind: 'pen' | 'open_area'; purpose: string; capacity: number | null; count: number; goats: Goat[] };
type Shed = { id: number; code: string; name: string; status: string; type: string | null; typeName: string | null; purpose: string | null; pens: Pen[] };
type UnitType = { code: string; name: string; purpose: string };
type Props = { sheds: Shed[]; types: UnitType[]; prefix: string; recent: Array<{ id: number; goat_id: string; number: string; summary: string; recorded_by: string }> };

/** Same rule as Pen::standardName on the server (D-055): the part after the "Pen" prefix. */
/** D-057: the pen number from what was typed or stored ("P01", "pen 1", "1" → 1); 0 when there is none. */
const penNumber = (text: string) => { const rest = text.replace(/^\s*(open\s*area|oa|pen\b|p)[\s\-:_]*/i, '').replace(/[\s\-_]*\bpen\s*$/i, '').trim(); return /^\d+$/.test(rest) ? Number(rest) : 0; };
const penCode = (n: number, area = false) => `${area ? 'OA' : 'P'}${String(n).padStart(2, '0')}`;

/** What the editor panel is doing: adding or editing a shed, or adding or editing a pen inside a shed (D-051). */
type Editing = { kind: 'shed'; shed?: Shed } | { kind: 'pen'; shed: Shed; pen?: Pen; area?: boolean } | null;

export default function Sheds({ sheds, types, prefix, recent, board }: Props & { board: ShedBoardData }) {
    usePoll(LIVE_MS, { only: ['sheds', 'recent', 'board'] }); // D-086: live
    const { auth } = usePage<SharedProps>().props;
    const canManage = !!auth?.gates.includes('manage_sheds');
    const active = sheds.filter((s) => s.status === 'active');
    const planned = sheds.filter((s) => s.status === 'planned');
    const pens = active.flatMap((s) => s.pens);
    const goats = pens.flatMap((p) => p.goats);
    const [selected, setSelected] = useState<number | null>(pens[0]?.id ?? null);
    const [editing, setEditing] = useState<Editing>(null);
    const pen = pens.find((p) => p.id === selected);
    const form = useForm({ animal_id: goats[0]?.id ?? '', pen_id: String(pens[0]?.id ?? '') });

    const editButton = (label: string, onClick: () => void, icon = <Pencil className="size-4" aria-hidden />) =>
        canManage ? <Button type="button" variant="quiet" onClick={onClick} aria-label={label} title={label}>{icon}</Button> : null;

    return (
        <>
            <Head title="Sheds" />
            <PageHeader
                title="Sheds"
                description={`${active.length} shed${active.length === 1 ? '' : 's'} in use, ${planned.length} planned, ${goats.length} goats housed.`}
                actions={canManage ? <Button type="button" onClick={() => setEditing({ kind: 'shed' })}><Plus className="mr-1 inline size-4" aria-hidden />Add shed</Button> : null}
            />

            {/* D-120: drag or tick goats into another pen; moved here from the dashboard. */}
            <div className="mb-4"><ShedBoard board={board} canMove /></div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="space-y-4">
                    {editing ? <Editor key={JSON.stringify([editing.kind, editing.shed?.id, editing.kind === 'pen' ? `${editing.pen?.id}-${editing.area}` : null])} editing={editing} sheds={sheds} types={types} prefix={prefix} onDone={() => setEditing(null)} /> : null}

                    {canManage && !editing ? (
                        <div className="xl:hidden"><Button type="button" variant="secondary" className="w-full" onClick={() => setEditing({ kind: 'shed' })}><Plus className="mr-1 inline size-4" aria-hidden />Add shed</Button></div>
                    ) : null}

                    {sheds.length === 0 ? <Empty title="No sheds yet">{canManage ? 'Add your first shed, then add pens inside it.' : 'The owner or manager adds sheds and pens.'}</Empty> : null}

                    {[...active, ...planned].map((shed) => (
                        <Panel
                            key={shed.id}
                            title={shed.name}
                            description={`${shed.typeName ? `${shed.typeName} · ${shed.purpose}` : 'No unit type yet: edit to choose one'}${shed.status === 'planned' ? ' · planned' : ''} · ${shed.pens.length} pen${shed.pens.length === 1 ? '' : 's'}`}
                            actions={canManage ? (
                                <div className="flex gap-1">
                                    {editButton(`Edit ${shed.name}`, () => setEditing({ kind: 'shed', shed }))}
                                    <Button type="button" variant="secondary" onClick={() => setEditing({ kind: 'pen', shed })}><Plus className="mr-1 inline size-4" aria-hidden />Pen</Button>
                                    <Button type="button" variant="secondary" onClick={() => setEditing({ kind: 'pen', shed, area: true })}><Plus className="mr-1 inline size-4" aria-hidden />Open area</Button>
                                </div>
                            ) : null}
                        >
                            {shed.pens.length ? (
                                <div className="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-3">
                                    {shed.pens.map((p) => {
                                        const fill = p.capacity ? Math.min(100, Math.round((p.count / p.capacity) * 100)) : 0;
                                        return (
                                            <div key={p.id} className={cx('relative rounded-tile border transition-colors', selected === p.id ? 'border-cobalt-700 bg-cobalt-100' : 'border-rule hover:border-rule-strong')}>
                                                <button type="button" aria-pressed={selected === p.id} onClick={() => setSelected(p.id)} className="w-full p-3 pr-10 text-left">
                                                    <div className="flex items-baseline justify-between gap-2"><span className="font-semibold text-cobalt-900">{p.name}{p.kind === 'open_area' ? <span className="ml-1.5 rounded-[4px] bg-watch-100 px-1.5 py-0.5 text-xs font-semibold text-watch-700">Open area</span> : null}</span><span className="text-sm text-ink-soft">{p.kind === 'open_area' ? 'outdoor' : `${p.count}${p.capacity ? ` of ${p.capacity}` : ''}`}</span></div>
                                                    <div className="text-sm text-ink-soft">{p.purpose}</div>
                                                    <div className="mt-2 h-1.5 overflow-hidden rounded-sm bg-rule" role="progressbar" aria-valuenow={fill} aria-valuemin={0} aria-valuemax={100} aria-label={`${p.name} occupancy`}><div className={cx('h-full', fill > 90 ? 'bg-treat-400' : 'bg-turq-600')} style={{ width: `${fill}%` }} /></div>
                                                </button>
                                                {canManage ? <button type="button" onClick={() => setEditing({ kind: 'pen', shed, pen: p })} aria-label={`Edit ${p.name}`} className="absolute right-1.5 top-1.5 rounded-md p-1.5 text-ink-soft hover:bg-white hover:text-cobalt-800"><Pencil className="size-4" aria-hidden /></button> : null}
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : <p className="text-sm text-ink-soft">No pens yet.{canManage ? ' Tap “Pen” to add one.' : ''}</p>}
                        </Panel>
                    ))}

                    {pen ? (
                        <Panel title={`${pen.name}: ${pen.purpose}`} description={`${pen.count} goats`}>
                            {pen.goats.length ? (
                                <div className="flex flex-wrap gap-2">
                                    {pen.goats.map((g) => (
                                        <Link key={g.id} href={`/goats/${g.id}`} className="flex w-40 items-center gap-2 rounded-tile border border-rule p-1.5 hover:border-turq-600">
                                            <GoatPortrait number={g.number} coat={g.coat} sex={g.sex} size={32} />
                                            <span className="min-w-0 truncate text-sm font-medium">{g.name ?? g.number}</span>
                                        </Link>
                                    ))}
                                </div>
                            ) : <p className="text-sm text-ink-soft">This pen is empty.</p>}
                        </Panel>
                    ) : null}
                </div>

                <div className="space-y-4">
                    {goats.length && pens.length > 1 ? (
                        <Panel title="Move a goat">
                            <form onSubmit={(e) => { e.preventDefault(); form.post('/sheds/transfer', { preserveScroll: true }); }} className="space-y-3">
                                <Field label="Goat" error={form.errors.animal_id}><select className="field" value={form.data.animal_id} onChange={(e) => form.setData('animal_id', e.target.value)}>{goats.map((g) => <option key={g.id} value={g.id}>{g.number}{g.name ? ` · ${g.name}` : ''}</option>)}</select></Field>
                                <Field label="To pen" error={form.errors.pen_id}><select className="field" value={form.data.pen_id} onChange={(e) => form.setData('pen_id', e.target.value)}>{active.map((s) => <optgroup key={s.id} label={s.typeName ? `${s.name} · ${s.typeName}` : s.name}>{s.pens.map((p) => <option key={p.id} value={p.id}>{p.name}: {p.purpose}</option>)}</optgroup>)}</select></Field>
                                <Button type="submit" className="w-full" disabled={form.processing}>Move goat</Button>
                            </form>
                        </Panel>
                    ) : null}
                    <Panel title="Recent moves" flush>
                        {recent.length ? <ul className="divide-y divide-rule text-sm">{recent.map((r) => <li key={r.id} className="px-4 py-2"><Link href={`/goats/${r.goat_id}`} className="font-semibold text-cobalt-800 hover:underline">{r.number}</Link> {r.summary}<div className="text-ink-faint">{r.recorded_by}</div></li>)}</ul> : <p className="p-4 text-sm text-ink-soft">Moves you make appear here and on each goat's lifecycle.</p>}
                    </Panel>
                    {planned.length ? (
                        <Panel title="Planned sheds">
                            <div className="flex flex-wrap gap-2">{planned.map((s) => <Chip key={s.id} className="bg-cobalt-100 px-2.5 py-1.5 text-sm text-cobalt-800">{s.name}</Chip>)}</div>
                        </Panel>
                    ) : null}
                </div>
            </div>
        </>
    );
}

function Editor({ editing, sheds, types, prefix, onDone }: { editing: NonNullable<Editing>; sheds: Shed[]; types: UnitType[]; prefix: string; onDone: () => void }) {
    return editing.kind === 'shed' ? <ShedForm shed={editing.shed} sheds={sheds} types={types} prefix={prefix} onDone={onDone} /> : <PenForm shed={editing.shed} pen={editing.pen} area={editing.area || editing.pen?.kind === 'open_area'} onDone={onDone} />;
}

/** D-056: the user chooses what the shed is for; its name is PREFIX-TYPE-NN, e.g. KKF-BDU-01. */
function ShedForm({ shed, sheds, types, prefix, onDone }: { shed?: Shed; sheds: Shed[]; types: UnitType[]; prefix: string; onDone: () => void }) {
    const form = useForm({ type: shed?.type ?? '', status: shed?.status ?? 'active' });
    const chosen = types.find((t) => t.code === form.data.type);
    const preview = (() => {
        if (!chosen) return null;
        if (shed && shed.type === chosen.code) return shed.name;
        const pattern = `${prefix}-${chosen.code}-`.toUpperCase();
        const used = sheds.filter((x) => x.id !== shed?.id && x.name.toUpperCase().startsWith(pattern)).map((x) => Number(x.name.slice(pattern.length)));
        let n = 1;
        while (used.includes(n)) n++;
        return `${pattern}${String(n).padStart(2, '0')}`;
    })();
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };
        if (shed) form.patch(`/sheds/${shed.id}`, options);
        else form.post('/sheds', options);
    };

    return (
        <Panel title={shed ? `Edit ${shed.name}` : 'Add a shed'} description="Choose what the shed is used for. The name is given automatically.">
            <form onSubmit={submit} className="grid gap-3 sm:grid-cols-2">
                <Field label="Used for (unit type)" error={form.errors.type}>
                    <select className="field" autoFocus value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} required>
                        {!form.data.type ? <option value="">Choose…</option> : null}
                        {types.map((t) => <option key={t.code} value={t.code}>{t.name} ({t.code})</option>)}
                    </select>
                </Field>
                <Field label="Status" error={form.errors.status}>
                    <select className="field" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                        <option value="active">In use</option>
                        <option value="planned">Planned (not built yet)</option>
                    </select>
                </Field>
                {chosen ? (
                    <div className="rounded-tile border border-turq-400 bg-turq-100 px-3 py-2 sm:col-span-2">
                        <div className="font-mono text-lg font-bold text-cobalt-900">{preview}</div>
                        <div className="text-sm text-cobalt-800">{chosen.name} · {chosen.purpose}</div>
                        {shed && shed.type && shed.type !== chosen.code ? <div className="mt-1 text-sm text-watch-700">{shed.name} will be renamed. Its pens and goats stay where they are.</div> : null}
                    </div>
                ) : null}
                <div className="flex gap-2 sm:col-span-2">
                    <Button type="submit" disabled={form.processing || !chosen}>{shed ? 'Save shed' : `Add ${preview ?? 'shed'}`}</Button>
                    <Button type="button" variant="secondary" onClick={onDone}>Cancel</Button>
                </div>
            </form>
        </Panel>
    );
}

function PenForm({ shed, pen, area = false, onDone }: { shed: Shed; pen?: Pen; area?: boolean; onDone: () => void }) {
    // D-055: pens are always "Pen …"; the user types only the part after the prefix. A new pen suggests the next number.
    // D-087: an open area is the shed's outdoor space, numbered OA01, OA02…
    const nextNumber = String(shed.pens.filter((p) => (p.kind === 'open_area') === area).reduce((max, p) => Math.max(max, penNumber(p.code)), 0) + 1);
    const form = useForm({ name: pen ? String(penNumber(pen.code) || '') : nextNumber, purpose: pen?.purpose ?? (area ? 'Grazing and exercise' : shed.purpose ?? ''), capacity: pen?.capacity ? String(pen.capacity) : '', kind: area ? 'open_area' : 'pen' });
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, capacity: d.capacity === '' ? null : Number(d.capacity) }));
        const options = { preserveScroll: true, onSuccess: onDone };
        if (pen) form.patch(`/pens/${pen.id}`, options);
        else form.post(`/sheds/${shed.id}/pens`, options);
    };

    return (
        <Panel title={pen ? `Edit ${pen.name}` : area ? `Add an open area to ${shed.name}` : `Add a pen to ${shed.name}`} description={pen ? (area ? 'Open area' : `${pen.count} goats`) : area ? 'Outdoor space where goats go out and come back in. Their home pen stays the same.' : undefined}>
            <form onSubmit={submit} className="grid gap-3 sm:grid-cols-3">
                <Field label={area ? 'Open area number' : 'Pen number'} error={form.errors.name} hint={penNumber(form.data.name) ? `Full name: ${shed.name}-${penCode(penNumber(form.data.name), area)}` : `Numbers only, e.g. 1 for ${area ? 'OA01' : 'P01'}`}>
                    <div className="flex items-stretch">
                        <span className="grid place-items-center whitespace-nowrap rounded-l-[4px] border border-r-0 border-rule bg-cobalt-100 px-2 font-mono text-sm font-semibold text-cobalt-800">{shed.name}-{area ? 'OA' : 'P'}</span>
                        <input className="field min-w-0 rounded-l-none font-mono" autoFocus inputMode="numeric" value={form.data.name} onChange={(e) => form.setData('name', e.target.value.replace(/[^0-9]/g, ''))} placeholder="01" maxLength={3} required aria-label={`Pen number after ${shed.name}-P`} />
                    </div>
                </Field>
                <Field label="Used for" error={form.errors.purpose}>
                    <input className="field" list="pen-purposes" value={form.data.purpose} onChange={(e) => form.setData('purpose', e.target.value)} placeholder="e.g. Pregnant does" maxLength={80} required />
                    <datalist id="pen-purposes">{['Breeding bucks', 'Breeding does', 'Pregnant does', 'Lactating does', 'Kids', 'Weaners', 'Growers', 'Fattening', 'Quarantine', 'Sick bay', 'All goats'].map((p) => <option key={p} value={p} />)}</datalist>
                </Field>
                <Field label="Capacity (goats)" error={form.errors.capacity} hint="Optional"><input className="field" type="number" inputMode="numeric" min={1} max={5000} value={form.data.capacity} onChange={(e) => form.setData('capacity', e.target.value)} /></Field>
                <div className="flex gap-2 sm:col-span-3">
                    <Button type="submit" disabled={form.processing}>{pen ? (area ? 'Save open area' : 'Save pen') : area ? 'Add open area' : 'Add pen'}</Button>
                    <Button type="button" variant="secondary" onClick={onDone}>Cancel</Button>
                </div>
            </form>
        </Panel>
    );
}
