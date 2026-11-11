import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type Lot = { id: number; no: string; item: string | null; on: string; kg: number; supplier: string | null; status: string; moisture: number | null; aflatoxin: number | null; appearance: string | null; sample: string | null; testedBy: string | null; receivedBy: string; decidedBy: string | null; note: string | null; tested: boolean };
type Props = {
    lots: Lot[]; items: Array<{ id: number; name: string; safety_test: boolean }>; limits: { moisture: number; aflatoxin: number };
    kpi: { quarantined: number; heldKg: number; rejected: number }; canDecide: boolean; canManage: boolean; me: string;
};

const TONE: Record<string, string> = { quarantined: 'bg-watch-100 text-watch-700', released: 'bg-ok-100 text-ok-700', rejected: 'bg-crit-100 text-crit-700' };
const LABEL: Record<string, string> = { quarantined: 'Quarantined', released: 'Released', rejected: 'Rejected' };

/** D-209: App 06 Feed Safety: lots, tests and holds (BR-016). */
export default function FeedLots(p: Props) {
    const held = p.lots.filter((l) => l.status === 'quarantined');
    const [tab, setTab] = useTabParam(held.length ? 'held' : 'all');
    return (
        <>
            <Head title="Feed Safety Lots" />
            <PageHeader title="Feed Safety: Lots, Tests & Holds" description="Every feed purchase or receipt is a lot. Feeds marked 'needs safety test' arrive quarantined and cannot be fed or mixed into TMR. Sample them for moisture, aflatoxin and appearance; then someone other than the receiver releases the lot within the limits, or rejects it and it is written off." />
            <StatRow>
                <Stat label="Quarantined lots" value={p.kpi.quarantined} tone={p.kpi.quarantined ? 'alert' : undefined} />
                <Stat label="KG on hold" value={p.kpi.heldKg.toLocaleString()} />
                <Stat label="Rejected (90 days)" value={p.kpi.rejected} />
                <Stat label="Limits" value={`${p.limits.moisture}% · ${p.limits.aflatoxin} ppb`} note="Moisture · aflatoxin" />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'held', label: 'On Hold', count: held.length || undefined }, { id: 'all', label: 'All Lots' }, { id: 'setup', label: 'Feeds & Limits' }]} />
            {tab === 'held' ? <Lots {...p} rows={held} empty="No feed on hold" /> : null}
            {tab === 'all' ? <Lots {...p} rows={p.lots} empty="No lots yet. Lots are made when feed is bought or received." /> : null}
            {tab === 'setup' ? <Setup {...p} /> : null}
        </>
    );
}

function Lots({ rows, empty, ...p }: Props & { rows: Lot[]; empty: string }) {
    const [shown, more] = useFive(rows, 20);
    return (
        <Panel flush>
            {rows.length ? <ul className="divide-y divide-rule">{shown.map((l) => <LotItem key={l.id} l={l} {...p} />)}</ul> : <Empty title={empty} />}
            {more}
        </Panel>
    );
}

function LotItem({ l, limits, canDecide, canManage }: Props & { l: Lot }) {
    const [open, setOpen] = useState(false);
    const test = useForm({ moisture_pct: l.moisture?.toString() ?? '', aflatoxin_ppb: l.aflatoxin?.toString() ?? '', appearance: l.appearance ?? '', sample_ref: l.sample ?? '' });
    const dec = useForm({ decision: 'released', note: '' });
    const e = { ...test.errors, ...dec.errors } as Record<string, string>;
    const over = (l.moisture !== null && l.moisture > limits.moisture) || (l.aflatoxin !== null && l.aflatoxin > limits.aflatoxin);
    const decide = (decision: string) => { dec.transform((d) => ({ ...d, decision })); dec.post(`/feeding/lots/${l.id}/decide`, { preserveScroll: true }); };
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono">{l.no}</strong>
                <span className="font-semibold">{l.item}</span>
                <span className="text-ink-soft">{l.kg.toLocaleString()} KG · {l.on}{l.supplier ? ` · ${l.supplier}` : ''} · received by {l.receivedBy}</span>
                <Chip className={TONE[l.status]}>{LABEL[l.status]}</Chip>
                {l.status === 'quarantined' && canManage ? <Button variant="secondary" className="ml-auto" onClick={() => setOpen(!open)}>{l.tested ? 'Results & decision' : 'Record test'}</Button> : null}
            </div>
            {l.tested ? <p className={over ? 'font-semibold text-crit-700' : 'text-ink-soft'}>Moisture {l.moisture}%{l.aflatoxin !== null ? ` · aflatoxin ${l.aflatoxin} ppb` : ''}{l.appearance ? ` · ${l.appearance}` : ''}{l.sample ? ` · sample ${l.sample}` : ''} · tested by {l.testedBy}{over ? ' · over the limit' : ''}</p> : null}
            {l.decidedBy ? <p className="text-ink-soft">{LABEL[l.status]} by {l.decidedBy}{l.note ? `: ${l.note}` : ''}</p> : null}
            {open && l.status === 'quarantined' ? (
                <div className="mt-2 space-y-2 rounded-tile bg-glaze p-3">
                    <div className="grid gap-2 sm:grid-cols-[120px_120px_1fr_140px_auto] sm:items-end">
                        <Field label="Moisture %" error={e.moisture_pct}><input type="number" step="0.1" className="field" value={test.data.moisture_pct} onChange={(x) => test.setData('moisture_pct', x.target.value)} /></Field>
                        <Field label="Aflatoxin ppb" error={e.aflatoxin_ppb}><input type="number" step="0.1" className="field" value={test.data.aflatoxin_ppb} onChange={(x) => test.setData('aflatoxin_ppb', x.target.value)} /></Field>
                        <Field label="Appearance" error={e.appearance}><input className="field" value={test.data.appearance} onChange={(x) => test.setData('appearance', x.target.value)} placeholder="Mould, smell, foreign matter" /></Field>
                        <Field label="Sample ref" error={e.sample_ref}><input className="field" value={test.data.sample_ref} onChange={(x) => test.setData('sample_ref', x.target.value)} /></Field>
                        <Button disabled={test.processing} onClick={() => { test.transform((d) => ({ ...d, aflatoxin_ppb: d.aflatoxin_ppb || null })); test.post(`/feeding/lots/${l.id}/test`, { preserveScroll: true }); }}>Save results</Button>
                    </div>
                    {canDecide ? (
                        <div className="grid gap-2 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                            <Field label="Reason (needed to reject)" error={e.note ?? e.decision}><input className="field" value={dec.data.note} onChange={(x) => dec.setData('note', x.target.value)} /></Field>
                            <Button disabled={dec.processing || !l.tested} onClick={() => decide('released')}>Release</Button>
                            <Button variant="secondary" disabled={dec.processing} onClick={() => decide('rejected')}>Reject & write off</Button>
                        </div>
                    ) : <p className="text-xs text-ink-soft">The owner, manager or vet releases or rejects the lot.</p>}
                </div>
            ) : null}
        </li>
    );
}

function Setup({ items, limits, canDecide, canManage }: Props) {
    const lim = useForm({ feed_max_moisture_pct: String(limits.moisture), feed_max_aflatoxin_ppb: String(limits.aflatoxin) });
    return (
        <div className="space-y-4">
            <Panel flush title="Feeds That Need a Safety Test" description="New lots of a ticked feed arrive quarantined. Grain, concentrates, oilseed cakes and silage are the usual ones.">
                <ul className="divide-y divide-rule">{items.map((i) => (
                    <li key={i.id} className="flex items-center gap-3 px-4 py-2 text-sm">
                        <label className="flex items-center gap-2"><input type="checkbox" disabled={!canManage} checked={i.safety_test} onChange={(x) => router.post(`/feeding/lots/items/${i.id}`, { safety_test: x.target.checked }, { preserveScroll: true })} /> {i.name}</label>
                    </li>
                ))}</ul>
            </Panel>
            {canDecide ? (
                <Panel title="Release Limits" description="Set by the owner or the vet / nutritionist.">
                    <div className="grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                        <Field label="Maximum moisture %" error={lim.errors.feed_max_moisture_pct}><input type="number" step="0.1" className="field" value={lim.data.feed_max_moisture_pct} onChange={(x) => lim.setData('feed_max_moisture_pct', x.target.value)} /></Field>
                        <Field label="Maximum aflatoxin ppb" error={lim.errors.feed_max_aflatoxin_ppb}><input type="number" step="0.1" className="field" value={lim.data.feed_max_aflatoxin_ppb} onChange={(x) => lim.setData('feed_max_aflatoxin_ppb', x.target.value)} /></Field>
                        <Button disabled={lim.processing} onClick={() => lim.post('/feeding/lots/limits', { preserveScroll: true })}>Save</Button>
                    </div>
                </Panel>
            ) : null}
        </div>
    );
}
