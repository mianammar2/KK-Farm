import { Head, Link, useForm } from '@inertiajs/react';
import { Button, Chip, Empty, Field, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type Review = {
    id: number; no: string; goatId: string; goat: string | null; animalId: string | null; pen: string | null; reason: string; details: string; requestedBy: string; on: string; welfare: string | null;
    assessment: string | null; recommends: boolean | null; vetBy: string | null; approvedBy: string | null; status: string; statusLabel: string; method: string | null; disposition: string | null; performedBy: string | null; performedAt: string | null;
};
type Props = { reviews: Review[]; goats: Array<{ id: string; number: string }>; cases: Array<{ id: number; label: string; goatId: string }>; reasons: Record<string, string>; isVet: boolean; canAuthorise: boolean; me: string };

const OPEN = ['requested', 'assessed', 'approved'];
const TONE: Record<string, string> = { requested: 'bg-watch-100 text-watch-700', assessed: 'bg-treat-100 text-treat-700', approved: 'bg-crit-100 text-crit-700', declined: 'bg-glaze', done: 'bg-glaze', withdrawn: 'bg-glaze' };
const FLOW = ['Request', 'Vet assessment', 'Owner / manager authorises', 'Vet performs', 'Death recorded & disposal'];

/** D-211: App 19 humane end-of-life review. */
export default function EndOfLife(p: Props) {
    const open = p.reviews.filter((r) => OPEN.includes(r.status));
    const [tab, setTab] = useTabParam(open.length ? 'open' : 'new');
    return (
        <>
            <Head title="End-of-Life Review" />
            <PageHeader title="Humane End-of-Life Review" description="When a goat is suffering and cannot recover, anyone caring for it can ask for a review. The vet assesses it, the owner or manager authorises it, and only the vet performs it and records the method and disposal. The death then closes the goat's record like any other exit." />
            <ol className="mb-3 flex flex-wrap items-center gap-1 text-[11px] font-semibold">{FLOW.map((s, i) => <li key={s} className="flex items-center gap-1"><span className="rounded-full bg-glaze px-2 py-0.5 text-ink-soft ring-1 ring-rule">{s}</span>{i < FLOW.length - 1 ? <span className="text-ink-faint">→</span> : null}</li>)}</ol>
            <StatRow>
                <Stat label="Waiting for the vet" value={open.filter((r) => r.status === 'requested').length} />
                <Stat label="Waiting for authorisation" value={open.filter((r) => r.status === 'assessed').length} />
                <Stat label="Authorised" value={open.filter((r) => r.status === 'approved').length} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'open', label: 'Open Reviews', count: open.length || undefined }, { id: 'new', label: 'Ask for a Review' }, { id: 'closed', label: 'Closed' }]} />
            {tab === 'open' ? <List rows={open} {...p} empty="No open reviews" /> : null}
            {tab === 'closed' ? <List rows={p.reviews.filter((r) => !OPEN.includes(r.status))} {...p} empty="None yet" /> : null}
            {tab === 'new' ? <NewReview {...p} done={() => setTab('open')} /> : null}
        </>
    );
}

function NewReview({ goats, cases, reasons, done }: Props & { done: () => void }) {
    const form = useForm({ animal_id: '', welfare_case_id: '', reason: 'poor_prognosis', details: '' });
    const e = form.errors as Record<string, string>;
    const linked = cases.filter((c) => c.goatId === form.data.animal_id);
    return (
        <Panel title="Ask for an End-of-Life Review" description="Describe the goat's condition, what has been tried, and why it is suffering. The vet is sent a task.">
            <div className="grid gap-2 sm:grid-cols-3">
                <Field label="Goat" error={e.animal_id}><select className="field" value={form.data.animal_id} onChange={(x) => form.setData('animal_id', x.target.value)}><option value="">Choose…</option>{goats.map((g) => <option key={g.id} value={g.id}>{g.number}</option>)}</select></Field>
                <Field label="Reason" error={e.reason}><select className="field" value={form.data.reason} onChange={(x) => form.setData('reason', x.target.value)}>{Object.entries(reasons).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Welfare case" error={e.welfare_case_id}><select className="field" value={form.data.welfare_case_id} onChange={(x) => form.setData('welfare_case_id', x.target.value)}><option value="">None</option>{linked.map((c) => <option key={c.id} value={c.id}>{c.label}</option>)}</select></Field>
            </div>
            <div className="mt-2"><Field label="Condition and what has been tried" error={e.details}><textarea className="field min-h-24" value={form.data.details} onChange={(x) => form.setData('details', x.target.value)} /></Field></div>
            <div className="mt-2"><Button disabled={form.processing} onClick={() => { form.transform((d) => ({ ...d, welfare_case_id: d.welfare_case_id || null })); form.post('/welfare/end-of-life', { preserveScroll: true, onSuccess: () => { form.reset(); done(); } }); }}>Send to the Vet</Button></div>
        </Panel>
    );
}

function List({ rows, empty, ...p }: Props & { rows: Review[]; empty: string }) {
    const [shown, more] = useFive(rows, 20);
    return (
        <Panel flush>
            {rows.length ? <ul className="divide-y divide-rule">{shown.map((r) => <Item key={r.id} r={r} {...p} />)}</ul> : <Empty title={empty} />}
            {more}
        </Panel>
    );
}

function Item({ r, isVet, canAuthorise, me }: Props & { r: Review }) {
    const vet = useForm({ recommends: true as boolean, assessment: '' });
    const auth = useForm({ decision: 'approved' });
    const act = useForm({ method: '', disposition: '' });
    const wd = useForm({});
    const e = { ...vet.errors, ...auth.errors, ...act.errors } as Record<string, string>;
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono">{r.no}</strong>
                <Link href={`/goats/${r.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{r.goat}</Link>
                {r.animalId ? <span className="font-mono text-xs text-ink-faint">{r.animalId}</span> : null}
                <span className="font-semibold">{r.reason}</span><span className="text-ink-soft">{r.pen} · asked by {r.requestedBy}, {r.on}{r.welfare ? ` · ${r.welfare}` : ''}</span>
                <Chip className={TONE[r.status]}>{r.statusLabel}</Chip>
                {OPEN.includes(r.status) && (r.requestedBy === me || isVet || canAuthorise) ? <Button variant="secondary" className="ml-auto" disabled={wd.processing} onClick={() => wd.post(`/welfare/end-of-life/${r.id}/withdraw`, { preserveScroll: true })}>Withdraw</Button> : null}
            </div>
            <p className="text-ink-soft">{r.details}</p>
            {r.assessment ? <p className="mt-1"><span className="font-semibold">Vet {r.vetBy}:</span> {r.recommends ? 'supports it. ' : 'does not support it. '}{r.assessment}</p> : null}
            {r.approvedBy ? <p className="text-ink-soft">{r.status === 'declined' ? 'Declined' : 'Authorised'} by {r.approvedBy}</p> : null}
            {r.performedBy ? <p className="text-ink-soft">Performed by {r.performedBy}, {r.performedAt}. Method: {r.method}. Disposal: {r.disposition}.</p> : null}
            {e.decision ? <p className="text-crit-700">{e.decision}</p> : null}

            {r.status === 'requested' && isVet ? (
                <div className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-[1fr_auto] sm:items-end">
                    <Field label="Your assessment (prognosis, pain, options tried)" error={e.assessment}><textarea className="field" value={vet.data.assessment} onChange={(x) => vet.setData('assessment', x.target.value)} /></Field>
                    <div className="flex flex-col gap-1">
                        <Button disabled={vet.processing} onClick={() => { vet.transform((d) => ({ ...d, recommends: true })); vet.post(`/welfare/end-of-life/${r.id}/assess`, { preserveScroll: true }); }}>Support</Button>
                        <Button variant="secondary" disabled={vet.processing} onClick={() => { vet.transform((d) => ({ ...d, recommends: false })); vet.post(`/welfare/end-of-life/${r.id}/assess`, { preserveScroll: true }); }}>Do not support</Button>
                    </div>
                </div>
            ) : null}
            {r.status === 'assessed' && canAuthorise ? (
                <div className="mt-2 flex flex-wrap gap-2">
                    <Button disabled={auth.processing} onClick={() => { auth.transform(() => ({ decision: 'approved' })); auth.post(`/welfare/end-of-life/${r.id}/authorise`, { preserveScroll: true }); }}>Authorise</Button>
                    <Button variant="secondary" disabled={auth.processing} onClick={() => { auth.transform(() => ({ decision: 'declined' })); auth.post(`/welfare/end-of-life/${r.id}/authorise`, { preserveScroll: true }); }}>Decline</Button>
                </div>
            ) : null}
            {r.status === 'approved' && isVet ? (
                <div className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                    <Field label="Method" error={e.method}><input className="field" value={act.data.method} onChange={(x) => act.setData('method', x.target.value)} /></Field>
                    <Field label="Carcass disposal" error={e.disposition}><input className="field" value={act.data.disposition} onChange={(x) => act.setData('disposition', x.target.value)} placeholder="e.g. burial pit, incineration" /></Field>
                    <Button disabled={act.processing} onClick={() => act.post(`/welfare/end-of-life/${r.id}/perform`, { preserveScroll: true })}>Record as Done</Button>
                </div>
            ) : null}
        </li>
    );
}
