import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/react';
import { LIVE_MS } from '../lib/live';
import { LocateFixed, MapPin, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import GoatPortrait from '../components/GoatPortrait';
import ShedBoard, { type ShedBoardData } from '../components/ShedBoard';
import { Button, Chip, cx, Empty, Field, PageHeader, Panel } from '../components/ui';
import type { Health, SharedProps } from '../types';
import AddButton from '../components/AddButton';
import Sheet from '../components/Sheet';

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
            <PageHeader
                title="Sheds"
                description={`${active.length} shed${active.length === 1 ? '' : 's'} in use, ${planned.length} planned, ${goats.length} goats housed.`}
                actions={canManage ? <><AddButton primary={false} label="Add a farm" onClick={() => setEditing({ kind: 'farm' })} /><AddButton label="Add a shed" onClick={() => setEditing({ kind: 'shed' })} /></> : null}
            />

            {/* D-127: adding or editing a shed, pen or open area opens in a panel. */}
            {editing ? <Sheet onClose={() => setEditing(null)}><Editor key={JSON.stringify([editing.kind, editing.kind === 'farm' ? editing.farm?.id : editing.shed?.id, editing.kind === 'pen' ? `${editing.pen?.id}-${editing.area}` : null])} editing={editing} farms={farms} sheds={sheds} types={types} prefix={prefix} onDone={() => setEditing(null)} /></Sheet> : null}

            {/* D-129: farms and where they are, at the top; picking one shows only its sheds below. */}
            <section className="mb-4 space-y-3" aria-label="Farms & Locations">
                {farms.length > 1 ? (
                    <div className="flex flex-wrap gap-2" role="tablist">
                        {farms.map((f) => <button key={f.id} type="button" role="tab" aria-selected={farmId === f.id} onClick={() => setFarmId(f.id)} className={cx('kk-pill min-h-10 rounded-full border px-4 text-sm font-semibold', farmId === f.id ? 'border-cobalt-800 bg-cobalt-800 text-white' : 'border-rule bg-tile text-cobalt-900 hover:border-turq-600')}>{f.name} <span className="font-mono opacity-75">{f.code}</span></button>)}
                        <button type="button" role="tab" aria-selected={farmId === null} onClick={() => setFarmId(null)} className={cx('kk-pill min-h-10 rounded-full border px-4 text-sm font-semibold', farmId === null ? 'border-cobalt-800 bg-cobalt-800 text-white' : 'border-rule bg-tile text-cobalt-900 hover:border-turq-600')}>All Farms</button>
                    </div>
                ) : null}
                {farms.filter((f) => farmId === null || f.id === farmId).map((f) => <FarmCard key={f.id} farm={f} sheds={sheds.filter((x) => x.farmId === f.id)} onEdit={canManage ? () => setEditing({ kind: 'farm', farm: f }) : undefined} />)}
            </section>

            {/* D-120: drag or tick goats into another pen; moved here from the dashboard. */}
            <div className="mb-4"><ShedBoard board={farmId === null ? board : { ...board, sheds: board.sheds.filter((x) => shownIds.has(x.id)) }} canMove /></div>

            <div>
                <div className="space-y-4">

                    {shown.length === 0 ? <Empty title={sheds.length ? 'No sheds on this farm yet' : 'No sheds yet'}>{canManage ? 'Add your first shed, then add pens inside it.' : 'The owner or manager adds sheds and pens.'}</Empty> : null}

                    {[...active, ...planned].map((shed) => (
                        <Panel
                            key={shed.id}
                            title={shed.name}
                            description={`${farms.length > 1 ? `${farms.find((f) => f.id === shed.farmId)?.name ?? 'No farm'} · ` : ''}${shed.typeName ? `${shed.typeName} · ${shed.purpose}` : 'No unit type yet: edit to choose one'}${shed.status === 'planned' ? ' · planned' : ''} · ${shed.pens.length} pen${shed.pens.length === 1 ? '' : 's'}`}
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

            </div>
        </>
    );
}

function Editor({ editing, farms, sheds, types, prefix, onDone }: { editing: NonNullable<Editing>; farms: FarmLoc[]; sheds: Shed[]; types: UnitType[]; prefix: string; onDone: () => void }) {
    if (editing.kind === 'farm') return <FarmForm farm={editing.farm} onDone={onDone} />;
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
function FarmForm({ farm, onDone }: { farm?: FarmLoc; onDone: () => void }) {
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
            </form>
        </Panel>
    );
}

/** D-129: one farm at the top of Sheds: where it is, its numbers, and a map (Google Maps, no key: a pin from the GPS, else the address). */
function FarmCard({ farm, sheds, onEdit }: { farm: FarmLoc; sheds: Shed[]; onEdit?: () => void }) {
    const [view, setView] = useState<'m' | 'k'>('k');
    const inUse = sheds.filter((s) => s.status === 'active');
    const pens = inUse.flatMap((s) => s.pens.filter((p) => p.kind === 'pen'));
    const goats = pens.flatMap((p) => p.goats);
    const females = goats.filter((g) => g.sex === 'female').length;
    const capacity = pens.reduce((a, p) => a + (p.capacity ?? 0), 0);
    const watch = goats.filter((g) => g.health !== 'healthy').length;
    const hasGps = farm.latitude !== null && farm.longitude !== null;
    const address = [farm.address, farm.city, farm.district, farm.province].filter(Boolean).join(', ');
    const query = hasGps ? `${farm.latitude},${farm.longitude}` : address ? `${address}, ${farm.country ?? ''}` : null;
    const stats: Array<[string, string | number]> = [
        ['Sheds', `${inUse.length}${sheds.length > inUse.length ? ` + ${sheds.length - inUse.length} planned` : ''}`],
        ['Pens', pens.length],
        ['Goats', capacity ? `${goats.length} of ${capacity}` : goats.length],
        ['Female / Male', `${females} / ${goats.length - females}`],
        ['Need Attention', watch],
    ];

    return (
        <div className={cx('kk-rise grid overflow-hidden rounded-tile border border-rule bg-tile shadow-tile lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]', !farm.active && 'opacity-70')}>
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
                <dl className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    {stats.map(([label, value]) => (
                        <div key={label} className="rounded-tile bg-glaze px-3 py-2">
                            <dt className="text-xs font-semibold text-ink-soft">{label}</dt>
                            <dd className={cx('text-lg font-bold tabular-nums', label === 'Need Attention' && Number(value) > 0 ? 'text-watch-700' : 'text-cobalt-900')}>{value}</dd>
                        </div>
                    ))}
                </dl>
                {farm.notes ? <p className="text-sm text-ink-soft">{farm.notes}</p> : null}
            </div>
            <div className="relative min-h-60 border-t border-rule bg-glaze lg:border-t-0 lg:border-l">
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
