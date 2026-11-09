import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import { LIVE_MS } from '../lib/live';
import { useState } from 'react';
import { Button, Chip, cx, Field, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import { useConfirm } from '../components/Confirm';

type Device = { id: number; code: string; name: string; type: string; typeLabel: string; vendor: string | null; model: string | null; place: string; shedId: number | null; penId: number | null; status: string; lastSeen: string | null; keyHint: string | null; settings: Record<string, number>; notes: string | null };
type Alert = { id: number; kind: string; severity: 'critical' | 'warning' | 'info'; title: string; detail: string | null; status: string; device: string | null; goat: string | null; goatId: string | null; pen: string | null; taskId: number | null; on: string; by: string | null };
type Reading = { id: number; at: string; device: string; metric: string; value: number | null; unit: string | null; rfid: string | null; goat: string | null; goatId: string | null; result: string | null };
type TypeDef = { key: string; label: string; metrics: Array<{ key: string; label: string; unit: string }>; help: string };
type Props = {
    devices: Device[]; alerts: Alert[]; readings: Reading[]; types: TypeDef[]; defaults: Record<string, number>;
    out: Array<{ goatId: string; goat: string; area: string | null; since: string; hours: number }>;
    places: Array<{ id: number; name: string; pens: Array<{ id: number; name: string; kind: string }> }>;
    endpoint: string; newKey: { device: string; key: string } | null; can: { manage: boolean };
};

const STATUS: Record<string, string> = { planned: 'bg-glaze text-ink-soft', active: 'bg-ok-100 text-ok-700', offline: 'bg-crit-100 text-crit-700', retired: 'bg-glaze text-ink-faint' };
const SEV: Record<string, string> = { critical: 'bg-crit-100 text-crit-700', warning: 'bg-watch-100 text-watch-700', info: 'bg-cobalt-100 text-cobalt-800' };
/** Which thresholds each device type uses (D-087). */
const SETTINGS: Record<string, Array<[string, string]>> = {
    weighing_scale: [['weight_drop_pct', 'Alert the vet when weight drops by (%)']],
    environment: [['ammonia_ppm_max', 'Ammonia above (ppm)'], ['temperature_c_max', 'Temperature above (°C)'], ['temperature_c_min', 'Temperature below (°C)'], ['humidity_pct_max', 'Humidity above (%)']],
};

export default function Sensors(p: Props) {
    usePoll(LIVE_MS, { only: ['devices', 'alerts', 'readings', 'out'] });
    const [tab, setTab] = useState(p.devices.length ? 'devices' : 'connect');
    const open = p.alerts.filter((a) => a.status === 'open');
    return (
        <>
            <Head title="Sensors" />
            <PageHeader title="Sensors and cameras" description="Weighing scales, RFID gates, cameras and air sensors. Each reading becomes the same record a person would make, and anything unusual becomes an alert. Alerts recommend; vets and managers decide." />
            <StatRow>
                <Stat label="Devices" value={p.devices.filter((d) => d.status !== 'retired').length} note={`${p.devices.filter((d) => d.status === 'active').length} reporting · ${p.devices.filter((d) => d.status === 'planned').length} planned`} />
                <Stat label="Offline" value={p.devices.filter((d) => d.status === 'offline').length} tone={p.devices.some((d) => d.status === 'offline') ? 'alert' : undefined} note="Not heard from recently" />
                <Stat label="Open alerts" value={open.length} tone={open.length ? 'alert' : undefined} note={`${open.filter((a) => a.severity === 'critical').length} critical`} />
                <Stat label="Goats out now" value={p.out.length} note="In open areas" />
            </StatRow>
            {p.newKey ? (
                <div className="mb-4 rounded-tile border-2 border-watch-400 bg-watch-100 p-3">
                    <p className="font-semibold text-watch-700">Key for {p.newKey.device}: copy it now, it is shown only once.</p>
                    <code className="mt-1 block break-all rounded-[4px] bg-tile px-2 py-1.5 font-mono text-sm text-cobalt-900">{p.newKey.key}</code>
                    <p className="mt-1 text-xs text-ink-soft">Put it in the device's settings as the Bearer token. Anyone with this key can send readings as this device; issue a new key if it leaks.</p>
                </div>
            ) : null}
            <Tabs value={tab} onChange={setTab} tabs={[
                { id: 'devices', label: 'Devices', count: p.devices.length }, { id: 'alerts', label: 'Alerts', count: open.length || undefined },
                { id: 'readings', label: 'Readings' }, { id: 'out', label: 'Open areas', count: p.out.length || undefined }, { id: 'connect', label: 'Connect a device' },
            ]} />
            {tab === 'devices' ? <DevicesTab {...p} /> : null}
            {tab === 'alerts' ? <AlertsTab alerts={p.alerts} /> : null}
            {tab === 'readings' ? <ReadingsTab readings={p.readings} /> : null}
            {tab === 'out' ? <OutTab out={p.out} /> : null}
            {tab === 'connect' ? <ConnectTab endpoint={p.endpoint} types={p.types} /> : null}
        </>
    );
}

function DevicesTab({ devices, types, places, defaults, can }: Props) {
    const [editing, setEditing] = useState<Device | 'new' | null>(null);
    return (
        <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <Panel flush title="Devices" actions={can.manage ? <Button type="button" onClick={() => setEditing('new')}>Add device</Button> : null}>
                {devices.length ? (
                    <ul className="divide-y divide-rule">
                        {devices.map((d) => <DeviceRow key={d.id} d={d} types={types} canManage={can.manage} onEdit={() => setEditing(d)} />)}
                    </ul>
                ) : <p className="p-4 text-sm text-ink-soft">No devices yet. Add each scale, gate, camera or sensor where it will be fitted, even before it arrives (status Planned). When it is connected it turns Active by itself.</p>}
            </Panel>
            {editing && can.manage ? <DeviceForm key={editing === 'new' ? 'new' : editing.id} device={editing === 'new' ? null : editing} types={types} places={places} defaults={defaults} onDone={() => setEditing(null)} /> : (
                <Panel title="What each device does">
                    <ul className="space-y-2 text-sm">{types.map((t) => <li key={t.key}><span className="font-semibold text-cobalt-900">{t.label}.</span> <span className="text-ink-soft">{t.help}</span></li>)}</ul>
                </Panel>
            )}
        </div>
    );
}

function DeviceRow({ d, types, canManage, onEdit }: { d: Device; types: TypeDef[]; canManage: boolean; onEdit: () => void }) {
    const metrics = types.find((t) => t.key === d.type)?.metrics ?? [];
    const form = useForm({ metric: metrics[0]?.key ?? '', value: '', rfid: '' });
    const [testing, setTesting] = useState(false);
    const [ask, confirmBox] = useConfirm(); // D-132
    return (
        <li className="px-4 py-3">
            {confirmBox}
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-mono font-semibold text-cobalt-900">{d.code}</span>
                <span className="font-medium">{d.name}</span>
                <Chip className={STATUS[d.status] ?? STATUS.planned}>{d.status}</Chip>
                <span className="ml-auto flex gap-3 text-sm">
                    {canManage ? <button type="button" className="font-semibold text-turq-800 hover:underline" onClick={() => setTesting(!testing)}>Test reading</button> : null}
                    {canManage ? <button type="button" className="font-semibold text-turq-800 hover:underline" onClick={onEdit}>Edit</button> : null}
                    {canManage ? <button type="button" className="font-semibold text-turq-800 hover:underline" onClick={async () => { if (await ask({ title: `Issue a new key for ${d.code}?`, message: 'The old key stops working at once.', confirm: 'Yes, new key', danger: false })) router.post(`/sensors/devices/${d.id}/key`, {}, { preserveScroll: true }); }}>New key</button> : null}
                </span>
            </div>
            <p className="text-sm text-ink-soft">{d.typeLabel} · {d.place}{d.vendor ? ` · ${d.vendor}${d.model ? ` ${d.model}` : ''}` : ''} · {d.lastSeen ? `last seen ${d.lastSeen} ago` : 'never connected'}{d.keyHint ? ` · key …${d.keyHint}` : ''}</p>
            {testing ? (
                <form className="mt-2 flex flex-wrap items-end gap-2 rounded-tile bg-glaze p-2" onSubmit={(e) => { e.preventDefault(); form.post(`/sensors/devices/${d.id}/test`, { preserveScroll: true, onSuccess: () => form.reset('value', 'rfid') }); }}>
                    <Field label="Reading">
                        {metrics.length ? <select className="field w-auto" value={form.data.metric} onChange={(e) => form.setData('metric', e.target.value)}>{metrics.map((m) => <option key={m.key} value={m.key}>{m.label}{m.unit ? ` (${m.unit})` : ''}</option>)}</select>
                            : <input className="field w-36" value={form.data.metric} onChange={(e) => form.setData('metric', e.target.value)} placeholder="metric_name" />}
                    </Field>
                    <Field label="Value" error={form.errors.value}><input className="field w-24" inputMode="decimal" value={form.data.value} onChange={(e) => form.setData('value', e.target.value)} /></Field>
                    {['weighing_scale', 'rfid_gate'].includes(d.type) ? <Field label="RFID tag"><input className="field w-48 font-mono" value={form.data.rfid} onChange={(e) => form.setData('rfid', e.target.value)} placeholder="E280…" /></Field> : null}
                    <Button type="submit" disabled={form.processing}>Send</Button>
                    <p className="w-full text-xs text-ink-soft">Goes through the same rules as the real device, so you can try the setup before the hardware arrives.</p>
                </form>
            ) : null}
        </li>
    );
}

function DeviceForm({ device, types, places, defaults, onDone }: { device: Device | null; types: TypeDef[]; places: Props['places']; defaults: Record<string, number>; onDone: () => void }) {
    const form = useForm({
        name: device?.name ?? '', type: device?.type ?? 'weighing_scale', code: '', vendor: device?.vendor ?? '', model: device?.model ?? '',
        shed_id: device?.shedId ? String(device.shedId) : '', pen_id: device?.penId ? String(device.penId) : '', status: device?.status === 'offline' ? 'active' : device?.status ?? 'planned',
        settings: Object.fromEntries(Object.entries(device?.settings ?? {}).map(([k, v]) => [k, String(v)])) as Record<string, string>, notes: device?.notes ?? '',
    });
    const shed = places.find((s) => String(s.id) === form.data.shed_id);
    const submit = (e: React.FormEvent) => { e.preventDefault(); form.post(device ? `/sensors/devices/${device.id}` : '/sensors/devices', { preserveScroll: true, onSuccess: onDone }); };
    return (
        <Panel title={device ? `Edit ${device.code}` : 'Add a device'} description={device ? undefined : 'A key is made for it; you will see it once.'}>
            <form className="space-y-3" onSubmit={submit}>
                <Field label="Type" error={form.errors.type}><select className="field" value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>{types.map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}</select></Field>
                <Field label="Name" error={form.errors.name}><input className="field" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. Walk-over scale, doe shed" required /></Field>
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Shed" error={form.errors.shed_id}><select className="field" value={form.data.shed_id} onChange={(e) => form.setData({ ...form.data, shed_id: e.target.value, pen_id: '' })}><option value="">Not placed</option>{places.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
                    <Field label="Pen or open area" error={form.errors.pen_id}><select className="field" value={form.data.pen_id} onChange={(e) => form.setData('pen_id', e.target.value)} disabled={!shed}><option value="">Whole shed</option>{shed?.pens.map((p) => <option key={p.id} value={p.id}>{p.name}{p.kind === 'open_area' ? ' (open area)' : ''}</option>)}</select></Field>
                </div>
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Brand"><input className="field" value={form.data.vendor} onChange={(e) => form.setData('vendor', e.target.value)} /></Field>
                    <Field label="Model"><input className="field" value={form.data.model} onChange={(e) => form.setData('model', e.target.value)} /></Field>
                </div>
                {!device ? <Field label="Code (optional)" error={form.errors.code} hint="Left empty: SCL-01, GATE-01, CAM-01…"><input className="field font-mono" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} /></Field> : null}
                {(SETTINGS[form.data.type] ?? []).map(([k, label]) => (
                    <Field key={k} label={label} hint={`Farm default ${defaults[k]}`}><input className="field" inputMode="decimal" value={form.data.settings[k] ?? ''} placeholder={String(defaults[k])} onChange={(e) => form.setData('settings', { ...form.data.settings, [k]: e.target.value })} /></Field>
                ))}
                <Field label="Status"><select className="field" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}><option value="planned">Planned (not fitted yet)</option><option value="active">Active</option><option value="retired">Retired</option></select></Field>
                <Field label="Notes"><input className="field" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field>
                <div className="flex gap-2"><Button type="submit" disabled={form.processing}>{device ? 'Save' : 'Add device'}</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}

function AlertsTab({ alerts }: { alerts: Alert[] }) {
    const act = (a: Alert, status: string) => router.post(`/sensors/alerts/${a.id}`, { status }, { preserveScroll: true });
    return (
        <Panel flush title="Sensor alerts" description="Weight loss opens a task for the vets. A person checks every alert and decides.">
            {alerts.length ? (
                <ul className="divide-y divide-rule">{alerts.map((a) => (
                    <li key={a.id} className={cx('px-4 py-3', a.status === 'resolved' && 'opacity-60')}>
                        <div className="flex flex-wrap items-center gap-2">
                            <Chip className={SEV[a.severity]}>{a.severity}</Chip>
                            <span className="font-semibold text-cobalt-900">{a.title}</span>
                            {a.status !== 'open' ? <Chip className="bg-glaze text-ink-soft">{a.status}{a.by ? ` · ${a.by}` : ''}</Chip> : null}
                            <span className="ml-auto flex gap-3 text-sm">
                                {a.status === 'open' ? <button type="button" className="font-semibold text-turq-800 hover:underline" onClick={() => act(a, 'acknowledged')}>Acknowledge</button> : null}
                                {a.status !== 'resolved' ? <button type="button" className="font-semibold text-turq-800 hover:underline" onClick={() => act(a, 'resolved')}>Resolve</button> : null}
                            </span>
                        </div>
                        <p className="text-sm text-ink-soft">{a.kind} · {a.on}{a.device ? ` · ${a.device}` : ''}{a.pen ? ` · ${a.pen}` : ''}{a.goat ? <> · <Link href={`/goats/${a.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{a.goat}</Link></> : null}{a.taskId ? <> · <Link href="/tasks" className="font-semibold text-turq-800 hover:underline">vet task</Link></> : null}</p>
                        {a.detail ? <p className="text-sm">{a.detail}</p> : null}
                    </li>
                ))}</ul>
            ) : <p className="p-4 text-sm text-ink-soft">No alerts.</p>}
        </Panel>
    );
}

function ReadingsTab({ readings }: { readings: Reading[] }) {
    return (
        <Panel flush title="Latest readings" description="Raw data from every device, newest first. Kept as received; never edited.">
            <div className="overflow-x-auto"><table className="data-table min-w-[720px]">
                <thead><tr><th>Time</th><th>Device</th><th>Reading</th><th>Value</th><th>Goat / Tag</th><th>Made</th></tr></thead>
                <tbody>{readings.map((r) => (
                    <tr key={r.id}><td className="whitespace-nowrap text-sm">{r.at}</td><td>{r.device}</td><td className="font-mono text-sm">{r.metric}</td><td className="tabular-nums">{r.value ?? '—'} {r.unit ?? ''}</td>
                        <td>{r.goat ? <Link href={`/goats/${r.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{r.goat}</Link> : r.rfid ? <span className="font-mono text-xs text-ink-soft">{r.rfid}</span> : '—'}</td>
                        <td className="text-sm text-ink-soft">{r.result ?? ''}</td></tr>
                ))}</tbody>
            </table></div>
            {!readings.length ? <p className="p-4 text-sm text-ink-soft">No readings yet.</p> : null}
        </Panel>
    );
}

function OutTab({ out }: { out: Props['out'] }) {
    return (
        <Panel flush title="Goats out in open areas now" description="From the RFID gate, a camera or by hand on the dashboard's Sheds and pens. Their home pen stays the same." actions={out.length ? <Button type="button" variant="secondary" onClick={() => router.post('/presence', { animal_ids: out.map((o) => o.goatId), direction: 'in' }, { preserveScroll: true })}>Bring all back in</Button> : null}>
            {out.length ? <ul className="divide-y divide-rule">{out.map((o) => <li key={o.goatId} className="flex justify-between px-4 py-2"><Link href={`/goats/${o.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{o.goat}</Link><span className={cx('text-sm', o.hours >= 8 ? 'font-semibold text-crit-700' : 'text-ink-soft')}>{o.area ?? 'Open area'} · {o.since}</span></li>)}</ul> : <p className="p-4 text-sm text-ink-soft">All goats are inside. Add an open area to a shed on the Sheds page.</p>}
        </Panel>
    );
}

function ConnectTab({ endpoint, types }: { endpoint: string; types: TypeDef[] }) {
    const example = `curl -X POST ${endpoint} \\
  -H "Authorization: Bearer <device key>" \\
  -H "Content-Type: application/json" \\
  -d '{"readings":[{"id":"<new uuid>","at":"2026-11-09T07:30:00Z","metric":"weight_kg","value":42.5,"rfid":"E28011700000020F1A2B3C4D"}]}'`;
    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Panel title="How a device sends data">
                <ol className="list-decimal space-y-2 pl-5 text-sm">
                    <li>Add the device on the Devices tab and copy its key.</li>
                    <li>The device (or a small gateway next to it) sends readings to <code className="break-all rounded bg-glaze px-1 font-mono">{endpoint}</code> with the key as a Bearer token.</li>
                    <li>Each reading has its own id (a UUID made by the device). Sending the same reading again is safe: it is stored once. Readings can be sent late when the internet comes back.</li>
                    <li>Check the connection with <code className="rounded bg-glaze px-1 font-mono">GET /api/v1/ping</code>.</li>
                </ol>
                <pre className="mt-3 overflow-x-auto rounded-tile bg-cobalt-950 p-3 text-xs text-white">{example}</pre>
            </Panel>
            <Panel title="Readings each device type sends">
                <ul className="space-y-2 text-sm">{types.filter((t) => t.metrics.length).map((t) => (
                    <li key={t.key}><span className="font-semibold text-cobalt-900">{t.label}:</span> {t.metrics.map((m) => <code key={m.key} className="mr-1 inline-block break-all rounded bg-glaze px-1 font-mono">{m.key}{m.unit ? ` (${m.unit})` : ''}</code>)}<span className="block text-ink-soft">{t.help}</span></li>
                ))}</ul>
                <p className="mt-3 text-xs text-ink-soft">Scales and RFID gates also send the tag (<code className="font-mono">rfid</code>). A gate sends 1 when a goat goes out to the open area and 0 when it comes back in. Gates and other actuators keep a local fail-safe and a manual override; the cloud is never the only control path.</p>
            </Panel>
        </div>
    );
}
