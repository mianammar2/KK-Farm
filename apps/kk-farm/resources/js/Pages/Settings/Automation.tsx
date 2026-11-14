import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Pencil, RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button, Field, PageHeader, Panel } from '../../components/ui';

type Pick = { value: string; label: string; name: string; sex: string; purpose: string | null; class: string; stage: string | null; active: boolean; custom: boolean; goats: number };
type RuleRow = { id: number; sex: string; stage: string; purpose: string; class: string; status: string | null; goats: number; role: string | null; fixed: boolean };
type Props = {
    rules: RuleRow[]; stages: Array<{ code: string; label: string; range: string; group: string }>; teeth: Array<{ code: string; label: string; age: string }>;
    picks: Pick[]; goalPurposes: Record<string, string>; categories: Record<string, string>; categorySex: Record<string, string>;
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
            <PageHeader title="Automation Rules" description="The registry asks for origin, breed, gender code, age (birth date, age stage or teeth), purpose, weight and PEN. Everything below is filled in by itself from these rules, for every goat at once."
                actions={<Link href="/settings"><Button variant="secondary"><ArrowLeft size={16} aria-hidden /> Settings</Button></Link>} />
            <div className="space-y-4">
                <Panel flush title="1. Gender codes" description="Picking a code in the Gender field fills in gender, purpose and category; for a bought goat with no age yet also the age stage (and so the teeth and an estimated birth date). Changes apply to new registrations.">
                    <div className="overflow-x-auto"><table className="data-table min-w-[900px]"><thead><tr><th>Code</th><th>Name</th><th>Gender</th><th>Purpose</th><th>Category</th><th>Age if not known</th><th className="text-right">Goats</th><th /></tr></thead>
                        <tbody>{p.picks.map((r) => <CodeRow key={r.value} r={r} p={p} />)}</tbody></table></div>
                    <AddCode p={p} />
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
                        <tbody>{p.rules.map((r) => <RuleRowView key={r.id} r={r} p={p} />)}</tbody>
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

const editBtn = 'inline-flex items-center gap-1 text-xs font-semibold text-cobalt-800 hover:underline';
const delBtn = 'ml-3 inline-flex items-center gap-1 text-xs font-semibold text-crit-700 hover:underline';
type CodeForm = { label: string; purpose: string; class: string; stage: string };

function CodeFields({ form, setForm, p, sex }: { form: CodeForm; setForm: (f: CodeForm) => void; p: Props; sex: string }) {
    const fits = Object.entries(p.categories).filter(([k]) => !p.categorySex[k] || p.categorySex[k] === sex);
    return (
        <>
            <td><input className="field py-1" aria-label="Name" value={form.label} maxLength={40} onChange={(e) => setForm({ ...form, label: e.target.value })} /></td>
            <td>{sex === 'female' ? 'Female' : 'Male'}</td>
            <td><select className="field py-1" aria-label="Purpose" value={form.purpose} onChange={(e) => setForm({ ...form, purpose: e.target.value })}><option value="">None</option>{Object.entries(p.goalPurposes).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></td>
            <td><select className="field py-1" aria-label="Category" value={form.class} onChange={(e) => setForm({ ...form, class: e.target.value })}><option value="">Choose…</option>{fits.map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></td>
            <td><select className="field py-1" aria-label="Age if not known" value={form.stage} onChange={(e) => setForm({ ...form, stage: e.target.value })}><option value="">Leave empty</option>{p.stages.map((s) => <option key={s.code} value={s.code}>{s.code} · {s.label}</option>)}</select></td>
        </>
    );
}

/** D-240: a gender code row: read-only with Edit and Delete (the standard on every list). */
function CodeRow({ r, p }: { r: Pick; p: Props }) {
    const [editing, setEditing] = useState(false);
    const start = { label: r.name, purpose: r.purpose ?? '', class: r.class, stage: r.stage ?? '' };
    const [form, setForm] = useState<CodeForm>(start);
    if (editing) {
        return (
            <tr><td className="font-mono font-semibold">{r.value}</td><CodeFields form={form} setForm={setForm} p={p} sex={r.sex} /><td />
                <td className="text-right whitespace-nowrap"><Button onClick={() => router.post(`/settings/automation/codes/${r.value}`, form, { preserveScroll: true, onSuccess: () => setEditing(false) })} disabled={!form.label || !form.class}>Save</Button>
                    <Button variant="secondary" className="ml-2" onClick={() => { setForm(start); setEditing(false); }}>Cancel</Button></td></tr>
        );
    }
    return (
        <tr className={r.active ? '' : 'opacity-50'}>
            <td className="font-mono font-semibold">{r.value}</td><td>{r.name}{r.custom ? <span className="ml-1.5 text-xs text-turq-800">farm code</span> : null}{!r.active ? <span className="ml-1.5 text-xs text-ink-soft">(removed)</span> : null}</td>
            <td>{r.sex === 'female' ? 'Female' : 'Male'}</td><td>{r.purpose ? p.goalPurposes[r.purpose] ?? r.purpose : '—'}</td><td>{p.categories[r.class] ?? r.class}</td><td className="font-mono">{r.stage ?? '—'}</td>
            <td className="text-right">{r.goats}</td>
            <td className="text-right whitespace-nowrap">{r.active ? <>
                <button type="button" className={editBtn} onClick={() => setEditing(true)}><Pencil size={14} aria-hidden /> Edit</button>
                <button type="button" className={delBtn} onClick={() => { if (window.confirm(`Delete ${r.value}? ${r.goats ? 'Goats with it keep it; it leaves the Gender field.' : ''}`)) router.delete(`/settings/automation/codes/${r.value}`, { preserveScroll: true }); }}><Trash2 size={14} aria-hidden /> Delete</button>
            </> : <button type="button" className={editBtn} onClick={() => router.post(`/settings/automation/codes/${r.value}/restore`, {}, { preserveScroll: true })}><RotateCcw size={14} aria-hidden /> Bring back</button>}</td>
        </tr>
    );
}

/** D-240: add the farm's own gender code. */
function AddCode({ p }: { p: Props }) {
    const add = useForm({ code: '', sex: 'female', label: '', purpose: '', class: '', stage: '' });
    const fits = Object.entries(p.categories).filter(([k]) => !p.categorySex[k] || p.categorySex[k] === add.data.sex);
    return (
        <form className="flex flex-wrap items-end gap-2 border-t border-rule p-4" onSubmit={(e) => { e.preventDefault(); add.post('/settings/automation/codes', { preserveScroll: true, onSuccess: () => add.reset() }); }}>
            <div className="w-24"><Field label="Code" error={add.errors.code}><input className="field font-mono uppercase" maxLength={4} placeholder="QB" value={add.data.code} onChange={(e) => add.setData('code', e.target.value.toUpperCase())} /></Field></div>
            <div className="w-44"><Field label="Name" error={add.errors.label}><input className="field" maxLength={40} placeholder="Qurbani Buck" value={add.data.label} onChange={(e) => add.setData('label', e.target.value)} /></Field></div>
            <div className="w-28"><Field label="Gender" error={add.errors.sex}><select className="field" value={add.data.sex} onChange={(e) => add.setData((d) => ({ ...d, sex: e.target.value, class: '' }))}><option value="female">Female</option><option value="male">Male</option></select></Field></div>
            <div className="w-44"><Field label="Purpose" error={add.errors.purpose}><select className="field" value={add.data.purpose} onChange={(e) => add.setData('purpose', e.target.value)}><option value="">None</option>{Object.entries(p.goalPurposes).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field></div>
            <div className="w-52"><Field label="Category" error={add.errors.class}><select className="field" value={add.data.class} onChange={(e) => add.setData('class', e.target.value)}><option value="">Choose…</option>{fits.map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field></div>
            <div className="w-40"><Field label="Age if not known" error={add.errors.stage}><select className="field" value={add.data.stage} onChange={(e) => add.setData('stage', e.target.value)}><option value="">Leave empty</option>{p.stages.map((s) => <option key={s.code} value={s.code}>{s.code} · {s.label}</option>)}</select></Field></div>
            <Button type="submit" disabled={add.processing || !add.data.code || !add.data.label || !add.data.class}>Add code</Button>
        </form>
    );
}

/** D-240: a category rule row: read-only with Edit and Delete (base rows can be edited, not deleted). */
function RuleRowView({ r, p }: { r: RuleRow; p: Props }) {
    const [editing, setEditing] = useState(false);
    const [cls, setCls] = useState(r.class);
    const [status, setStatus] = useState(r.status ?? '');
    const fits = Object.entries(p.categories).filter(([k]) => !p.categorySex[k] || p.categorySex[k] === r.sex);
    return (
        <tr>
            <td>{r.sex === 'female' ? 'Female' : 'Male'}</td><td className="font-mono font-semibold">{r.stage}</td><td>{p.purposes[r.purpose] ?? r.purpose}</td>
            <td>{editing ? <select className="field py-1" aria-label="Goat category" value={cls} onChange={(e) => setCls(e.target.value)}>{fits.map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select> : p.categories[r.class] ?? r.class}</td>
            <td>{r.sex !== 'female' ? <span className="text-ink-faint">—</span> : editing ? <select className="field py-1" aria-label="Female status" value={status} onChange={(e) => setStatus(e.target.value)}><option value="">Not set</option>{Object.entries(p.statuses).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select> : (r.status ? p.statuses[r.status] ?? r.status : <span className="text-ink-faint">Not set</span>)}</td>
            <td className="text-right">{r.goats}</td>
            <td className="text-right whitespace-nowrap">{editing ? <>
                <Button onClick={() => router.post('/settings/automation/rules', { sex: r.sex, stage: r.stage, purpose: r.purpose, class: cls, status }, { preserveScroll: true, onSuccess: () => setEditing(false) })}>Save</Button>
                <Button variant="secondary" className="ml-2" onClick={() => { setCls(r.class); setStatus(r.status ?? ''); setEditing(false); }}>Cancel</Button>
            </> : <>
                <button type="button" className={editBtn} onClick={() => setEditing(true)}><Pencil size={14} aria-hidden /> Edit</button>
                {!r.fixed ? <button type="button" className={delBtn} onClick={() => { if (window.confirm('Delete this rule?')) router.delete(`/settings/automation/rules/${r.id}`, { preserveScroll: true }); }}><Trash2 size={14} aria-hidden /> Delete</button> : null}
            </>}</td>
        </tr>
    );
}
