import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/react';
import { LIVE_MS } from '../lib/live';
import { LocateFixed, MapPin, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import ShedBoard, { type ShedBoardData } from '../components/ShedBoard';
import { BarnRoof, EarTag, GoatFigure } from '../components/FarmScene';
import { Button, Chip, cx, Empty, Field, HealthChip, Panel } from '../components/ui';
import type { Health, SharedProps } from '../types';
import { useFive } from '../lib/useFive';
import Sheet from '../components/Sheet';
import { useConfirm } from '../components/Confirm';

type Goat = { id: string; number: string; name: string | null; coat: string; sex: string; health: Health; repro: string | null };
type Pen = { id: number; name: string; code: string; kind: 'pen' | 'open_area'; purpose: string; capacity: number | null; count: number; goats: Goat[] };
type Shed = { id: number; farmId: number | null; code: string; name: string; status: string; type: string | null; typeName: string | null; purpose: string | null; pens: Pen[] };
type UnitType = { code: string; name: string; purpose: string };
/** D-128: a farm (location). Organization → Farm → Shed → Pen. */
type FarmLoc = { id: number; code: string; name: string; address: string | null; city: string | null; district: string | null; province: string | null; country: string | null; latitude: number | null; longitude: number | null; notes: string | null; active: boolean; place: string; sheds: number };
type Props = { farms: FarmLoc[]; sheds: Shed[]; types: UnitType[]; prefix: string };

/** Same rule as Pen::standardName on the server (D-055): the part after the "Pen" prefix. */
/** D-057: the pen number from what was typed or stored ("P01", "pen 1", "1" → 1); 0 when there is none. */
const penNumber = (text: string) => { const rest = text.replace(/^\s*(open\s*area|oa|pen\b|p)[\s\-:_]*/i, '').replace(/[\s\-_]*\bpen\s*$/i, '').trim(); return /^\d+$/.test(rest) ? Number(rest) : 0; };
const penCode = (n: number, area = false) => `${area ? 'OA' : 'P'}${String(n).padStart(2, '0')}`;

/** What the editor panel is doing: adding or editing a shed, or adding or editing a pen inside a shed (D-051). */
type Editing = { kind: 'farm'; farm?: FarmLoc } | { kind: 'shed'; shed?: Shed } | { kind: 'pen'; shed: Shed; pen?: Pen; area?: boolean } | null;

export default function Sheds({ farms, sheds, types, prefix, board }: Props & { board: ShedBoardData }) {
    usePoll(LIVE_MS, { only: ['farms', 'sheds', 'board'] }); // D-086: live
    const { auth } = usePage<SharedProps>().props;
    const canManage = !!auth?.gates.includes('manage_sheds');
    // D-129: the page shows one farm at a time when there are several; its sheds, pens and goats follow.
    const [farmId, setFarmId] = useState<number | null>(farms.length > 1 ? farms[0].id : null);
    const shown = farmId === null ? sheds : sheds.filter((s) => s.farmId === farmId);
    const shownIds = new Set(shown.map((s) => s.id));
    const active = shown.filter((s) => s.status === 'active');
    const planned = shown.filter((s) => s.status === 'planned');
    const pens = active.flatMap((s) => s.pens);
    const goats = pens.flatMap((p) => p.goats);
    const [selected, setSelected] = useState<number | null>(pens[0]?.id ?? null);
    const [editing, setEditing] = useState<Editing>(null);
    const pen = pens.find((p) => p.id === selected);

    const editButton = (label: string, onClick: () => void, icon = <Pencil className="size-4" aria-hidden />) =>
        canManage ? <Button type="button" variant="quiet" onClick={onClick} aria-label={label} title={label}>{icon}</Button> : null;

    return (
        <>
            <Head title="Sheds" />
            {/* D-142: the page header is the farm's wooden sign over a fence. */}
            <header className="kk-page-sign">
                <div className="kk-page-board">
                    <div className="min-w-0">
                        <h1 className="text-2xl font-extrabold tracking-tight text-[#fff7e6] sm:text-3xl">Sheds</h1>
                        <p className="text-sm text-[#f3e3c3]">{active.length} shed{active.length === 1 ? '' : 's'} in use · {planned.length} planned · {goats.length} goats housed</p>
                    </div>
                    {canManage ? (
                        <div className="flex flex-wrap gap-2">
                            <button type="button" className="kk-sign-btn kk-lift" onClick={() => setEditing({ kind: 'farm' })}><Plus className="size-4" aria-hidden />Add a Farm</button>
                            <button type="button" className="kk-sign-btn kk-sign-btn-green kk-lift" onClick={() => setEditing({ kind: 'shed' })}><Plus className="size-4" aria-hidden />Add a Shed</button>
                        </div>
                    ) : null}
                </div>
                <div className="kk-fence" aria-hidden />
            </header>

            {/* D-127: adding or editing a shed, pen or open area opens in a panel. */}
            {editing ? <Sheet onClose={() => setEditing(null)}><Editor key={JSON.stringify([editing.kind, editing.kind === 'farm' ? editing.farm?.id : editing.shed?.id, editing.kind === 'pen' ? `${editing.pen?.id}-${editing.area}` : null])} editing={editing} farms={farms} sheds={sheds} types={types} prefix={prefix} onDone={() => setEditing(null)} /></Sheet> : null}

            {/* D-129: farms and where they are, at the top; picking one shows only its sheds below. */}
            <section className="mb-4 space-y-3" aria-label="Farms & Locations">
                {farms.length > 1 ? (
                    <div className="flex flex-wrap gap-2" role="tablist">
                        {farms.map((f) => <button key={f.id} type="button" role="tab" aria-selected={farmId === f.id} onClick={() => setFarmId(f.id)} className={cx('kk-tab-sign', farmId === f.id && 'kk-tab-sign-on')}><MapPin className="size-3.5" aria-hidden />{f.name} <span className="font-mono opacity-80">{f.code}</span></button>)}
                        <button type="button" role="tab" aria-selected={farmId === null} onClick={() => setFarmId(null)} className={cx('kk-tab-sign', farmId === null && 'kk-tab-sign-on')}>All Farms</button>
                    </div>
                ) : null}
                {farms.filter((f) => farmId === null || f.id === farmId).map((f) => <FarmCard key={f.id} farm={f} sheds={sheds.filter((x) => x.farmId === f.id)} onEdit={canManage ? () => setEditing({ kind: 'farm', farm: f }) : undefined} />)}
            </section>

            {/* D-120: drag or tick goats into another pen; moved here from the dashboard. */}
            <div className="mb-4"><ShedBoard board={farmId === null ? board : { ...board, sheds: board.sheds.filter((x) => shownIds.has(x.id)) }} canMove /></div>

            <div>
                <div className="space-y-4">

                    {shown.length === 0 ? <Empty title={sheds.length ? 'No sheds on this farm yet' : 'No sheds yet'}>{canManage ? 'Add your first shed, then add pens inside it.' : 'The owner or manager adds sheds and pens.'}</Empty> : null}

                    {/* D-141: each shed drawn as the barn, its pens with their real goats (white female, brown male, red tag unwell). */}
                    {[...active, ...planned].map((shed) => (
                        <section key={shed.id} className={cx('kk-barn', shed.status === 'planned' && 'kk-barn-planned')} aria-label={shed.name}>
                            <BarnRoof id={`shed-${shed.id}`} name={shed.name} typeName={shed.typeName} />
                            <div className="kk-barn-body">
                                <div className="mb-2 flex flex-wrap items-center gap-2 px-1">
                                    <span className="min-w-[14rem] flex-1 text-sm text-[#4a3a22]">
                                        {farms.length > 1 ? <span className="font-semibold">{farms.find((f) => f.id === shed.farmId)?.name ?? 'No farm'} · </span> : null}
                                        {shed.typeName ? `${shed.purpose}` : 'No unit type yet: edit to choose one'}
                                        {shed.status === 'planned' ? <span className="kk-paper-note ml-2 inline-block !py-0.5 !pl-6 text-xs font-semibold">Planned</span> : null}
                                    </span>
                                    {canManage ? (
                                        <span className="flex flex-wrap gap-1.5">
                                            <button type="button" className="kk-fix kk-lift inline-flex items-center gap-1" onClick={() => setEditing({ kind: 'shed', shed })} aria-label={`Edit ${shed.name}`}><Pencil className="size-3.5" aria-hidden />Edit</button>
                                            <button type="button" className="kk-fix kk-lift inline-flex items-center gap-1" onClick={() => setEditing({ kind: 'pen', shed })}><Plus className="size-3.5" aria-hidden />Pen</button>
                                            <button type="button" className="kk-fix kk-lift inline-flex items-center gap-1" onClick={() => setEditing({ kind: 'pen', shed, area: true })}><Plus className="size-3.5" aria-hidden />Open Area</button>
                                        </span>
                                    ) : null}
                                </div>
                                {shed.pens.length ? (
                                    <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4">
                                        {shed.pens.map((p) => {
                                            const area = p.kind === 'open_area';
                                            const shown = p.goats.slice(0, 18);
                                            const full = p.capacity !== null && p.count > p.capacity;
                                            return (
                                                <div key={p.id} className={cx('kk-pen relative !p-0', area && 'kk-yard', selected === p.id && 'kk-pen-selected')}>
                                                    <button type="button" aria-pressed={selected === p.id} onClick={() => setSelected(p.id)} className="block w-full px-2 pt-1.5 pb-2 pr-8 text-left">
                                                        <span className="flex items-center justify-between gap-1">
                                                            <span className="kk-pen-sign">{p.code}</span>
                                                            <span className={cx('text-sm font-bold tabular-nums', full ? 'text-crit-700' : 'text-[#3b2e1a]')}>{area ? 'Outdoor' : <>{p.count}{p.capacity ? <span className="text-xs font-semibold opacity-70">/{p.capacity}</span> : null}</>}</span>
                                                        </span>
                                                        <span className="mt-0.5 block truncate text-xs text-[#4a3a22]">{p.purpose}</span>
                                                        <span className="kk-herd" aria-hidden>
                                                            {shown.map((g, i) => <GoatFigure key={g.id} female={g.sex === 'female'} flag={g.health !== 'healthy'} delay={(i * 0.37) % 2.4} flip={i % 3 === 1} />)}
                                                            {p.goats.length > shown.length ? <span className="self-center text-xs font-bold text-[#4a3a22]">+{p.goats.length - shown.length}</span> : null}
                                                        </span>
                                                        {!area ? <span className="kk-trough mt-1 block" aria-hidden /> : null}
                                                    </button>
                                                    {canManage ? <button type="button" onClick={() => setEditing({ kind: 'pen', shed, pen: p })} aria-label={`Edit ${p.name}`} className="absolute right-1 top-1 rounded-md p-1.5 text-[#5a3b1e] hover:bg-white/70"><Pencil className="size-4" aria-hidden /></button> : null}
                                                </div>
                                            );
                                        })}
                                    </div>
                                ) : <p className="px-1 text-sm text-[#4a3a22]">No pens yet.{canManage ? ' Tap “Pen” to add one.' : ''}</p>}
                            </div>
                            <div className="kk-grass" aria-hidden />
                        </section>
                    ))}

                    {pen ? (
                        <PenRollCall pen={pen} />
                    ) : null}
                </div>

            </div>
        </>
    );
}

function Editor({ editing, farms, sheds, types, prefix, onDone }: { editing: NonNullable<Editing>; farms: FarmLoc[]; sheds: Shed[]; types: UnitType[]; prefix: string; onDone: () => void }) {
    if (editing.kind === 'farm') return <FarmForm farm={editing.farm} only={farms.length <= 1} onDone={onDone} />;
    return editing.kind === 'shed' ? <ShedForm shed={editing.shed} farms={farms} sheds={sheds} types={types} prefix={prefix} onDone={onDone} /> : <PenForm shed={editing.shed} pen={editing.pen} area={editing.area || editing.pen?.kind === 'open_area'} onDone={onDone} />;
}

/** D-056: the user chooses what the shed is for; its name is PREFIX-TYPE-NN, e.g. KKF-BDU-01. */
function ShedForm({ shed, farms, sheds, types, prefix, onDone }: { shed?: Shed; farms: FarmLoc[]; sheds: Shed[]; types: UnitType[]; prefix: string; onDone: () => void }) {
    const usable = farms.filter((f) => f.active || f.id === shed?.farmId);
    const form = useForm({ farm_id: String(shed?.farmId ?? usable[0]?.id ?? ''), type: shed?.type ?? '', status: shed?.status ?? 'active' });
    const chosen = types.find((t) => t.code === form.data.type);
    const farm = farms.find((f) => String(f.id) === form.data.farm_id);
    const code = farm?.code ?? prefix;
    const movingFarm = !!shed && String(shed.farmId ?? '') !== form.data.farm_id;
    const preview = (() => {
        if (!chosen) return null;
        if (shed && shed.type === chosen.code && !movingFarm) return shed.name;
        const pattern = `${code}-${chosen.code}-`.toUpperCase();
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
        <Panel title={shed ? `Edit ${shed.name}` : 'Add a shed'} description="Choose the farm first, then what the shed is used for. The name is given automatically.">
            <form onSubmit={submit} className="grid gap-3">
                <Field label="Farm / Location" error={(form.errors as Record<string, string>).farm_id}>
                    <select className="field" autoFocus value={form.data.farm_id} onChange={(e) => form.setData('farm_id', e.target.value)} required>
                        {usable.map((f) => <option key={f.id} value={f.id}>{f.name} ({f.code}){f.place ? ` · ${f.place}` : ''}</option>)}
                    </select>
                </Field>
                <Field label="Used for (unit type)" error={form.errors.type}>
                    <select className="field" value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} required>
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
                    <div className="rounded-tile border border-turq-400 bg-turq-100 px-3 py-2">
                        <div className="font-mono text-lg font-bold text-cobalt-900">{preview}</div>
                        <div className="text-sm text-cobalt-800">{chosen.name} · {chosen.purpose}</div>
                        {shed && shed.type && (shed.type !== chosen.code || movingFarm) ? <div className="mt-1 text-sm text-watch-700">{shed.name} will be renamed. Its pens and goats stay where they are.</div> : null}
                    </div>
                ) : null}
                <div className="flex gap-2">
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

/** D-128: a farm and where it is. "Use My Location" fills the GPS from the phone or computer (the browser asks first). */
function FarmForm({ farm, only = false, onDone }: { farm?: FarmLoc; only?: boolean; onDone: () => void }) {
    const form = useForm({
        name: farm?.name ?? '', code: farm?.code ?? '', address: farm?.address ?? '', city: farm?.city ?? '', district: farm?.district ?? '', province: farm?.province ?? '',
        country: farm?.country ?? 'Pakistan', latitude: farm?.latitude != null ? String(farm.latitude) : '', longitude: farm?.longitude != null ? String(farm.longitude) : '', notes: farm?.notes ?? '', active: farm?.active ?? true,
    });
    const [locating, setLocating] = useState<string | null>(null);
    const e = form.errors as Record<string, string>;
    const locked = !!farm && farm.sheds > 0;
    const locate = () => {
        if (!navigator.geolocation) { setLocating('This device cannot share its location.'); return; }
        setLocating('Finding your location…');
        navigator.geolocation.getCurrentPosition(
            (p) => { form.setData({ ...form.data, latitude: p.coords.latitude.toFixed(6), longitude: p.coords.longitude.toFixed(6) }); setLocating(`Found, accurate to about ${Math.round(p.coords.accuracy)} m.`); },
            () => setLocating('Location was not shared. Type it in, or allow location for this site.'),
            { enableHighAccuracy: true, timeout: 15000 },
        );
    };
    const input = (key: keyof typeof form.data, props: React.InputHTMLAttributes<HTMLInputElement> = {}) => (
        <input className="field" value={String(form.data[key])} onChange={(x) => form.setData(key, x.target.value as never)} {...props} />
    );
    // D-130: delete only an empty farm, and never the last one.
    const [deleteError, setDeleteError] = useState<string | null>(null);
    const [ask, confirmBox] = useConfirm(); // D-132
    const blocked = farm ? (farm.sheds > 0 ? `Move its ${farm.sheds} shed${farm.sheds === 1 ? '' : 's'} to another farm first.` : only ? 'This is the only farm. Add another before deleting it.' : null) : null;
    const remove = async () => {
        if (!farm || blocked) return;
        if (!(await ask({ title: `Delete ${farm.name}?`, message: `The farm ${farm.code} and its location will be removed. This cannot be undone.`, confirm: 'Yes, delete farm' }))) return;
        router.delete(`/farms/${farm.id}`, { preserveScroll: true, onSuccess: onDone, onError: (er) => setDeleteError(er.farm ?? 'Could not delete this farm.') });
    };
    const submit = (ev: React.FormEvent) => {
        ev.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };
        if (farm) form.patch(`/farms/${farm.id}`, options);
        else form.post('/farms', options);
    };

    return (
        <Panel title={farm ? `Edit ${farm.name}` : 'Add a farm'} description="Where the farm is. Its code starts the names of its sheds, e.g. KKF-BDU-01.">
            <form onSubmit={submit} className="grid gap-3">
                <Field label="Farm name" error={e.name}>{input('name', { autoFocus: true, placeholder: 'e.g. KK Farms Bahawalpur', required: true })}</Field>
                <Field label="Code" error={e.code} hint={locked ? 'Fixed: its sheds already use it.' : '2 to 6 letters, used in shed names'}>{input('code', { className: 'field font-mono uppercase', maxLength: 6, disabled: locked, required: true, placeholder: 'KKF' })}</Field>
                <Field label="Address" error={e.address}>{input('address', { placeholder: 'Village, road, landmark' })}</Field>
                <div className="grid grid-cols-2 gap-2">
                    <Field label="City / Town" error={e.city}>{input('city')}</Field>
                    <Field label="District" error={e.district}>{input('district')}</Field>
                </div>
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Province" error={e.province}>{input('province', { placeholder: 'e.g. Punjab' })}</Field>
                    <Field label="Country" error={e.country}>{input('country')}</Field>
                </div>
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Latitude" error={e.latitude}>{input('latitude', { inputMode: 'decimal', placeholder: '29.395700' })}</Field>
                    <Field label="Longitude" error={e.longitude}>{input('longitude', { inputMode: 'decimal', placeholder: '71.683300' })}</Field>
                </div>
                <div>
                    <Button type="button" variant="secondary" onClick={locate}><LocateFixed className="mr-1 inline size-4" aria-hidden />Use My Location</Button>
                    {locating ? <p className="mt-1 text-sm text-ink-soft">{locating}</p> : null}
                </div>
                <Field label="Notes (optional)" error={e.notes}><textarea className="field min-h-16" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                {farm ? <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="size-5" checked={form.data.active} onChange={(x) => form.setData('active', x.target.checked)} /> In use</label> : null}
                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>{farm ? 'Save farm' : 'Add farm'}</Button>
                    <Button type="button" variant="secondary" onClick={onDone}>Cancel</Button>
                </div>
                {confirmBox}
                {farm ? (
                    <div className="border-t border-rule pt-3">
                        <button type="button" onClick={remove} disabled={!!blocked} className="inline-flex min-h-11 items-center gap-2 rounded-full border border-crit-700 px-4 text-sm font-semibold text-crit-700 hover:bg-crit-100 disabled:cursor-not-allowed disabled:border-rule disabled:text-ink-faint disabled:hover:bg-transparent"><Trash2 size={16} aria-hidden />Delete Farm</button>
                        {blocked ? <p className="mt-1 text-sm text-ink-soft">{blocked}</p> : null}
                        {deleteError ? <p className="mt-1 text-sm text-crit-700" role="alert">{deleteError}</p> : null}
                    </div>
                ) : null}
            </form>
        </Panel>
    );
}

/** D-129: one farm at the top of Sheds: where it is, its numbers, and a map (Google Maps, no key: a pin from the GPS, else the address). */
function FarmCard({ farm, sheds, onEdit }: { farm: FarmLoc; sheds: Shed[]; onEdit?: () => void }) {
    const [view, setView] = useState<'m' | 'k'>('k');
    const [open, setOpen] = useState<Detail | null>(null); // D-131: a tile opens its list
    const inUse = sheds.filter((s) => s.status === 'active');
    const pens = inUse.flatMap((s) => s.pens.filter((p) => p.kind === 'pen'));
    const goats = pens.flatMap((p) => p.goats);
    const females = goats.filter((g) => g.sex === 'female').length;
    const capacity = pens.reduce((a, p) => a + (p.capacity ?? 0), 0);
    const watch = goats.filter((g) => g.health !== 'healthy').length;
    const hasGps = farm.latitude !== null && farm.longitude !== null;
    const address = [farm.address, farm.city, farm.district, farm.province].filter(Boolean).join(', ');
    const query = hasGps ? `${farm.latitude},${farm.longitude}` : address ? `${address}, ${farm.country ?? ''}` : null;
    // D-131: each tile opens its list below the card; tiles that would show 0 are hidden (owner rule).
    const penOf = new Map(pens.flatMap((p) => p.goats.map((g) => [g.id, p.name] as const)));
    const stats: Array<{ id: Detail; label: string; value: string | number; count: number }> = [
        { id: 'sheds', label: 'Sheds', value: `${inUse.length}${sheds.length > inUse.length ? ` + ${sheds.length - inUse.length} planned` : ''}`, count: sheds.length },
        { id: 'pens', label: 'Pens', value: pens.length, count: pens.length },
        { id: 'goats', label: 'Goats', value: capacity ? `${goats.length} of ${capacity}` : goats.length, count: goats.length },
        { id: 'sex', label: 'Female / Male', value: `${females} / ${goats.length - females}`, count: goats.length },
        { id: 'attention', label: 'Need Attention', value: watch, count: watch },
    ].filter((t) => t.count > 0) as Array<{ id: Detail; label: string; value: string | number; count: number }>;

    return (
        <div className={cx('kk-farmcard kk-rise grid overflow-hidden lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]', !farm.active && 'opacity-70')}>
            <div className="space-y-3 p-4">
                <div className="flex items-start gap-3">
                    <MapPin className="mt-1 size-5 shrink-0 text-turq-700" aria-hidden />
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2"><h2 className="text-lg font-semibold text-cobalt-900">{farm.name}</h2><Chip className="bg-cobalt-100 font-mono text-cobalt-800">{farm.code}</Chip>{!farm.active ? <Chip className="bg-rule text-ink-soft">Not In Use</Chip> : null}</div>
                        <p className="text-sm text-ink-soft">{address || 'No address yet'}{farm.country ? `${address ? ', ' : ' · '}${farm.country}` : ''}</p>
                        {hasGps ? <p className="font-mono text-xs text-ink-faint">GPS {farm.latitude!.toFixed(5)}, {farm.longitude!.toFixed(5)}</p> : null}
                    </div>
                    {onEdit ? <Button type="button" variant="quiet" onClick={onEdit} aria-label={`Edit ${farm.name}`} title={`Edit ${farm.name}`}><Pencil className="size-4" aria-hidden /></Button> : null}
                </div>
                {stats.length ? (
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        {stats.map((t) => (
                            <button key={t.id} type="button" onClick={() => setOpen(open === t.id ? null : t.id)} aria-expanded={open === t.id}
                                className={cx('kk-stat-plank kk-lift text-left', open === t.id && 'kk-stat-plank-on')}>
                                <span className="block text-[11px] font-semibold text-[#5a4628]">{t.label}</span>
                                <span className={cx('block text-lg font-extrabold tabular-nums', t.id === 'attention' ? 'text-crit-700' : 'text-[#2f5a2a]')}>{t.value}</span>
                            </button>
                        ))}
                    </div>
                ) : <p className="rounded-tile bg-glaze px-3 py-2 text-sm text-ink-soft">No sheds on this farm yet. Add a shed and choose this farm.</p>}
                {open ? <FarmDetail detail={open} sheds={sheds} pens={pens} goats={goats} penOf={penOf} onClose={() => setOpen(null)} /> : null}
                {farm.notes ? <p className="text-sm text-ink-soft">{farm.notes}</p> : null}
            </div>
            <div className="kk-map-frame relative min-h-60">
                {query ? (
                    <>
                        <iframe title={`Map of ${farm.name}`} className="absolute inset-0 size-full" loading="lazy" referrerPolicy="no-referrer-when-downgrade"
                            src={`https://maps.google.com/maps?q=${encodeURIComponent(query)}&z=${hasGps ? 16 : 13}&t=${view}&output=embed`} />
                        <div className="absolute top-2 left-2 flex overflow-hidden rounded-full border border-rule bg-tile text-xs font-semibold shadow-tile">
                            {(['k', 'm'] as const).map((v) => <button key={v} type="button" onClick={() => setView(v)} aria-pressed={view === v} className={cx('px-3 py-1.5', view === v ? 'bg-cobalt-800 text-white' : 'text-cobalt-900')}>{v === 'k' ? 'Satellite' : 'Map'}</button>)}
                        </div>
                        <a href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(query)}`} target="_blank" rel="noreferrer" className="absolute right-2 bottom-2 rounded-full bg-tile px-3 py-1.5 text-xs font-semibold text-turq-800 shadow-tile hover:underline">Open in Google Maps</a>
                    </>
                ) : (
                    <div className="grid h-full min-h-60 place-items-center p-4 text-center text-sm text-ink-soft">
                        <div><MapPin className="mx-auto mb-2 size-8 text-ink-faint" aria-hidden />Add the address or GPS to show the farm on the map.{onEdit ? <div className="mt-2"><Button type="button" variant="secondary" onClick={onEdit}>Add Location</Button></div> : null}</div>
                    </div>
                )}
            </div>
        </div>
    );
}

type Detail = 'sheds' | 'pens' | 'goats' | 'sex' | 'attention';
const HEALTH_ORDER: Record<Health, number> = { critical: 0, treatment: 1, watch: 2, healthy: 3 };

/** D-131: the list behind a farm tile: first 5, then Show More. Goats open their page. */
function FarmDetail({ detail, sheds, pens, goats, penOf, onClose }: { detail: Detail; sheds: Shed[]; pens: Pen[]; goats: Goat[]; penOf: Map<string, string>; onClose: () => void }) {
    type Row = { key: string | number; href?: string; title: string; sub: string; right: ReactNode };
    const goatRow = (g: Goat, right: ReactNode): Row => ({ key: g.id, href: `/goats/${g.id}`, title: g.number, sub: [g.name, penOf.get(g.id)].filter(Boolean).join(' · '), right });
    const rows: Row[] = detail === 'sheds'
        ? sheds.map((x) => ({ key: x.id, title: x.name, sub: `${x.typeName ?? 'No unit type'}${x.status === 'planned' ? ' · planned' : ''}`, right: `${x.pens.length} pen${x.pens.length === 1 ? '' : 's'} · ${x.pens.reduce((a, p) => a + p.count, 0)} goats` }))
        : detail === 'pens'
            ? pens.map((p) => ({ key: p.id, title: p.name, sub: p.purpose, right: `${p.count}${p.capacity ? ` of ${p.capacity}` : ''} goats` }))
            : detail === 'goats'
                ? goats.map((g) => goatRow(g, <HealthChip health={g.health} />))
                : detail === 'sex'
                    ? [...goats].sort((a, b) => a.sex.localeCompare(b.sex) || a.number.localeCompare(b.number)).map((g) => goatRow(g, <Chip className={g.sex === 'female' ? 'bg-manganese-100 text-manganese-600' : 'bg-cobalt-100 text-cobalt-800'}>{g.sex === 'female' ? '♀ Female' : '♂ Male'}</Chip>))
                    : goats.filter((g) => g.health !== 'healthy').sort((a, b) => HEALTH_ORDER[a.health] - HEALTH_ORDER[b.health]).map((g) => goatRow(g, <HealthChip health={g.health} />));
    const [visible, more] = useFive(rows);
    const title = { sheds: 'Sheds', pens: 'Pens', goats: 'Goats', sex: 'Female and Male', attention: 'Need Attention' }[detail];

    return (
        <div className="kk-rise overflow-hidden rounded-tile border border-rule bg-white">
            <div className="flex items-center justify-between border-b border-rule px-3 py-2"><span className="font-semibold text-cobalt-900">{title} · {rows.length}</span><button type="button" onClick={onClose} className="text-sm font-semibold text-turq-800 hover:underline">Close</button></div>
            <ul className="divide-y divide-rule">
                {visible.map((r) => {
                    const body = <><span className="min-w-0 flex-1"><span className="block font-semibold text-cobalt-900">{r.title}</span>{r.sub ? <span className="block truncate text-sm text-ink-soft">{r.sub}</span> : null}</span><span className="shrink-0 text-sm text-ink-soft">{r.right}</span></>;
                    return <li key={r.key}>{r.href ? <Link href={r.href} className="flex items-center gap-3 px-3 py-2 hover:bg-glaze">{body}</Link> : <div className="flex items-center gap-3 px-3 py-2">{body}</div>}</li>;
                })}
            </ul>
            {more}
        </div>
    );
}

/** D-142: the selected pen's roll call on a clipboard: each goat's ear tag, its figure and name; first 6, then Show More. */
function PenRollCall({ pen }: { pen: Pen }) {
    const [shown, more] = useFive(pen.goats, 6);
    return (
        <section className="kk-clipboard !m-0" aria-label={`${pen.name}: ${pen.count} goats`}>
            <span className="kk-clip" aria-hidden />
            <div className="kk-lined !pl-3">
                <h2 className="flex flex-wrap items-baseline gap-x-2 py-2 font-semibold text-[#3b2e1a]"><span className="kk-pen-sign">{pen.code}</span>{pen.name}<span className="text-sm font-normal text-[#6b5434]">{pen.purpose} · {pen.count} goats</span></h2>
                {pen.goats.length ? (
                    <>
                        <ul className="grid gap-2 pb-2 sm:grid-cols-2 lg:grid-cols-3">
                            {shown.map((g) => (
                                <li key={g.id}>
                                    <Link href={`/goats/${g.id}`} className="kk-lift flex items-center gap-2 rounded-md bg-[#fff8e6] p-1.5 pr-2 shadow-[0_1px_0_#d8c49a]">
                                        <EarTag number={g.number} />
                                        <span className="kk-th-goat" aria-hidden><GoatFigure female={g.sex === 'female'} flag={g.health !== 'healthy'} delay={0} flip={false} /></span>
                                        <span className="min-w-0 truncate text-sm font-semibold text-[#3b2e1a]">{g.name ?? ''}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                        {more ? <div className="-mx-3 pb-1">{more}</div> : null}
                    </>
                ) : <p className="pb-3 text-sm text-[#6b5434]">This pen is empty.</p>}
            </div>
        </section>
    );
}
