import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Button, Chip, Empty, Field, LibIcon, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';
import { useFive } from '../lib/useFive';

type Grow = {
    id: string; number: string; sex: string; breed: string | null; pen: string | null; dam: string | null; weak: boolean; weaned: boolean;
    weight: number | null; on: string | null; age: number; target: number | null; pct: number | null; adg: number | null; below: boolean; stale: boolean;
    intervention: { id: number; kind: string; due: string; overdue: boolean } | null;
};
type Interv = { id: number; goatId: string; number: string; kind: string; reason: string; action: string | null; weight: number | null; target: number | null; opened: string; due: string; status: string; result: number | null; by: string; closedBy: string | null };
type Check = { ok: boolean; label: string };
type Cand = { id: string; number: string; sex: string; breed: string | null; pen: string | null; purpose: string | null; checks: Record<string, Check>; eligible: boolean; last: string | null };
type Sel = { id: number; goatId: string; number: string; decision: string; reason: string | null; override: boolean; failed: string[]; by: string; on: string };
type Point = { age_days: number; target_kg: number };
type Props = {
    kpi: { born: number; stillborn: number; weak: number; diedPreWeaning: number; survival: number | null; weaning: number | null; adg: number | null; weaned: number; selected: number; sale: number; selectionRate: number | null };
    growth: Grow[]; interventions: Interv[]; candidates: Cand[]; selections: Sel[];
    plan: { farm: Point[]; breeds: Record<string, Array<Point & { breed_id: number }>>; defaults: Point[] }; breeds: Array<{ id: number; name: string }>;
    settings: { selectionAge: number; alertPct: number; weaningAge: number };
    options: { kinds: Record<string, string>; status: Record<string, string>; decisions: Record<string, string> }; isLead: boolean;
};

const plus = (days: number) => new Date(Date.now() + days * 864e5 - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const CHECKS: Record<string, string> = { age: 'Maturity', growth: 'Growth', bcs: 'Body condition', health: 'Health', pedigree: 'Pedigree' };
const FUNNEL = ['Mating & pregnancy', 'Due-date & maternity', 'Kidding (live / stillborn)', 'Colostrum & stable?', 'Kid ID + RFID', 'Suckling & feeding', 'Weaning & weighing', 'Growth on target?', 'Breeder or sale'];

/** D-191: V3 cross-app journey B, Birth-to-Breeder (flow 25). */
export default function BirthToBreeder(p: Props) {
    const [tab, setTab] = useTabParam('growth');
    const below = p.growth.filter((g) => g.below && !g.intervention);
    const openInt = p.interventions.filter((i) => i.status === 'open');
    return (
        <>
            <Head title="Birth to Breeder" />
            <PageHeader title="Birth to Breeder" description={`Kids from birth to weaning, growth against the plan, nutrition or vet help when a goat falls behind, then replacement breeder or sale stock from ${p.settings.selectionAge} days.`} />
            <StatRow>
                <Stat label="Kid survival to weaning" value={p.kpi.survival === null ? '—' : `${p.kpi.survival}%`} note={`${p.kpi.diedPreWeaning} died · ${p.kpi.weak} weak-born`} />
                <Stat label="Weaning rate" value={p.kpi.weaning === null ? '—' : `${p.kpi.weaning}%`} note={`${p.kpi.weaned} weaned (12 months)`} />
                <Stat label="ADG birth → weaning" value={p.kpi.adg === null ? '—' : `${p.kpi.adg} g/day`} />
                <Stat label="Replacement selection" value={p.kpi.selectionRate === null ? '—' : `${p.kpi.selectionRate}%`} note={`${p.kpi.selected} breeders · ${p.kpi.sale} sale`} />
                <Stat label="Below growth target" value={below.length} tone={below.length ? 'alert' : undefined} note={`under ${p.settings.alertPct}% of target`} />
            </StatRow>
            <ol className="mb-3 flex flex-wrap items-center gap-1 text-[11px] font-semibold">
                {FUNNEL.map((s, i) => <li key={s} className="flex items-center gap-1"><span className={`rounded-full px-2 py-0.5 ${i >= 6 ? 'bg-cobalt-900 text-white' : 'bg-glaze text-ink-soft ring-1 ring-rule'}`}>{s}</span>{i < FUNNEL.length - 1 ? <span className="text-ink-faint">→</span> : null}</li>)}
                <li className="ml-2 text-xs font-normal text-ink-soft">Earlier steps: <Link href="/breeding?tab=kidding" className="text-cobalt-800 hover:underline">Kidding</Link> · <Link href="/breeding?tab=kidcare" className="text-cobalt-800 hover:underline">Kid Care</Link> · <Link href="/breeding?tab=weaning" className="text-cobalt-800 hover:underline">Weaning</Link></li>
            </ol>
            <p className="mb-3 text-xs text-ink-soft">Last 12 months: {p.kpi.born} born alive, {p.kpi.stillborn} stillborn (recorded on the kidding, no goat IDs).</p>
            <Tabs value={tab} onChange={setTab} tabs={[
                { id: 'growth', label: 'Growth Check', count: below.length || undefined }, { id: 'interventions', label: 'Interventions', count: openInt.length || undefined },
                { id: 'selection', label: 'Breeder / Sale Selection', count: p.candidates.filter((c) => c.eligible).length || undefined }, { id: 'selections', label: 'Selection Records' },
                { id: 'plan', label: 'Growth Plan' },
            ]} />
            {tab === 'growth' ? <GrowthList {...p} /> : null}
            {tab === 'interventions' ? <Interventions {...p} /> : null}
            {tab === 'selection' ? <Selection {...p} /> : null}
            {tab === 'selections' ? <Selections {...p} /> : null}
            {tab === 'plan' ? <Plan {...p} /> : null}
        </>
    );
}

function GrowthList(p: Props) {
    const [only, setOnly] = useState(true);
    const rows = only ? p.growth.filter((g) => g.below || g.intervention || g.stale) : p.growth;
    const [shown, more] = useFive(rows, 20);
    const [open, setOpen] = useState<string | null>(null);
    return (
        <Panel flush title="Young Stock vs Growth Plan" description={`Goats up to 12 months old. Below ${p.settings.alertPct}% of the target for their age needs a nutrition or veterinary intervention.`}
            actions={<label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={only} onChange={(e) => setOnly(e.target.checked)} /> Needs attention only</label>}>
            {rows.length ? <ul className="divide-y divide-rule">{shown.map((g) => (
                <li key={g.id} className="px-4 py-2.5 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                        <Link href={`/goats/${g.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{g.number}</Link>
                        <span className="text-ink-soft">{g.sex} · {g.breed} · {g.age} days{g.dam ? ` · dam ${g.dam}` : ''}{g.pen ? ` · ${g.pen}` : ''}</span>
                        {g.weak ? <Chip className="bg-watch-100 text-watch-700">Born weak</Chip> : null}
                        {g.weight !== null ? <span>{g.weight} KG ({g.on}){g.target ? ` · target ${g.target} KG` : ''}{g.adg !== null ? ` · ADG ${g.adg} g` : ''}</span> : <span className="text-ink-soft">No weight yet</span>}
                        {g.pct !== null ? <Chip className={g.below ? 'bg-crit-100 text-crit-700' : 'bg-ok-100 text-ok-700'}>{g.pct}% of target</Chip> : null}
                        {g.stale ? <Chip className="bg-glaze text-ink-soft">Weigh again</Chip> : null}
                        {g.intervention ? <Chip className={g.intervention.overdue ? 'bg-treat-100 text-treat-700' : 'bg-cobalt-100 text-cobalt-800'}>{p.options.kinds[g.intervention.kind]} · follow-up {g.intervention.due}</Chip>
                            : g.below || g.stale ? <Button variant="secondary" className="ml-auto" onClick={() => setOpen(open === g.id ? null : g.id)}>Intervene</Button> : null}
                    </div>
                    {open === g.id ? <IntervForm g={g} options={p.options} close={() => setOpen(null)} /> : null}
                </li>))}</ul> : <Empty title={only ? 'Every young goat is on target' : 'No young goats'} />}
            {more}
        </Panel>
    );
}

function IntervForm({ g, options, close }: { g: Grow; options: Props['options']; close: () => void }) {
    const form = useForm({ kind: 'nutrition', action: '', follow_up_on: plus(14) });
    const e = form.errors as Record<string, string>;
    return (
        <div className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-4">
            <Field label="Intervention" error={e.kind}><select className="field" value={form.data.kind} onChange={(x) => form.setData('kind', x.target.value)}>{Object.entries(options.kinds).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            <Field label="What will be done" error={e.action}><input className="field" value={form.data.action} onChange={(x) => form.setData('action', x.target.value)} placeholder={form.data.kind === 'nutrition' ? 'e.g. Creep feed 200 g/day' : 'e.g. Faecal test, deworm as vet decides'} /></Field>
            <Field label="Follow-up weighing" error={e.follow_up_on}><input type="date" className="field" value={form.data.follow_up_on} onChange={(x) => form.setData('follow_up_on', x.target.value)} /></Field>
            <div className="flex items-end gap-2"><Button disabled={form.processing} onClick={() => form.post(`/growth/${g.id}/intervene`, { preserveScroll: true, onSuccess: close })}>Start</Button><Button variant="secondary" onClick={close}>Cancel</Button></div>
        </div>
    );
}

function Interventions(p: Props) {
    const [shown, more] = useFive(p.interventions);
    return (
        <Panel flush title="Nutrition & Veterinary Interventions" description="Follow-up: weigh again. Back on target closes it; not improved means start another (veterinary if nutrition did not help).">
            {p.interventions.length ? <ul className="divide-y divide-rule">{shown.map((i) => <IntervRow key={i.id} i={i} options={p.options} />)}</ul> : <Empty title="No interventions" />}
            {more}
        </Panel>
    );
}

function IntervRow({ i, options }: { i: Interv; options: Props['options'] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ weight_kg: '', body_score: '' });
    return (
        <li className="px-4 py-2.5 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <Link href={`/goats/${i.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{i.number}</Link>
                <Chip className="bg-glaze text-ink-soft">{options.kinds[i.kind]}</Chip>
                <span>{i.reason}</span>
                <Chip className={i.status === 'improved' ? 'bg-ok-100 text-ok-700' : i.status === 'not_improved' ? 'bg-crit-100 text-crit-700' : 'bg-cobalt-100 text-cobalt-800'}>{options.status[i.status]}{i.result ? ` · ${i.result} KG` : ''}</Chip>
                <span className="text-ink-soft">{i.opened} · {i.by}{i.status === 'open' ? ` · follow-up ${i.due}` : ''}</span>
                {i.status === 'open' ? <Button variant="secondary" className="ml-auto" onClick={() => setOpen(!open)}>Record Follow-up</Button> : null}
            </div>
            {i.action ? <p className="text-xs text-ink-soft">Action: {i.action}</p> : null}
            {open ? <div className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-3">
                <Field label="Weight now (KG)" error={form.errors.weight_kg}><input type="number" step="0.1" className="field" value={form.data.weight_kg} onChange={(x) => form.setData('weight_kg', x.target.value)} /></Field>
                <Field label="Body condition (1–5)" error={form.errors.body_score}><select className="field" value={form.data.body_score} onChange={(x) => form.setData('body_score', x.target.value)}><option value="">Not scored</option>{[1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}</select></Field>
                <div className="flex items-end gap-2"><Button disabled={form.processing} onClick={() => { form.transform((d) => ({ ...d, body_score: d.body_score || null })); form.post(`/growth/interventions/${i.id}/follow-up`, { preserveScroll: true, onSuccess: () => setOpen(false) }); }}>Save</Button></div>
            </div> : null}
        </li>
    );
}

function Selection(p: Props) {
    const [shown, more] = useFive(p.candidates, 10);
    return (
        <Panel flush title="Select Replacement Breeder or Sale Stock" description={`Goats from ${p.settings.selectionAge} days old not yet selected. The system checks maturity, growth, body condition, health and pedigree (BR-005); the owner, manager or vet decides.`}>
            {p.candidates.length ? <ul className="divide-y divide-rule">{shown.map((c) => <CandRow key={c.id} c={c} {...p} />)}</ul> : <Empty title="No goats waiting for selection" />}
            {more}
        </Panel>
    );
}

function CandRow({ c, options, isLead }: Props & { c: Cand }) {
    const form = useForm({ decision: c.eligible ? 'replacement' : 'sale', reason: '' });
    const [open, setOpen] = useState(false);
    return (
        <li className="px-4 py-2.5 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <Link href={`/goats/${c.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{c.number}</Link>
                <span className="text-ink-soft">{c.sex} · {c.breed}{c.pen ? ` · ${c.pen}` : ''}{c.last ? ` · last: ${c.last}` : ''}</span>
                <Chip className={c.eligible ? 'bg-ok-100 text-ok-700' : 'bg-watch-100 text-watch-700'}>{c.eligible ? 'Recommended: breeder' : 'Not all checks pass'}</Chip>
                {isLead ? <Button variant="secondary" className="ml-auto" onClick={() => setOpen(!open)}>Decide</Button> : null}
            </div>
            <div className="mt-1 flex flex-wrap gap-1.5 text-xs">{Object.entries(c.checks).map(([k, x]) => <span key={k} className={`rounded-full px-2 py-0.5 ${x.ok ? 'bg-ok-100 text-ok-700' : 'bg-crit-100 text-crit-700'}`}>{CHECKS[k]}: {x.label}</span>)}</div>
            {open ? <div className="mt-2 grid gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-3">
                <Field label="Decision" error={form.errors.decision}><select className="field" value={form.data.decision} onChange={(x) => form.setData('decision', x.target.value)}>{Object.entries(options.decisions).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Reason" error={form.errors.reason} hint={!c.eligible && form.data.decision === 'replacement' ? 'Required: not all checks pass' : undefined}><input className="field" value={form.data.reason} onChange={(x) => form.setData('reason', x.target.value)} /></Field>
                <div className="flex items-end gap-2"><Button disabled={form.processing} onClick={() => form.post(`/growth/${c.id}/select`, { preserveScroll: true, onSuccess: () => setOpen(false) })}><LibIcon name="mod-breeding" size={16} /> Save</Button></div>
            </div> : null}
        </li>
    );
}

function Selections({ selections, options }: Props) {
    const [shown, more] = useFive(selections);
    return (
        <Panel flush title="Selection Records">
            {selections.length ? <ul className="divide-y divide-rule">{shown.map((s) => <li key={s.id} className="flex flex-wrap items-center gap-2 px-4 py-2.5 text-sm">
                <Link href={`/goats/${s.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{s.number}</Link>
                <Chip className={s.decision === 'replacement' ? 'bg-ok-100 text-ok-700' : s.decision === 'sale' ? 'bg-cobalt-100 text-cobalt-800' : 'bg-glaze text-ink-soft'}>{options.decisions[s.decision]}</Chip>
                {s.override ? <Chip className="bg-treat-100 text-treat-700">Override</Chip> : null}
                <span className="text-ink-soft">{s.on} · {s.by}{s.reason ? ` · ${s.reason}` : ''}{s.failed.length ? ` · failed: ${s.failed.join(', ')}` : ''}</span>
            </li>)}</ul> : <Empty title="No selections yet" />}
            {more}
        </Panel>
    );
}

function Plan({ plan, breeds, settings, isLead }: Props) {
    const [breed, setBreed] = useState('');
    const current = (breed ? plan.breeds[breed] : plan.farm) ?? [];
    const start = current.length ? current : breed && plan.farm.length ? plan.farm : plan.defaults;
    const form = useForm({ breed_id: breed, points: start.map((x) => ({ age_days: String(x.age_days), target_kg: String(x.target_kg) })) });
    const s = useForm({ selection_age_days: String(settings.selectionAge), growth_alert_pct: String(settings.alertPct) });
    const pick = (b: string) => {
        setBreed(b);
        const pts = (b ? plan.breeds[b] : plan.farm) ?? [];
        const use = pts.length ? pts : b && plan.farm.length ? plan.farm : plan.defaults;
        form.setData({ breed_id: b, points: use.map((x) => ({ age_days: String(x.age_days), target_kg: String(x.target_kg) })) });
    };
    const e = form.errors as Record<string, string>;
    return (
        <div className="grid gap-3 lg:grid-cols-2">
            <Panel title="Growth Plan" description={current.length ? 'Target live weight by age. Between two ages the target is in proportion.' : 'Not set yet: showing the starting values. Save to use your own.'}>
                <div className="space-y-2 p-4">
                    <Field label="For"><select className="field" value={breed} onChange={(x) => pick(x.target.value)}><option value="">Every breed (farm plan)</option>{breeds.map((b) => <option key={b.id} value={b.id}>{b.name}{plan.breeds[b.id] ? ' · own plan' : ''}</option>)}</select></Field>
                    <table className="data-table"><thead><tr><th>Age (days)</th><th>Target KG</th><th /></tr></thead><tbody>
                        {form.data.points.map((pt, i) => <tr key={i}>
                            <td><input type="number" className="field w-24" disabled={!isLead} value={pt.age_days} onChange={(x) => form.setData('points', form.data.points.map((q, j) => (j === i ? { ...q, age_days: x.target.value } : q)))} /></td>
                            <td><input type="number" step="0.1" className="field w-24" disabled={!isLead} value={pt.target_kg} onChange={(x) => form.setData('points', form.data.points.map((q, j) => (j === i ? { ...q, target_kg: x.target.value } : q)))} /></td>
                            <td>{isLead ? <button type="button" className="text-crit-700" onClick={() => form.setData('points', form.data.points.filter((_, j) => j !== i))} aria-label="Remove">×</button> : null}</td>
                        </tr>)}
                    </tbody></table>
                    {e.points ? <p className="text-sm text-crit-700">{e.points}</p> : null}
                    {isLead ? <div className="flex gap-2"><Button variant="secondary" onClick={() => form.setData('points', [...form.data.points, { age_days: '', target_kg: '' }])}>+ Age</Button><Button disabled={form.processing} onClick={() => { form.transform((d) => ({ ...d, breed_id: d.breed_id || null })); form.post('/growth/plan', { preserveScroll: true }); }}>Save Plan</Button></div> : null}
                </div>
            </Panel>
            <Panel title="Selection Settings">
                <div className="grid gap-3 p-4 sm:grid-cols-2">
                    <Field label="Selection from age (days)" error={s.errors.selection_age_days}><input type="number" className="field" disabled={!isLead} value={s.data.selection_age_days} onChange={(x) => s.setData('selection_age_days', x.target.value)} /></Field>
                    <Field label="Growth alert below (% of target)" error={s.errors.growth_alert_pct}><input type="number" className="field" disabled={!isLead} value={s.data.growth_alert_pct} onChange={(x) => s.setData('growth_alert_pct', x.target.value)} /></Field>
                    {isLead ? <div><Button disabled={s.processing} onClick={() => s.post('/growth/settings', { preserveScroll: true })}>Save</Button></div> : null}
                    <p className="text-xs text-ink-soft sm:col-span-2">Weaning age ({settings.weaningAge} days) is set in Breeding → Settings.</p>
                </div>
            </Panel>
        </div>
    );
}
