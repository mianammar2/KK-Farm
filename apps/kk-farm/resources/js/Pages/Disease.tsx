import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, LibIcon, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type Concern = { id: number; goatId: string; number: string; pen: string | null; title: string; notes: string | null; severity: string | null; by: string; on: string | null };
type CaseRow = {
    id: number; no: string; goatId: string; number: string | null; pen: string | null; condition: string; severity: string; contagion: string; emergency: boolean; isolated: boolean; status: string;
    onset: string; days: number; review: string | null; reviewDue: boolean; diagnosis: string | null; restrictedPen: string | null; outbreak: string | null;
    lab: { test: string; on: string | null; result: string | null } | null; treatments: Array<{ on: string; product: string; dose: string; meat: string | null; milk: string | null }>;
    cost: { medicine: number; lab: number; labour: number; total: number };
};
type OutbreakRow = { id: number; code: string; condition: string; status: string; opened: string; closed: string | null; cases: number; active: number; recovered: number; deaths: number; population: number; attackRate: number | null; cost: number; goats: string[]; report: string | null; by: string; closedBy: string | null };
type Opt = Record<string, string>;
type Props = {
    concerns: Concern[]; cases: CaseRow[]; restricted: Array<{ pen: string | null; cases: string; goats: number }>; outbreaks: OutbreakRow[];
    kpi: { active: number; emergencies: number; restrictedPens: number; recoveryDays: number | null; attackRate: number; caseCost: number | null; releaseLag: number | null; deaths: number };
    diseases: Array<{ name: string; contagious: boolean; notifiable: boolean; zoonotic: boolean }>; conditions: string[];
    pens: Array<{ id: number; name: string; isolation: boolean }>; options: { severity: Opt; contagion: Opt; status: Opt }; labourRate: number; isVet: boolean;
};

const pkr = (n: number | null) => (n === null ? '—' : `PKR ${Math.round(n).toLocaleString()}`);
const plus = (d: number) => new Date(Date.now() + d * 864e5 - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const today = () => plus(0);
const SEV: Record<string, string> = { low: 'bg-glaze text-ink-soft', moderate: 'bg-watch-100 text-watch-700', high: 'bg-treat-100 text-treat-700', emergency: 'bg-crit-100 text-crit-700' };
const FLOW = ['Report', 'Triage', 'Isolate / alert', 'Vet case & lab', 'Treatment protocol', 'Medicine lot given', 'Withdrawal / restriction', 'Follow-up', 'Vet clears', 'Cost', 'Close & outbreak report'];

/** D-193: V3 cross-app journey D, Disease-to-Recovery (flow 27). */
export default function Disease(p: Props) {
    const [tab, setTab] = useTabParam(p.concerns.length ? 'triage' : 'cases');
    return (
        <>
            <Head title="Disease Control" />
            <PageHeader title="Disease Control & Recovery" description="Triage reported symptoms, isolate and alert for contagious risk, keep affected pens still until the vet clears them, follow cases to recovery with their true cost, and report outbreaks." />
            <ol className="mb-3 flex flex-wrap items-center gap-1 text-[11px] font-semibold">{FLOW.map((s, i) => <li key={s} className="flex items-center gap-1"><span className="rounded-full bg-glaze px-2 py-0.5 text-ink-soft ring-1 ring-rule">{s}</span>{i < FLOW.length - 1 ? <span className="text-ink-faint">→</span> : null}</li>)}</ol>
            <StatRow>
                <Stat label="Active cases" value={p.kpi.active} note={p.kpi.emergencies ? `${p.kpi.emergencies} emergencies` : undefined} tone={p.kpi.emergencies ? 'alert' : undefined} />
                <Stat label="Restricted pens" value={p.kpi.restrictedPens} tone={p.kpi.restrictedPens ? 'alert' : undefined} note="No moves or sales" />
                <Stat label="Recovery time" value={p.kpi.recoveryDays === null ? '—' : `${p.kpi.recoveryDays} days`} note="90 days" />
                <Stat label="Attack rate" value={`${p.kpi.attackRate}%`} note="Goats with a case, 90 days" />
                <Stat label="Average case cost" value={pkr(p.kpi.caseCost)} note="Medicine + lab + labour" />
                <Stat label="Quarantine release lag" value={p.kpi.releaseLag === null ? '—' : `${p.kpi.releaseLag} days`} note="Intake, past the set days" />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[
                { id: 'triage', label: 'Triage Reports', count: p.concerns.length || undefined }, { id: 'cases', label: 'Active Cases', count: p.cases.length || undefined },
                { id: 'restricted', label: 'Restricted Pens', count: p.restricted.length || undefined }, { id: 'outbreaks', label: 'Outbreaks', count: p.outbreaks.filter((o) => o.status === 'open').length || undefined },
                { id: 'settings', label: 'Cost Settings' },
            ]} />
            {tab === 'triage' ? <Triage {...p} /> : null}
            {tab === 'cases' ? <Cases {...p} /> : null}
            {tab === 'restricted' ? <Restricted {...p} /> : null}
            {tab === 'outbreaks' ? <Outbreaks {...p} /> : null}
            {tab === 'settings' ? <Settings {...p} /> : null}
        </>
    );
}

function Triage(p: Props) {
    const [open, setOpen] = useState<number | null>(null);
    const [shown, more] = useFive(p.concerns, 10);
    return (
        <Panel flush title="Reported Symptoms to Triage" description="Workers report from the goat page or the scan screen. The vet triages severity and contagion risk; high risk isolates the goat and raises an emergency alert.">
            {p.concerns.length ? <ul className="divide-y divide-rule">{shown.map((c) => <li key={c.id} className="px-4 py-3 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                    <Link href={`/goats/${c.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{c.number}</Link>
                    <span className="font-semibold">{c.title}</span><span className="text-ink-soft">{c.pen} · {c.on} · {c.by}</span>
                    {p.isVet ? <Button className="ml-auto" onClick={() => setOpen(open === c.id ? null : c.id)}>Triage</Button> : null}
                </div>
                {c.notes ? <p className="text-ink-soft">{c.notes}</p> : null}
                {open === c.id ? <TriageForm c={c} {...p} close={() => setOpen(null)} /> : null}
            </li>)}</ul> : <Empty title="No reports waiting">New cases can also be opened on the Health page.</Empty>}
            {more}
        </Panel>
    );
}

function TriageForm({ c, diseases, conditions, pens, options, close }: Props & { c: Concern; close: () => void }) {
    const form = useForm({ animal_id: c.goatId, report_id: String(c.id), condition: '', symptoms: c.notes ?? c.title, severity: c.severity === 'high' ? 'high' : 'moderate', contagion: 'none', isolate_pen_id: '', restrict_pen: false, declare_outbreak: false });
    const e = form.errors as Record<string, string>;
    const known = diseases.find((d) => d.name === form.data.condition);
    const risky = form.data.contagion !== 'none' || ['high', 'emergency'].includes(form.data.severity);
    return (
        <div className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-3">
            <Field label="Condition (suspected)" error={e.condition}><input className="field" list="disease-list" value={form.data.condition} onChange={(x) => { const v = x.target.value; const d = diseases.find((q) => q.name === v); form.setData((f) => ({ ...f, condition: v, contagion: d?.contagious && f.contagion === 'none' ? 'suspected' : f.contagion, restrict_pen: d?.contagious ? true : f.restrict_pen })); }} />
                <datalist id="disease-list">{[...diseases.map((d) => d.name), ...conditions].filter((v, i, a) => a.indexOf(v) === i).map((n) => <option key={n} value={n} />)}</datalist></Field>
            <Field label="Severity" error={e.severity}><select className="field" value={form.data.severity} onChange={(x) => form.setData('severity', x.target.value)}>{Object.entries(options.severity).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            <Field label="Contagion risk" error={e.contagion} hint={known?.notifiable ? 'Notifiable: a reporting task is created' : undefined}><select className="field" value={form.data.contagion} onChange={(x) => form.setData((f) => ({ ...f, contagion: x.target.value, restrict_pen: x.target.value !== 'none' ? true : f.restrict_pen }))}>{Object.entries(options.contagion).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            <Field label="Isolate now in" error={e.isolate_pen_id ?? e.to_pen_id}><select className="field" value={form.data.isolate_pen_id} onChange={(x) => form.setData('isolate_pen_id', x.target.value)}><option value="">{risky ? 'Choose an isolation pen…' : 'No isolation'}</option>{pens.map((pn) => <option key={pn.id} value={pn.id}>{pn.name}{pn.isolation ? ' (isolation)' : ''}</option>)}</select></Field>
            <Field label="Symptoms" error={e.symptoms}><input className="field" value={form.data.symptoms} onChange={(x) => form.setData('symptoms', x.target.value)} /></Field>
            <div className="flex flex-col justify-end gap-1 text-sm">
                <label className="flex items-center gap-2"><input type="checkbox" checked={form.data.restrict_pen} onChange={(x) => form.setData('restrict_pen', x.target.checked)} /> Restrict {c.pen ?? 'the pen'} (no moves or sales)</label>
                {form.data.contagion !== 'none' ? <label className="flex items-center gap-2"><input type="checkbox" checked={form.data.declare_outbreak} onChange={(x) => form.setData('declare_outbreak', x.target.checked)} /> Declare an outbreak</label> : null}
            </div>
            <div className="flex items-end gap-2 sm:col-span-3"><Button disabled={form.processing} onClick={() => { form.transform((d) => ({ ...d, isolate_pen_id: d.isolate_pen_id || null })); form.post('/disease/triage', { preserveScroll: true, onSuccess: close }); }}><LibIcon name="app-veterinary" size={16} /> {risky ? 'Open Case, Isolate & Alert' : 'Open Veterinary Case'}</Button><Button variant="secondary" onClick={close}>Cancel</Button></div>
        </div>
    );
}

function Cases(p: Props) {
    const [shown, more] = useFive(p.cases, 10);
    return (
        <Panel flush title="Active Cases" description="Treatment and withdrawal are recorded on the case in Health (Medical Cases), from the medicine store. Here: investigations, follow-up, clearance and cost.">
            {p.cases.length ? <ul className="divide-y divide-rule">{shown.map((c) => <CaseItem key={c.id} c={c} {...p} />)}</ul> : <Empty title="No active cases" />}
            {more}
        </Panel>
    );
}

function CaseItem({ c, pens, options, isVet }: Props & { c: CaseRow }) {
    const [mode, setMode] = useState<string | null>(null);
    const lab = useForm({ lab_test: c.lab?.test ?? '', lab_sampled_on: today(), lab_result: c.lab?.result ?? '', lab_cost: '', contagion: c.contagion });
    const ext = useForm({ review_on: plus(3), notes: '' });
    const clr = useForm({ to_pen_id: '', labour_hours: '', notes: '' });
    const done = () => setMode(null);
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono text-cobalt-900">{c.no}</strong>
                <Link href={`/goats/${c.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{c.number}</Link>
                <span className="font-semibold">{c.condition}</span>
                <Chip className={SEV[c.severity]}>{options.severity[c.severity]}</Chip>
                {c.contagion !== 'none' ? <Chip className="bg-crit-100 text-crit-700">{options.contagion[c.contagion]}</Chip> : null}
                {c.emergency ? <Chip className="bg-crit-100 text-crit-700">Emergency</Chip> : null}
                {c.isolated ? <Chip className="bg-treat-100 text-treat-700">Isolated</Chip> : null}
                {c.outbreak ? <Chip className="bg-watch-100 text-watch-700">{c.outbreak}</Chip> : null}
                <span className="text-ink-soft">{options.status[c.status]} · day {c.days} · {c.pen}</span>
                {c.review ? <Chip className={c.reviewDue ? 'bg-treat-100 text-treat-700' : 'bg-glaze text-ink-soft'}>Reassess {c.review}</Chip> : null}
            </div>
            {c.restrictedPen ? <p className="mt-1 text-crit-700">Movement restriction on {c.restrictedPen} until cleared.</p> : null}
            {c.treatments.length ? <p className="mt-1 text-xs text-ink-soft">{c.treatments.map((t) => `${t.on} ${t.product} ${t.dose}${t.meat ? ` · meat clear ${t.meat}` : ''}${t.milk ? ` · milk clear ${t.milk}` : ''}`).join(' | ')}</p> : <p className="mt-1 text-xs text-ink-soft">No treatment recorded yet: give it from the medicine store on the Health page.</p>}
            {c.lab ? <p className="mt-1 text-xs">Lab: {c.lab.test} ({c.lab.on}){c.lab.result ? ` → ${c.lab.result}` : ' · waiting for result'}</p> : null}
            <p className="mt-1 text-xs">Cost so far: medicine {pkr(c.cost.medicine)} · lab {pkr(c.cost.lab)} · labour {pkr(c.cost.labour)} = <strong>{pkr(c.cost.total)}</strong></p>
            {isVet ? <div className="mt-2 flex flex-wrap gap-1.5">
                <Link href={`/health?tab=cases`} className="self-center text-xs font-semibold text-cobalt-800 hover:underline">Treat (Health) →</Link>
                <Button variant="secondary" onClick={() => setMode('lab')}>Lab Investigation</Button>
                <Button variant="secondary" onClick={() => setMode('extend')}>Not Recovered: Extend</Button>
                <Button onClick={() => setMode('clear')}><LibIcon name="ic-healthy" size={16} /> Recovered: Clear</Button>
                <Link href={`/goats/${c.goatId}`} className="self-center text-xs text-ink-soft hover:underline">Died? Record the exit on the goat page</Link>
            </div> : null}
            {mode === 'lab' ? <div className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-3">
                <Field label="Test" error={lab.errors.lab_test}><input className="field" value={lab.data.lab_test} onChange={(x) => lab.setData('lab_test', x.target.value)} placeholder="e.g. Faecal egg count, PPR PCR" /></Field>
                <Field label="Sampled on" error={lab.errors.lab_sampled_on}><input type="date" max={today()} className="field" value={lab.data.lab_sampled_on} onChange={(x) => lab.setData('lab_sampled_on', x.target.value)} /></Field>
                <Field label="Lab fee (PKR)" error={lab.errors.lab_cost} hint="Posted once to Veterinary services"><input type="number" className="field" value={lab.data.lab_cost} onChange={(x) => lab.setData('lab_cost', x.target.value)} /></Field>
                <Field label="Result" error={lab.errors.lab_result}><input className="field" value={lab.data.lab_result} onChange={(x) => lab.setData('lab_result', x.target.value)} /></Field>
                <Field label="Contagion" error={lab.errors.contagion}><select className="field" value={lab.data.contagion} onChange={(x) => lab.setData('contagion', x.target.value)}>{Object.entries(options.contagion).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <div className="flex items-end gap-2"><Button disabled={lab.processing} onClick={() => { lab.transform((d) => ({ ...d, lab_cost: d.lab_cost || null, lab_result: d.lab_result || null })); lab.post(`/disease/cases/${c.id}/lab`, { preserveScroll: true, onSuccess: done }); }}>Save</Button><Button variant="secondary" onClick={done}>Cancel</Button></div>
            </div> : null}
            {mode === 'extend' ? <div className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-3">
                <Field label="Reassess on" error={ext.errors.review_on}><input type="date" className="field" value={ext.data.review_on} onChange={(x) => ext.setData('review_on', x.target.value)} /></Field>
                <Field label="What changes" error={ext.errors.notes}><input className="field" value={ext.data.notes} onChange={(x) => ext.setData('notes', x.target.value)} placeholder="Treatment, dose, protocol" /></Field>
                <div className="flex items-end gap-2"><Button disabled={ext.processing} onClick={() => ext.post(`/disease/cases/${c.id}/extend`, { preserveScroll: true, onSuccess: done })}>Save</Button><Button variant="secondary" onClick={done}>Cancel</Button></div>
            </div> : null}
            {mode === 'clear' ? <div className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-4">
                <Field label="Move to suitable housing" error={clr.errors.to_pen_id ?? (clr.errors as Record<string, string>).to_pen_id}><select className="field" value={clr.data.to_pen_id} onChange={(x) => clr.setData('to_pen_id', x.target.value)}><option value="">Stay in {c.pen ?? 'current pen'}</option>{pens.filter((pn) => !pn.isolation).map((pn) => <option key={pn.id} value={pn.id}>{pn.name}</option>)}</select></Field>
                <Field label="Labour hours on the case" error={clr.errors.labour_hours}><input type="number" step="0.5" className="field" value={clr.data.labour_hours} onChange={(x) => clr.setData('labour_hours', x.target.value)} /></Field>
                <Field label="Notes" error={clr.errors.notes}><input className="field" value={clr.data.notes} onChange={(x) => clr.setData('notes', x.target.value)} /></Field>
                <div className="flex items-end gap-2"><Button disabled={clr.processing} onClick={() => { clr.transform((d) => ({ ...d, to_pen_id: d.to_pen_id || null, labour_hours: d.labour_hours || null })); clr.post(`/disease/cases/${c.id}/clear`, { preserveScroll: true, onSuccess: done }); }}>Clear</Button><Button variant="secondary" onClick={done}>Cancel</Button></div>
            </div> : null}
        </li>
    );
}

function Restricted({ restricted }: Props) {
    return (
        <Panel flush title="Pens under Movement Restriction" description="No goat moves in or out and none is sold or sent for meat until the vet clears the case. Only the vet moves goats of these pens (for example into isolation).">
            {restricted.length ? <ul className="divide-y divide-rule">{restricted.map((r) => <li key={r.pen} className="flex flex-wrap items-center gap-2 px-4 py-2.5 text-sm"><Chip className="bg-crit-100 text-crit-700">Restricted</Chip><strong>{r.pen}</strong><span className="text-ink-soft">{r.goats} goats · {r.cases}</span></li>)}</ul> : <Empty title="No pen is restricted" />}
        </Panel>
    );
}

function Outbreaks({ outbreaks, isVet }: Props) {
    return (
        <Panel flush title="Outbreaks" description="Declared at triage for a contagious condition; later cases of the same condition join it. Close it with the outbreak report when every case is resolved.">
            {outbreaks.length ? <ul className="divide-y divide-rule">{outbreaks.map((o) => <OutbreakItem key={o.id} o={o} isVet={isVet} />)}</ul> : <Empty title="No outbreaks" />}
        </Panel>
    );
}

function OutbreakItem({ o, isVet }: { o: OutbreakRow; isVet: boolean }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ report: '' });
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono text-cobalt-900">{o.code}</strong><span className="font-semibold">{o.condition}</span>
                <Chip className={o.status === 'open' ? 'bg-crit-100 text-crit-700' : 'bg-ok-100 text-ok-700'}>{o.status === 'open' ? 'Open' : `Closed ${o.closed}`}</Chip>
                <span className="text-ink-soft">since {o.opened} · {o.cases} cases ({o.active} active, {o.recovered} recovered, {o.deaths} died){o.attackRate !== null ? ` · attack rate ${o.attackRate}% of ${o.population}` : ''} · cost {pkr(o.cost)}</span>
                {isVet && o.status === 'open' ? <Button variant="secondary" className="ml-auto" onClick={() => setOpen(!open)}>Close with Report</Button> : null}
            </div>
            <p className="mt-1 text-xs text-ink-soft">Goats: {o.goats.join(', ') || '—'}</p>
            {o.report ? <p className="mt-1 whitespace-pre-wrap">{o.report}</p> : null}
            {open ? <div className="mt-2 space-y-2 rounded-tile bg-glaze p-3">
                <Field label="Outbreak report" error={form.errors.report} hint="Source, spread, control measures, losses and lessons"><textarea className="field" rows={4} value={form.data.report} onChange={(x) => form.setData('report', x.target.value)} /></Field>
                <Button disabled={form.processing} onClick={() => form.post(`/disease/outbreaks/${o.id}/close`, { preserveScroll: true, onSuccess: () => setOpen(false) })}>Close Outbreak</Button>
            </div> : null}
        </li>
    );
}

function Settings({ labourRate }: Props) {
    const form = useForm({ labour_rate: String(labourRate) });
    return (
        <Panel title="Case Cost" description="A case costs the medicine given (from the store), the lab fees and the labour hours at this rate.">
            <div className="flex items-end gap-2 p-4"><Field label="Labour rate (PKR per hour)" error={form.errors.labour_rate}><input type="number" className="field w-40" value={form.data.labour_rate} onChange={(x) => form.setData('labour_rate', x.target.value)} /></Field><Button disabled={form.processing} onClick={() => form.post('/disease/settings', { preserveScroll: true })}>Save</Button></div>
        </Panel>
    );
}
