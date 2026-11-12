import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, Note, PageHeader, Panel, Stat, StatRow } from '../../components/ui';

type Row = { id: string; number: string; name: string | null; sex: string; breed: string | null; code: string | null; roles: string[]; sale: string; saleLabel: string; blockers: string[]; fattening: string | null; health: string };
type Props = { rows: Row[]; weights: { male: number; female: number }; history: Array<{ id: number; goatId: string; number: string | null; decision: string; on: string; by: string; reason: string | null }>; canClear: boolean };

const TABS = [['market_weight', 'Market weight: clearance pending'], ['sale_ready', 'Sale ready'], ['blocked', 'Blocked'], ['reserved', 'Reserved'], ['growing', 'Growing to target']] as const;
const TONE: Record<string, string> = { market_weight: 'bg-watch-100 text-watch-700', sale_ready: 'bg-ok-100 text-ok-700', blocked: 'bg-crit-100 text-crit-700', reserved: 'bg-cobalt-100 text-cobalt-800', growing: 'bg-glaze text-ink-soft' };

/** D-232: Sales → Sale Readiness & Clearance. Market weight is a flag; a person clears the sale; blockers win. */
export default function Readiness({ rows, weights, history, canClear }: Props) {
    const count = (s: string) => rows.filter((r) => r.sale === s).length;
    const [tab, setTab] = useState<string>(TABS.find(([k]) => count(k))?.[0] ?? 'market_weight'); // the first list with goats in it
    const list = rows.filter((r) => r.sale === tab);
    const w = useForm({ market_weight_male_kg: String(weights.male), market_weight_female_kg: String(weights.female) });
    return (
        <>
            <Head title="Sale Readiness" />
            <PageHeader title="Sale Readiness & Clearance" description="Fattening, sale and cull goats reaching their market weight show here. Reaching the weight never sells a goat: a person clears it, and any blocker (sickness, withdrawal, hold, no RFID tag) stops the sale again by itself." />
            <StatRow>
                <Stat label="Clearance pending" value={count('market_weight')} tone={count('market_weight') ? 'alert' : undefined} />
                <Stat label="Sale ready" value={count('sale_ready')} tone="good" />
                <Stat label="Blocked" value={count('blocked')} tone={count('blocked') ? 'alert' : undefined} />
                <Stat label="Reserved" value={count('reserved')} />
                <Stat label="Growing to target" value={count('growing')} />
            </StatRow>
            <div className="mb-3 flex flex-wrap gap-1" role="radiogroup" aria-label="Show">{TABS.map(([k, l]) => (
                <button key={k} type="button" role="radio" aria-checked={tab === k} onClick={() => setTab(k)} className={tab === k ? 'rounded-full bg-cobalt-800 px-3 py-1.5 text-xs font-semibold text-white' : 'rounded-full bg-glaze px-3 py-1.5 text-xs font-semibold ring-1 ring-rule'}>{l} ({count(k)})</button>
            ))}</div>
            <Panel flush title={`${list.length} goats`}>
                {list.length ? <ul className="divide-y divide-rule">{list.map((r) => <GoatRow key={r.id} r={r} canClear={canClear} />)}</ul> : <Empty title="No goats here" />}
            </Panel>
            <Panel title="Market Weight" className="mt-4" description="Used for goats not in a finishing batch; a batch's own target weight wins for its goats.">
                <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => { e.preventDefault(); w.post('/sales/readiness/weights', { preserveScroll: true }); }}>
                    <div className="w-36"><Field label="Males (KG)" error={w.errors.market_weight_male_kg}><input className="field" type="number" step="0.5" disabled={!canClear} value={w.data.market_weight_male_kg} onChange={(e) => w.setData('market_weight_male_kg', e.target.value)} /></Field></div>
                    <div className="w-36"><Field label="Females (KG)" error={w.errors.market_weight_female_kg}><input className="field" type="number" step="0.5" disabled={!canClear} value={w.data.market_weight_female_kg} onChange={(e) => w.setData('market_weight_female_kg', e.target.value)} /></Field></div>
                    {canClear ? <Button type="submit" disabled={w.processing || !w.isDirty}>Save</Button> : null}
                </form>
            </Panel>
            <Panel flush title="Clearances" className="mt-4" description="Every clearance and withdrawal is kept.">
                {history.length ? <div className="overflow-x-auto"><table className="data-table min-w-[520px]"><thead><tr><th>Goat</th><th>Decision</th><th>On</th><th>By</th><th>Reason</th></tr></thead><tbody>{history.map((h) => (
                    <tr key={h.id}><td><Link href={`/goats/${h.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{h.number}</Link></td><td>{h.decision === 'cleared' ? 'Cleared for sale' : 'Withdrawn'}</td><td>{h.on}</td><td>{h.by}</td><td className="text-sm text-ink-soft">{h.reason ?? '—'}</td></tr>
                ))}</tbody></table></div> : <Empty title="No clearances yet" />}
            </Panel>
        </>
    );
}

function GoatRow({ r, canClear }: { r: Row; canClear: boolean }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ decision: r.sale === 'sale_ready' ? 'withdrawn' : 'cleared', reason: '' });
    const stock = r.roles.some((c) => ['BB', 'BD', 'RB', 'RD', 'PS', 'PD', 'DD', 'CM', 'CF'].includes(c));
    const can = canClear && ['market_weight', 'sale_ready', 'growing', 'blocked'].includes(r.sale) && !(r.sale !== 'sale_ready' && r.blockers.length);
    return (
        <li className="px-4 py-3">
            <div className="flex flex-wrap items-center gap-2">
                <Link href={`/goats/${r.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{r.number}</Link>
                <span className="text-sm text-ink-soft">{[r.name, r.sex === 'female' ? 'Female' : 'Male', r.breed, r.fattening].filter(Boolean).join(' · ')}</span>
                {r.code ? <Chip className="bg-cobalt-100 font-mono text-cobalt-800">{r.code}</Chip> : null}
                {r.roles.map((c) => <Chip key={c} className="bg-glaze font-mono text-cobalt-800 ring-1 ring-rule">{c}</Chip>)}
                <Chip className={TONE[r.sale] ?? 'bg-glaze text-ink-soft'}>{r.saleLabel}</Chip>
                {can && !open ? <Button className="ml-auto" variant={r.sale === 'market_weight' ? 'primary' : 'secondary'} onClick={() => setOpen(true)}>{r.sale === 'sale_ready' ? 'Withdraw clearance' : 'Clear for sale'}</Button> : null}
            </div>
            {r.blockers.length ? <p className="mt-1 text-sm font-semibold text-crit-700">Blocked: {r.blockers.join('; ')}</p> : null}
            {open ? (
                <form className="mt-3 grid max-w-xl gap-2 rounded-tile bg-glaze p-3" onSubmit={(e) => { e.preventDefault(); form.post(`/sales/readiness/${r.id}`, { preserveScroll: true, onSuccess: () => setOpen(false) }); }}>
                    {form.data.decision === 'cleared' && (stock || r.sale !== 'market_weight') ? <Note>{stock ? 'This is breeding stock or a cull goat: give the management reason for the sale.' : 'Not at market weight yet: give the reason for clearing it now.'}</Note> : null}
                    <Field label="Reason" error={form.errors.reason ?? form.errors.decision}><input className="field" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} /></Field>
                    <span className="flex gap-2"><Button type="submit" disabled={form.processing}>{form.data.decision === 'cleared' ? 'Clear for sale' : 'Withdraw'}</Button><Button type="button" variant="secondary" onClick={() => setOpen(false)}>Cancel</Button></span>
                </form>
            ) : null}
        </li>
    );
}
