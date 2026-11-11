import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, PageHeader, Panel, pkr, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';

type Goat = { id: number; number: string | null; status: string; fit: boolean | null; fitBy: string | null; note: string | null };
type Trip = {
    id: number; no: string; order: string | null; orderStatus: string | null; customer: string | null; vehicle: string | null; capacity: number | null; vehicleOk: boolean; driver: string; destination: string; km: number | null;
    status: string; gatePass: string | null; departed: string | null; loaded: number | null; delivered: string | null; received: number | null; receivedBy: string | null; receipt: string | null; goats: Goat[];
    checks: Array<{ at: string; where: string | null; down: number; injured: number; dead: number; watered: boolean; temp: number | null; notes: string | null; by: string }>;
    claims: Array<{ id: number; kind: string; head: number; details: string; claimed: number | null; status: string; credit: number | null; by: string | null; note: string | null }>;
};
type Vehicle = { id: number; reg: string; kind: string; capacity: number; ownership: string; driver: string | null; phone: string | null; inspected: string | null; inspection: string[]; approved: boolean; usable: boolean; active: boolean };
type Props = {
    trips: Trip[]; vehicles: Vehicle[]; orders: Array<{ id: number; no: string; customer: string | null; head: number }>;
    kpi: { lossPct: number | null; dead: number; mismatches: number; trips: number; openClaims: number; inTransit: number };
    options: { kinds: Record<string, string>; points: Record<string, string>; claims: Record<string, string> }; canPlan: boolean; canDecide: boolean; canFit: boolean; canCrew: boolean;
};

const STATUS: Record<string, string> = { planned: 'Planned: check fitness, load', in_transit: 'On the road', delivered: 'Delivered' };
const nul = (v: string) => (v === '' ? null : v);

/** D-203: journey N Transport-to-Delivery (V3 flow 37). */
export default function Transport(p: Props) {
    const [tab, setTab] = useTabParam('trips');
    const err = (usePage().props.errors ?? {}) as Record<string, string>;
    return (
        <>
            <Head title="Transport & Delivery" />
            <PageHeader title="Transport & Delivery" description="Approved vehicles, fitness to travel before loading, gate pass, welfare checks on the road, the buyer's receipt and claims." />
            <StatRow>
                <Stat label="On the road" value={p.kpi.inTransit} />
                <Stat label="Loss in transit (90 days)" value={p.kpi.lossPct !== null ? `${p.kpi.lossPct}%` : '—'} note={`${p.kpi.dead} died · ${p.kpi.trips} trips`} tone={p.kpi.dead ? 'alert' : undefined} />
                <Stat label="Dispatch mismatches" value={p.kpi.mismatches} note="Received ≠ loaded" tone={p.kpi.mismatches ? 'alert' : undefined} />
                <Stat label="Open claims" value={p.kpi.openClaims} tone={p.kpi.openClaims ? 'alert' : undefined} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'trips', label: 'Trips', count: p.trips.filter((t) => t.status !== 'delivered').length || undefined }, ...(p.canPlan ? [{ id: 'plan', label: 'Plan a Trip', count: p.orders.length || undefined }] : []), { id: 'vehicles', label: 'Vehicles' }]} />
            {Object.values(err)[0] ? <p className="mb-2 rounded-tile bg-crit-100 px-3 py-2 text-sm text-crit-700">{Object.values(err)[0]}</p> : null}
            {tab === 'trips' ? <Panel flush title="Trips">{p.trips.length ? <ul className="divide-y divide-rule">{p.trips.map((t) => <TripRow key={t.id} t={t} {...p} />)}</ul> : <Empty title="No trips yet">Plan one for an approved sale order.</Empty>}</Panel> : null}
            {tab === 'plan' && p.canPlan ? <PlanTrip {...p} onDone={() => setTab('trips')} /> : null}
            {tab === 'vehicles' ? <Vehicles {...p} /> : null}
        </>
    );
}

function PlanTrip({ orders, vehicles, onDone }: Props & { onDone: () => void }) {
    const usable = vehicles.filter((v) => v.usable);
    const form = useForm({ sale_order_id: String(orders[0]?.id ?? ''), vehicle_id: String(usable[0]?.id ?? ''), driver: '', destination: '', distance_km: '' });
    const e = form.errors as Record<string, string>;
    if (!orders.length) return <Panel><Empty title="No approved orders waiting for transport" /></Panel>;
    return (
        <Panel title="Plan a Trip" description="Only inspected, approved vehicles with room for the whole order.">
            <form className="grid gap-3 p-4 sm:grid-cols-5" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, driver: nul(d.driver), distance_km: nul(d.distance_km) })); form.post('/transport/trips', { preserveScroll: true, onSuccess: onDone }); }}>
                <Field label="Sale order" error={e.sale_order_id}><select className="field" value={form.data.sale_order_id} onChange={(x) => form.setData('sale_order_id', x.target.value)}>{orders.map((o) => <option key={o.id} value={o.id}>{o.no} · {o.customer} · {o.head} goats</option>)}</select></Field>
                <Field label="Vehicle" error={e.vehicle_id}><select className="field" value={form.data.vehicle_id} onChange={(x) => form.setData('vehicle_id', x.target.value)}><option value="">Choose…</option>{usable.map((v) => <option key={v.id} value={v.id}>{v.reg} · {v.capacity} head</option>)}</select></Field>
                <Field label="Driver" error={e.driver}><input className="field" value={form.data.driver} onChange={(x) => form.setData('driver', x.target.value)} placeholder="Vehicle's driver if empty" /></Field>
                <Field label="Destination" error={e.destination}><input className="field" value={form.data.destination} onChange={(x) => form.setData('destination', x.target.value)} /></Field>
                <Field label="Distance KM"><input type="number" className="field" value={form.data.distance_km} onChange={(x) => form.setData('distance_km', x.target.value)} /></Field>
                <div className="sm:col-span-5"><Button type="submit" disabled={form.processing}>Plan Trip</Button></div>
            </form>
        </Panel>
    );
}

function TripRow({ t, canFit, canCrew, canDecide, options }: { t: Trip } & Props) {
    const [mode, setMode] = useState<'check' | 'deliver' | null>(null);
    const check = useForm({ location: '', down: '0', injured: '0', dead: '0', watered: false, temp_c: '', notes: '' });
    const deliver = useForm({ head_received: String((t.loaded ?? 0) - t.checks.reduce((n, c) => n + c.dead, 0)), injured: '0', received_by: '', notes: '', claimed: '' });
    const de = deliver.errors as Record<string, string>;
    const fit = (id: number, ok: boolean) => { const note = ok ? null : window.prompt('Why is this goat not fit to travel?'); if (!ok && !note) return; router.post(`/transport/lines/${id}/fitness`, { fit: ok, note }, { preserveScroll: true }); };
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono text-cobalt-900">{t.no}</strong><span>{t.order} · {t.customer}</span><span className="text-ink-soft">{t.vehicle} ({t.capacity} head) · {t.driver} → {t.destination}{t.km ? ` · ${t.km} km` : ''}</span>
                <Chip className={t.status === 'delivered' ? 'bg-ok-100 text-ok-700' : t.status === 'in_transit' ? 'bg-cobalt-100 text-cobalt-800' : 'bg-watch-100 text-watch-700'}>{STATUS[t.status]}</Chip>
                {!t.vehicleOk && t.status === 'planned' ? <Chip className="bg-crit-100 text-crit-700">Vehicle not approved</Chip> : null}
                {t.gatePass ? <span className="text-xs">Gate pass <strong>{t.gatePass}</strong> · left {t.departed} · {t.loaded} loaded</span> : null}
                {t.delivered ? <span className="text-xs">Received {t.received} of {t.loaded} by {t.receivedBy} · {t.delivered}</span> : null}
                {t.status === 'in_transit' && canCrew ? <span className="ml-auto flex gap-1.5"><Button variant="secondary" onClick={() => setMode(mode === 'check' ? null : 'check')}>Welfare Check</Button><Button onClick={() => setMode(mode === 'deliver' ? null : 'deliver')}>Record Delivery</Button></span> : null}
            </div>
            {t.status === 'planned' ? (
                <ul className="mt-2 flex flex-wrap gap-1.5">{t.goats.map((g) => (
                    <li key={g.id} className="flex items-center gap-1 rounded-full bg-glaze px-2 py-0.5 text-xs"><span className="font-mono font-semibold">{g.number}</span>
                        {g.fit === true ? <span className="text-ok-700">fit ({g.fitBy})</span> : canFit ? <><button type="button" className="font-semibold text-ok-700" onClick={() => fit(g.id, true)}>Fit</button><button type="button" className="font-semibold text-crit-700" onClick={() => fit(g.id, false)}>Unfit</button></> : <span className="text-ink-soft">fitness not checked</span>}
                    </li>
                ))}</ul>
            ) : null}
            {t.status === 'planned' ? <p className="mt-1 text-xs text-ink-soft">Then load by scan and dispatch on the sale order: the gate pass is issued at dispatch.</p> : null}
            {t.checks.length ? <ul className="mt-1 space-y-0.5 text-xs text-ink-soft">{t.checks.map((c, i) => <li key={i}>{c.at}{c.where ? ` · ${c.where}` : ''} · {c.dead || c.down || c.injured ? <span className="font-semibold text-crit-700">{c.dead} dead, {c.down} down, {c.injured} injured</span> : 'all fine'}{c.watered ? ' · watered' : ''}{c.temp !== null ? ` · ${c.temp} °C` : ''}{c.notes ? ` · ${c.notes}` : ''} · {c.by}</li>)}</ul> : null}
            {t.claims.map((c) => <ClaimRow key={c.id} c={c} canDecide={canDecide} options={options} />)}
            {mode === 'check' ? (
                <form className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-7" onSubmit={(ev) => { ev.preventDefault(); check.transform((d) => ({ ...d, temp_c: nul(d.temp_c), location: nul(d.location), notes: nul(d.notes) })); check.post(`/transport/trips/${t.id}/check`, { preserveScroll: true, onSuccess: () => setMode(null) }); }}>
                    <Field label="Where"><input className="field" value={check.data.location} onChange={(x) => check.setData('location', x.target.value)} /></Field>
                    <Field label="Down"><input type="number" min={0} className="field" value={check.data.down} onChange={(x) => check.setData('down', x.target.value)} /></Field>
                    <Field label="Injured"><input type="number" min={0} className="field" value={check.data.injured} onChange={(x) => check.setData('injured', x.target.value)} /></Field>
                    <Field label="Dead" error={check.errors.dead}><input type="number" min={0} className="field" value={check.data.dead} onChange={(x) => check.setData('dead', x.target.value)} /></Field>
                    <Field label="°C"><input type="number" className="field" value={check.data.temp_c} onChange={(x) => check.setData('temp_c', x.target.value)} /></Field>
                    <label className="flex items-end gap-2 pb-2"><input type="checkbox" checked={check.data.watered} onChange={(x) => check.setData('watered', x.target.checked)} /> Watered</label>
                    <div className="flex items-end"><Button type="submit" disabled={check.processing}>Save</Button></div>
                </form>
            ) : null}
            {mode === 'deliver' ? (
                <form className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-6" onSubmit={(ev) => { ev.preventDefault(); deliver.transform((d) => ({ ...d, notes: nul(d.notes), claimed: nul(d.claimed) })); deliver.post(`/transport/trips/${t.id}/deliver`, { preserveScroll: true, onSuccess: () => setMode(null) }); }}>
                    <Field label="Goats received" error={de.head_received}><input type="number" min={0} className="field" value={deliver.data.head_received} onChange={(x) => deliver.setData('head_received', x.target.value)} /></Field>
                    <Field label="Injured on arrival"><input type="number" min={0} className="field" value={deliver.data.injured} onChange={(x) => deliver.setData('injured', x.target.value)} /></Field>
                    <Field label="Received by" error={de.received_by}><input className="field" value={deliver.data.received_by} onChange={(x) => deliver.setData('received_by', x.target.value)} /></Field>
                    <Field label="Customer claims PKR"><input type="number" min={0} className="field" value={deliver.data.claimed} onChange={(x) => deliver.setData('claimed', x.target.value)} /></Field>
                    <Field label="Notes"><input className="field" value={deliver.data.notes} onChange={(x) => deliver.setData('notes', x.target.value)} /></Field>
                    <div className="flex items-end"><Button type="submit" disabled={deliver.processing}>Save Receipt</Button></div>
                </form>
            ) : null}
        </li>
    );
}

function ClaimRow({ c, canDecide, options }: { c: Trip['claims'][number]; canDecide: boolean; options: Props['options'] }) {
    const form = useForm({ decision: 'accepted', credit: c.claimed ? String(c.claimed / 100) : '', note: '' });
    const [open, setOpen] = useState(false);
    return (
        <div className="mt-2 rounded-tile bg-treat-100/40 px-3 py-2 text-xs">
            <span className="font-semibold">{options.claims[c.kind]}</span> · {c.details}{c.claimed ? ` · claimed ${pkr(c.claimed / 100)}` : ''}
            <Chip className={`ml-2 ${c.status === 'open' ? 'bg-watch-100 text-watch-700' : c.status === 'accepted' ? 'bg-cobalt-100 text-cobalt-800' : 'bg-glaze text-ink-soft'}`}>{c.status}{c.credit ? ` · credit ${pkr(c.credit / 100)}` : ''}{c.by ? ` · ${c.by}` : ''}</Chip>
            {c.status === 'open' && canDecide ? <Button variant="quiet" className="ml-2" onClick={() => setOpen(!open)}>Decide</Button> : null}
            {open ? (
                <form className="mt-2 flex flex-wrap items-end gap-2" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, credit: d.decision === 'accepted' ? d.credit : null, note: nul(d.note) })); form.post(`/transport/claims/${c.id}`, { preserveScroll: true, onSuccess: () => setOpen(false) }); }}>
                    <select className="field !w-32" value={form.data.decision} onChange={(x) => form.setData('decision', x.target.value)}><option value="accepted">Accept</option><option value="rejected">Reject</option></select>
                    {form.data.decision === 'accepted' ? <input type="number" className="field !w-32" placeholder="Credit PKR" value={form.data.credit} onChange={(x) => form.setData('credit', x.target.value)} /> : <input className="field !w-56" placeholder="Why rejected" value={form.data.note} onChange={(x) => form.setData('note', x.target.value)} />}
                    <Button type="submit" disabled={form.processing}>Save</Button>
                </form>
            ) : null}
        </div>
    );
}

function Vehicles({ vehicles, options, canDecide }: Props) {
    const [edit, setEdit] = useState<Vehicle | 'new' | null>(null);
    return (
        <div className="space-y-3">
            {edit ? <VehicleForm v={edit === 'new' ? null : edit} options={options} onDone={() => setEdit(null)} /> : null}
            <Panel flush title="Vehicles" description="An inspection is good for 90 days. Every point must pass for approval." actions={canDecide ? <Button onClick={() => setEdit('new')}>+ Vehicle</Button> : undefined}>
                {vehicles.length ? <ul className="divide-y divide-rule">{vehicles.map((v) => (
                    <li key={v.id} className="flex flex-wrap items-center gap-2 px-4 py-2 text-sm"><strong className="font-mono">{v.reg}</strong><span className="text-ink-soft">{options.kinds[v.kind]} · {v.capacity} head · {v.ownership}{v.driver ? ` · ${v.driver}` : ''}</span>
                        <Chip className={v.usable ? 'bg-ok-100 text-ok-700' : 'bg-crit-100 text-crit-700'}>{v.usable ? `Approved · inspected ${v.inspected}` : v.approved ? 'Inspection expired' : 'Not approved'}</Chip>
                        {canDecide ? <Button variant="quiet" className="ml-auto" onClick={() => setEdit(v)}>Re-inspect</Button> : null}</li>
                ))}</ul> : <Empty title="No vehicles yet" />}
            </Panel>
        </div>
    );
}

function VehicleForm({ v, options, onDone }: { v: Vehicle | null; options: Props['options']; onDone: () => void }) {
    const form = useForm({ reg_no: v?.reg ?? '', kind: v?.kind ?? 'truck', capacity_head: String(v?.capacity ?? ''), ownership: v?.ownership ?? 'hired', driver: v?.driver ?? '', phone: v?.phone ?? '', inspection: [] as string[], active: v?.active ?? true });
    const e = form.errors as Record<string, string>;
    const toggle = (k: string) => form.setData('inspection', form.data.inspection.includes(k) ? form.data.inspection.filter((x) => x !== k) : [...form.data.inspection, k]);
    return (
        <Panel title={v ? `Inspect ${v.reg}` : 'New Vehicle'}>
            <form className="grid gap-3 p-4 sm:grid-cols-6" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, driver: nul(d.driver), phone: nul(d.phone) })); form.post(v ? `/transport/vehicles/${v.id}` : '/transport/vehicles', { preserveScroll: true, onSuccess: onDone }); }}>
                <Field label="Registration" error={e.reg_no}><input className="field font-mono" value={form.data.reg_no} onChange={(x) => form.setData('reg_no', x.target.value)} /></Field>
                <Field label="Type"><select className="field" value={form.data.kind} onChange={(x) => form.setData('kind', x.target.value)}>{Object.entries(options.kinds).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></Field>
                <Field label="Capacity (head)" error={e.capacity_head}><input type="number" className="field" value={form.data.capacity_head} onChange={(x) => form.setData('capacity_head', x.target.value)} /></Field>
                <Field label="Ownership"><select className="field" value={form.data.ownership} onChange={(x) => form.setData('ownership', x.target.value)}><option value="hired">Hired</option><option value="own">Own</option></select></Field>
                <Field label="Driver"><input className="field" value={form.data.driver} onChange={(x) => form.setData('driver', x.target.value)} /></Field>
                <Field label="Phone"><input className="field" value={form.data.phone} onChange={(x) => form.setData('phone', x.target.value)} /></Field>
                <div className="sm:col-span-6"><p className="mb-1 text-xs font-semibold">Inspection today</p><div className="flex flex-wrap gap-3">{Object.entries(options.points).map(([k, l]) => <label key={k} className="flex items-center gap-1 text-sm"><input type="checkbox" checked={form.data.inspection.includes(k)} onChange={() => toggle(k)} /> {l}</label>)}</div></div>
                <div className="flex gap-2 sm:col-span-6"><Button type="submit" disabled={form.processing}>Save Inspection</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}
