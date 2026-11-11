import { Head, useForm } from '@inertiajs/react';
import { Button, Chip, Empty, Field, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type Batch = { id: number; code: string; product: string; lot: string | null; input: number; output: number; unit: string; yield: number | null; heat: string | null; made: string; bestBefore: string | null; status: string; by: string; releasedBy: string | null; notes: string | null };
type Props = {
    milk: Array<{ id: number; lot: string | null; on: string; litres: number; left: number }>; batches: Batch[];
    kpi: { litres: number; batches: number; toRelease: number; waiting: number };
    products: Array<{ id: string; label: string; unit: string; heated: boolean }>; canRelease: boolean; me: string;
};

const TONE: Record<string, string> = { made: 'bg-watch-100 text-watch-700', released: 'bg-ok-100 text-ok-700', discarded: 'bg-crit-100 text-crit-700' };
const LABEL: Record<string, string> = { made: 'Waiting for release', released: 'Released', discarded: 'Discarded' };
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

/** D-211: App 17 optional milk processing. */
export default function MilkProcessing(p: Props) {
    const [tab, setTab] = useTabParam('batches');
    return (
        <>
            <Head title="Milk Processing" />
            <PageHeader title="Milk Processing Batches" description="Milk sent to processing from a released lot (Dispatch & Trace) becomes product batches: yogurt, cheese, ghee, khoa or pasteurised milk. Record the heat treatment, output and best-before; someone other than the maker releases the batch for sale. Every batch traces back to its milk lot." />
            <StatRow>
                <Stat label="Milk waiting to process" value={`${p.kpi.waiting} L`} />
                <Stat label="Litres processed (30 days)" value={`${p.kpi.litres} L`} note={`${p.kpi.batches} batches`} />
                <Stat label="Batches to release" value={p.kpi.toRelease} tone={p.kpi.toRelease ? 'alert' : undefined} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'batches', label: 'Batches', count: p.kpi.toRelease || undefined }, { id: 'new', label: 'New Batch' }]} />
            {tab === 'new' ? <NewBatch {...p} done={() => setTab('batches')} /> : <Batches {...p} />}
        </>
    );
}

function NewBatch({ milk, products, done }: Props & { done: () => void }) {
    const open = milk.filter((m) => m.left > 0);
    const form = useForm({ dispatch_id: String(open[0]?.id ?? ''), product: 'yogurt', input_l: '', heat_temp_c: '', heat_minutes: '', output_qty: '', made_on: today(), best_before: '', notes: '' });
    const e = form.errors as Record<string, string>;
    const prod = products.find((x) => x.id === form.data.product);
    if (!open.length) return <Panel><Empty title="No milk waiting">Send milk to processing from a released lot under Dispatch & Trace first.</Empty></Panel>;
    return (
        <Panel title="Record a Processing Batch">
            <div className="grid gap-2 sm:grid-cols-3">
                <Field label="Milk (lot)" error={e.dispatch_id}><select className="field" value={form.data.dispatch_id} onChange={(x) => form.setData('dispatch_id', x.target.value)}>{open.map((m) => <option key={m.id} value={m.id}>{m.lot} · {m.on} · {m.left} L left</option>)}</select></Field>
                <Field label="Product" error={e.product}><select className="field" value={form.data.product} onChange={(x) => form.setData('product', x.target.value)}>{products.map((x) => <option key={x.id} value={x.id}>{x.label}</option>)}</select></Field>
                <Field label="Litres used" error={e.input_l}><input type="number" step="0.1" className="field" value={form.data.input_l} onChange={(x) => form.setData('input_l', x.target.value)} /></Field>
                <Field label="Heat treatment °C" error={e.heat_temp_c} hint={prod?.heated ? 'Needed before release' : undefined}><input type="number" step="0.1" className="field" value={form.data.heat_temp_c} onChange={(x) => form.setData('heat_temp_c', x.target.value)} /></Field>
                <Field label="Held for (minutes)" error={e.heat_minutes}><input type="number" className="field" value={form.data.heat_minutes} onChange={(x) => form.setData('heat_minutes', x.target.value)} /></Field>
                <Field label={`Output (${prod?.unit ?? 'kg'})`} error={e.output_qty}><input type="number" step="0.01" className="field" value={form.data.output_qty} onChange={(x) => form.setData('output_qty', x.target.value)} /></Field>
                <Field label="Made on" error={e.made_on}><input type="date" max={today()} className="field" value={form.data.made_on} onChange={(x) => form.setData('made_on', x.target.value)} /></Field>
                <Field label="Best before" error={e.best_before}><input type="date" className="field" value={form.data.best_before} onChange={(x) => form.setData('best_before', x.target.value)} /></Field>
                <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
            </div>
            <div className="mt-2"><Button disabled={form.processing} onClick={() => { form.transform((d) => ({ ...d, heat_temp_c: d.heat_temp_c || null, heat_minutes: d.heat_minutes || null, best_before: d.best_before || null })); form.post('/dairy/processing', { preserveScroll: true, onSuccess: done }); }}>Save Batch</Button></div>
        </Panel>
    );
}

function Batches({ batches, canRelease, me }: Props) {
    const [shown, more] = useFive(batches, 20);
    return (
        <Panel flush>
            {batches.length ? <ul className="divide-y divide-rule">{shown.map((b) => <BatchItem key={b.id} b={b} canRelease={canRelease} me={me} />)}</ul> : <Empty title="No processing batches yet" />}
            {more}
        </Panel>
    );
}

function BatchItem({ b, canRelease, me }: { b: Batch; canRelease: boolean; me: string }) {
    const form = useForm({ decision: 'released', note: '' });
    const e = form.errors as Record<string, string>;
    const send = (decision: string) => { form.transform((d) => ({ ...d, decision })); form.post(`/dairy/processing/${b.id}/decide`, { preserveScroll: true }); };
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono">{b.code}</strong><span className="font-semibold">{b.product}</span>
                <span className="text-ink-soft">{b.input} L → {b.output} {b.unit}{b.yield !== null ? ` (${b.yield}%)` : ''} · lot {b.lot} · {b.made} · {b.by}</span>
                {b.heat ? <span className="text-ink-soft">Heat {b.heat}</span> : null}
                {b.bestBefore ? <span className="text-ink-soft">Best before {b.bestBefore}</span> : null}
                <Chip className={TONE[b.status]}>{LABEL[b.status]}{b.releasedBy ? ` · ${b.releasedBy}` : ''}</Chip>
            </div>
            {b.notes ? <p className="text-ink-soft">{b.notes}</p> : null}
            {b.status === 'made' && canRelease ? (
                <div className="mt-2 grid gap-2 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                    <Field label="Reason (needed to discard)" error={e.note ?? e.decision}><input className="field" value={form.data.note} onChange={(x) => form.setData('note', x.target.value)} /></Field>
                    <Button disabled={form.processing || b.by === me} onClick={() => send('released')}>Release for sale</Button>
                    <Button variant="secondary" disabled={form.processing} onClick={() => send('discarded')}>Discard</Button>
                </div>
            ) : null}
        </li>
    );
}
