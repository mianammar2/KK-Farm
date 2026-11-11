import { Head, Link, useForm } from '@inertiajs/react';
import { Button, Chip, Empty, Field, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type Row = { id: number; goatId: string; goat: string | null; animalId: string | null; on: string; kg: number; flag: string; note: string | null; by: string; context: string | null; status: string; reviewedBy: string | null; reviewNote: string | null };
type Props = { held: Row[]; done: Row[]; canReview: boolean };

/** D-208 / BR-018: weights held for review. */
export default function WeightReview(p: Props) {
    const [tab, setTab] = useTabParam('held');
    return (
        <>
            <Head title="Held Weights" />
            <PageHeader title="Held Weights to Review" description="Impossible readings, repeated scale readings, two different weights on the same day, and gains or losses no goat makes are kept but held. They don't count in daily gain, growth or costing until the owner, manager or vet accepts them. A rejected reading stays on record and never counts." />
            <StatRow>
                <Stat label="Waiting for review" value={p.held.length} tone={p.held.length ? 'alert' : undefined} />
                <Stat label="Accepted (recent)" value={p.done.filter((d) => d.status === 'accepted').length} />
                <Stat label="Rejected (recent)" value={p.done.filter((d) => d.status === 'rejected').length} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'held', label: 'Held', count: p.held.length || undefined }, { id: 'done', label: 'Reviewed' }]} />
            {tab === 'held' ? <List rows={p.held} canReview={p.canReview} empty="No held weights" /> : <List rows={p.done} canReview={false} empty="Nothing reviewed yet" />}
        </>
    );
}

function List({ rows, canReview, empty }: { rows: Row[]; canReview: boolean; empty: string }) {
    const [shown, more] = useFive(rows, 20);
    return (
        <Panel flush>
            {rows.length ? <ul className="divide-y divide-rule">{shown.map((r) => <Item key={r.id} r={r} canReview={canReview} />)}</ul> : <Empty title={empty} />}
            {more}
        </Panel>
    );
}

function Item({ r, canReview }: { r: Row; canReview: boolean }) {
    const form = useForm({ decision: 'accepted', note: '' });
    const e = form.errors as Record<string, string>;
    const send = (decision: string) => { form.transform((d) => ({ ...d, decision })); form.post(`/weights/review/${r.id}`, { preserveScroll: true }); };
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <Link href={`/goats/${r.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{r.goat}</Link>
                {r.animalId ? <span className="font-mono text-xs text-ink-faint">{r.animalId}</span> : null}
                <strong>{r.kg} KG</strong>
                <span className="text-ink-soft">{r.on} · {r.by}</span>
                <Chip className="bg-watch-100 text-watch-700">{r.flag}</Chip>
                {r.status === 'accepted' ? <Chip className="bg-ok-100 text-ok-700">Accepted by {r.reviewedBy}</Chip> : null}
                {r.status === 'rejected' ? <Chip className="bg-crit-100 text-crit-700">Rejected by {r.reviewedBy}</Chip> : null}
            </div>
            {r.note ? <p className="text-ink-soft">{r.note}</p> : null}
            {r.reviewNote ? <p className="text-ink-soft">Reason: {r.reviewNote}</p> : null}
            {canReview ? (
                <div className="mt-2 grid gap-2 sm:grid-cols-[1fr_auto_auto] sm:items-end">
                    <Field label="Reason (needed to reject)" error={e.note}><input className="field" value={form.data.note} onChange={(x) => form.setData('note', x.target.value)} placeholder="e.g. scale not zeroed, wrong goat" /></Field>
                    <Button disabled={form.processing} onClick={() => send('accepted')}>Accept</Button>
                    <Button variant="secondary" disabled={form.processing} onClick={() => send('rejected')}>Reject</Button>
                </div>
            ) : null}
        </li>
    );
}
