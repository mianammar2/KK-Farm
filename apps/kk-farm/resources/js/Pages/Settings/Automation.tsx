import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Trash2 } from 'lucide-react';
import { Button, Field, PageHeader, Panel } from '../../components/ui';

type RuleRow = { id: number; sex: string; stage: string; purpose: string; class: string; status: string | null; goats: number; role: string | null; fixed: boolean };
type Props = {
    rules: RuleRow[]; stages: Array<{ code: string; label: string; range: string; group: string }>; teeth: Array<{ code: string; label: string; age: string }>;
    picks: Array<{ value: string; label: string; sex: string; purpose: string | null; class: string; stage: string | null }>; categories: Record<string, string>; categorySex: Record<string, string>;
    statuses: Record<string, string>; purposes: Record<string, string>; kid: { newbornDays: number; weaningDays: number };
    breeding: { min_age_months: number; min_weight_kg: number; min_bcs: number | null }; market: { male: number; female: number };
};

/** D-236: Settings → Automation Rules: everything the registry fills in by itself, and the table behind it. */
export default function Automation(p: Props) {
    const add = useForm({ sex: 'female', stage: p.stages[0]?.code ?? '', purpose: 'breeding', class: '', status: '' });
    const fits = (sex: string) => Object.entries(p.categories).filter(([k]) => !p.categorySex[k] || p.categorySex[k] === sex);
    const save = (r: RuleRow, patch: Partial<{ class: string; status: string }>) => router.post('/settings/automation/rules', { sex: r.sex, stage: r.stage, purpose: r.purpose, class: patch.class ?? r.class, status: patch.status ?? r.status ?? '' }, { preserveScroll: true });
    return (
        <>
            <Head title="Automation Rules" />
            <PageHeader title="Automation Rules" description="The registry asks for origin, breed, gender code, age (birth date, age stage or teeth), purpose, weight and pen. Everything below is filled in by itself from these rules, for every goat at once."
                actions={<Link href="/settings"><Button variant="secondary"><ArrowLeft size={16} aria-hidden /> Settings</Button></Link>} />
            <div className="space-y-4">
                <Panel flush title="1. Gender code fills in" description="Picking a code in the Gender field sets these at once. For a bought goat with no age yet it also fills in the age stage (and so the teeth and an estimated birth date).">
                    <div className="overflow-x-auto"><table className="data-table min-w-[560px]"><thead><tr><th>Code</th><th>Gender</th><th>Purpose</th><th>Category</th><th>Age if not known</th></tr></thead>
                        <tbody>{p.picks.map((r) => <tr key={r.value}><td className="font-semibold">{r.label}</td><td>{r.sex === 'female' ? 'Female' : 'Male'}</td><td>{r.purpose ? p.purposes[r.purpose] ?? r.purpose : '—'}</td><td>{p.categories[r.class] ?? r.class}</td><td className="font-mono">{r.stage ?? '—'}</td></tr>)}</tbody></table></div>
                </Panel>

                <Panel flush title="2. Age and teeth" description="From the birth date (or the age stage or teeth picked for a bought goat). Change them in Age Groups." actions={<Link href="/settings/ages" className="text-sm font-semibold text-cobalt-800 hover:underline">Edit in Age Groups</Link>}>
                    <div className="grid gap-4 p-4 md:grid-cols-2">
                        <ul className="space-y-1 text-sm">{p.stages.map((s) => <li key={s.code}><span className="font-mono font-semibold">{s.code}</span> · {s.label} · {s.range}</li>)}</ul>
                        <ul className="space-y-1 text-sm">{p.teeth.map((t) => <li key={t.code}><span className="font-mono font-semibold">{t.code}</span> · {t.label} · {t.age}</li>)}<li className="text-ink-soft">A teeth check by the vet wins over the age.</li></ul>
                    </div>
                </Panel>

                <Panel flush title="3. Category and female status by gender, age stage and purpose" description="Used for every goat whose category was not set by hand (approval, castration or the Gender code set it). 'No purpose set' and 'Any other purpose' are each stage's base rows.">
                    <div className="overflow-x-auto"><table className="data-table min-w-[820px]">
                        <thead><tr><th>Gender</th><th>Age stage</th><th>Purpose</th><th>Goat category</th><th>Female status at start</th><th className="text-right">Goats</th><th /></tr></thead>
                        <tbody>{p.rules.map((r) => (
                            <tr key={r.id}>
                                <td>{r.sex === 'female' ? 'Female' : 'Male'}</td><td className="font-mono font-semibold">{r.stage}</td><td>{p.purposes[r.purpose] ?? r.purpose}</td>
                                <td><select className="field py-1" aria-label="Goat category" value={r.class} onChange={(e) => save(r, { class: e.target.value })}>{fits(r.sex).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></td>
                                <td>{r.sex === 'female' ? <select className="field py-1" aria-label="Female status" value={r.status ?? ''} onChange={(e) => save(r, { status: e.target.value })}><option value="">Not set</option>{Object.entries(p.statuses).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select> : <span className="text-ink-faint">—</span>}</td>
                                <td className="text-right">{r.goats}</td>
                                <td className="text-right">{!r.fixed ? <button type="button" className="inline-flex items-center gap-1 text-xs font-semibold text-crit-700 hover:underline" onClick={() => { if (window.confirm('Remove this rule?')) router.delete(`/settings/automation/rules/${r.id}`, { preserveScroll: true }); }}><Trash2 size={13} aria-hidden /> Remove</button> : null}</td>
                            </tr>
                        ))}</tbody>
                    </table></div>
                    <form className="flex flex-wrap items-end gap-2 border-t border-rule p-4" onSubmit={(e) => { e.preventDefault(); add.post('/settings/automation/rules', { preserveScroll: true, onSuccess: () => add.reset('class', 'status') }); }}>
                        <div className="w-28"><Field label="Gender" error={add.errors.sex}><select className="field" value={add.data.sex} onChange={(e) => add.setData((d) => ({ ...d, sex: e.target.value, class: '' }))}><option value="female">Female</option><option value="male">Male</option></select></Field></div>
                        <div className="w-28"><Field label="Age stage" error={add.errors.stage}><select className="field" value={add.data.stage} onChange={(e) => add.setData('stage', e.target.value)}>{p.stages.map((s) => <option key={s.code} value={s.code}>{s.code}</option>)}</select></Field></div>
                        <div className="w-48"><Field label="Purpose" error={add.errors.purpose}><select className="field" value={add.data.purpose} onChange={(e) => add.setData('purpose', e.target.value)}>{Object.entries(p.purposes).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field></div>
                        <div className="w-56"><Field label="Goat category" error={add.errors.class}><select className="field" value={add.data.class} onChange={(e) => add.setData('class', e.target.value)}><option value="">Choose…</option>{fits(add.data.sex).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field></div>
                        {add.data.sex === 'female' ? <div className="w-52"><Field label="Female status" error={add.errors.status}><select className="field" value={add.data.status} onChange={(e) => add.setData('status', e.target.value)}><option value="">Not set</option>{Object.entries(p.statuses).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field></div> : null}
                        <Button type="submit" disabled={add.processing || !add.data.class}>Add or change rule</Button>
                    </form>
                </Panel>

                <Panel title="4. Kid stage" description="From age: Newborn until the second age stage starts, Suckling until weaning age, then Weaned (or when the weaning is recorded).">
                    <p className="text-sm">Newborn: first <strong>{p.kid.newbornDays} days</strong> · Weaned from <strong>{p.kid.weaningDays} days</strong>. <Link href="/breeding?tab=settings" className="font-semibold text-cobalt-800 hover:underline">Change weaning age</Link></p>
                </Panel>

                <Panel title="5. Breeding and sale" description="Worked out from the records; qualified goats and market weight are only flags, a person approves.">
                    <ul className="space-y-1 text-sm">
                        <li>Breeding eligibility (all breeds, females): {p.breeding.min_age_months} months, {p.breeding.min_weight_kg} KG{p.breeding.min_bcs ? `, body condition ${p.breeding.min_bcs}` : ''}. <Link href="/breeding/eligibility#rules" className="font-semibold text-cobalt-800 hover:underline">Edit breeding rules</Link></li>
                        <li>Market weight: males {p.market.male} KG, females {p.market.female} KG. <Link href="/sales/readiness" className="font-semibold text-cobalt-800 hover:underline">Edit market weight</Link></li>
                    </ul>
                </Panel>
            </div>
        </>
    );
}
