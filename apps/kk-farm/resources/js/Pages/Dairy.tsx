import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, LibIcon, PageHeader, Panel, pkr, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type Session = {
    session: string; produced: number; held: number; colostrum: number; does: number;
    doeList: Array<{ id: string; number: string | null; litres: number; held: boolean; reason: string | null }>;
    closed: { kid: number; discard: number; saleable: number; by: string; lot: string | null } | null;
};
type Lot = {
    id: number; code: string; litres: number; remaining: number; status: string; session: string; tank: string | null; temp: number | null; residue: string | null;
    testedBy: string | null; releasedBy: string | null; hold: string | null; buyers: Array<{ on: string; to: string; litres: number; kind: string }>;
};
type Props = {
    day: string; sessions: Session[]; lots: Lot[]; customers: Array<{ id: number; name: string }>; options: { status: Record<string, string> }; canQuality: boolean;
    kpi: { perDoeDay: number | null; saleablePct: number | null; lossPct: number | null; produced: number; revenue: number; costPerLitre: number | null; feedDay: number };
};

const LOT_CLS: Record<string, string> = { pending_qc: 'bg-watch-100 text-watch-700', stored: 'bg-cobalt-100 text-cobalt-800', released: 'bg-ok-100 text-ok-700', hold: 'bg-treat-100 text-treat-700', rejected: 'bg-crit-100 text-crit-700', dispatched: 'bg-glaze text-ink-soft', recalled: 'bg-crit-100 text-crit-700' };
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

/** D-188: App 17 Dairy & Milk (V3 flow 17): close sessions, milk lots, QA, release, dispatch, hold and recall. */
export default function Dairy(p: Props) {
    const [tab, setTab] = useTabParam('sessions');
    const waiting = p.lots.filter((l) => ['pending_qc', 'stored', 'hold'].includes(l.status)).length;
    return (
        <>
            <Head title="Dairy & Milk" />
            <PageHeader title="Dairy & Milk Production" description="Per-doe litres are logged under Milking. Here each session is closed, saleable milk becomes a lot, and only QA-released lots are dispatched." actions={<Link href="/milking"><Button variant="secondary"><LibIcon name="ic-lactating" size={18} /> Log Litres per Doe</Button></Link>} />
            <StatRow>
                <Stat label="Litres / doe / day (30 days)" value={p.kpi.perDoeDay ?? '—'} note={`${p.kpi.produced} L produced`} />
                <Stat label="Saleable share" value={p.kpi.saleablePct !== null ? `${p.kpi.saleablePct}%` : '—'} note={p.kpi.lossPct !== null ? `${p.kpi.lossPct}% held or discarded` : undefined} />
                <Stat label="Milk revenue (30 days)" value={pkr(p.kpi.revenue)} />
                <Stat label="Feed cost / litre (estimate)" value={p.kpi.costPerLitre !== null ? pkr(p.kpi.costPerLitre) : '—'} note={`does in milk eat about ${pkr(p.kpi.feedDay)}/day`} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'sessions', label: 'Close Sessions' }, { id: 'lots', label: 'Milk Lots & QA', count: waiting || undefined }, { id: 'trace', label: 'Dispatch & Trace' }]} />
            {tab === 'sessions' ? (
                <div className="space-y-3">
                    <div className="flex items-center gap-2 text-sm"><span className="font-semibold">Day</span><input type="date" max={today()} className="field !w-44 !py-1.5" value={p.day} onChange={(e) => router.get('/dairy', { tab: 'sessions', day: e.target.value }, { preserveScroll: true })} /></div>
                    <div className="grid gap-3 lg:grid-cols-2">{p.sessions.map((s) => <SessionCard key={s.session} s={s} day={p.day} />)}</div>
                </div>
            ) : null}
            {tab === 'lots' ? <Lots {...p} lots={p.lots.filter((l) => !['dispatched', 'recalled', 'rejected'].includes(l.status))} empty="No lots waiting. Close a session to create one." /> : null}
            {tab === 'trace' ? <Lots {...p} lots={p.lots} empty="No milk lots in the last 30 days." /> : null}
        </>
    );
}

function SessionCard({ s, day }: { s: Session; day: string }) {
    const form = useForm({ milked_on: day, session: s.session, hygiene_ok: true, equipment_ok: true, operator: '', mastitis: [] as string[], kid_l: '', discard_l: '', notes: '' });
    const e = form.errors as Record<string, string>;
    const d = form.data;
    const available = Math.max(0, s.produced - s.held - s.colostrum);
    const saleable = d.hygiene_ok && d.equipment_ok ? Math.max(0, available - Number(d.kid_l || 0) - Number(d.discard_l || 0)) : 0;
    const title = s.session === 'morning' ? 'Morning (AM)' : 'Evening (PM)';
    if (s.closed) {
        return (
            <Panel title={title} description={`Closed by ${s.closed.by}`} actions={s.closed.lot ? <Chip className="bg-ok-100 text-ok-700">Lot {s.closed.lot}</Chip> : <Chip className="bg-glaze text-ink-soft">No saleable milk</Chip>}>
                <Balance produced={s.produced} held={s.held} colostrum={s.colostrum} kid={s.closed.kid} discard={s.closed.discard} saleable={s.closed.saleable} />
            </Panel>
        );
    }
    if (!s.does) return <Panel title={title}><Empty title="No litres logged yet">Log each doe's litres under Milking first.</Empty></Panel>;
    return (
        <Panel title={title} description={`${s.does} does · ${s.produced} L logged`}>
            <form className="space-y-3 p-4" onSubmit={(ev) => { ev.preventDefault(); form.transform((x) => ({ ...x, kid_l: x.kid_l || 0, discard_l: x.discard_l || 0 })); form.post('/dairy/sessions', { preserveScroll: true }); }}>
                <div className="rounded-tile bg-glaze p-3 text-sm">
                    <p className="mb-1.5 font-bold text-cobalt-900">1 · Hygiene, equipment and operator check</p>
                    <div className="flex flex-wrap items-center gap-3">
                        <label className="flex items-center gap-1.5"><input type="checkbox" checked={d.hygiene_ok} onChange={(x) => form.setData('hygiene_ok', x.target.checked)} /> Udders and hands clean</label>
                        <label className="flex items-center gap-1.5"><input type="checkbox" checked={d.equipment_ok} onChange={(x) => form.setData('equipment_ok', x.target.checked)} /> Buckets / machine clean</label>
                        <input className="field !w-44 !py-1" placeholder="Milker" value={d.operator} onChange={(x) => form.setData('operator', x.target.value)} />
                        {e.operator ? <span className="text-xs text-crit-700">{e.operator}</span> : null}
                    </div>
                    {!d.hygiene_ok || !d.equipment_ok ? <p className="mt-1 text-xs font-semibold text-crit-700">A failed check discards this session's saleable milk.</p> : null}
                </div>
                <div className="rounded-tile bg-glaze p-3 text-sm">
                    <p className="mb-1.5 font-bold text-cobalt-900">2 · Pre-milking udder check</p>
                    <p className="mb-1 text-xs text-ink-soft">Tick any doe with a hard, hot or swollen udder or clots in the milk: her milk is held and the vet gets a task.</p>
                    <div className="flex flex-wrap gap-1.5">{s.doeList.map((x) => (
                        <label key={x.id} className={`flex items-center gap-1 rounded-full px-2 py-0.5 text-xs ring-1 ${x.held ? 'bg-crit-100 text-crit-700 ring-crit-500/30' : d.mastitis.includes(x.id) ? 'bg-treat-100 text-treat-700 ring-treat-400' : 'bg-white ring-rule'}`} title={x.reason ?? ''}>
                            {x.held ? null : <input type="checkbox" checked={d.mastitis.includes(x.id)} onChange={() => form.setData('mastitis', d.mastitis.includes(x.id) ? d.mastitis.filter((y) => y !== x.id) : [...d.mastitis, x.id])} />}
                            <span className="font-mono">{x.number}</span> {x.litres} L{x.held ? ' · held' : ''}
                        </label>
                    ))}</div>
                </div>
                <div className="rounded-tile bg-glaze p-3 text-sm">
                    <p className="mb-1.5 font-bold text-cobalt-900">3 · Kid milk and colostrum</p>
                    <div className="grid gap-2 sm:grid-cols-3">
                        <div className="pt-1">Colostrum (first {3} days): <strong>{s.colostrum} L</strong><div className="text-xs text-ink-soft">never saleable</div></div>
                        <Field label="Fed to kids L" error={e.kid_l}><input type="number" step="0.1" min={0} className="field" value={d.kid_l} onChange={(x) => form.setData('kid_l', x.target.value)} /></Field>
                        <Field label="Other discard L"><input type="number" step="0.1" min={0} className="field" value={d.discard_l} onChange={(x) => form.setData('discard_l', x.target.value)} /></Field>
                    </div>
                </div>
                <Balance produced={s.produced} held={s.held} colostrum={s.colostrum} kid={Number(d.kid_l || 0)} discard={d.hygiene_ok && d.equipment_ok ? Number(d.discard_l || 0) : available - Number(d.kid_l || 0)} saleable={saleable} />
                {e.session ? <p className="text-sm text-crit-700">{e.session}</p> : null}
                <Button type="submit" disabled={form.processing}>Close Session{saleable > 0 ? ` & Create Lot (${saleable.toFixed(1)} L)` : ''}</Button>
            </form>
        </Panel>
    );
}

function Balance({ produced, held, colostrum, kid, discard, saleable }: { produced: number; held: number; colostrum: number; kid: number; discard: number; saleable: number }) {
    const parts = [['Held (vet)', held, 'bg-crit-500'], ['Colostrum', colostrum, 'bg-watch-400'], ['Kids', kid, 'bg-turq-600'], ['Discard', Math.max(0, discard), 'bg-rule-strong'], ['Saleable', Math.max(0, saleable), 'bg-ok-700']] as const;
    return (
        <div className="space-y-1.5 px-4 pb-3 text-xs">
            <div className="flex h-3 overflow-hidden rounded-full bg-rule/50">{parts.map(([l, v, c]) => (produced > 0 && v > 0 ? <span key={l} className={c} style={{ width: `${(v / produced) * 100}%` }} title={`${l} ${v} L`} /> : null))}</div>
            <div className="flex flex-wrap gap-x-3 gap-y-0.5 text-ink-soft"><strong className="text-cobalt-900">{produced} L produced</strong>{parts.map(([l, v]) => <span key={l}>{l} {Math.round(v * 10) / 10} L</span>)}</div>
        </div>
    );
}

function Lots({ lots, customers, options, canQuality, empty }: Props & { empty: string }) {
    const [shown, more] = useFive(lots);
    return (
        <Panel flush>
            {lots.length ? <ul className="divide-y divide-rule">{shown.map((l) => <LotItem key={l.id} l={l} customers={customers} options={options} canQuality={canQuality} />)}</ul> : <Empty title={empty} />}
            {more}
        </Panel>
    );
}

function LotItem({ l, customers, options, canQuality }: { l: Lot; customers: Props['customers']; options: Props['options']; canQuality: boolean }) {
    const [mode, setMode] = useState<'test' | 'dispatch' | 'hold' | 'recall' | null>(null);
    const test = useForm({ appearance_ok: true, smell_ok: true, residue: 'negative', tank: '', storage_temp_c: '', notes: '' });
    const send = useForm({ kind: 'sale', litres: String(l.remaining), dispatched_on: today(), customer_id: '', buyer: '', price_per_litre: '' });
    const reason = useForm({ reason: '' });
    const e = { ...(test.errors as Record<string, string>), ...(send.errors as Record<string, string>) };
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono text-cobalt-900">{l.code}</strong>
                <Chip className={LOT_CLS[l.status]}>{options.status[l.status]}</Chip>
                <span className="text-ink-soft">{l.session} · {l.litres} L{l.remaining < l.litres ? ` · ${l.remaining} L left` : ''}{l.tank ? ` · ${l.tank}` : ''}{l.temp !== null ? ` · ${l.temp} °C` : ''}{l.residue ? ` · residue ${l.residue}` : ''}</span>
                <span className="ml-auto flex flex-wrap gap-1.5">
                    {['pending_qc', 'hold'].includes(l.status) ? <Button variant="secondary" onClick={() => setMode(mode === 'test' ? null : 'test')}>Quality & Residue Check</Button> : null}
                    {l.status === 'stored' && canQuality ? <Button onClick={() => router.post(`/dairy/lots/${l.id}/release`, {}, { preserveScroll: true })}>Release</Button> : null}
                    {l.status === 'released' ? <Button onClick={() => setMode(mode === 'dispatch' ? null : 'dispatch')}>Dispatch</Button> : null}
                    {['stored', 'released'].includes(l.status) && canQuality ? <Button variant="quiet" onClick={() => setMode('hold')}>Hold</Button> : null}
                    {['released', 'dispatched', 'hold'].includes(l.status) && canQuality ? <Button variant="quiet" onClick={() => setMode('recall')}>Recall</Button> : null}
                </span>
            </div>
            {l.testedBy || l.releasedBy || l.hold ? <p className="mt-1 text-xs text-ink-soft">{l.testedBy ? `Tested by ${l.testedBy}` : ''}{l.releasedBy ? ` · released by ${l.releasedBy}` : ''}{l.hold ? ` · ${l.hold}` : ''}</p> : null}
            {l.buyers.length ? <p className="mt-1 text-xs">Sent to: {l.buyers.map((b) => `${b.to} ${b.litres} L (${b.on})`).join(', ')}</p> : null}
            {mode === 'test' ? (
                <form className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-6" onSubmit={(ev) => { ev.preventDefault(); test.transform((x) => ({ ...x, storage_temp_c: x.storage_temp_c || null })); test.post(`/dairy/lots/${l.id}/test`, { preserveScroll: true, onSuccess: () => setMode(null) }); }}>
                    <label className="flex items-center gap-1.5 pt-5"><input type="checkbox" checked={test.data.appearance_ok} onChange={(x) => test.setData('appearance_ok', x.target.checked)} /> Appearance normal</label>
                    <label className="flex items-center gap-1.5 pt-5"><input type="checkbox" checked={test.data.smell_ok} onChange={(x) => test.setData('smell_ok', x.target.checked)} /> Smell normal</label>
                    <Field label="Antibiotic residue"><select className="field" value={test.data.residue} onChange={(x) => test.setData('residue', x.target.value)}><option value="negative">Negative</option><option value="positive">Positive</option><option value="not_tested">Not tested</option></select></Field>
                    <Field label="Tank"><input className="field" value={test.data.tank} onChange={(x) => test.setData('tank', x.target.value)} /></Field>
                    <Field label="Chilled to °C" error={e.storage_temp_c}><input type="number" step="0.1" className="field" value={test.data.storage_temp_c} onChange={(x) => test.setData('storage_temp_c', x.target.value)} /></Field>
                    <div className="flex items-end"><Button type="submit" disabled={test.processing}>Save Check</Button></div>
                    <p className="text-xs text-ink-soft sm:col-span-6">Passes only with normal appearance and smell and a negative residue test; otherwise the lot is rejected for discard.</p>
                </form>
            ) : null}
            {mode === 'dispatch' ? (
                <form className="mt-2 grid gap-2 rounded-tile bg-turq-100/50 p-3 sm:grid-cols-6" onSubmit={(ev) => { ev.preventDefault(); send.transform((x) => ({ ...x, customer_id: x.customer_id || null, price_per_litre: x.kind === 'sale' ? x.price_per_litre : null })); send.post(`/dairy/lots/${l.id}/dispatch`, { preserveScroll: true, onSuccess: () => setMode(null) }); }}>
                    <Field label="To"><select className="field" value={send.data.kind} onChange={(x) => send.setData('kind', x.target.value)}><option value="sale">Buyer (sale)</option><option value="processing">Processing</option></select></Field>
                    <Field label="Litres" error={e.litres}><input type="number" step="0.1" className="field" value={send.data.litres} onChange={(x) => send.setData('litres', x.target.value)} /></Field>
                    {send.data.kind === 'sale' ? <Field label="Customer"><select className="field" value={send.data.customer_id} onChange={(x) => send.setData('customer_id', x.target.value)}><option value="">—</option>{customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field> : null}
                    {send.data.kind === 'sale' ? <Field label="Or buyer"><input className="field" value={send.data.buyer} onChange={(x) => send.setData('buyer', x.target.value)} /></Field> : null}
                    {send.data.kind === 'sale' ? <Field label="PKR / litre" error={e.price_per_litre}><input type="number" className="field" value={send.data.price_per_litre} onChange={(x) => send.setData('price_per_litre', x.target.value)} /></Field> : null}
                    <div className="flex items-end"><Button type="submit" disabled={send.processing}>Dispatch</Button></div>
                </form>
            ) : null}
            {mode === 'hold' || mode === 'recall' ? (
                <form className="mt-2 flex gap-2" onSubmit={(ev) => { ev.preventDefault(); reason.post(`/dairy/lots/${l.id}/${mode}`, { preserveScroll: true, onSuccess: () => setMode(null) }); }}>
                    <input className="field flex-1" placeholder={mode === 'hold' ? 'Why hold (retest before release)' : 'Why recall (buyers will be listed to notify)'} value={reason.data.reason} onChange={(x) => reason.setData('reason', x.target.value)} />
                    <Button type="submit" variant={mode === 'recall' ? 'danger' : 'primary'} disabled={reason.processing}>{mode === 'hold' ? 'Hold Lot' : 'Recall Lot'}</Button>
                </form>
            ) : null}
        </li>
    );
}
