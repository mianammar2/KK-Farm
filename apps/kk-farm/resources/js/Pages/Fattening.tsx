import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { BreedBadge } from '../components/GoatBadges';
import { Button, Chip, Empty, Field, LibIcon, PageHeader, Panel, pkr, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';

type Member = {
    id: number; goatId: string; number: string; breed: string | null; sex: string; status: string; joined: string; days: number; entry: number | null; current: number | null;
    gain: number | null; adg: number | null; bcs: number | null; lastWeighed: string | null; kgDay: number; feedCost: number; feedBasis: string; healthCost: number; totalCost: number; revenue: number | null; profit: number | null; entryCost: number | null; costPerKgGain: number | null;
    atTarget: boolean; exception: string | null; meatHold: string | null; cleared: boolean; clearedBy: string | null; health: string; saleId: number | null; exitWeight: number | null; exitReason: string | null; leftOn: string | null;
};
type Batch = {
    id: number; code: string; name: string | null; pen: string | null; penId: number | null; ration: string | null; rationId: number | null; started: string; daysOnFeed: number;
    targetWeight: number; targetDays: number; targetDate: string; status: string; notes: string | null; headcount: number; atTarget: number; exceptions: number; adg: number | null;
    feedCost: number; healthCost: number; feedBasis: string; costPerKgGain: number | null; sold: number; died: number; revenue: number; margin: number | null; returnPerHead: number | null; avgDaysOnFeed: number | null; members: Member[];
};
type Props = {
    batches: Batch[]; candidates: Array<{ id: string; number: string; breed: string | null; sex: string; purpose: string | null; health: string; quarantine: boolean }>;
    pens: Array<{ id: number; name: string }>; rations: Array<{ id: number; name: string }>; customers: Array<{ id: number; name: string }>;
    options: { status: Record<string, string>; memberStatus: Record<string, string> }; canManage: boolean; isVet: boolean;
    selections: Selection[]; proposable: Array<{ id: string; number: string; sex: string; purpose: string | null }>; reasons: Record<string, string>;
};
type Selection = {
    id: number; goatId: string; number: string | null; breed: string | null; sex: string | null; purpose: string | null; reason: string; notes: string | null; status: string;
    proposedBy: string; proposedAt: string | null; decidedBy: string | null; decidedAt: string | null; decisionNote: string | null; mine: boolean;
};
const SEL_TONE: Record<string, string> = { proposed: 'bg-watch-100 text-watch-700', approved: 'bg-ok-100 text-ok-700', rejected: 'bg-crit-100 text-crit-700', batched: 'bg-cobalt-100 text-cobalt-800' };

const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const money = (minor: number | null) => (minor === null ? '—' : pkr(minor / 100));
const FLOW = ['Selected', 'Finishing', 'Target Reached', 'Vet Cleared', 'Reserved', 'Sold'];
const flowStep = (m: Member) => (m.status === 'sold' ? 5 : m.status === 'reserved' ? 4 : m.status === 'ready' ? 3 : m.atTarget ? 2 : 1);

/** D-187: App 18 Fattening & Meat Production (V3 flow 18). */
export default function Fattening(p: Props) {
    const [tab, setTab] = useTabParam('batches');
    const open = p.batches.filter((b) => b.status !== 'closed');
    const [selected, setSelected] = useState<number | null>(open[0]?.id ?? null);
    const batch = p.batches.find((b) => b.id === selected) ?? open[0] ?? null;
    const sold = p.batches.flatMap((b) => b.members.filter((m) => m.status === 'sold'));
    const gainKg = p.batches.reduce((n, b) => n + b.members.reduce((x, m) => x + Math.max(0, m.gain ?? 0), 0), 0);
    const costKgGain = gainKg > 0 ? Math.round(p.batches.reduce((n, b) => n + b.feedCost + b.healthCost, 0) / gainKg) : null;
    const returnHead = sold.length ? Math.round(sold.reduce((n, m) => n + (m.profit ?? 0), 0) / sold.length) : null;
    const allMembers = p.batches.flatMap((b) => b.members);
    const daysOnFeed = allMembers.length ? Math.round(allMembers.reduce((n, m) => n + m.days, 0) / allMembers.length) : null;
    const waiting = p.selections.filter((x) => x.status === 'proposed').length;
    return (
        <>
            <Head title="Fattening & Meat" />
            <PageHeader title="Fattening & Meat Production" description="Finishing batches from selected growers and feeders to vet-cleared sale and batch profit." />
            <StatRow>
                <Stat label="Goats finishing" value={open.reduce((n, b) => n + b.headcount, 0)} />
                <Stat label="At target weight" value={open.reduce((n, b) => n + b.atTarget, 0)} />
                <Stat label="Exceptions" value={open.reduce((n, b) => n + b.exceptions, 0)} tone={open.some((b) => b.exceptions) ? 'alert' : undefined} />
                <Stat label="Batch margin (sold)" value={money(p.batches.reduce((n, b) => n + (b.margin ?? 0), 0))} />
                <Stat label="Cost per KG gained" value={money(costKgGain)} note="Feed + health ÷ KG gained" />
                <Stat label="Return per head" value={money(returnHead)} note="Profit per goat sold" tone={returnHead !== null && returnHead < 0 ? 'alert' : undefined} />
                <Stat label="Days on feed" value={daysOnFeed ?? '—'} note="Average per goat" />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[
                { id: 'batches', label: 'Finishing Batches', count: open.length || undefined },
                { id: 'selection', label: 'Meat Selection', count: waiting || undefined },
                ...(p.canManage ? [{ id: 'new', label: 'New Batch' }] : []),
                { id: 'profit', label: 'Batch Profitability' },
            ]} />
            {tab === 'selection' ? <Selections {...p} /> : null}
            {tab === 'new' && p.canManage ? <NewBatch {...p} onDone={() => setTab('batches')} /> : null}
            {tab === 'batches' ? (
                open.length ? (
                    <div className="space-y-3">
                        <div className="flex flex-wrap gap-2">{open.map((b) => <button key={b.id} type="button" onClick={() => setSelected(b.id)} className={`rounded-full px-3 py-1.5 text-sm font-semibold ring-1 ${batch?.id === b.id ? 'bg-cobalt-900 text-white ring-cobalt-900' : 'bg-white text-cobalt-900 ring-rule'}`}>{b.code} · {b.headcount}</button>)}</div>
                        {batch ? <BatchView b={batch} {...p} /> : null}
                    </div>
                ) : <Panel><Empty title="No finishing batches">{p.canManage ? 'Create one under New Batch from goats of fattening, sale or meat purpose and growing males.' : 'The owner or manager creates batches.'}</Empty></Panel>
            ) : null}
            {tab === 'profit' ? (
                <Panel flush title="Batch Profitability" description="Per goat: purchase / entry + feed actually fed (ration estimate only when no feeding was recorded) + health (treatments and lab) = total cost. Fixed at exit.">
                    {p.batches.length ? <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Batch</th><th>Status</th><th>Started</th><th>Head</th><th>Days on feed</th><th>ADG g/day</th><th>Feed cost</th><th>Health cost</th><th>Cost / KG gain</th><th>Sold</th><th>Revenue</th><th>Margin</th><th>Return / head</th></tr></thead><tbody>
                        {p.batches.map((b) => <tr key={b.id}><td className="font-mono font-semibold">{b.code}</td><td>{p.options.status[b.status]}</td><td>{b.started}</td><td>{b.headcount}</td><td>{b.avgDaysOnFeed ?? '—'}</td><td>{b.adg ?? '—'}</td><td>{money(b.feedCost)}{b.feedBasis !== 'actual' ? <span className="block text-xs text-watch-700">{b.feedBasis === 'mixed' ? 'part estimate' : 'estimate'}</span> : null}</td><td>{money(b.healthCost)}</td><td>{money(b.costPerKgGain)}</td><td>{b.sold}{b.died ? ` (${b.died} died)` : ''}</td><td>{money(b.revenue)}</td><td className={b.margin !== null && b.margin < 0 ? 'font-semibold text-crit-700' : 'font-semibold'}>{money(b.margin)}</td><td>{money(b.returnPerHead)}</td></tr>)}
                    </tbody></table></div> : <Empty title="No batches yet" />}
                </Panel>
            ) : null}
            {tab === 'profit' && sold.length ? (
                <Panel flush title="Goat Profitability" description="Every goat sold from a finishing batch">
                    <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Goat</th><th>Days</th><th>Gain KG</th><th>Entry</th><th>Feed</th><th>Health</th><th>Total cost</th><th>Sale</th><th>Profit</th></tr></thead><tbody>
                        {sold.map((m) => <tr key={m.id}><td><Link href={`/goats/${m.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{m.number}</Link></td><td>{m.days}</td><td>{m.gain ?? '—'}</td><td>{money(m.entryCost)}</td><td>{money(m.feedCost)}{m.feedBasis === 'estimate' ? <span className="text-xs text-watch-700"> est.</span> : null}</td><td>{money(m.healthCost)}</td><td>{money(m.totalCost)}</td><td>{money(m.revenue)}</td><td className={m.profit !== null && m.profit < 0 ? 'font-semibold text-crit-700' : 'font-semibold'}>{money(m.profit)}</td></tr>)}
                    </tbody></table></div>
                </Panel>
            ) : null}
        </>
    );
}

function NewBatch({ candidates, pens, rations, onDone }: Props & { onDone: () => void }) {
    const form = useForm({ name: '', pen_id: '', ration_plan_id: '', started_on: today(), target_weight_kg: '45', target_days: '90', animal_ids: [] as string[], notes: '' });
    const e = form.errors as Record<string, string>;
    const toggle = (id: string) => form.setData('animal_ids', form.data.animal_ids.includes(id) ? form.data.animal_ids.filter((x) => x !== id) : [...form.data.animal_ids, id]);
    return (
        <Panel title="Create Finishing Batch + Pen" description="Select candidates / feeders. Goats in intake quarantine or with an open health case are held for vet review instead of joining.">
            <form className="space-y-4 p-4" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, pen_id: d.pen_id || null, ration_plan_id: d.ration_plan_id || null })); form.post('/fattening/batches', { preserveScroll: true, onSuccess: onDone }); }}>
                <div className="grid gap-3 sm:grid-cols-3">
                    <Field label="Name (optional)" error={e.name}><input className="field" placeholder="e.g. Eid finishing" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} /></Field>
                    <Field label="Finishing pen" error={e.pen_id} hint="Goats are moved there"><select className="field" value={form.data.pen_id} onChange={(x) => form.setData('pen_id', x.target.value)}><option value="">Keep current pens</option>{pens.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
                    <Field label="Ration" error={e.ration_plan_id}><select className="field" value={form.data.ration_plan_id} onChange={(x) => form.setData('ration_plan_id', x.target.value)}><option value="">From Feeding</option>{rations.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</select></Field>
                    <Field label="Start" error={e.started_on}><input type="date" max={today()} className="field" value={form.data.started_on} onChange={(x) => form.setData('started_on', x.target.value)} /></Field>
                    <Field label="Target weight KG" error={e.target_weight_kg}><input type="number" className="field" value={form.data.target_weight_kg} onChange={(x) => form.setData('target_weight_kg', x.target.value)} /></Field>
                    <Field label="Target days" error={e.target_days}><input type="number" className="field" value={form.data.target_days} onChange={(x) => form.setData('target_days', x.target.value)} /></Field>
                </div>
                <div>
                    <p className="mb-2 text-sm font-semibold text-cobalt-900">Candidates · {form.data.animal_ids.length} selected {e.animal_ids ? <span className="text-crit-700">{e.animal_ids}</span> : null}</p>
                    {candidates.length ? <div className="grid max-h-80 gap-1.5 overflow-y-auto sm:grid-cols-3">{candidates.map((c) => (
                        <label key={c.id} className={`flex items-center gap-2 rounded-lg px-2.5 py-1.5 text-sm ring-1 ${form.data.animal_ids.includes(c.id) ? 'bg-cobalt-100 ring-cobalt-600' : 'bg-white ring-rule'}`}>
                            <input type="checkbox" checked={form.data.animal_ids.includes(c.id)} onChange={() => toggle(c.id)} />
                            <span className="font-mono font-semibold">{c.number}</span><BreedBadge breed={c.breed} />
                            {c.quarantine ? <Chip className="bg-watch-100 text-watch-700">Quarantine</Chip> : c.health !== 'healthy' ? <Chip className="bg-crit-100 text-crit-700">{c.health}</Chip> : null}
                        </label>
                    ))}</div> : <p className="text-sm text-ink-soft">No eligible goats. Breeding stock needs an approved Meat Selection first; otherwise set Animal Purpose to Fattening, Sale or Meat Sale.</p>}
                </div>
                <Button type="submit" disabled={form.processing || !form.data.animal_ids.length}>Create Batch</Button>
            </form>
        </Panel>
    );
}

function BatchView({ b, pens, rations, customers, options, canManage, isVet }: { b: Batch } & Props) {
    const [mode, setMode] = useState<'weigh' | 'plan' | null>(null);
    const [selling, setSelling] = useState<Member | null>(null);
    const live = b.members.filter((m) => ['finishing', 'ready', 'reserved'].includes(m.status));
    return (
        <Panel flush title={`${b.code}${b.name ? ` · ${b.name}` : ''}`} description={`${options.status[b.status]} · started ${b.started} · day ${b.daysOnFeed} of ${b.targetDays} · target ${b.targetWeight} KG by ${b.targetDate}${b.pen ? ` · ${b.pen}` : ''}${b.ration ? ` · ${b.ration}` : ''}`}
            actions={<span className="flex gap-1.5"><Button variant="secondary" onClick={() => setMode(mode === 'weigh' ? null : 'weigh')}><LibIcon name="app-growth" size={16} /> Weigh Batch</Button>{canManage || isVet ? <Button variant="secondary" onClick={() => setMode(mode === 'plan' ? null : 'plan')}>Revise Plan</Button> : null}</span>}>
            <div className="grid grid-cols-2 gap-2 border-b border-rule p-3 text-sm sm:grid-cols-5">
                {[['Head', b.headcount], ['Avg ADG', b.adg !== null ? `${b.adg} g/day` : '—'], ['At target', b.atTarget], ['Feed + health', money(b.feedCost + b.healthCost)], ['Cost / KG gain', money(b.costPerKgGain)]].map(([l, v]) => <div key={l as string} className="rounded-lg bg-glaze px-3 py-2"><div className="text-xs text-ink-soft">{l}</div><div className="font-bold text-cobalt-900">{v}</div></div>)}
            </div>
            {mode === 'weigh' ? <WeighForm b={b} live={live} onDone={() => setMode(null)} /> : null}
            {mode === 'plan' ? <PlanForm b={b} pens={pens} rations={rations} onDone={() => setMode(null)} /> : null}
            {selling ? <SellForm m={selling} customers={customers} onDone={() => setSelling(null)} /> : null}
            <div className="overflow-x-auto">
                <table className="data-table">
                    <thead><tr><th>Goat</th><th>Flow</th><th>Entry → Now</th><th>ADG</th><th>BCS</th><th>Feed cost</th><th>Health</th><th>Total cost</th><th>Exception</th><th /></tr></thead>
                    <tbody>{b.members.map((m) => (
                        <tr key={m.id} className={m.exception && !['sold', 'died', 'removed'].includes(m.status) ? 'bg-crit-100/30' : ''}>
                            <td><Link href={`/goats/${m.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{m.number}</Link> <BreedBadge breed={m.breed} /></td>
                            <td><div className="flex gap-0.5">{FLOW.map((f, i) => <span key={f} title={f} className={`h-2 w-5 rounded-full ${i <= flowStep(m) ? 'bg-cobalt-900' : 'bg-rule'}`} />)}</div><span className="text-xs text-ink-soft">{FLOW[flowStep(m)]}</span></td>
                            <td>{m.entry ?? '—'} → <strong>{m.current ?? '—'}</strong> KG{m.gain !== null ? <span className={m.gain < 0 ? 'text-crit-700' : 'text-ok-700'}> ({m.gain > 0 ? '+' : ''}{m.gain})</span> : null}{m.lastWeighed ? <div className="text-xs text-ink-soft">{m.lastWeighed}</div> : null}</td>
                            <td>{m.adg !== null ? `${m.adg} g` : '—'}</td><td>{m.bcs ?? '—'}</td><td>{money(m.feedCost)}{m.feedBasis === 'estimate' ? <span className="block text-xs text-watch-700">estimate</span> : null}</td><td>{money(m.healthCost)}</td>
                            <td>{money(m.totalCost)}{m.profit !== null ? <span className={`block text-xs font-semibold ${m.profit < 0 ? 'text-crit-700' : 'text-ok-700'}`}>Profit {money(m.profit)}</span> : null}</td>
                            <td>{m.meatHold ? <Chip className="bg-crit-100 text-crit-700">Meat hold to {m.meatHold}</Chip> : m.exception ? <Chip className="bg-watch-100 text-watch-700">{m.exception}</Chip> : null}</td>
                            <td className="whitespace-nowrap">
                                {m.status === 'finishing' && m.atTarget && isVet ? <Button onClick={() => router.post(`/fattening/members/${m.id}/clear`, {}, { preserveScroll: true })}>Vet Clearance</Button> : null}
                                {m.status === 'finishing' && m.atTarget && !isVet ? <span className="text-xs text-ink-soft">Awaiting vet clearance</span> : null}
                                {m.status === 'ready' && canManage ? <Button variant="secondary" onClick={() => router.post(`/fattening/members/${m.id}/reserve`, {}, { preserveScroll: true })}>Reserve</Button> : null}
                                {['ready', 'reserved'].includes(m.status) && canManage ? <Button className="ml-1" onClick={() => setSelling(m)}>Scan & Dispatch</Button> : null}
                                {['sold', 'died', 'removed'].includes(m.status) ? <span className="text-xs text-ink-soft">{options.memberStatus[m.status]} {m.leftOn}</span> : null}
                            </td>
                        </tr>
                    ))}</tbody>
                </table>
            </div>
        </Panel>
    );
}

function WeighForm({ b, live, onDone }: { b: Batch; live: Member[]; onDone: () => void }) {
    const form = useForm({ measured_on: today(), weights: live.map((m) => ({ member_id: m.id, kg: '', bcs: '' })) });
    const e = form.errors as Record<string, string>;
    return (
        <form className="border-b border-rule bg-glaze p-3" onSubmit={(ev) => { ev.preventDefault(); form.post(`/fattening/batches/${b.id}/weigh`, { preserveScroll: true, onSuccess: onDone }); }}>
            <div className="mb-2 flex items-center gap-2 text-sm"><strong>Weigh, track ADG and BCS</strong><input type="date" max={today()} className="field !w-40 !py-1" value={form.data.measured_on} onChange={(x) => form.setData('measured_on', x.target.value)} />{e.weights ? <span className="text-crit-700">{e.weights}</span> : null}</div>
            <div className="grid gap-1.5 sm:grid-cols-3">{form.data.weights.map((w, i) => (
                <div key={w.member_id} className="flex items-center gap-2 text-sm"><span className="w-28 font-mono">{live[i].number}</span>
                    <input className="field !w-24 !py-1" type="number" step="0.1" placeholder="KG" value={w.kg} onChange={(x) => form.setData('weights', form.data.weights.map((y, j) => (j === i ? { ...y, kg: x.target.value } : y)))} />
                    <select className="field !w-20 !py-1" value={w.bcs} onChange={(x) => form.setData('weights', form.data.weights.map((y, j) => (j === i ? { ...y, bcs: x.target.value } : y)))}><option value="">BCS</option>{[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}</select>
                </div>
            ))}</div>
            <div className="mt-2 flex gap-2"><Button type="submit" disabled={form.processing}>Save Weights</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}

function PlanForm({ b, pens, rations, onDone }: { b: Batch; pens: Props['pens']; rations: Props['rations']; onDone: () => void }) {
    const form = useForm({ target_weight_kg: String(b.targetWeight), target_days: String(b.targetDays), ration_plan_id: b.rationId ? String(b.rationId) : '', pen_id: b.penId ? String(b.penId) : '', notes: b.notes ?? '', status: b.status === 'sale_hold' ? 'sale_hold' : 'active' });
    return (
        <form className="grid gap-3 border-b border-rule bg-glaze p-3 sm:grid-cols-6" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, ration_plan_id: d.ration_plan_id || null, pen_id: d.pen_id || null })); form.post(`/fattening/batches/${b.id}/plan`, { preserveScroll: true, onSuccess: onDone }); }}>
            <Field label="Target KG"><input type="number" className="field" value={form.data.target_weight_kg} onChange={(x) => form.setData('target_weight_kg', x.target.value)} /></Field>
            <Field label="Target days"><input type="number" className="field" value={form.data.target_days} onChange={(x) => form.setData('target_days', x.target.value)} /></Field>
            <Field label="Ration"><select className="field" value={form.data.ration_plan_id} onChange={(x) => form.setData('ration_plan_id', x.target.value)}><option value="">From Feeding</option>{rations.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}</select></Field>
            <Field label="Pen"><select className="field" value={form.data.pen_id} onChange={(x) => form.setData('pen_id', x.target.value)}><option value="">—</option>{pens.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
            <Field label="Status"><select className="field" value={form.data.status} onChange={(x) => form.setData('status', x.target.value)}><option value="active">Active</option><option value="sale_hold">Sale Hold</option></select></Field>
            <Field label="Feeding & health plan notes"><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
            <div className="flex gap-2 sm:col-span-6"><Button type="submit" disabled={form.processing}>Save Plan</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}

function SellForm({ m, customers, onDone }: { m: Member; customers: Props['customers']; onDone: () => void }) {
    const form = useForm({ scanned: '', price: '', exit_weight_kg: m.current ? String(m.current) : '', customer_id: '', buyer: '', sold_on: today() });
    const e = form.errors as Record<string, string>;
    return (
        <form className="grid gap-3 border-b border-rule bg-turq-100/50 p-3 sm:grid-cols-6" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, customer_id: d.customer_id || null, exit_weight_kg: d.exit_weight_kg || null })); form.post(`/fattening/members/${m.id}/sell`, { preserveScroll: true, onSuccess: onDone }); }}>
            <div className="sm:col-span-6 text-sm font-bold text-cobalt-900">Scan on dispatch: {m.number}</div>
            <Field label="Scan tag / RFID" error={e.scanned} hint="Must be this goat"><input autoFocus className="field font-mono" value={form.data.scanned} onChange={(x) => form.setData('scanned', x.target.value)} /></Field>
            <Field label="Price PKR" error={e.price}><input type="number" className="field" value={form.data.price} onChange={(x) => form.setData('price', x.target.value)} /></Field>
            <Field label="Exit weight KG" error={e.exit_weight_kg}><input type="number" step="0.1" className="field" value={form.data.exit_weight_kg} onChange={(x) => form.setData('exit_weight_kg', x.target.value)} /></Field>
            <Field label="Customer" error={e.customer_id}><select className="field" value={form.data.customer_id} onChange={(x) => form.setData('customer_id', x.target.value)}><option value="">—</option>{customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
            <Field label="Or buyer name" error={e.buyer}><input className="field" value={form.data.buyer} onChange={(x) => form.setData('buyer', x.target.value)} /></Field>
            <Field label="Date" error={e.sold_on}><input type="date" max={today()} className="field" value={form.data.sold_on} onChange={(x) => form.setData('sold_on', x.target.value)} /></Field>
            {e.type ? <p className="sm:col-span-6 text-sm text-crit-700">{e.type}</p> : null}
            <div className="flex gap-2 sm:col-span-6"><Button type="submit" disabled={form.processing}>Dispatch & Post Margin</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}

/** D-197 (journey H): propose a breeder cull, feeder or slow grower for meat; the owner or manager (not the proposer) decides. */
function Selections({ selections, proposable, reasons, canManage }: Props) {
    const form = useForm({ animal_id: '', reason: 'cull_breeder', notes: '' });
    const e = form.errors as Record<string, string>;
    const [rejecting, setRejecting] = useState<number | null>(null);
    const [note, setNote] = useState('');
    const err = (usePage().props.errors ?? {}) as Record<string, string>;
    const decide = (id: number, decision: string, n?: string) => router.post(`/fattening/selections/${id}/decide`, { decision, note: n ?? null }, { preserveScroll: true, onSuccess: () => { setRejecting(null); setNote(''); } });
    return (
        <div className="space-y-3">
            <Panel title="Propose for Meat" description="A pregnant doe cannot be proposed. Approval makes the goat a finishing candidate.">
                <form className="grid gap-3 p-4 sm:grid-cols-4" onSubmit={(ev) => { ev.preventDefault(); form.post('/fattening/selections', { preserveScroll: true, onSuccess: () => form.reset() }); }}>
                    <Field label="Goat" error={e.animal_id}><select className="field" value={form.data.animal_id} onChange={(x) => form.setData('animal_id', x.target.value)} required><option value="">Choose…</option>{proposable.map((a) => <option key={a.id} value={a.id}>{a.number} · {a.sex}{a.purpose ? ` · ${a.purpose}` : ''}</option>)}</select></Field>
                    <Field label="Reason" error={e.reason}><select className="field" value={form.data.reason} onChange={(x) => form.setData('reason', x.target.value)}>{Object.entries(reasons).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Field label="Notes" error={e.notes}><input className="field" placeholder="e.g. 3 failed matings" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                    <div className="flex items-end"><Button type="submit" disabled={form.processing}>Propose</Button></div>
                </form>
            </Panel>
            <Panel flush title="Meat Selections" description={err.decision ?? err.note ?? undefined}>
                {selections.length ? <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Goat</th><th>Reason</th><th>Proposed</th><th>Status</th><th>Decision</th><th /></tr></thead><tbody>
                    {selections.map((x) => (
                        <tr key={x.id}>
                            <td><Link href={`/goats/${x.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{x.number}</Link> <BreedBadge breed={x.breed} /></td>
                            <td>{reasons[x.reason]}{x.notes ? <span className="block text-xs text-ink-soft">{x.notes}</span> : null}</td>
                            <td>{x.proposedBy}<span className="block text-xs text-ink-soft">{x.proposedAt}</span></td>
                            <td><Chip className={SEL_TONE[x.status]}>{x.status}</Chip></td>
                            <td>{x.decidedBy ? <>{x.decidedBy} · {x.decidedAt}{x.decisionNote ? <span className="block text-xs text-ink-soft">{x.decisionNote}</span> : null}</> : '—'}</td>
                            <td className="whitespace-nowrap">
                                {x.status === 'proposed' && canManage ? (rejecting === x.id ? (
                                    <span className="flex gap-1"><input className="field !w-44 !py-1" placeholder="Why rejected" value={note} onChange={(v) => setNote(v.target.value)} /><Button variant="secondary" disabled={!note.trim()} onClick={() => decide(x.id, 'rejected', note)}>Reject</Button></span>
                                ) : (
                                    <span className="flex gap-1"><Button onClick={() => decide(x.id, 'approved')}>Approve</Button><Button variant="secondary" onClick={() => setRejecting(x.id)}>Reject</Button></span>
                                )) : null}
                                {x.status === 'proposed' && !canManage ? <span className="text-xs text-ink-soft">Waiting for owner or manager</span> : null}
                            </td>
                        </tr>
                    ))}
                </tbody></table></div> : <Empty title="No meat selections yet" />}
            </Panel>
        </div>
    );
}
