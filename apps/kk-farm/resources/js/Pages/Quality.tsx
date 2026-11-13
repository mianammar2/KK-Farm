import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, LibIcon, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type Capa = {
    id: number; kind: string; action: string; owner: string; due: string; overdue: boolean; status: string; plannedBy: string; approvedBy: string | null;
    executedBy: string | null; notes: string | null; verifiedBy: string | null; verification: string | null; replaced: boolean;
};
type CaseHold = { id: number; kind: string; what: string; active: boolean; outcome: string | null; releasedBy: string | null; note: string | null };
type Case = {
    id: number; no: string; source: string; title: string; description: string | null; subject: string; what: string; goatId: string | null; risk: boolean; severity: string;
    status: string; rootCause: string | null; evidence: string | null; learning: string | null; by: string; opened: string; closedBy: string | null; closedAt: string | null;
    incidentId: number | null; capas: Capa[]; holds: CaseHold[]; trace: string[];
};
type Hold = { id: number; kind: string; case: string; caseId: number; what: string; by: string; at: string; caseVerified: boolean };
type Sop = {
    id: number; code: string; title: string; area: string; version: number; active: boolean; review: string | null; reviewDue: boolean;
    current: { body: string; by: string; approvedBy: string | null; on: string | null } | null;
    pending: { id: number; version: number; body: string; note: string | null; by: string } | null;
    history: Array<{ version: number; note: string | null; by: string; approvedBy: string | null; on: string | null }>;
};
type Reg = { id: number; kind: string; name: string; authority: string | null; reference: string | null; owner: string | null; notes: string | null; issued: string | null; expires: string | null; state: string };
type Drill = { id: number; scenario: string; on: string; participants: number; minutes: number | null; passed: boolean; findings: string | null; next: string | null; by: string; caseId: number | null; latest: boolean; due: boolean };
type Opt = Record<string, string>;
type Props = {
    cases: Case[]; holds: Hold[]; sops: Sop[]; register: Reg[]; drills: Drill[];
    kpi: { openCases: number; activeHolds: number; capaOnTime: number | null; overdueCapas: number; traceCompleteness: number | null; rejectRate: number | null; registerDue: number; sopReviews: number; drillsDue: number; bySource: Record<string, number> };
    pick: { goats: Array<{ id: string; farm_number: string }>; lots: Array<{ id: number; code: string; status: string }>; feeds: Array<{ id: number; name: string }>; medicines: Array<{ id: number; name: string }>; incidents: Array<{ id: number; name: string }>; users: Array<{ id: number; name: string }> };
    options: { sources: Opt; subjects: Opt; severity: Opt; status: Opt; holds: Opt; capaKinds: Opt; capaStatus: Opt; areas: Opt; registerKinds: Opt; scenarios: Opt };
    prefill: { id: number; description: string | null; severity: string; animal_id: string | null } | null;
    me: string; isLead: boolean;
};

const SEV: Record<string, string> = { low: 'bg-glaze text-ink-soft', medium: 'bg-watch-100 text-watch-700', high: 'bg-treat-100 text-treat-700', critical: 'bg-crit-100 text-crit-700' };
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const opts = (o: Opt) => Object.entries(o).map(([k, v]) => <option key={k} value={k}>{v}</option>);

/** V3 flow 21, so each case shows where it is on the path. */
const FLOW = [['open', 'Recorded'], ['contained', 'Contained'], ['capa', 'Root Cause'], ['plan', 'CAPA'], ['verifying', 'Executed'], ['verified', 'Verified'], ['closed', 'Closed']] as const;
const ORDER = ['open', 'contained', 'investigating', 'capa', 'verifying', 'reopened', 'verified', 'closed'];

/** D-189: App 21 Quality, Compliance & Safety (V3 flow 21). */
export default function Quality(p: Props) {
    const [tab, setTab] = useTabParam(p.prefill ? 'new' : 'dashboard');
    const open = p.cases.filter((c) => c.status !== 'closed');
    const pct = (n: number | null) => (n === null ? '—' : `${n}%`);
    return (
        <>
            <Head title="Quality & Compliance" />
            <PageHeader title="Quality, Compliance & Safety" description="Food and feed safety, holds and recalls, root cause and CAPA with independent verification, SOPs, the regulatory register and emergency readiness." />
            <StatRow>
                <Stat label="Open quality cases" value={p.kpi.openCases} tone={p.kpi.openCases ? 'alert' : undefined} />
                <Stat label="Active holds" value={p.kpi.activeHolds} tone={p.kpi.activeHolds ? 'alert' : undefined} note="Cannot be sold or used" />
                <Stat label="CAPA on time" value={pct(p.kpi.capaOnTime)} note={p.kpi.overdueCapas ? `${p.kpi.overdueCapas} overdue` : '90 days'} tone={p.kpi.overdueCapas ? 'alert' : undefined} />
                <Stat label="Trace completeness" value={pct(p.kpi.traceCompleteness)} note="Milk lots, 90 days" />
                <Stat label="Milk reject rate" value={p.kpi.rejectRate === null ? '—' : `${p.kpi.rejectRate}%`} note="Lots tested, 90 days" />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[
                { id: 'dashboard', label: 'Dashboard' }, { id: 'new', label: 'Raise a Quality Alarm' }, { id: 'cases', label: 'Cases & CAPA', count: open.length || undefined },
                { id: 'holds', label: 'Holds & Recall', count: p.holds.length || undefined }, { id: 'sops', label: 'SOP Library' }, { id: 'register', label: 'Regulatory Register', count: p.kpi.registerDue || undefined },
                { id: 'drills', label: 'Emergency Readiness', count: p.kpi.drillsDue || undefined }, { id: 'closed', label: 'Closed & Learning' },
            ]} />
            {tab === 'dashboard' ? <Dashboard {...p} go={setTab} /> : null}
            {tab === 'new' ? <NewCase {...p} done={() => setTab('cases')} /> : null}
            {tab === 'cases' ? <CaseList {...p} list={open} empty="No open quality cases." /> : null}
            {tab === 'closed' ? <CaseList {...p} list={p.cases.filter((c) => c.status === 'closed')} empty="No cases closed in the last 90 days." /> : null}
            {tab === 'holds' ? <Holds {...p} /> : null}
            {tab === 'sops' ? <Sops {...p} /> : null}
            {tab === 'register' ? <Register {...p} /> : null}
            {tab === 'drills' ? <Drills {...p} /> : null}
        </>
    );
}

function Dashboard(p: Props & { go: (t: string) => void }) {
    const attention = [
        p.kpi.overdueCapas ? { n: p.kpi.overdueCapas, t: 'CAPA actions overdue', tab: 'cases' } : null,
        p.cases.filter((c) => c.status === 'reopened').length ? { n: p.cases.filter((c) => c.status === 'reopened').length, t: 'cases reopened after a failed verification', tab: 'cases' } : null,
        p.kpi.activeHolds ? { n: p.kpi.activeHolds, t: 'products or animals on hold', tab: 'holds' } : null,
        p.kpi.registerDue ? { n: p.kpi.registerDue, t: 'licences or permits expiring within 30 days', tab: 'register' } : null,
        p.kpi.sopReviews ? { n: p.kpi.sopReviews, t: 'SOPs due for review', tab: 'sops' } : null,
        p.kpi.drillsDue ? { n: p.kpi.drillsDue, t: 'emergency drills due', tab: 'drills' } : null,
    ].filter(Boolean) as Array<{ n: number; t: string; tab: string }>;
    const sources = Object.entries(p.kpi.bySource).filter(([, n]) => n > 0);
    return (
        <div className="grid gap-3 lg:grid-cols-2">
            <Panel title="Needs Attention">
                {attention.length ? <ul className="divide-y divide-rule">{attention.map((a) => <li key={a.t}><button type="button" onClick={() => p.go(a.tab)} className="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm hover:bg-glaze"><strong className="text-lg text-crit-700">{a.n}</strong>{a.t}</button></li>)}</ul> : <Empty title="Nothing needs attention" />}
            </Panel>
            <Panel title="Cases by Source (90 days)">
                {sources.length ? <ul className="divide-y divide-rule">{sources.map(([k, n]) => <li key={k} className="flex justify-between px-4 py-2 text-sm"><span>{p.options.sources[k] ?? k}</span><strong>{n}</strong></li>)}</ul> : <Empty title="No quality cases yet" />}
            </Panel>
        </div>
    );
}

function NewCase({ pick, options, prefill, isLead, done }: Props & { done: () => void }) {
    const form = useForm({
        source: prefill ? 'incident' : 'milk', title: '', description: prefill?.description ?? '', subject: prefill?.animal_id ? 'animal' : 'milk_lot', severity: prefill?.severity ?? 'medium',
        animal_id: prefill?.animal_id ?? '', milk_lot_id: '', feed_item_id: '', medicine_id: '', batch: '', process: '', incident_id: prefill ? String(prefill.id) : '',
        immediate_risk: false, containment: '',
    });
    const e = form.errors as Record<string, string>;
    const s = form.data.subject;
    const holdKinds = Object.entries(options.holds).filter(([k]) => (k === 'isolate' ? s === 'animal' : k === 'recall' ? s === 'milk_lot' && isLead : true));
    return (
        <Panel title="Quality Alarm / Audit Finding" description="Record the affected animal, lot or process. If there is an immediate food or animal risk, contain it now: held items cannot be sold, fed or used.">
            <form className="grid gap-3 p-4 sm:grid-cols-3" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v]))); form.post('/quality/cases', { preserveScroll: true, onSuccess: () => { form.reset(); done(); } }); }}>
                <Field label="Source" error={e.source}><select className="field" value={form.data.source} onChange={(x) => form.setData('source', x.target.value)}>{opts(options.sources)}</select></Field>
                <Field label="What was found" error={e.title}><input className="field" value={form.data.title} onChange={(x) => form.setData('title', x.target.value)} placeholder="e.g. Residue positive on lot test" /></Field>
                <Field label="Severity" error={e.severity} hint="High and critical alert management"><select className="field" value={form.data.severity} onChange={(x) => form.setData('severity', x.target.value)}>{opts(options.severity)}</select></Field>
                <Field label="Affected" error={e.subject}><select className="field" value={s} onChange={(x) => { form.setData((d) => ({ ...d, subject: x.target.value, containment: '' })); }}>{opts(options.subjects)}</select></Field>
                {s === 'animal' ? <Field label="Goat" error={e.animal_id}><select className="field font-mono" value={form.data.animal_id} onChange={(x) => form.setData('animal_id', x.target.value)}><option value="">Choose…</option>{pick.goats.map((g) => <option key={g.id} value={g.id}>{g.farm_number}</option>)}</select></Field> : null}
                {s === 'milk_lot' ? <Field label="Milk lot" error={e.milk_lot_id}><select className="field font-mono" value={form.data.milk_lot_id} onChange={(x) => form.setData('milk_lot_id', x.target.value)}><option value="">Choose…</option>{pick.lots.map((l) => <option key={l.id} value={l.id}>{l.code} · {l.status}</option>)}</select></Field> : null}
                {s === 'feed_item' ? <Field label="Feed" error={e.feed_item_id}><select className="field" value={form.data.feed_item_id} onChange={(x) => form.setData('feed_item_id', x.target.value)}><option value="">Choose…</option>{pick.feeds.map((f) => <option key={f.id} value={f.id}>{f.name}</option>)}</select></Field> : null}
                {s === 'medicine' ? <>
                    <Field label="Medicine" error={e.medicine_id}><select className="field" value={form.data.medicine_id} onChange={(x) => form.setData('medicine_id', x.target.value)}><option value="">Choose…</option>{pick.medicines.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}</select></Field>
                    <Field label="Batch" error={e.batch} hint="Empty = every batch"><input className="field font-mono" value={form.data.batch} onChange={(x) => form.setData('batch', x.target.value)} /></Field>
                </> : null}
                {s === 'process' ? <Field label="Process" error={e.process}><input className="field" value={form.data.process} onChange={(x) => form.setData('process', x.target.value)} placeholder="e.g. Evening milking hygiene" /></Field> : null}
                <Field label="Linked incident" error={e.incident_id}><select className="field" value={form.data.incident_id} onChange={(x) => form.setData('incident_id', x.target.value)}><option value="">None</option>{pick.incidents.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}</select></Field>
                <Field label="Details" error={e.description}><input className="field" value={form.data.description} onChange={(x) => form.setData('description', x.target.value)} /></Field>
                <Field label="Immediate food / animal risk?" error={e.immediate_risk}><select className="field" value={form.data.immediate_risk ? 'yes' : 'no'} onChange={(x) => form.setData((d) => ({ ...d, immediate_risk: x.target.value === 'yes', containment: '' }))}><option value="no">No: go to root cause</option><option value="yes">Yes: contain now</option></select></Field>
                {form.data.immediate_risk ? <Field label="Containment" error={e.containment}><select className="field" value={form.data.containment} onChange={(x) => form.setData('containment', x.target.value)}><option value="">Choose…</option>{holdKinds.map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field> : null}
                <div className="flex items-end gap-2 sm:col-span-3"><Button type="submit" disabled={form.processing}><LibIcon name="app-quality" size={18} /> Record Case</Button></div>
            </form>
        </Panel>
    );
}

function CaseList(p: Props & { list: Case[]; empty: string }) {
    const [shown, more] = useFive(p.list);
    return (
        <Panel flush title={p.list[0]?.status === 'closed' ? 'Closed Cases & Shared Learning' : 'Quality Cases'}>
            {p.list.length ? <ul className="divide-y divide-rule">{shown.map((c) => <CaseItem key={c.id} c={c} {...p} />)}</ul> : <Empty title={p.empty} />}
            {more}
        </Panel>
    );
}

function CaseItem({ c, options, pick, isLead, me }: Props & { c: Case }) {
    const [mode, setMode] = useState<string | null>(null);
    const at = ORDER.indexOf(c.status);
    const reached = (key: string) => c.status === 'closed' || (key === 'open') || (key === 'contained' ? c.holds.length > 0 : key === 'capa' ? !!c.rootCause : key === 'plan' ? c.capas.length > 0 : at >= ORDER.indexOf(key) && c.status !== 'reopened');
    const live = c.capas.filter((x) => !x.replaced);
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono text-cobalt-900">{c.no}</strong>
                <span className="font-semibold">{c.title}</span>
                {c.goatId ? <Link href={`/goats/${c.goatId}`} className="font-mono text-cobalt-800 hover:underline">{c.what}</Link> : <span>{c.what}</span>}
                <Chip className={SEV[c.severity]}>{options.severity[c.severity]}</Chip>
                <Chip className={c.status === 'reopened' ? 'bg-crit-100 text-crit-700' : c.status === 'closed' ? 'bg-ok-100 text-ok-700' : 'bg-cobalt-100 text-cobalt-800'}>{options.status[c.status]}</Chip>
                <span className="text-ink-soft">{options.sources[c.source]} · {c.opened} · {c.by}</span>
            </div>
            <ol className="mt-2 flex flex-wrap items-center gap-1 text-[11px] font-semibold">
                {FLOW.filter(([k]) => k !== 'contained' || c.risk).map(([key, label], i, all) => <li key={key} className="flex items-center gap-1"><span className={`rounded-full px-2 py-0.5 ${reached(key) ? 'bg-cobalt-900 text-white' : 'bg-glaze text-ink-soft ring-1 ring-rule'}`}>{label}</span>{i < all.length - 1 ? <span className="text-ink-faint">→</span> : null}</li>)}
                {c.status === 'reopened' ? <li><span className="rounded-full bg-crit-100 px-2 py-0.5 text-crit-700">Failed: replan</span></li> : null}
            </ol>
            {c.description ? <p className="mt-1 text-ink-soft">{c.description}</p> : null}
            {c.holds.length ? <p className="mt-1 text-xs">{c.holds.map((h) => <span key={h.id} className={`mr-2 ${h.active ? 'font-semibold text-crit-700' : 'text-ink-soft'}`}>{options.holds[h.kind]}: {h.what}{h.active ? ' (active)' : ` · ${h.outcome} by ${h.releasedBy}`}</span>)}</p> : null}
            {c.rootCause ? <p className="mt-1"><span className="font-semibold">Root cause:</span> {c.rootCause} <span className="text-ink-soft">· Evidence: {c.evidence}</span></p> : null}
            {c.trace.length ? <details className="mt-1 text-xs text-ink-soft"><summary className="cursor-pointer font-semibold">Traceability ({c.trace.length})</summary><ul className="mt-1 list-disc pl-5">{c.trace.map((t, i) => <li key={i}>{t}</li>)}</ul></details> : null}
            {c.capas.length ? <ul className="mt-2 space-y-1.5">{c.capas.map((x) => <CapaRow key={x.id} x={x} options={options} isLead={isLead} me={me} caseId={c.id} pick={pick} />)}</ul> : null}
            {c.learning ? <p className="mt-1 text-ok-700">Learning ({c.closedAt}, {c.closedBy}): {c.learning}</p> : null}
            {c.status !== 'closed' ? (
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {isLead && c.subject !== 'process' ? <Button variant="secondary" onClick={() => setMode('hold')}>Hold / Isolate / Recall</Button> : null}
                    <Button variant={c.rootCause ? 'secondary' : undefined} onClick={() => setMode('investigate')}>{c.rootCause ? 'Update Root Cause' : 'Root Cause & Evidence'}</Button>
                    {c.rootCause ? <Button variant={live.length ? 'secondary' : undefined} onClick={() => setMode('capa')}>+ Design CAPA</Button> : null}
                    {isLead && (c.status === 'verified' || (!c.capas.length && c.rootCause)) ? <Button onClick={() => setMode('close')}><LibIcon name="ic-healthy" size={16} /> Close & Share Learning</Button> : null}
                </div>
            ) : null}
            {mode === 'hold' ? <HoldForm c={c} options={options} close={() => setMode(null)} /> : null}
            {mode === 'investigate' ? <InvestigateForm c={c} close={() => setMode(null)} /> : null}
            {mode === 'capa' ? <CapaForm c={c} options={options} pick={pick} close={() => setMode(null)} /> : null}
            {mode === 'close' ? <CloseForm c={c} close={() => setMode(null)} /> : null}
        </li>
    );
}

const Box = ({ children }: { children: React.ReactNode }) => <div className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-3">{children}</div>;

function HoldForm({ c, options, close }: { c: Case; options: Props['options']; close: () => void }) {
    const form = useForm({ kind: 'hold' });
    const kinds = Object.entries(options.holds).filter(([k]) => (k === 'isolate' ? c.subject === 'animal' : k === 'recall' ? c.subject === 'milk_lot' : true));
    return <Box>
        <Field label="Containment" error={form.errors.kind}><select className="field" value={form.data.kind} onChange={(x) => form.setData('kind', x.target.value)}>{kinds.map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
        <div className="flex items-end gap-2"><Button disabled={form.processing} onClick={() => form.post(`/quality/cases/${c.id}/hold`, { preserveScroll: true, onSuccess: close })}>Place</Button><Button variant="secondary" onClick={close}>Cancel</Button></div>
    </Box>;
}

function InvestigateForm({ c, close }: { c: Case; close: () => void }) {
    const form = useForm({ root_cause: c.rootCause ?? '', evidence: c.evidence ?? '' });
    return <Box>
        <Field label="Root cause" error={form.errors.root_cause}><textarea className="field" rows={2} value={form.data.root_cause} onChange={(x) => form.setData('root_cause', x.target.value)} /></Field>
        <Field label="Evidence" error={form.errors.evidence} hint="Tests, photos, records checked"><textarea className="field" rows={2} value={form.data.evidence} onChange={(x) => form.setData('evidence', x.target.value)} /></Field>
        <div className="flex items-end gap-2"><Button disabled={form.processing} onClick={() => form.post(`/quality/cases/${c.id}/investigate`, { preserveScroll: true, onSuccess: close })}>Save</Button><Button variant="secondary" onClick={close}>Cancel</Button></div>
    </Box>;
}

function CapaForm({ c, options, pick, close, replaces }: { c: Case; options: Props['options']; pick: Props['pick']; close: () => void; replaces?: Capa }) {
    const form = useForm({ kind: replaces?.kind ?? 'corrective', action: '', owner_id: '', due_on: '', replaces_id: replaces ? String(replaces.id) : '' });
    const e = form.errors as Record<string, string>;
    return <Box>
        <Field label="Type" error={e.kind}><select className="field" value={form.data.kind} onChange={(x) => form.setData('kind', x.target.value)}>{opts(options.capaKinds)}</select></Field>
        <Field label={replaces ? 'Replanned action' : 'Action'} error={e.action}><input className="field" value={form.data.action} onChange={(x) => form.setData('action', x.target.value)} /></Field>
        <Field label="Owner" error={e.owner_id}><select className="field" value={form.data.owner_id} onChange={(x) => form.setData('owner_id', x.target.value)}><option value="">Choose…</option>{pick.users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></Field>
        <Field label="Due date" error={e.due_on}><input type="date" min={today()} className="field" value={form.data.due_on} onChange={(x) => form.setData('due_on', x.target.value)} /></Field>
        <div className="flex items-end gap-2"><Button disabled={form.processing} onClick={() => { form.transform((d) => ({ ...d, replaces_id: d.replaces_id || null })); form.post(`/quality/cases/${c.id}/capa`, { preserveScroll: true, onSuccess: close }); }}>Plan CAPA</Button><Button variant="secondary" onClick={close}>Cancel</Button></div>
    </Box>;
}

function CloseForm({ c, close }: { c: Case; close: () => void }) {
    const form = useForm({ learning: '' });
    return <Box>
        <Field label="Learning to share" error={form.errors.learning} hint="What changes so it does not happen again"><textarea className="field" rows={2} value={form.data.learning} onChange={(x) => form.setData('learning', x.target.value)} /></Field>
        <div className="flex items-end gap-2"><Button disabled={form.processing} onClick={() => form.post(`/quality/cases/${c.id}/close`, { preserveScroll: true, onSuccess: close })}>Close Case</Button><Button variant="secondary" onClick={close}>Cancel</Button></div>
    </Box>;
}

function CapaRow({ x, options, isLead, me, caseId, pick }: { x: Capa; options: Props['options']; isLead: boolean; me: string; caseId: number; pick: Props['pick'] }) {
    const [mode, setMode] = useState<string | null>(null);
    const form = useForm({ notes: '', pass: true as boolean });
    const post = (url: string) => form.post(url, { preserveScroll: true, onSuccess: () => { setMode(null); form.reset(); } });
    const tone = x.status === 'passed' ? 'bg-ok-100 text-ok-700' : x.status === 'failed' ? 'bg-crit-100 text-crit-700' : x.overdue ? 'bg-treat-100 text-treat-700' : 'bg-cobalt-100 text-cobalt-800';
    return (
        <li className="rounded-tile border border-rule px-3 py-2">
            <div className="flex flex-wrap items-center gap-2">
                <Chip className="bg-glaze text-ink-soft">{options.capaKinds[x.kind]}</Chip>
                <span className={x.replaced ? 'line-through text-ink-soft' : ''}>{x.action}</span>
                <span className="text-ink-soft">· {x.owner} · due {x.due}</span>
                <Chip className={tone}>{x.overdue ? 'Overdue' : options.capaStatus[x.status]}</Chip>
            </div>
            <p className="mt-0.5 text-xs text-ink-soft">Planned by {x.plannedBy}{x.approvedBy ? ` · approved by ${x.approvedBy}` : ''}{x.executedBy ? ` · done by ${x.executedBy}: ${x.notes}` : ''}{x.verifiedBy ? ` · verified by ${x.verifiedBy}: ${x.verification}` : ''}</p>
            <div className="mt-1 flex flex-wrap gap-1.5">
                {x.status === 'planned' && isLead && x.plannedBy !== me ? <Button variant="secondary" onClick={() => post(`/quality/capas/${x.id}/approve`)}>Approve</Button> : null}
                {x.status === 'planned' && x.plannedBy === me ? <span className="text-xs text-ink-soft">Waiting for someone else to approve.</span> : null}
                {x.status === 'approved' && (x.owner === me || isLead) ? <Button variant="secondary" onClick={() => setMode('execute')}>Record Execution</Button> : null}
                {x.status === 'executed' && isLead && x.executedBy !== me ? <Button variant="secondary" onClick={() => setMode('verify')}>Verify Fix & Traceability</Button> : null}
                {x.status === 'executed' && x.executedBy === me ? <span className="text-xs text-ink-soft">Needs independent verification by someone else.</span> : null}
                {x.status === 'failed' && !x.replaced ? <Button onClick={() => setMode('replan')}>Replan</Button> : null}
            </div>
            {mode === 'execute' ? <Box>
                <Field label="What was done" error={form.errors.notes}><input className="field" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field>
                <div className="flex items-end gap-2"><Button disabled={form.processing} onClick={() => post(`/quality/capas/${x.id}/execute`)}>Save</Button><Button variant="secondary" onClick={() => setMode(null)}>Cancel</Button></div>
            </Box> : null}
            {mode === 'verify' ? <Box>
                <Field label="Result"><select className="field" value={form.data.pass ? 'pass' : 'fail'} onChange={(e) => form.setData('pass', e.target.value === 'pass')}><option value="pass">Pass: fixed and traceable</option><option value="fail">Fail: reopen / escalate</option></select></Field>
                <Field label="What was checked" error={form.errors.notes}><input className="field" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field>
                <div className="flex items-end gap-2"><Button disabled={form.processing} onClick={() => post(`/quality/capas/${x.id}/verify`)}>Save</Button><Button variant="secondary" onClick={() => setMode(null)}>Cancel</Button></div>
            </Box> : null}
            {mode === 'replan' ? <CapaForm c={{ id: caseId } as Case} options={options} pick={pick} replaces={x} close={() => setMode(null)} /> : null}
        </li>
    );
}

function Holds({ holds, isLead, options }: Props) {
    const [shown, more] = useFive(holds);
    return (
        <Panel flush title="Active Holds, Isolation & Recalls" description="Held goats cannot be sold or leave for meat, held milk lots cannot be released or dispatched, held feed cannot be fed or mixed, held medicine batches are not issued.">
            {holds.length ? <ul className="divide-y divide-rule">{shown.map((h) => <HoldRow key={h.id} h={h} isLead={isLead} options={options} />)}</ul> : <Empty title="Nothing on hold" />}
            {more}
        </Panel>
    );
}

function HoldRow({ h, isLead, options }: { h: Hold; isLead: boolean; options: Props['options'] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ outcome: h.caseVerified && h.kind !== 'recall' ? 'released' : 'disposed', note: '' });
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <Chip className="bg-crit-100 text-crit-700">{options.holds[h.kind]}</Chip>
                <strong>{h.what}</strong>
                <span className="text-ink-soft">{h.case} · since {h.at} · {h.by}</span>
                {isLead ? <Button variant="secondary" onClick={() => setOpen(!open)}>Release / Dispose</Button> : null}
            </div>
            {open ? <Box>
                <Field label="Outcome" error={form.errors.outcome} hint={h.caseVerified ? undefined : 'Release needs the fix verified first'}><select className="field" value={form.data.outcome} onChange={(x) => form.setData('outcome', x.target.value)}>{h.kind !== 'recall' ? <option value="released">Release (authorised)</option> : null}<option value="disposed">Disposed</option></select></Field>
                <Field label="Note" error={form.errors.note}><input className="field" value={form.data.note} onChange={(x) => form.setData('note', x.target.value)} /></Field>
                <div className="flex items-end gap-2"><Button disabled={form.processing} onClick={() => form.post(`/quality/holds/${h.id}/release`, { preserveScroll: true, onSuccess: () => setOpen(false) })}>Save</Button></div>
            </Box> : null}
        </li>
    );
}

function Sops({ sops, options, isLead, me }: Props) {
    const [adding, setAdding] = useState(false);
    const form = useForm({ code: '', title: '', area: 'milking', body: '', review_on: '' });
    const e = form.errors as Record<string, string>;
    return (
        <div className="space-y-3">
            {adding ? <Panel title="New SOP" description="Version 1 is in force once someone other than the writer approves it.">
                <form className="grid gap-3 p-4 sm:grid-cols-3" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, review_on: d.review_on || null })); form.post('/quality/sops', { preserveScroll: true, onSuccess: () => { setAdding(false); form.reset(); } }); }}>
                    <Field label="Code" error={e.code}><input className="field font-mono" value={form.data.code} onChange={(x) => form.setData('code', x.target.value)} placeholder="SOP-MLK-01" /></Field>
                    <Field label="Title" error={e.title}><input className="field" value={form.data.title} onChange={(x) => form.setData('title', x.target.value)} /></Field>
                    <Field label="Area" error={e.area}><select className="field" value={form.data.area} onChange={(x) => form.setData('area', x.target.value)}>{opts(options.areas)}</select></Field>
                    <div className="sm:col-span-3"><Field label="Procedure" error={e.body}><textarea className="field" rows={6} value={form.data.body} onChange={(x) => form.setData('body', x.target.value)} /></Field></div>
                    <Field label="Review by" error={e.review_on} hint="Default: one year"><input type="date" className="field" value={form.data.review_on} onChange={(x) => form.setData('review_on', x.target.value)} /></Field>
                    <div className="flex items-end gap-2"><Button type="submit" disabled={form.processing}>Save Draft</Button><Button type="button" variant="secondary" onClick={() => setAdding(false)}>Cancel</Button></div>
                </form>
            </Panel> : null}
            <Panel flush title="SOP Library" actions={isLead ? <Button onClick={() => setAdding(true)}>+ New SOP</Button> : undefined}>
                {sops.length ? <ul className="divide-y divide-rule">{sops.map((s) => <SopRow key={s.id} s={s} options={options} isLead={isLead} me={me} />)}</ul> : <Empty title="No SOPs yet" />}
            </Panel>
        </div>
    );
}

function SopRow({ s, options, isLead, me }: { s: Sop; options: Props['options']; isLead: boolean; me: string }) {
    const [mode, setMode] = useState<string | null>(null);
    const form = useForm({ body: s.current?.body ?? '', change_note: '' });
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono text-cobalt-900">{s.code}</strong><span className="font-semibold">{s.title}</span>
                <Chip className="bg-glaze text-ink-soft">{options.areas[s.area]}</Chip>
                {s.version ? <Chip className="bg-ok-100 text-ok-700">v{s.version} in force</Chip> : <Chip className="bg-watch-100 text-watch-700">Draft</Chip>}
                {s.reviewDue ? <Chip className="bg-treat-100 text-treat-700">Review due</Chip> : s.review ? <span className="text-ink-soft">review by {s.review}</span> : null}
                <Button variant="secondary" onClick={() => setMode(mode === 'read' ? null : 'read')}>{mode === 'read' ? 'Hide' : 'Read'}</Button>
                {isLead && !s.pending ? <Button variant="secondary" onClick={() => setMode('revise')}>Revise</Button> : null}
            </div>
            {s.pending ? <div className="mt-2 rounded-tile bg-watch-100/40 p-3">
                <p className="text-xs font-semibold">Version {s.pending.version} waiting for approval · by {s.pending.by}{s.pending.note ? ` · ${s.pending.note}` : ''}</p>
                <pre className="mt-1 whitespace-pre-wrap font-sans text-xs">{s.pending.body}</pre>
                {isLead && s.pending.by !== me ? <Button className="mt-2" onClick={() => form.post(`/quality/sop-revisions/${s.pending!.id}/approve`, { preserveScroll: true })}>Approve Version {s.pending.version}</Button> : <p className="mt-1 text-xs text-ink-soft">Someone other than the writer approves it.</p>}
            </div> : null}
            {mode === 'read' && s.current ? <div className="mt-2">
                <pre className="whitespace-pre-wrap rounded-tile bg-glaze p-3 font-sans text-xs">{s.current.body}</pre>
                <ul className="mt-1 text-xs text-ink-soft">{s.history.map((h) => <li key={h.version}>v{h.version} · {h.on} · {h.note ?? ''} · written by {h.by}, approved by {h.approvedBy}</li>)}</ul>
            </div> : null}
            {mode === 'revise' ? <Box>
                <div className="sm:col-span-3"><Field label="Revised procedure" error={form.errors.body}><textarea className="field" rows={6} value={form.data.body} onChange={(x) => form.setData('body', x.target.value)} /></Field></div>
                <Field label="What changed and why" error={form.errors.change_note}><input className="field" value={form.data.change_note} onChange={(x) => form.setData('change_note', x.target.value)} /></Field>
                <div className="flex items-end gap-2"><Button disabled={form.processing} onClick={() => form.post(`/quality/sops/${s.id}/revise`, { preserveScroll: true, onSuccess: () => setMode(null) })}>Save Revision</Button><Button variant="secondary" onClick={() => setMode(null)}>Cancel</Button></div>
            </Box> : null}
        </li>
    );
}

function Register({ register, options, isLead }: Props) {
    const [edit, setEdit] = useState<Reg | 'new' | null>(null);
    const STATE: Record<string, [string, string]> = { expired: ['Expired', 'bg-crit-100 text-crit-700'], due: ['Renew soon', 'bg-treat-100 text-treat-700'], ok: ['Valid', 'bg-ok-100 text-ok-700'] };
    return (
        <div className="space-y-3">
            {edit ? <RegForm item={edit === 'new' ? null : edit} options={options} close={() => setEdit(null)} /> : null}
            <Panel flush title="Regulatory Register" description="Licences, permits, certificates and inspections with their expiry. Anything expiring within 30 days shows on the dashboard." actions={isLead ? <Button onClick={() => setEdit('new')}>+ Add</Button> : undefined}>
                {register.length ? <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Item</th><th>Type</th><th>Authority</th><th>Reference</th><th>Expires</th><th>Status</th><th>Owner</th><th /></tr></thead><tbody>
                    {register.map((r) => <tr key={r.id}><td className="font-semibold">{r.name}</td><td>{options.registerKinds[r.kind]}</td><td>{r.authority ?? '—'}</td><td className="font-mono">{r.reference ?? '—'}</td><td>{r.expires ?? '—'}</td><td><Chip className={STATE[r.state][1]}>{STATE[r.state][0]}</Chip></td><td>{r.owner ?? '—'}</td><td>{isLead ? <button type="button" className="text-cobalt-800 hover:underline" onClick={() => setEdit(r)}>Renew / Edit</button> : null}</td></tr>)}
                </tbody></table></div> : <Empty title="Nothing in the register yet" />}
            </Panel>
        </div>
    );
}

function RegForm({ item, options, close }: { item: Reg | null; options: Props['options']; close: () => void }) {
    const form = useForm({ kind: item?.kind ?? 'licence', name: item?.name ?? '', authority: item?.authority ?? '', reference: item?.reference ?? '', issued_on: item?.issued ?? '', expires_on: item?.expires ?? '', owner: item?.owner ?? '', notes: item?.notes ?? '', active: true });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title={item ? `Renew / Edit: ${item.name}` : 'Add to the Register'}>
            <form className="grid gap-3 p-4 sm:grid-cols-3" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v]))); form.post(item ? `/quality/register/${item.id}` : '/quality/register', { preserveScroll: true, onSuccess: close }); }}>
                <Field label="Type" error={e.kind}><select className="field" value={form.data.kind} onChange={(x) => form.setData('kind', x.target.value)}>{opts(options.registerKinds)}</select></Field>
                <Field label="Name" error={e.name}><input className="field" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} placeholder="e.g. Livestock Dept registration" /></Field>
                <Field label="Authority" error={e.authority}><input className="field" value={form.data.authority} onChange={(x) => form.setData('authority', x.target.value)} /></Field>
                <Field label="Reference no." error={e.reference}><input className="field font-mono" value={form.data.reference} onChange={(x) => form.setData('reference', x.target.value)} /></Field>
                <Field label="Issued" error={e.issued_on}><input type="date" className="field" value={form.data.issued_on} onChange={(x) => form.setData('issued_on', x.target.value)} /></Field>
                <Field label="Expires" error={e.expires_on}><input type="date" className="field" value={form.data.expires_on} onChange={(x) => form.setData('expires_on', x.target.value)} /></Field>
                <Field label="Owner" error={e.owner}><input className="field" value={form.data.owner} onChange={(x) => form.setData('owner', x.target.value)} /></Field>
                <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                <div className="flex items-end gap-2">
                    <Button type="submit" disabled={form.processing}>Save</Button><Button type="button" variant="secondary" onClick={close}>Cancel</Button>
                    {item ? <Button type="button" variant="secondary" onClick={() => { form.transform((d) => ({ ...d, active: false })); form.post(`/quality/register/${item.id}`, { preserveScroll: true, onSuccess: close }); }}>Retire</Button> : null}
                </div>
            </form>
        </Panel>
    );
}

function Drills({ drills, options, isLead }: Props) {
    const [adding, setAdding] = useState(false);
    const form = useForm({ scenario: 'fire', held_on: today(), participants: '', response_minutes: '', passed: true as boolean, findings: '', next_due_on: '' });
    const e = form.errors as Record<string, string>;
    const [shown, more] = useFive(drills);
    return (
        <div className="space-y-3">
            {adding ? <Panel title="Record an Emergency Drill" description="A failed drill opens a quality case for the root cause and CAPA.">
                <form className="grid gap-3 p-4 sm:grid-cols-3" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => Object.fromEntries(Object.entries(d).map(([k, v]) => [k, v === '' ? null : v]))); form.post('/quality/drills', { preserveScroll: true, onSuccess: () => { setAdding(false); form.reset(); } }); }}>
                    <Field label="Scenario" error={e.scenario}><select className="field" value={form.data.scenario} onChange={(x) => form.setData('scenario', x.target.value)}>{opts(options.scenarios)}</select></Field>
                    <Field label="Held on" error={e.held_on}><input type="date" max={today()} className="field" value={form.data.held_on} onChange={(x) => form.setData('held_on', x.target.value)} /></Field>
                    <Field label="People taking part" error={e.participants}><input type="number" min={1} className="field" value={form.data.participants} onChange={(x) => form.setData('participants', x.target.value)} /></Field>
                    <Field label="Response time (minutes)" error={e.response_minutes}><input type="number" min={0} className="field" value={form.data.response_minutes} onChange={(x) => form.setData('response_minutes', x.target.value)} /></Field>
                    <Field label="Result" error={e.passed}><select className="field" value={form.data.passed ? 'pass' : 'fail'} onChange={(x) => form.setData('passed', x.target.value === 'pass')}><option value="pass">Passed</option><option value="fail">Failed</option></select></Field>
                    <Field label="Findings" error={e.findings}><input className="field" value={form.data.findings} onChange={(x) => form.setData('findings', x.target.value)} /></Field>
                    <Field label="Next drill" error={e.next_due_on} hint="Default: in 6 months"><input type="date" className="field" value={form.data.next_due_on} onChange={(x) => form.setData('next_due_on', x.target.value)} /></Field>
                    <div className="flex items-end gap-2"><Button type="submit" disabled={form.processing}>Save Drill</Button><Button type="button" variant="secondary" onClick={() => setAdding(false)}>Cancel</Button></div>
                </form>
            </Panel> : null}
            <Panel flush title="Emergency Readiness" actions={isLead ? <Button onClick={() => setAdding(true)}>+ Record Drill</Button> : undefined}>
                {drills.length ? <ul className="divide-y divide-rule">{shown.map((d) => <li key={d.id} className="flex flex-wrap items-center gap-2 px-4 py-2.5 text-sm">
                    <strong>{options.scenarios[d.scenario]}</strong><span className="text-ink-soft">{d.on} · {d.participants} people{d.minutes !== null ? ` · ${d.minutes} min` : ''} · {d.by}</span>
                    <Chip className={d.passed ? 'bg-ok-100 text-ok-700' : 'bg-crit-100 text-crit-700'}>{d.passed ? 'Passed' : 'Failed'}</Chip>
                    {d.latest && d.next ? <Chip className={d.due ? 'bg-treat-100 text-treat-700' : 'bg-glaze text-ink-soft'}>{d.due ? 'Drill due' : `Next ${d.next}`}</Chip> : null}
                    {d.findings ? <span className="w-full text-xs text-ink-soft">{d.findings}</span> : null}
                </li>)}</ul> : <Empty title="No drills recorded yet" />}
                {more}
            </Panel>
        </div>
    );
}
