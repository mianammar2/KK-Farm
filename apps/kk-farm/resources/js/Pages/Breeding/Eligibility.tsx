import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, Pencil, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { Button, Chip, cx, Empty, Field, Note, PageHeader, Panel, Stat, StatRow } from '../../components/ui';

type CheckRow = { ok: boolean; label: string; info?: boolean };
type Row = { id: string; number: string; name: string | null; sex: string; breed: string | null; code: string | null; roles: string[]; age: string | null; status: string; statusLabel: string; review: boolean; recommendation: string | null; checks: Record<string, CheckRow> };
type Rule = { id: number; breedId: number | null; breed: string; sex: string; minAge: number; minWeight: number; mature: number | null; pct: number; bcs: number | null; days: number };
type Props = { rows: Row[]; rules: Rule[]; breeds: Array<{ id: number; name: string }>; history: Array<{ id: number; goatId: string; number: string | null; decision: string; on: string; by: string; override: boolean; reason: string | null }>; canApprove: boolean };

const CHECK_LABEL: Record<string, string> = { age: 'Age', weight: 'Weight', mature: 'Mature weight', bcs: 'Body condition', health: 'Health', teeth: 'Teeth', genetics: 'Genetics' };
const FILTERS = [['qualified', 'Qualified: approval pending'], ['review', 'Maturity review'], ['not_eligible', 'Not eligible yet'], ['rejected', 'Not approved'], ['approved', 'Approved']] as const;

/** D-231: Breeding → Breeding Eligibility & Approval. The system checks; a person approves. */
export default function Eligibility({ rows, rules, breeds, history, canApprove }: Props) {
    const count = (f: string) => rows.filter((r) => (f === 'review' ? r.review : r.status === f)).length;
    const [filter, setFilter] = useState<string>(count('qualified') ? 'qualified' : count('review') ? 'review' : 'not_eligible');
    const list = rows.filter((r) => (filter === 'review' ? r.review : r.status === filter));
    return (
        <>
            <Head title="Breeding Eligibility" />
            <PageHeader title="Breeding Eligibility & Approval" description="Replacement and growing goats are checked against the farm's rules every day and after every weighing. Passing the checks only asks for approval: a goat becomes a breeder when an authorised person approves it." />
            <StatRow>
                <Stat label="Qualified, approval pending" value={count('qualified')} tone={count('qualified') ? 'alert' : undefined} />
                <Stat label="Maturity review" value={count('review')} note="2 teeth or target weight, not yet qualified" />
                <Stat label="Not eligible yet" value={count('not_eligible')} />
                <Stat label="Approved" value={count('approved')} tone="good" />
            </StatRow>
            <div className="mb-3 flex flex-wrap gap-1" role="radiogroup" aria-label="Show">{FILTERS.map(([k, l]) => (
                <button key={k} type="button" role="radio" aria-checked={filter === k} onClick={() => setFilter(k)} className={filter === k ? 'rounded-full bg-cobalt-800 px-3 py-1.5 text-xs font-semibold text-white' : 'rounded-full bg-glaze px-3 py-1.5 text-xs font-semibold ring-1 ring-rule'}>{l} ({count(k)})</button>
            ))}</div>
            {filter === 'review' ? <div className="mb-3"><Note>These goats have 2 or more permanent teeth or have reached the minimum weight, but do not pass every check yet. This is a prompt to look at them, never an approval.</Note></div> : null}
            <Panel flush title={`${list.length} goats`}>
                {list.length ? <ul className="divide-y divide-rule">{list.map((r) => <GoatRow key={r.id} r={r} canApprove={canApprove} />)}</ul> : <Empty title="No goats here" />}
            </Panel>
            <RulesPanel rules={rules} breeds={breeds} canEdit={canApprove} />
            <Panel flush title="Decisions" description="Every approval and rejection is kept with the checks at that moment." className="mt-4">
                {history.length ? <div className="overflow-x-auto"><table className="data-table min-w-[560px]"><thead><tr><th>Goat</th><th>Decision</th><th>On</th><th>By</th><th>Reason</th></tr></thead><tbody>{history.map((h) => (
                    <tr key={h.id}><td><Link href={`/goats/${h.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{h.number}</Link></td>
                        <td>{h.decision === 'approved' ? <Chip className="bg-ok-100 text-ok-700">Approved{h.override ? ' (override)' : ''}</Chip> : <Chip className="bg-crit-100 text-crit-700">Not approved</Chip>}</td>
                        <td>{h.on}</td><td>{h.by}</td><td className="text-sm text-ink-soft">{h.reason ?? '—'}</td></tr>
                ))}</tbody></table></div> : <Empty title="No decisions yet" />}
            </Panel>
        </>
    );
}

function GoatRow({ r, canApprove }: { r: Row; canApprove: boolean }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ decision: 'approved', repro_exam_passed: false, genetics_approved: false, reason: '' });
    const failed = Object.values(r.checks).some((c) => !c.ok);
    const decidable = canApprove && ['qualified', 'not_eligible', 'rejected'].includes(r.status);
    return (
        <li className="px-4 py-3">
            <div className="flex flex-wrap items-center gap-2">
                <Link href={`/goats/${r.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{r.number}</Link>
                <span className="text-sm text-ink-soft">{[r.name, r.sex === 'female' ? 'Female' : 'Male', r.breed, r.age].filter(Boolean).join(' · ')}</span>
                {r.code ? <Chip className="bg-cobalt-100 font-mono text-cobalt-800">{r.code}</Chip> : null}
                {r.roles.map((c) => <Chip key={c} className="bg-glaze font-mono text-cobalt-800 ring-1 ring-rule">{c}</Chip>)}
                <Chip className={r.status === 'qualified' ? 'bg-watch-100 text-watch-700' : r.status === 'approved' ? 'bg-ok-100 text-ok-700' : r.status === 'rejected' ? 'bg-crit-100 text-crit-700' : 'bg-glaze text-ink-soft'}>{r.statusLabel}</Chip>
                {r.recommendation ? <span className="text-xs font-semibold text-watch-700">{r.recommendation}</span> : null}
                {decidable && !open ? <Button variant={r.status === 'qualified' ? 'primary' : 'secondary'} className="ml-auto" onClick={() => setOpen(true)}>{r.status === 'qualified' ? 'Approve or reject' : 'Decide'}</Button> : null}
            </div>
            {Object.keys(r.checks).length ? <ul className="mt-2 flex flex-wrap gap-1.5">{Object.entries(r.checks).map(([k, c]) => (
                <li key={k} className={cx('inline-flex items-center gap-1 rounded-[4px] px-2 py-0.5 text-xs', c.info ? 'bg-glaze text-ink-soft' : c.ok ? 'bg-ok-100 text-ok-700' : 'bg-crit-100 text-crit-700')}>
                    {c.info ? null : c.ok ? <Check size={12} aria-hidden /> : <X size={12} aria-hidden />}<span className="font-semibold">{CHECK_LABEL[k] ?? k}:</span> {c.label}
                </li>
            ))}</ul> : null}
            {open ? (
                <form className="mt-3 grid max-w-xl gap-2 rounded-tile bg-glaze p-3" onSubmit={(e) => { e.preventDefault(); form.post(`/breeding/eligibility/${r.id}`, { preserveScroll: true, onSuccess: () => setOpen(false) }); }}>
                    <div className="flex gap-3 text-sm" role="radiogroup">{(['approved', 'rejected'] as const).map((d) => <label key={d} className="flex items-center gap-1.5"><input type="radio" checked={form.data.decision === d} onChange={() => form.setData('decision', d)} />{d === 'approved' ? 'Approve for breeding' : 'Do not approve'}</label>)}</div>
                    {form.data.decision === 'approved' ? <>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="size-4.5" checked={form.data.repro_exam_passed} onChange={(e) => form.setData('repro_exam_passed', e.target.checked)} /> Reproductive examination passed</label>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="size-4.5" checked={form.data.genetics_approved} onChange={(e) => form.setData('genetics_approved', e.target.checked)} /> Sire / dam line approved for breeding</label>
                        {form.errors.repro_exam_passed ? <p className="text-xs font-semibold text-crit-700">{form.errors.repro_exam_passed}</p> : null}
                        {failed ? <Note>Some checks did not pass. Approving anyway is recorded as an override with your reason.</Note> : null}
                    </> : null}
                    <Field label={form.data.decision === 'approved' && failed ? 'Reason for override' : 'Notes'} error={form.errors.reason ?? form.errors.decision}><input className="field" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} /></Field>
                    <span className="flex gap-2"><Button type="submit" disabled={form.processing}>Save decision</Button><Button type="button" variant="secondary" onClick={() => setOpen(false)}>Cancel</Button></span>
                </form>
            ) : null}
        </li>
    );
}

const blank = (sex = 'female', breedId = '') => ({ breed_id: breedId, sex, min_age_months: '', min_weight_kg: '', mature_weight_kg: '', min_mature_pct: '65', min_bcs: '3', weight_max_age_days: '30' });

/** D-231: thresholds per sex, for every breed or one breed (a breed rule wins over the all-breeds rule). */
function RulesPanel({ rules, breeds, canEdit }: { rules: Rule[]; breeds: Array<{ id: number; name: string }>; canEdit: boolean }) {
    const [editing, setEditing] = useState<string | null>(null);
    const form = useForm(blank());
    const edit = (r: Rule | null) => {
        form.clearErrors();
        if (!r) { form.setData(blank()); setEditing('new'); return; }
        form.setData({ breed_id: r.breedId ? String(r.breedId) : '', sex: r.sex, min_age_months: String(r.minAge), min_weight_kg: String(r.minWeight), mature_weight_kg: r.mature === null ? '' : String(r.mature), min_mature_pct: String(r.pct), min_bcs: r.bcs === null ? '' : String(r.bcs), weight_max_age_days: String(r.days) });
        setEditing(String(r.id));
    };
    const save = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, breed_id: d.breed_id || null, mature_weight_kg: d.mature_weight_kg || null, min_bcs: d.min_bcs || null }));
        form.post('/breeding/eligibility/rules', { preserveScroll: true, onSuccess: () => setEditing(null) });
    };
    const n = (k: 'min_age_months' | 'min_weight_kg' | 'mature_weight_kg' | 'min_mature_pct' | 'min_bcs' | 'weight_max_age_days', label: string, ph?: string) => <div className="w-28"><Field label={label} error={form.errors[k]}><input className="field" type="number" step="0.5" min={0} placeholder={ph} value={form.data[k]} onChange={(e) => form.setData(k, e.target.value)} /></Field></div>;
    return (
        <Panel id="rules" flush title="Breeding Eligibility Rules" className="mt-4" description="Examples from the KK Farms framework; set your own per breed. A breed's rule wins over the all-breeds rule. Mature weight empty = that check is skipped." actions={canEdit && !editing ? <Button variant="secondary" onClick={() => edit(null)}>Add breed rule</Button> : null}>
            <div className="overflow-x-auto"><table className="data-table min-w-[760px]">
                <thead><tr><th>Breed</th><th>Sex</th><th className="text-right">Min age</th><th className="text-right">Min weight</th><th className="text-right">Mature weight</th><th className="text-right">% of mature</th><th className="text-right">Min BCS</th><th className="text-right">Weight valid</th><th /></tr></thead>
                <tbody>{rules.map((r) => (
                    <tr key={r.id}><td className="font-semibold">{r.breed}</td><td>{r.sex === 'female' ? 'Female' : 'Male'}</td><td className="text-right">{r.minAge} mo</td><td className="text-right">{r.minWeight} KG</td>
                        <td className="text-right">{r.mature ? `${r.mature} KG` : <span className="text-ink-faint">Not set</span>}</td><td className="text-right">{r.pct}%</td><td className="text-right">{r.bcs ?? '—'}</td><td className="text-right">{r.days} days</td>
                        <td className="text-right whitespace-nowrap">{canEdit ? <><button type="button" className="inline-flex items-center gap-1 text-xs font-semibold text-cobalt-800 hover:underline" onClick={() => edit(r)}><Pencil size={13} aria-hidden /> Edit</button>
                            {r.breedId ? <button type="button" className="ml-3 inline-flex items-center gap-1 text-xs font-semibold text-crit-700 hover:underline" onClick={() => { if (window.confirm(`Remove the ${r.breed} rule?`)) router.delete(`/breeding/eligibility/rules/${r.id}`, { preserveScroll: true }); }}><Trash2 size={13} aria-hidden /> Remove</button> : null}</> : null}</td></tr>
                ))}</tbody>
            </table></div>
            {editing ? (
                <form onSubmit={save} className="flex flex-wrap items-end gap-2 border-t border-rule p-4">
                    <div className="w-44"><Field label="Breed" error={form.errors.breed_id}><select className="field" value={form.data.breed_id} disabled={editing !== 'new'} onChange={(e) => form.setData('breed_id', e.target.value)}><option value="">All breeds</option>{breeds.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select></Field></div>
                    <div className="w-28"><Field label="Sex" error={form.errors.sex}><select className="field" value={form.data.sex} disabled={editing !== 'new'} onChange={(e) => form.setData('sex', e.target.value)}><option value="female">Female</option><option value="male">Male</option></select></Field></div>
                    {n('min_age_months', 'Min age (months)')}{n('min_weight_kg', 'Min weight KG')}{n('mature_weight_kg', 'Mature weight KG', 'Not set')}{n('min_mature_pct', '% of mature')}{n('min_bcs', 'Min BCS', 'Not checked')}{n('weight_max_age_days', 'Weight valid (days)')}
                    <Button type="submit" disabled={form.processing}>Save rule</Button><Button type="button" variant="secondary" onClick={() => setEditing(null)}>Cancel</Button>
                </form>
            ) : null}
        </Panel>
    );
}
