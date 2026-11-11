import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, PageHeader, Panel, pkr, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';

type Crop = {
    id: number; label: string; crop: string; field: string | null; acres: number; sown: string; expected: string | null; expectedKg: number; status: string; cost: number; harvestedKg: number;
    safeFrom: string | null; phiOpen: boolean; inputs: Array<{ id: number; kind: string; on: string; product: string | null; qty: number | null; unit: string | null; cost: number | null; safeFrom: string | null }>;
};
type Lot = {
    id: number; no: string; crop: string | null; on: string; form: string; kg: number; final: boolean; dm: number | null; dmMethod: string | null; mould: boolean | null; smellOk: boolean | null;
    nitrate: string; aflatoxin: number | null; cp: number | null; lab: string | null; status: string; harvestedBy: string; decidedBy: string | null; note: string | null; blockers: string[];
    cost: number | null; perKg: number | null; perKgDm: number | null; item: string | null; formulas: Array<{ name: string | null; pct: number }>;
};
type Props = {
    enabled: boolean; isOwner: boolean; canManage: boolean; canRelease: boolean; me: string;
    fields: Array<{ id: number; name: string; acres: number; location: string | null; soil: string | null; irrigation: string | null; active: boolean }>;
    crops: Crop[]; lots: Lot[]; aflatoxinLimit: number;
    kpi: { yieldPerAcre: number | null; dmPerAcre: number | null; costPerKgDm: number | null; costPerKg: number | null; boughtPerKg: number | null; rejectRate: number | null; released: number; quarantine: number } | null;
    options: { crops: Record<string, string>; inputs: Record<string, string>; forms: Record<string, string>; dmMethods: Record<string, string>; irrigation: Record<string, string> };
};

const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const money = (minor: number | null) => (minor === null ? '—' : pkr(minor / 100));
const nul = (v: string) => (v === '' ? null : v);

/** D-202: App 22 Fodder & Pasture and journey M Fodder-to-TMR (V3 flows 22 and 36). */
export default function Fodder(p: Props) {
    const [tab, setTab] = useTabParam('crops');
    if (!p.enabled) return <><Head title="Fodder & Pasture" /><PageHeader title="Fodder & Pasture" description="Optional app for farms that grow their own fodder." /><Setup {...p} /></>;
    const k = p.kpi!;
    return (
        <>
            <Head title="Fodder & Pasture" />
            <PageHeader title="Fodder & Pasture" description="Own-grown fodder from sowing to harvest, tested and released into feed stock at its real cost per KG of dry matter." />
            <StatRow>
                <Stat label="Lots waiting for test / release" value={k.quarantine} tone={k.quarantine ? 'alert' : undefined} />
                <Stat label="Farm cost per KG DM" value={money(k.costPerKgDm)} note={`${money(k.costPerKg)} as fed${k.boughtPerKg !== null ? ` · bought roughage ${money(k.boughtPerKg)}` : ''}`} />
                <Stat label="Yield per acre (12 months)" value={k.yieldPerAcre !== null ? `${k.yieldPerAcre.toLocaleString()} KG` : '—'} note={k.dmPerAcre !== null ? `${k.dmPerAcre.toLocaleString()} KG DM released` : undefined} />
                <Stat label="Lots released / reject rate" value={k.released} note={k.rejectRate !== null ? `${k.rejectRate}% rejected` : undefined} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'crops', label: 'Crops' }, { id: 'lots', label: 'Harvest Lots', count: k.quarantine || undefined }, { id: 'fields', label: 'Fields' }, { id: 'report', label: 'Yield & Cost' }, ...(p.isOwner ? [{ id: 'setup', label: 'Settings' }] : [])]} />
            {tab === 'crops' ? <Crops {...p} /> : null}
            {tab === 'lots' ? <Lots {...p} /> : null}
            {tab === 'fields' ? <Fields {...p} /> : null}
            {tab === 'report' ? (
                <Panel flush title="Released Lots" description="Cost = the lot's share of its crop's inputs (by weight against the expected yield; the final cut takes the rest)">
                    {p.lots.some((l) => l.status === 'released') ? <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Lot</th><th>Crop</th><th>KG</th><th>DM %</th><th>Cost</th><th>Per KG</th><th>Per KG DM</th><th>Feed item</th><th>Used in TMR</th></tr></thead><tbody>
                        {p.lots.filter((l) => l.status === 'released').map((l) => <tr key={l.id}><td className="font-mono">{l.no}</td><td>{l.crop}</td><td>{l.kg.toLocaleString()}</td><td>{l.dm}</td><td>{money(l.cost)}</td><td>{money(l.perKg)}</td><td>{money(l.perKgDm)}</td><td>{l.item}</td><td>{l.formulas.length ? l.formulas.map((f) => `${f.name} (${f.pct}%)`).join(', ') : '—'}</td></tr>)}
                    </tbody></table></div> : <Empty title="No released lots yet" />}
                </Panel>
            ) : null}
            {tab === 'setup' && p.isOwner ? <Setup {...p} /> : null}
        </>
    );
}

function Setup({ enabled, isOwner, aflatoxinLimit }: Props) {
    const form = useForm({ enabled: true as boolean, aflatoxin_limit_ppb: String(aflatoxinLimit) });
    if (!isOwner) return <Panel><Empty title="Fodder & Pasture is off">The owner can turn it on.</Empty></Panel>;
    return (
        <Panel title={enabled ? 'Fodder Settings' : 'Turn on Fodder & Pasture'} description="For farms that grow berseem, maize, sorghum, oats or grass for their goats.">
            <form className="flex flex-wrap items-end gap-3 p-4" onSubmit={(ev) => { ev.preventDefault(); form.post('/fodder/toggle', { preserveScroll: true }); }}>
                <label className="flex items-center gap-2 pb-2 text-sm font-semibold"><input type="checkbox" checked={form.data.enabled} onChange={(x) => form.setData('enabled', x.target.checked)} /> App turned on</label>
                <Field label="Aflatoxin limit (ppb)" hint="Silage and haylage above this are not released" error={form.errors.aflatoxin_limit_ppb}><input type="number" className="field w-32" value={form.data.aflatoxin_limit_ppb} onChange={(x) => form.setData('aflatoxin_limit_ppb', x.target.value)} /></Field>
                <Button type="submit" disabled={form.processing}>Save</Button>
            </form>
        </Panel>
    );
}

function Fields({ fields, options, canManage }: Props) {
    const form = useForm({ name: '', area_acres: '', location: '', soil: '', irrigation: 'tubewell', active: true });
    const e = form.errors as Record<string, string>;
    return (
        <div className="space-y-3">
            {canManage ? (
                <Panel title="Add Field">
                    <form className="grid gap-3 p-4 sm:grid-cols-6" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, location: nul(d.location), soil: nul(d.soil) })); form.post('/fodder/fields', { preserveScroll: true, onSuccess: () => form.reset() }); }}>
                        <Field label="Name" error={e.name}><input className="field" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} /></Field>
                        <Field label="Acres" error={e.area_acres}><input type="number" step="0.01" className="field" value={form.data.area_acres} onChange={(x) => form.setData('area_acres', x.target.value)} /></Field>
                        <Field label="Location"><input className="field" value={form.data.location} onChange={(x) => form.setData('location', x.target.value)} /></Field>
                        <Field label="Soil"><input className="field" value={form.data.soil} onChange={(x) => form.setData('soil', x.target.value)} /></Field>
                        <Field label="Irrigation"><select className="field" value={form.data.irrigation} onChange={(x) => form.setData('irrigation', x.target.value)}>{Object.entries(options.irrigation).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                        <div className="flex items-end"><Button type="submit" disabled={form.processing}>Add</Button></div>
                    </form>
                </Panel>
            ) : null}
            <Panel flush title="Field Registry">
                {fields.length ? <ul className="divide-y divide-rule">{fields.map((f) => <li key={f.id} className="flex gap-2 px-4 py-2 text-sm"><strong>{f.name}</strong><span className="text-ink-soft">{f.acres} acres{f.location ? ` · ${f.location}` : ''}{f.soil ? ` · ${f.soil}` : ''}{f.irrigation ? ` · ${options.irrigation[f.irrigation]}` : ''}</span></li>)}</ul> : <Empty title="No fields yet" />}
            </Panel>
        </div>
    );
}

function Crops({ crops, fields, options }: Props) {
    const [adding, setAdding] = useState(false);
    const form = useForm({ field_id: String(fields[0]?.id ?? ''), crop: 'berseem', variety: '', area_acres: '', sown_on: today(), expected_harvest_on: '', expected_yield_kg: '', notes: '' });
    const e = form.errors as Record<string, string>;
    return (
        <div className="space-y-3">
            {adding ? (
                <Panel title="Sow a Crop">
                    <form className="grid gap-3 p-4 sm:grid-cols-4" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, variety: nul(d.variety), expected_harvest_on: nul(d.expected_harvest_on), notes: nul(d.notes) })); form.post('/fodder/crops', { preserveScroll: true, onSuccess: () => setAdding(false) }); }}>
                        <Field label="Field" error={e.field_id}><select className="field" value={form.data.field_id} onChange={(x) => form.setData('field_id', x.target.value)}>{fields.map((f) => <option key={f.id} value={f.id}>{f.name} ({f.acres} ac)</option>)}</select></Field>
                        <Field label="Crop"><select className="field" value={form.data.crop} onChange={(x) => form.setData('crop', x.target.value)}>{Object.entries(options.crops).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                        <Field label="Variety"><input className="field" value={form.data.variety} onChange={(x) => form.setData('variety', x.target.value)} /></Field>
                        <Field label="Acres" error={e.area_acres}><input type="number" step="0.01" className="field" value={form.data.area_acres} onChange={(x) => form.setData('area_acres', x.target.value)} /></Field>
                        <Field label="Sown on" error={e.sown_on}><input type="date" max={today()} className="field" value={form.data.sown_on} onChange={(x) => form.setData('sown_on', x.target.value)} /></Field>
                        <Field label="Expected harvest" error={e.expected_harvest_on}><input type="date" className="field" value={form.data.expected_harvest_on} onChange={(x) => form.setData('expected_harvest_on', x.target.value)} /></Field>
                        <Field label="Expected yield (KG fresh, all cuts)" error={e.expected_yield_kg}><input type="number" className="field" value={form.data.expected_yield_kg} onChange={(x) => form.setData('expected_yield_kg', x.target.value)} /></Field>
                        <div className="flex items-end gap-2"><Button type="submit" disabled={form.processing}>Save</Button><Button type="button" variant="secondary" onClick={() => setAdding(false)}>Cancel</Button></div>
                    </form>
                </Panel>
            ) : null}
            <Panel flush title="Crops" actions={fields.length ? <Button onClick={() => setAdding(true)}>+ Sow a Crop</Button> : undefined}>
                {crops.length ? <ul className="divide-y divide-rule">{crops.map((c) => <CropRow key={c.id} c={c} options={options} />)}</ul> : <Empty title={fields.length ? 'No crops yet' : 'Add a field first'} />}
            </Panel>
        </div>
    );
}

function CropRow({ c, options }: { c: Crop; options: Props['options'] }) {
    const [mode, setMode] = useState<'input' | 'harvest' | null>(null);
    const input = useForm({ kind: 'fertiliser', applied_on: today(), product: '', qty: '', unit: '', cost: '', phi_days: '' });
    const harvest = useForm({ harvested_on: today(), form: 'green_chop', fresh_kg: '', final_cut: false });
    const ie = input.errors as Record<string, string>;
    const he = harvest.errors as Record<string, string>;
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="text-cobalt-900">{c.label}</strong><span className="text-ink-soft">{c.acres} ac · sown {c.sown}{c.expected ? ` · harvest ${c.expected}` : ''}</span>
                <Chip className={c.status === 'growing' ? 'bg-ok-100 text-ok-700' : 'bg-glaze text-ink-soft'}>{c.status}</Chip>
                {c.phiOpen ? <Chip className="bg-crit-100 text-crit-700">Spray interval: no cutting before {c.safeFrom}</Chip> : null}
                <span className="text-xs">Inputs {money(c.cost)} · harvested {c.harvestedKg.toLocaleString()} of {c.expectedKg.toLocaleString()} KG</span>
                {c.status === 'growing' ? <span className="ml-auto flex gap-1.5"><Button variant="secondary" onClick={() => setMode(mode === 'input' ? null : 'input')}>Add Input</Button><Button onClick={() => setMode(mode === 'harvest' ? null : 'harvest')}>Harvest</Button></span> : null}
            </div>
            {c.inputs.length ? <ul className="mt-1 space-y-0.5 text-xs text-ink-soft">{c.inputs.map((i) => <li key={i.id}>{i.on} · {options.inputs[i.kind]}{i.product ? ` ${i.product}` : ''}{i.qty ? ` · ${i.qty} ${i.unit ?? ''}` : ''}{i.cost ? ` · ${money(i.cost)}` : ''}{i.safeFrom ? ` · safe from ${i.safeFrom}` : ''}</li>)}</ul> : null}
            {mode === 'input' ? (
                <form className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-7" onSubmit={(ev) => { ev.preventDefault(); input.transform((d) => ({ ...d, product: nul(d.product), qty: nul(d.qty), unit: nul(d.unit), cost: nul(d.cost), phi_days: nul(d.phi_days) })); input.post(`/fodder/crops/${c.id}/inputs`, { preserveScroll: true, onSuccess: () => setMode(null) }); }}>
                    <Field label="Kind"><select className="field" value={input.data.kind} onChange={(x) => input.setData('kind', x.target.value)}>{Object.entries(options.inputs).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Field label="Date" error={ie.applied_on}><input type="date" max={today()} className="field" value={input.data.applied_on} onChange={(x) => input.setData('applied_on', x.target.value)} /></Field>
                    <Field label="Product" error={ie.product}><input className="field" value={input.data.product} onChange={(x) => input.setData('product', x.target.value)} /></Field>
                    <Field label="Qty"><input type="number" className="field" value={input.data.qty} onChange={(x) => input.setData('qty', x.target.value)} /></Field>
                    <Field label="Unit"><input className="field" placeholder="bag, L, hr" value={input.data.unit} onChange={(x) => input.setData('unit', x.target.value)} /></Field>
                    <Field label="Cost PKR" error={ie.cost}><input type="number" className="field" value={input.data.cost} onChange={(x) => input.setData('cost', x.target.value)} /></Field>
                    {input.data.kind === 'chemical' ? <Field label="Pre-harvest days" error={ie.phi_days}><input type="number" className="field" value={input.data.phi_days} onChange={(x) => input.setData('phi_days', x.target.value)} /></Field> : null}
                    <div className="flex items-end"><Button type="submit" disabled={input.processing}>Save</Button></div>
                </form>
            ) : null}
            {mode === 'harvest' ? (
                <form className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-5" onSubmit={(ev) => { ev.preventDefault(); harvest.post(`/fodder/crops/${c.id}/harvest`, { preserveScroll: true, onSuccess: () => setMode(null) }); }}>
                    <Field label="Harvested on" error={he.harvested_on}><input type="date" max={today()} className="field" value={harvest.data.harvested_on} onChange={(x) => harvest.setData('harvested_on', x.target.value)} /></Field>
                    <Field label="Form"><select className="field" value={harvest.data.form} onChange={(x) => harvest.setData('form', x.target.value)}>{Object.entries(options.forms).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Field label="Weight KG (fresh)" error={he.fresh_kg}><input type="number" className="field" value={harvest.data.fresh_kg} onChange={(x) => harvest.setData('fresh_kg', x.target.value)} /></Field>
                    <label className="flex items-end gap-2 pb-2"><input type="checkbox" checked={harvest.data.final_cut} onChange={(x) => harvest.setData('final_cut', x.target.checked)} /> Final cut</label>
                    <div className="flex items-end"><Button type="submit" disabled={harvest.processing}>Save Lot</Button></div>
                    {he.harvested_on?.startsWith('Chemical') ? <p className="text-sm text-crit-700 sm:col-span-5">{he.harvested_on}</p> : null}
                </form>
            ) : null}
        </li>
    );
}

function Lots({ lots, options, canRelease, me, aflatoxinLimit }: Props) {
    const err = (usePage().props.errors ?? {}) as Record<string, string>;
    return (
        <Panel flush title="Harvest Lots" description={err.lot ?? `Held until dry matter is measured and quality passes (no mould, good smell, nitrate tested, aflatoxin ≤ ${aflatoxinLimit} ppb for silage and haylage). Released by someone other than the harvester.`}>
            {lots.length ? <ul className="divide-y divide-rule">{lots.map((l) => <LotRow key={l.id} l={l} options={options} canRelease={canRelease} me={me} />)}</ul> : <Empty title="No harvests yet" />}
        </Panel>
    );
}

function LotRow({ l, options, canRelease, me }: { l: Lot; options: Props['options']; canRelease: boolean; me: string }) {
    const [mode, setMode] = useState<'test' | 'reject' | null>(null);
    const test = useForm({ dm_pct: l.dm !== null ? String(l.dm) : '', dm_method: l.dmMethod ?? 'oven', mould: l.mould ?? false, smell_ok: l.smellOk ?? true, nitrate: l.nitrate, aflatoxin_ppb: l.aflatoxin !== null ? String(l.aflatoxin) : '', cp_pct: l.cp !== null ? String(l.cp) : '', test_lab: l.lab ?? '' });
    const decide = useForm({ decision: 'release', note: '' });
    const te = test.errors as Record<string, string>;
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono text-cobalt-900">{l.no}</strong><span>{l.crop}</span><span className="text-ink-soft">{l.on} · {options.forms[l.form]} · {l.kg.toLocaleString()} KG{l.final ? ' · final cut' : ''} · by {l.harvestedBy}</span>
                <Chip className={l.status === 'released' ? 'bg-ok-100 text-ok-700' : l.status === 'rejected' ? 'bg-crit-100 text-crit-700' : 'bg-watch-100 text-watch-700'}>{l.status === 'quarantine' ? 'Held' : l.status}</Chip>
                {l.dm !== null ? <span className="text-xs">DM {l.dm}% ({l.dmMethod})</span> : null}
                {l.status === 'released' ? <span className="text-xs">{money(l.perKg)}/KG · {money(l.perKgDm)}/KG DM → {l.item}</span> : null}
                {l.status === 'quarantine' ? (
                    <span className="ml-auto flex gap-1.5">
                        <Button variant="secondary" onClick={() => setMode(mode === 'test' ? null : 'test')}>Tests & DM</Button>
                        {canRelease && l.harvestedBy !== me ? <><Button disabled={l.blockers.length > 0 || decide.processing} onClick={() => decide.post(`/fodder/lots/${l.id}/decide`, { preserveScroll: true })}>Release to Feed Stock</Button><Button variant="secondary" onClick={() => setMode('reject')}>Reject</Button></> : null}
                    </span>
                ) : null}
            </div>
            {l.blockers.length ? <p className="mt-1 text-xs font-semibold text-crit-700">Not releasable: {l.blockers.join('; ')}</p> : null}
            {l.note ? <p className="mt-1 text-ink-soft">{l.decidedBy}: {l.note}</p> : null}
            {mode === 'test' ? (
                <form className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-4" onSubmit={(ev) => { ev.preventDefault(); test.transform((d) => ({ ...d, aflatoxin_ppb: nul(d.aflatoxin_ppb), cp_pct: nul(d.cp_pct), test_lab: nul(d.test_lab) })); test.post(`/fodder/lots/${l.id}/test`, { preserveScroll: true, onSuccess: () => setMode(null) }); }}>
                    <Field label="Dry matter %" error={te.dm_pct}><input type="number" step="0.1" className="field" value={test.data.dm_pct} onChange={(x) => test.setData('dm_pct', x.target.value)} /></Field>
                    <Field label="Measured by" error={te.dm_method}><select className="field" value={test.data.dm_method} onChange={(x) => test.setData('dm_method', x.target.value)}>{Object.entries(options.dmMethods).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Field label="Nitrate"><select className="field" value={test.data.nitrate} onChange={(x) => test.setData('nitrate', x.target.value)}><option value="untested">Not tested</option><option value="low">Low</option><option value="medium">Medium</option><option value="high">High (unsafe)</option></select></Field>
                    <Field label="Aflatoxin ppb"><input type="number" step="0.1" className="field" value={test.data.aflatoxin_ppb} onChange={(x) => test.setData('aflatoxin_ppb', x.target.value)} /></Field>
                    <Field label="Crude protein %"><input type="number" step="0.1" className="field" value={test.data.cp_pct} onChange={(x) => test.setData('cp_pct', x.target.value)} /></Field>
                    <Field label="Lab"><input className="field" value={test.data.test_lab} onChange={(x) => test.setData('test_lab', x.target.value)} /></Field>
                    <label className="flex items-end gap-2 pb-2"><input type="checkbox" checked={test.data.mould} onChange={(x) => test.setData('mould', x.target.checked)} /> Mould found</label>
                    <label className="flex items-end gap-2 pb-2"><input type="checkbox" checked={test.data.smell_ok} onChange={(x) => test.setData('smell_ok', x.target.checked)} /> Smell good</label>
                    <div className="sm:col-span-4"><Button type="submit" disabled={test.processing}>Save Tests</Button></div>
                </form>
            ) : null}
            {mode === 'reject' ? (
                <form className="mt-2 flex gap-2" onSubmit={(ev) => { ev.preventDefault(); decide.transform(() => ({ decision: 'reject', note: decide.data.note })); decide.post(`/fodder/lots/${l.id}/decide`, { preserveScroll: true, onSuccess: () => setMode(null) }); }}>
                    <input className="field flex-1" placeholder="Why rejected" value={decide.data.note} onChange={(x) => decide.setData('note', x.target.value)} required /><Button type="submit">Reject Lot</Button>
                </form>
            ) : null}
        </li>
    );
}
