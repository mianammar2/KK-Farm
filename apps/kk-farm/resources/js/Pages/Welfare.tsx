import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, LibIcon, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type Case = {
    id: number; no: string; goat: { id: string; number: string } | null; pen: string | null; opened: string; issue: string; severity: string; triage: string | null; status: string; by: string;
    referred: boolean; closedBy: string | null; closedAt: string | null; evidence: string | null; canClose: boolean;
    steps: Array<{ kind: string; notes: string | null; improved: boolean | null; by: string; on: string; assignee: string | null; due: string | null }>;
    healthCase: { label: string; condition: string; status: string; active: boolean } | null; repeatOf: string | null; restricted: boolean; handlingClearedBy: string | null; held: number;
};
type Check = { id: number; on: string; goat: string | null; pen: string | null; bcs: number | null; behaviour: string; injury: string; lameness: number; pain: boolean; caseId: number | null; by: string };
type Props = {
    cases: Case[]; checks: Check[]; due: number; kpi: { checks: number; lamenessRate: number | null; unresolved: number; closeDays: number | null; recurrence: number | null; lameness: number; awaitingVet: number; held: number };
    users: Array<{ id: number; name: string }>;
    goats: Array<{ id: string; farm_number: string }>; pens: Array<{ id: number; name: string }>;
    options: { issues: Record<string, string>; severity: Record<string, string>; status: Record<string, string>; behaviour: Record<string, string>; injury: Record<string, string>; lameness: Record<string, string>; actions: Record<string, string> };
    isVet: boolean;
};

const SEV: Record<string, string> = { low: 'bg-glaze text-ink-soft', medium: 'bg-watch-100 text-watch-700', high: 'bg-treat-100 text-treat-700', critical: 'bg-crit-100 text-crit-700' };
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

/** The V3 flow 19 path, so every case shows where it is: check → triage → referral? → action → improvement? → close. */
const FLOW = [['open', 'Triage'], ['vet_referral', 'Vet Diagnosis'], ['action', 'Care Action'], ['follow_up', 'Improved'], ['closed', 'Closed']] as const;

/** D-185: App 19 Animal Welfare & Husbandry (V3 flow 19). */
export default function Welfare(p: Props) {
    const [tab, setTab] = useTabParam('cases');
    const open = p.cases.filter((c) => c.status !== 'closed');
    return (
        <>
            <Head title="Animal Welfare" />
            <PageHeader title="Animal Welfare & Husbandry" description="Daily welfare and mobility checks, welfare cases, care actions and verified recovery. The vet decides any treatment." />
            <StatRow>
                <Stat label="Unresolved cases" value={p.kpi.unresolved} tone={p.kpi.unresolved ? 'alert' : undefined} />
                <Stat label="Lameness rate (30 days)" value={p.kpi.lamenessRate !== null ? `${p.kpi.lamenessRate}%` : '—'} note={`${p.kpi.checks} checks`} />
                <Stat label="Average days to close" value={p.kpi.closeDays ?? '—'} />
                <Stat label="Inspections due" value={p.due} tone={p.due ? 'alert' : undefined} />
                <Stat label="Lameness incidents (30 days)" value={p.kpi.lameness} />
                <Stat label="Recurrence (90 days)" value={p.kpi.recurrence !== null ? `${p.kpi.recurrence}%` : '—'} note="Same problem again on the same goat" />
                <Stat label="Waiting for vet diagnosis" value={p.kpi.awaitingVet} tone={p.kpi.awaitingVet ? 'alert' : undefined} note={p.kpi.held ? `${p.kpi.held} routine tasks on hold` : undefined} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[
                { id: 'check', label: 'Daily Welfare Check' }, { id: 'cases', label: 'Welfare Cases', count: open.length || undefined },
                { id: 'checks', label: 'Check Records' }, { id: 'closed', label: 'Closed Cases' },
            ]} />
            {tab === 'check' ? <CheckForm {...p} /> : null}
            {tab === 'cases' ? <Cases {...p} cases={open} empty="No open welfare cases." /> : null}
            {tab === 'closed' ? <Cases {...p} cases={p.cases.filter((c) => c.status === 'closed')} empty="No cases closed in the last 90 days." /> : null}
            {tab === 'checks' ? (
                <Panel flush title="Last 14 Days">
                    {p.checks.length ? <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Day</th><th>Goat / Pen</th><th>BCS</th><th>Behaviour</th><th>Injury</th><th>Lameness</th><th>Result</th><th>By</th></tr></thead><tbody>
                        {p.checks.map((c) => <tr key={c.id}><td>{c.on}</td><td className="font-mono">{c.goat ?? c.pen}</td><td>{c.bcs ?? '—'}</td><td>{p.options.behaviour[c.behaviour]}</td><td>{p.options.injury[c.injury]}</td><td>{c.lameness}</td><td>{c.caseId ? <Chip className="bg-watch-100 text-watch-700">Case opened</Chip> : <Chip className="bg-ok-100 text-ok-700">Normal</Chip>}</td><td>{c.by}</td></tr>)}
                    </tbody></table></div> : <Empty title="No checks yet" />}
                </Panel>
            ) : null}
        </>
    );
}

function CheckForm({ goats, pens, options }: Props) {
    const form = useForm({ animal_id: '', pen_id: '', checked_on: today(), bcs: '3', behaviour: 'normal', injury: 'none', lameness: '0', pain: false, notes: '', next_check_on: '' });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title="Daily Welfare / Mobility Check" description="Record body condition, behaviour and injury. Anything wrong opens a welfare case for triage; otherwise it is marked normal with the next inspection.">
            <form className="grid gap-3 p-4 sm:grid-cols-3" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, animal_id: d.animal_id || null, pen_id: d.pen_id || null, bcs: d.bcs || null, next_check_on: d.next_check_on || null })); form.post('/welfare/checks', { preserveScroll: true, onSuccess: () => form.reset('animal_id', 'notes', 'pain', 'injury', 'lameness', 'behaviour') }); }}>
                <Field label="Goat" error={e.animal_id}><input className="field font-mono" list="welfare-goats" placeholder="Tag ID" value={goats.find((g) => g.id === form.data.animal_id)?.farm_number ?? form.data.animal_id} onChange={(x) => form.setData('animal_id', goats.find((g) => g.farm_number === x.target.value.toUpperCase())?.id ?? x.target.value)} /><datalist id="welfare-goats">{goats.map((g) => <option key={g.id} value={g.farm_number} />)}</datalist></Field>
                <Field label="Or a whole pen" error={e.pen_id}><select className="field" value={form.data.pen_id} onChange={(x) => form.setData('pen_id', x.target.value)}><option value="">—</option>{pens.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
                <Field label="Date" error={e.checked_on}><input type="date" max={today()} className="field" value={form.data.checked_on} onChange={(x) => form.setData('checked_on', x.target.value)} /></Field>
                <Field label="Body condition (1–5)" error={e.bcs}><select className="field" value={form.data.bcs} onChange={(x) => form.setData('bcs', x.target.value)}><option value="">Not scored</option>{[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}</select></Field>
                <Field label="Behaviour" error={e.behaviour}><select className="field" value={form.data.behaviour} onChange={(x) => form.setData('behaviour', x.target.value)}>{Object.entries(options.behaviour).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Injury" error={e.injury}><select className="field" value={form.data.injury} onChange={(x) => form.setData('injury', x.target.value)}>{Object.entries(options.injury).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Lameness" error={e.lameness}><select className="field" value={form.data.lameness} onChange={(x) => form.setData('lameness', x.target.value)}>{Object.entries(options.lameness).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                <label className="flex items-end gap-2 pb-2 text-sm font-semibold"><input type="checkbox" checked={form.data.pain} onChange={(x) => form.setData('pain', x.target.checked)} /> Signs of pain or distress</label>
                <div className="flex gap-2 sm:col-span-3"><Button type="submit" disabled={form.processing}><LibIcon name="ic-healthy" size={18} /> Save Check</Button></div>
            </form>
        </Panel>
    );
}

function Cases({ cases, options, isVet, goats, pens, users, empty }: Props & { empty: string }) {
    const [shown, more] = useFive(cases);
    const [adding, setAdding] = useState(false);
    const form = useForm({ animal_id: '', pen_id: '', issue: 'lameness', severity: 'medium', triage: '' });
    const e = form.errors as Record<string, string>;
    return (
        <div className="space-y-3">
            {adding ? (
                <Panel title="Open a Welfare Case" description="From an inspection, an animal report, an injury or a lameness alert">
                    <form className="grid gap-3 p-4 sm:grid-cols-3" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, animal_id: d.animal_id || null, pen_id: d.pen_id || null })); form.post('/welfare/cases', { preserveScroll: true, onSuccess: () => setAdding(false) }); }}>
                        <Field label="Goat" error={e.animal_id}><select className="field" value={form.data.animal_id} onChange={(x) => form.setData('animal_id', x.target.value)}><option value="">—</option>{goats.map((g) => <option key={g.id} value={g.id}>{g.farm_number}</option>)}</select></Field>
                        <Field label="Or pen" error={e.pen_id}><select className="field" value={form.data.pen_id} onChange={(x) => form.setData('pen_id', x.target.value)}><option value="">—</option>{pens.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field>
                        <Field label="Issue" error={e.issue}><select className="field" value={form.data.issue} onChange={(x) => form.setData('issue', x.target.value)}>{Object.entries(options.issues).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                        <Field label="Severity" error={e.severity} hint="High and critical alert the vet"><select className="field" value={form.data.severity} onChange={(x) => form.setData('severity', x.target.value)}>{Object.entries(options.severity).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                        <Field label="Triage notes" error={e.triage}><input className="field" value={form.data.triage} onChange={(x) => form.setData('triage', x.target.value)} /></Field>
                        <div className="flex items-end gap-2"><Button type="submit" disabled={form.processing}>Open Case</Button><Button type="button" variant="secondary" onClick={() => setAdding(false)}>Cancel</Button></div>
                    </form>
                </Panel>
            ) : null}
            <Panel flush title="Welfare Cases" actions={<Button onClick={() => setAdding(true)}>+ Open Case</Button>}>
                {cases.length ? <ul className="divide-y divide-rule">{shown.map((c) => <CaseItem key={c.id} c={c} options={options} isVet={isVet} pens={pens} users={users} />)}</ul> : <Empty title={empty} />}
                {more}
            </Panel>
        </div>
    );
}

function CaseItem({ c, options, isVet, pens, users }: { c: Case; options: Props['options']; isVet: boolean; pens: Props['pens']; users: Props['users'] }) {
    const [mode, setMode] = useState<string | null>(null);
    const form = useForm({ kind: '', notes: '', improved: true as boolean, evidence: '', pen_id: '', assigned_user_id: '', due_on: '' });
    const err = form.errors as Record<string, string>;
    const go = (kind: string, extra: Partial<typeof form.data> = {}) => { form.transform((d) => ({ ...d, ...extra, kind, pen_id: d.pen_id || null, assigned_user_id: d.assigned_user_id || null, due_on: d.due_on || null })); form.post(`/welfare/cases/${c.id}/step`, { preserveScroll: true, onSuccess: () => { setMode(null); form.reset(); } }); };
    const reached = (key: string) => c.status === key || c.steps.some((s) => (key === 'vet_referral' && s.kind === 'vet_referral') || (key === 'action' && ['hoof', 'handling', 'habitat'].includes(s.kind)) || (key === 'follow_up' && s.kind === 'follow_up' && s.improved));
    return (
        <li className="px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <strong className="font-mono text-cobalt-900">{c.no}</strong>
                {c.goat ? <Link href={`/goats/${c.goat.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{c.goat.number}</Link> : <span>{c.pen}</span>}
                <span>{options.issues[c.issue]}</span>
                <Chip className={SEV[c.severity]}>{options.severity[c.severity]}</Chip>
                <Chip className={c.status === 'escalated' ? 'bg-crit-100 text-crit-700' : c.status === 'closed' ? 'bg-ok-100 text-ok-700' : 'bg-cobalt-100 text-cobalt-800'}>{options.status[c.status]}</Chip>
                <span className="text-ink-soft">opened {c.opened} by {c.by}</span>
            </div>
            {/* where the case is on flow 19 */}
            <ol className="mt-2 flex flex-wrap items-center gap-1 text-[11px] font-semibold">
                {FLOW.map(([key, label], i) => <li key={key} className="flex items-center gap-1"><span className={`rounded-full px-2 py-0.5 ${reached(key) || c.status === 'closed' ? 'bg-cobalt-900 text-white' : 'bg-glaze text-ink-soft ring-1 ring-rule'}`}>{label}</span>{i < FLOW.length - 1 ? <span className="text-ink-faint">→</span> : null}</li>)}
                {c.status === 'escalated' ? <li><span className="rounded-full bg-crit-100 px-2 py-0.5 text-crit-700">Escalated: reassess</span></li> : null}
            </ol>
            {c.repeatOf || c.healthCase || c.restricted || c.handlingClearedBy ? (
                <div className="mt-1 flex flex-wrap gap-1.5 text-xs">
                    {c.repeatOf ? <Chip className="bg-treat-100 text-treat-700">Repeat of {c.repeatOf}</Chip> : null}
                    {c.healthCase ? <Chip className={c.healthCase.active ? 'bg-cobalt-100 text-cobalt-800' : 'bg-ok-100 text-ok-700'}>Vet {c.healthCase.label}: {c.healthCase.condition} · {c.healthCase.status.replace('_', ' ')}</Chip> : c.status === 'vet_referral' ? <Chip className="bg-watch-100 text-watch-700">Waiting for vet diagnosis</Chip> : null}
                    {c.restricted ? <Chip className="bg-crit-100 text-crit-700">Humane handling: no routine work{c.held ? ` · ${c.held} task(s) on hold` : ''}</Chip> : null}
                    {c.handlingClearedBy ? <Chip className="bg-ok-100 text-ok-700">Routine handling cleared by {c.handlingClearedBy}</Chip> : null}
                </div>
            ) : null}
            {c.triage ? <p className="mt-1 text-ink-soft">Triage: {c.triage}</p> : null}
            {c.steps.length ? <ul className="mt-1 space-y-0.5 text-xs text-ink-soft">{c.steps.map((s, i) => <li key={i}>{s.on} · {options.actions[s.kind]}{s.assignee ? ` · assigned to ${s.assignee}${s.due ? ` by ${s.due}` : ''}` : ''}{s.improved !== null ? (s.improved ? ' · improved' : ' · not improved') : ''}{s.notes ? ` · ${s.notes}` : ''} · {s.by}</li>)}</ul> : null}
            {c.evidence ? <p className="mt-1 text-ok-700">Closed {c.closedAt} by {c.closedBy}: {c.evidence}</p> : null}
            {c.status !== 'closed' ? (
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {['open', 'escalated'].includes(c.status) ? <Button onClick={() => setMode('vet_referral')}><LibIcon name="app-veterinary" size={16} /> Urgent Vet Referral</Button> : null}
                    {c.status !== 'vet_referral' || isVet ? <Button variant="secondary" onClick={() => setMode('action')}>Hoof / Handling / Habitat Action</Button> : null}
                    {['action', 'vet_referral', 'escalated'].includes(c.status) ? <Button variant="secondary" onClick={() => setMode('follow_up')}>Follow-up: Improved?</Button> : null}
                    {c.goat ? <Button variant="secondary" onClick={() => setMode('housing')}>Move to Care Housing</Button> : null}
                    {c.restricted && isVet ? <Button variant="secondary" onClick={() => setMode('handling_ok')}>Clear Routine Handling</Button> : null}
                    {c.canClose ? <Button onClick={() => setMode('close')}><LibIcon name="ic-healthy" size={16} /> Close with Evidence</Button> : null}
                </div>
            ) : null}
            {mode ? (
                <div className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-3">
                    {mode === 'action' ? <Field label="Action"><select className="field" value={form.data.kind} onChange={(x) => form.setData('kind', x.target.value)}><option value="">Choose…</option>{['hoof', 'handling', 'habitat'].map((k) => <option key={k} value={k}>{options.actions[k]}</option>)}</select></Field> : null}
                    {mode === 'action' ? <Field label="Assign to (optional)" hint="Creates a task for them"><select className="field" value={form.data.assigned_user_id} onChange={(x) => form.setData('assigned_user_id', x.target.value)}><option value="">Done now</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select></Field> : null}
                    {mode === 'action' && form.data.assigned_user_id ? <Field label="Due"><input type="date" min={today()} className="field" value={form.data.due_on} onChange={(x) => form.setData('due_on', x.target.value)} /></Field> : null}
                    {mode === 'housing' ? <Field label="Move to pen" error={err.pen_id}><select className="field" value={form.data.pen_id} onChange={(x) => form.setData('pen_id', x.target.value)}><option value="">Choose…</option>{pens.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select></Field> : null}
                    {mode === 'follow_up' ? <Field label="Welfare improved?"><select className="field" value={form.data.improved ? 'yes' : 'no'} onChange={(x) => form.setData('improved', x.target.value === 'yes')}><option value="yes">Yes: improving</option><option value="no">No: escalate</option></select></Field> : null}
                    {err.kind ? <p className="text-sm text-crit-700 sm:col-span-3">{err.kind}</p> : null}
                    {mode === 'close' ? <Field label="Evidence of recovery" error={err.evidence}><input className="field" placeholder="e.g. walking sound, eating, BCS 3" value={form.data.evidence} onChange={(x) => form.setData('evidence', x.target.value)} /></Field>
                        : <Field label="Notes"><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>}
                    <div className="flex items-end gap-2">
                        <Button disabled={form.processing || (mode === 'action' && !form.data.kind) || (mode === 'housing' && !form.data.pen_id)} onClick={() => go(mode === 'action' ? form.data.kind : mode)}>Save</Button>
                        <Button variant="secondary" onClick={() => setMode(null)}>Cancel</Button>
                    </div>
                </div>
            ) : null}
        </li>
    );
}
