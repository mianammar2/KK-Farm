import DataGrid from './DataGrid';
import { router, useForm } from '@inertiajs/react';
import DeleteButton from './DeleteButton';
import { Blend, FlaskConical, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Sheet from './Sheet';
import { Button, Chip, Empty, Field, Note, Panel } from './ui';
import { useFive } from '../lib/useFive';

type Line = { itemId: number; name: string; pct: number; cp: number | null; stock: number };
type Formula = { id: number; name: string; code: string; active: boolean; notes: string | null; stock: number; lines: Line[]; cp: number | null; dm: number | null; costMinor: number };
export type TmrBoard = { formulas: Formula[]; batches: Array<{ id: number; no: string; formula: string; on: string; kg: number; cost: number; by: string }> };
export type WasteReportData = { days: number; rows: Array<{ pen: string; feed: string; offered: number; refused: number; wasted: number; eaten: number; wastePct: number; meals: number }>; total: { offered: number; refused: number; wasted: number; eaten: number; wastePct: number } };
type Item = { id: number; name: string; category: string; cp: number | null; dm: number | null; costPerKg: number; active: boolean; stock: number };

const kg = (n: number) => `${(Math.round(n * 10) / 10).toLocaleString('en-PK')} KG`;
const pkr = (n: number) => `PKR ${n.toLocaleString('en-PK', { maximumFractionDigits: 2 })}`;
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

/**
 * D-177: TMR formulas (a recipe in % of feeds, with CP, DM and cost per KG worked out from the ingredients) and mixing
 * batches (ingredients leave the store, the mixed TMR enters it at its real cost).
 */
export function TmrPanel({ tmr, items, canPlan, canMix }: { tmr: TmrBoard; items: Item[]; canPlan: boolean; canMix: boolean }) {
    const [editing, setEditing] = useState<Formula | 'new' | null>(null);
    const [mixing, setMixing] = useState<Formula | null>(null);
    const [batches, more] = useFive(tmr.batches);
    const feeds = items.filter((i) => i.active && i.category !== 'tmr');

    return (
        <div className="space-y-4">
            <Panel title="TMR Formulas" description="A recipe in % of your feeds. Its CP, DM and cost per KG come from the ingredients."
                actions={canPlan ? <Button type="button" onClick={() => setEditing('new')}><Plus size={15} className="mr-1 inline" aria-hidden />New Formula</Button> : null}>
                {tmr.formulas.length ? (
                    <div className="grid gap-3 lg:grid-cols-2">
                        {tmr.formulas.map((f) => (
                            <section key={f.id} className={`rounded-tile border border-rule bg-tile p-3 ${f.active ? '' : 'opacity-60'}`}>
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="kk-icon-tile size-9!" aria-hidden><FlaskConical size={18} /></span>
                                    <span className="min-w-0 flex-1"><span className="block font-semibold text-cobalt-900">{f.name}</span><span className="font-mono text-xs text-ink-soft">{f.code}</span></span>
                                    {!f.active ? <Chip className="bg-glaze text-ink-soft">Not in use</Chip> : null}
                                    {canPlan ? <><Button type="button" variant="quiet" onClick={() => setEditing(f)} aria-label={`Edit ${f.name}`}><Pencil size={15} aria-hidden /></Button><DeleteButton type="tmr" id={f.id} name={f.name} /></> : null}
                                </div>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    <span className="inline-flex items-center rounded-full bg-[#e8f5ec] px-2.5 py-1 text-sm font-bold tabular-nums text-[#1f7a3a]">CP {f.cp ?? '?'} %</span>
                                    <span className="inline-flex items-center rounded-full bg-[#e8f5ec] px-2.5 py-1 text-sm font-bold tabular-nums text-[#1f7a3a]">DM {f.dm ?? '?'} %</span>
                                    <span className="inline-flex items-center rounded-full bg-[#e8f5ec] px-2.5 py-1 text-sm font-bold tabular-nums text-[#1f7a3a]">{pkr(f.costMinor / 100)}/KG</span>
                                    <span className="inline-flex items-center rounded-full bg-[#e8f5ec] px-2.5 py-1 text-sm font-bold tabular-nums text-[#1f7a3a]">{kg(f.stock)} in store</span>
                                </div>
                                {f.cp === null || f.dm === null ? <p className="mt-1 text-xs text-watch-700">Some ingredients have no CP or DM in the Feed Master, so the totals are incomplete.</p> : null}
                                <table className="mt-2 w-full text-sm">
                                    <tbody className="divide-y divide-rule">
                                        {f.lines.map((l) => (
                                            <tr key={l.itemId}><td className="py-1">{l.name}</td><td className="py-1 text-right font-semibold tabular-nums">{l.pct} %</td><td className="py-1 text-right text-xs text-ink-soft">{kg(l.stock)} in store</td></tr>
                                        ))}
                                    </tbody>
                                </table>
                                {canMix && f.active ? <Button type="button" className="mt-2" onClick={() => setMixing(f)}><Blend size={15} className="mr-1 inline" aria-hidden />Mix a Batch</Button> : null}
                            </section>
                        ))}
                    </div>
                ) : <Empty title="No TMR formulas yet">{canPlan ? 'Add a formula: choose your feeds and their share. The TMR becomes a feed you can ration and keep in store.' : 'The manager or vet adds TMR formulas.'}</Empty>}
            </Panel>

            <Panel flush title="Mixing Batches" description="Each batch took its ingredients out of the store and put the TMR in.">
                {tmr.batches.length ? (
                    <>
                        <div className="overflow-x-auto">
                            <table className="data-table min-w-[36rem]">
                                <thead><tr><th>Batch</th><th>Formula</th><th>Mixed</th><th className="text-right">KG</th><th className="text-right">Cost</th><th>By</th></tr></thead>
                                <tbody>{batches.map((b) => <tr key={b.id}><td className="font-mono">{b.no}</td><td>{b.formula}</td><td>{b.on}</td><td className="text-right tabular-nums">{kg(b.kg)}</td><td className="text-right tabular-nums">{pkr(b.cost)}</td><td className="text-sm text-ink-soft">{b.by}</td></tr>)}</tbody>
                            </table>
                        </div>
                        {more}
                    </>
                ) : <p className="p-4 text-sm text-ink-soft">No batches mixed yet.</p>}
            </Panel>

            {editing ? <Sheet onClose={() => setEditing(null)}><FormulaForm formula={editing === 'new' ? null : editing} feeds={feeds} onDone={() => setEditing(null)} /></Sheet> : null}
            {mixing ? <Sheet onClose={() => setMixing(null)}><MixForm formula={mixing} onDone={() => setMixing(null)} /></Sheet> : null}
        </div>
    );
}

function FormulaForm({ formula, feeds, onDone }: { formula: Formula | null; feeds: Item[]; onDone: () => void }) {
    const form = useForm({
        name: formula?.name ?? '', code: formula?.code ?? '', notes: formula?.notes ?? '', active: formula?.active ?? true,
        lines: formula ? formula.lines.map((l) => ({ feed_item_id: String(l.itemId), pct: String(l.pct) })) : [{ feed_item_id: '', pct: '' }, { feed_item_id: '', pct: '' }],
    });
    const e = form.errors as Record<string, string>;
    const total = form.data.lines.reduce((s, l) => s + (Number(l.pct) || 0), 0);
    const preview = form.data.lines.reduce((acc, l) => {
        const it = feeds.find((f) => String(f.id) === l.feed_item_id); const share = (Number(l.pct) || 0) / 100;
        if (!it) return acc;
        return { cp: it.cp === null || acc.cp === null ? null : acc.cp + share * it.cp, cost: acc.cost + share * it.costPerKg };
    }, { cp: 0 as number | null, cost: 0 });
    const setLine = (i: number, k: 'feed_item_id' | 'pct', v: string) => form.setData('lines', form.data.lines.map((l, j) => (j === i ? { ...l, [k]: v } : l)));
    const submit = (ev: React.FormEvent) => {
        ev.preventDefault();
        form.transform((d) => ({ ...d, lines: d.lines.filter((l) => l.feed_item_id).map((l) => ({ feed_item_id: Number(l.feed_item_id), pct: Number(l.pct) })) }));
        formula ? form.post(`/feeding/tmr/${formula.id}`, { preserveScroll: true, onSuccess: onDone }) : form.post('/feeding/tmr', { preserveScroll: true, onSuccess: onDone });
    };
    return (
        <Panel title={formula ? `Edit ${formula.name}` : 'New TMR Formula'} description="Choose the feeds and their share. Shares must add up to 100 %.">
            <form onSubmit={submit} className="space-y-3">
                <div className="grid grid-cols-[minmax(0,1fr)_7rem] gap-2">
                    <Field label="Name" error={e.name}><input className="field" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} placeholder="e.g. Grower TMR" required /></Field>
                    <Field label="Code" error={e.code}><input className="field font-mono uppercase" value={form.data.code} onChange={(x) => form.setData('code', x.target.value)} placeholder="TMR-GR-01" required /></Field>
                </div>
                <div className="space-y-2">
                    {form.data.lines.map((l, i) => (
                        <div key={i} className="grid grid-cols-[minmax(0,1fr)_5.5rem_auto] items-end gap-2">
                            <Field label={i === 0 ? 'Feed' : ''} error={e[`lines.${i}.feed_item_id`]}>
                                <select className="field" value={l.feed_item_id} onChange={(x) => setLine(i, 'feed_item_id', x.target.value)}>
                                    <option value="">Choose a feed…</option>
                                    {feeds.map((f) => <option key={f.id} value={f.id} disabled={form.data.lines.some((o, j) => j !== i && o.feed_item_id === String(f.id))}>{f.name}{f.cp !== null ? ` · CP ${f.cp} %` : ''}</option>)}
                                </select>
                            </Field>
                            <Field label={i === 0 ? 'Share %' : ''} error={e[`lines.${i}.pct`]}><input className="field" type="number" inputMode="decimal" min={0} max={100} step="0.5" value={l.pct} onChange={(x) => setLine(i, 'pct', x.target.value)} /></Field>
                            <button type="button" onClick={() => form.setData('lines', form.data.lines.filter((_, j) => j !== i))} disabled={form.data.lines.length <= 2} className="mb-1 grid size-10 place-items-center rounded-full text-ink-soft hover:bg-glaze disabled:opacity-30" aria-label="Remove this feed"><Trash2 size={16} aria-hidden /></button>
                        </div>
                    ))}
                    <button type="button" onClick={() => form.setData('lines', [...form.data.lines, { feed_item_id: '', pct: '' }])} className="text-sm font-semibold text-turq-800 hover:underline">+ Add a feed</button>
                </div>
                {e.lines ? <p className="text-sm font-semibold text-crit-700" role="alert">{e.lines}</p> : null}
                <div className="flex flex-wrap gap-2">
                    <span className={`inline-flex items-center rounded-full bg-[#e8f5ec] px-2.5 py-1 text-sm font-bold tabular-nums text-[#1f7a3a] ${Math.abs(total - 100) > 0.05 ? 'bg-watch-100! text-watch-700!' : ''}`}>Total {Math.round(total * 100) / 100} %</span>
                    <span className="inline-flex items-center rounded-full bg-[#e8f5ec] px-2.5 py-1 text-sm font-bold tabular-nums text-[#1f7a3a]">CP {preview.cp === null ? '?' : Math.round(preview.cp * 10) / 10} %</span>
                    <span className="inline-flex items-center rounded-full bg-[#e8f5ec] px-2.5 py-1 text-sm font-bold tabular-nums text-[#1f7a3a]">{pkr(Math.round(preview.cost * 100) / 100)}/KG</span>
                </div>
                <Field label="Notes (optional)" error={e.notes}><textarea className="field min-h-16" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                {formula ? <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="size-5" checked={form.data.active} onChange={(x) => form.setData('active', x.target.checked)} /> In use</label> : null}
                <Note>The TMR becomes a feed (category TMR) you can put in rations and keep in store. Mixed batches are not changed when the recipe changes.</Note>
                <div className="flex gap-2"><Button type="submit" disabled={form.processing}>{formula ? 'Save Formula' : 'Add Formula'}</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}

function MixForm({ formula, onDone }: { formula: Formula; onDone: () => void }) {
    const form = useForm({ total_kg: '', mixed_on: today(), notes: '', output_kg: '' });
    const e = form.errors as Record<string, string>;
    const total = Number(form.data.total_kg) || 0;
    return (
        <Panel title={`Mix ${formula.name}`} description="The ingredients are taken out of the store and the TMR is put in.">
            <form onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, output_kg: d.output_kg || null })); form.post(`/feeding/tmr/${formula.id}/mix`, { preserveScroll: true, onSuccess: onDone }); }} className="space-y-3">
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Batch size KG" error={e.total_kg}><input className="field" type="number" inputMode="decimal" min={1} step="1" value={form.data.total_kg} onChange={(x) => form.setData('total_kg', x.target.value)} autoFocus required /></Field>
                    <Field label="Mixed on" error={e.mixed_on}><input className="field" type="date" max={today()} value={form.data.mixed_on} onChange={(x) => form.setData('mixed_on', x.target.value)} required /></Field>
                    <Field label="Weighed after mixing KG" error={e.output_kg} hint={total && Number(form.data.output_kg) ? `Shrinkage ${Math.round((1 - Number(form.data.output_kg) / total) * 1000) / 10}%` : 'Optional: captures mixing loss'}><input className="field" type="number" inputMode="decimal" min={1} step="0.1" value={form.data.output_kg} onChange={(x) => form.setData('output_kg', x.target.value)} /></Field>
                </div>
                <table className="w-full text-sm">
                    <thead className="text-left text-xs text-ink-soft"><tr><th className="py-1">Feed</th><th className="py-1 text-right">Uses</th><th className="py-1 text-right">In store</th></tr></thead>
                    <tbody className="divide-y divide-rule">
                        {formula.lines.map((l) => {
                            const need = Math.round(total * l.pct) / 100;
                            return <tr key={l.itemId} className={need > l.stock ? 'text-crit-700' : ''}><td className="py-1">{l.name} <span className="text-ink-faint">({l.pct} %)</span></td><td className="py-1 text-right font-semibold tabular-nums">{kg(need)}</td><td className="py-1 text-right tabular-nums">{kg(l.stock)}</td></tr>;
                        })}
                    </tbody>
                </table>
                <Field label="Notes (optional)" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                <div className="flex gap-2"><Button type="submit" disabled={form.processing || total <= 0 || formula.lines.some((l) => Math.round(total * l.pct) / 100 > l.stock)}>Mix {total ? kg(total) : 'Batch'}</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}

/** D-177: feed offered, refused (left in the trough) and wasted (spilled, spoiled) per pen and feed. */
export function WasteReport({ data }: { data: WasteReportData }) {
    const t = data.total;
    const tone = (p: number) => (p >= 15 ? 'text-crit-700' : p >= 8 ? 'text-watch-700' : 'text-ok-700');
    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
                {[7, 30].map((d) => <button key={d} type="button" onClick={() => router.get('/feeding', { tab: 'waste', days: d }, { preserveScroll: true })} className={`kk-tab-sign ${data.days === d ? 'kk-tab-sign-on' : ''}`}>Last {d} Days</button>)}
            </div>
            <div className="kk-store">
                {([['Offered', t.offered], ['Refused', t.refused], ['Wasted', t.wasted], ['Eaten', t.eaten]] as const).map(([label, v]) => (
                    <div key={label} className="kk-sack"><span className="kk-sack-tag">{label}</span><span className="kk-lcd self-start text-lg">{kg(v)}</span></div>
                ))}
            </div>
            <Panel flush title="By Pen and Feed" description={`Refused + wasted as a share of what was given · ${t.wastePct} % overall`}>
                {data.rows.length ? (
                    <DataGrid id="feed-waste" name="Feed offered refused wasted" rows={data.rows} rowKey={(r) => `${r.pen}|${r.feed}`} cols={[
                        { key: 'pen', label: 'Pen', type: 'enum', render: (r) => <span className="font-mono">{r.pen}</span> },
                        { key: 'feed', label: 'Feed', type: 'enum' },
                        { key: 'offered', label: 'Offered KG', type: 'number', total: 'sum' },
                        { key: 'refused', label: 'Refused KG', type: 'number', total: 'sum' },
                        { key: 'wasted', label: 'Wasted KG', type: 'number', total: 'sum' },
                        { key: 'eaten', label: 'Eaten KG', type: 'number', total: 'sum' },
                        { key: 'wastePct', label: 'Lost %', type: 'number', total: 'avg', render: (r) => <span className={`font-semibold ${tone(r.wastePct)}`}>{r.wastePct} %</span> },
                    ]} />
                ) : <p className="p-4 text-sm text-ink-soft">No feeding recorded in these days. Record feed given and leftover on Feed Now.</p>}
            </Panel>
        </div>
    );
}
