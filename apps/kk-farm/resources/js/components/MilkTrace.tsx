import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useConfirm } from './Confirm';
import Sheet from './Sheet';
import { Button, Chip, Field, Panel, pkr, Stat, StatRow } from './ui';

export type RecallKpi = { rejectedL: number; detectionHours: number | null; openCases: number; traceability: number; refunds: number };
type Dispatch = { id: number; on: string; kind: string; to: string; phone: string | null; litres: number; invoice: string | null; order: string | null; amount: number | null; paid: boolean; adjustment: string | null; adjusted: number | null; returned: number | null };
export type TraceData = {
    code: string; status: string; litres: number; remaining: number; reason: string | null; recalled: boolean;
    session: { on: string; session: string; by: string | null; operator: string | null } | null;
    does: Array<{ id: string; number: string | null; litres: number; held: boolean; reason: string | null }>;
    qa: { by: string | null; at: string | null; residue: string | null; tank: string | null; temp: number | null; releasedBy: string | null; releasedAt: string | null };
    temps: Array<{ at: string; c: number; excursion: boolean; by: string }>;
    dispatches: Dispatch[];
    holds: Array<{ kind: string; case: string | null; caseId: number; title: string | null; placed: string | null; released: string | null }>;
};

/** D-196: the KPIs of journey G (flow 30) over the last 30 days. */
export function RecallKpis({ k }: { k: RecallKpi }) {
    return (
        <StatRow>
            <Stat label="Rejected milk (30 days)" value={`${k.rejectedL} L`} />
            <Stat label="Issue detection time" value={k.detectionHours !== null ? `${k.detectionHours} h` : '—'} note="Lot made → hold placed" />
            <Stat label="Traceability" value={`${k.traceability}%`} note="Dispatches with a known recipient" tone={k.traceability < 100 ? 'alert' : 'good'} />
            <Stat label="Open milk quality cases" value={k.openCases} tone={k.openCases ? 'alert' : undefined} />
            {k.refunds ? <Stat label="Refunds & credits" value={pkr(k.refunds)} /> : null}
        </StatRow>
    );
}

/**
 * D-196: recipient trace of one milk lot: back to the session and each doe's litres, QA and release, storage
 * temperatures, forward to every customer (order, invoice, paid), holds and quality cases; complaints and, for a
 * recalled lot, refund or credit of each sale.
 */
export function MilkTracePanel({ t, canQuality, onClose }: { t: TraceData; canQuality: boolean; onClose: () => void }) {
    const [act, setAct] = useState<{ d: Dispatch; mode: 'complaint' | 'settle' } | null>(null);
    return (
        <Sheet onClose={onClose}>
            <Panel title={`Trace ${t.code}`} description={`${t.status} · ${t.litres} L${t.remaining < t.litres ? ` · ${t.remaining} L on farm` : ''}${t.reason ? ` · ${t.reason}` : ''}`}>
                <div className="space-y-4 text-sm">
                    <section>
                        <h3 className="mb-1 font-semibold text-cobalt-900">From</h3>
                        {t.session ? <p className="text-ink-soft">{t.session.on} · {t.session.session} session{t.session.operator ? ` · milker ${t.session.operator}` : ''}{t.session.by ? ` · closed by ${t.session.by}` : ''}</p> : null}
                        <ul className="mt-1 flex flex-wrap gap-1.5">
                            {t.does.map((d) => <li key={d.id}><Link href={`/goats/${d.id}`} className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${d.held ? 'bg-crit-100 text-crit-700 line-through' : 'bg-glaze text-cobalt-900'}`} title={d.reason ?? undefined}>{d.number} · {d.litres} L</Link></li>)}
                        </ul>
                        <p className="mt-1 text-xs text-ink-soft">Crossed out: milk held (not in this lot).</p>
                    </section>
                    <section>
                        <h3 className="mb-1 font-semibold text-cobalt-900">Quality</h3>
                        <p className="text-ink-soft">{t.qa.by ? `Tested by ${t.qa.by} (${t.qa.at})` : 'Not tested yet'}{t.qa.residue ? ` · residue ${t.qa.residue}` : ''}{t.qa.tank ? ` · ${t.qa.tank}` : ''}{t.qa.temp !== null ? ` · ${t.qa.temp} °C` : ''}{t.qa.releasedBy ? ` · released by ${t.qa.releasedBy} (${t.qa.releasedAt})` : ''}</p>
                        {t.temps.length ? <p className="mt-1 text-xs">Storage: {t.temps.map((x) => <span key={x.at} className={x.excursion ? 'font-bold text-crit-700' : ''}>{x.c} °C ({x.at}) </span>)}</p> : null}
                    </section>
                    <section>
                        <h3 className="mb-1 font-semibold text-cobalt-900">To</h3>
                        {t.dispatches.length ? (
                            <ul className="divide-y divide-rule rounded-tile border border-rule">
                                {t.dispatches.map((d) => (
                                    <li key={d.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
                                        <span className="min-w-0 flex-1"><span className="font-semibold">{d.to}</span>{d.phone ? <span className="text-xs text-ink-soft"> · {d.phone}</span> : null}<span className="block text-xs text-ink-soft">{d.on} · {d.litres} L{d.invoice ? ` · ${d.invoice}` : ''}{d.order ? ` · ${d.order}` : ''}{d.amount !== null ? ` · ${pkr(d.amount)} ${d.paid ? 'paid' : 'owed'}` : ''}</span></span>
                                        {d.adjustment ? <Chip className="bg-cobalt-100 text-cobalt-800">{d.adjustment === 'credit' ? 'Credited' : 'Refunded'} {pkr(d.adjusted ?? 0)}{d.returned ? ` · ${d.returned} L back` : ''}</Chip> : null}
                                        {d.kind === 'sale' ? <Button variant="quiet" onClick={() => setAct({ d, mode: 'complaint' })}>Complaint</Button> : null}
                                        {t.recalled && d.amount !== null && !d.adjustment ? <Button variant="secondary" onClick={() => setAct({ d, mode: 'settle' })}>{d.paid ? 'Refund' : 'Credit'}</Button> : null}
                                    </li>
                                ))}
                            </ul>
                        ) : <p className="text-ink-soft">Not dispatched.</p>}
                    </section>
                    {t.holds.length ? (
                        <section>
                            <h3 className="mb-1 font-semibold text-cobalt-900">Holds & Quality Cases</h3>
                            <ul className="space-y-1">{t.holds.map((h, i) => <li key={i}><Chip className={h.released ? 'bg-glaze text-ink-soft' : 'bg-treat-100 text-treat-700'}>{h.kind}</Chip> <Link href="/quality?tab=cases" className="font-semibold text-cobalt-800 hover:underline">{h.case}</Link> {h.title} · {h.placed}{h.released ? ` · released ${h.released}` : ''}</li>)}</ul>
                        </section>
                    ) : null}
                    {act?.mode === 'complaint' ? <ComplaintForm d={act.d} canQuality={canQuality} onDone={() => setAct(null)} /> : null}
                    {act?.mode === 'settle' ? <SettleForm d={act.d} onDone={() => setAct(null)} /> : null}
                </div>
            </Panel>
        </Sheet>
    );
}

function ComplaintForm({ d, canQuality, onDone }: { d: Dispatch; canQuality: boolean; onDone: () => void }) {
    const form = useForm({ details: '', severity: 'medium', recall: false });
    const e = form.errors as Record<string, string>;
    const [ask, box] = useConfirm();
    return (
        <form className="space-y-2 rounded-tile bg-glaze p-3" onSubmit={async (ev) => { ev.preventDefault(); if (form.data.recall && !(await ask({ title: 'Recall this lot?', message: 'All its buyers are listed to be notified, and every sale can then be refunded or credited.', confirm: 'Yes, recall' }))) return; form.post(`/dairy/dispatches/${d.id}/complaint`, { preserveScroll: true, onSuccess: onDone }); }}>
            {box}
            <p className="font-semibold">Complaint from {d.to} ({d.invoice})</p>
            <Field label="What is the complaint?" error={e.details}><textarea className="field min-h-16" value={form.data.details} onChange={(x) => form.setData('details', x.target.value)} required /></Field>
            <Field label="How serious"><select className="field" value={form.data.severity} onChange={(x) => form.setData('severity', x.target.value)}><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option><option value="critical">Critical (health risk)</option></select></Field>
            {canQuality ? <label className="flex items-center gap-2"><input type="checkbox" className="size-5" checked={form.data.recall} onChange={(x) => form.setData('recall', x.target.checked)} /> Recall the whole lot now</label> : null}
            <div className="flex gap-2"><Button type="submit" disabled={form.processing}>Record Complaint</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}

function SettleForm({ d, onDone }: { d: Dispatch; onDone: () => void }) {
    const form = useForm({ kind: d.paid ? 'refund' : 'credit', amount: String(d.amount ?? ''), returned_l: '' });
    const e = form.errors as Record<string, string>;
    return (
        <form className="space-y-2 rounded-tile bg-glaze p-3" onSubmit={(ev) => { ev.preventDefault(); form.transform((x) => ({ ...x, amount: x.amount || null, returned_l: x.returned_l || null })); form.post(`/dairy/dispatches/${d.id}/settle`, { preserveScroll: true, onSuccess: onDone }); }}>
            <p className="font-semibold">{d.paid ? 'Refund' : 'Credit'} {d.to} for {d.invoice}</p>
            <p className="text-xs text-ink-soft">{d.paid ? 'The customer paid: the whole amount reverses the sale; a part refund is recorded as Milk Refunds.' : 'Still owed: a credit cancels the whole invoice and clears the receivable.'}</p>
            <div className="grid grid-cols-2 gap-2">
                <Field label="Amount (PKR)" error={e.amount ?? e.kind}><input type="number" step="0.01" className="field" value={form.data.amount} onChange={(x) => form.setData('amount', x.target.value)} disabled={!d.paid} /></Field>
                <Field label="Litres returned" error={e.returned_l}><input type="number" step="0.1" className="field" value={form.data.returned_l} onChange={(x) => form.setData('returned_l', x.target.value)} /></Field>
            </div>
            <div className="flex gap-2"><Button type="submit" disabled={form.processing}>{d.paid ? 'Record Refund' : 'Record Credit'}</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}
