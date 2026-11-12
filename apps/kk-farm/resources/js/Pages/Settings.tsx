import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, ChevronRight, Pencil, Trash2 } from 'lucide-react';
import { Button, Field, PageHeader, Panel } from '../components/ui';

type Breed = { id: number; name: string; code: string; next: string; gestation: number | null; lightBelow: number | null; goats: number; used: number };
type Group = { key: string; label: string; goats: number };
type Tooth = { id: number; key: string; label: string; local: string | null; from: number; to: number | null; estimate: number; goats: number };
type Stage = { id: number; code: string; label: string; group: string; at: number; unit: 'days' | 'months'; range: string; goats: number }; // D-229
type Props = { stages: Stage[]; section: 'breeds' | 'ages' | 'system' | null; ages: { growerFrom: number; adultFrom: number; groups: Group[] }; teeth: Tooth[]; breeds: Breed[]; counts: { users: number; fields: number; overrides: number }; environment: Record<string, string> };

/** D-215: each settings page stands on its own; /settings is the master page that links to all of them. */
const PAGES = [
    { href: '/settings/users', title: 'Users', note: (p: Props) => `${p.counts.users} people can sign in. Add people, set role and department, and tick what each person may open or do.` },
    { href: '/settings/access', title: 'Access Rights by Role', note: (p: Props) => `What each role opens and may do. ${p.counts.overrides ? `${p.counts.overrides} people have their own ticks on top.` : 'Nobody has their own ticks yet.'}` },
    { href: '/settings/breeds', title: 'Breeds', note: (p: Props) => `${p.breeds.length} breeds, their tag codes and light weight lines.` },
    { href: '/settings/ages', title: 'Age Groups', note: (p: Props) => `Kid under ${p.ages.growerFrom} months, adult from ${p.ages.adultFrom} months, with each stage's code and teeth.` },
    { href: '/settings/automation', title: 'Automation Rules', note: () => 'What the registry fills in by itself: gender codes, category by age and purpose, female status, kid stages, teeth by age.' },
    { href: '/settings/fields', title: 'Register Fields', note: (p: Props) => `${p.counts.fields} custom fields on the goat register.` },
    { href: '/settings/system', title: 'System', note: () => 'Versions of this installation.' },
];

export default function Settings(p: Props) {
    const back = <Link href="/settings"><Button variant="secondary"><ArrowLeft size={16} aria-hidden /> Settings</Button></Link>;
    if (p.section === 'breeds') return <><Head title="Breeds" /><PageHeader title="Breeds" description="Breed names, the code each tag starts with, and the light weight line used to estimate age." actions={back} /><div className="max-w-2xl"><Breeds breeds={p.breeds} /></div></>;
    if (p.section === 'ages') return <><Head title="Age Groups" /><PageHeader title="Age Groups" description="The age stage moves on by itself from the birth date, and the teeth follow the age (from the Dental Codes' ages) unless the vet records a teeth check. Together they give each goat's code, e.g. GRW-MLT or ADT-T04." actions={back} /><div className="max-w-5xl space-y-4"><AgeStagesPanel stages={p.stages} /><DentalCodesPanel teeth={p.teeth} /></div></>;
    if (p.section === 'system') return <><Head title="System" /><PageHeader title="System" actions={back} /><Panel className="max-w-xl"><dl className="space-y-1 text-sm">{Object.entries(p.environment).map(([k, v]) => <div key={k} className="flex justify-between gap-2"><dt className="text-ink-soft capitalize">{k}</dt><dd className="font-medium">{v}</dd></div>)}</dl></Panel></>;
    return (
        <>
            <Head title="Settings" />
            <PageHeader title="Settings" description="The master page: every settings page, each one on its own." />
            <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">{PAGES.map((x) => (
                <li key={x.href}><Link href={x.href} className="group flex h-full flex-col gap-1 rounded-tile border border-rule bg-tile p-4 shadow-tile hover:border-cobalt-200">
                    <span className="flex items-center justify-between font-bold text-cobalt-900">{x.title}<ChevronRight size={18} className="text-ink-faint group-hover:text-cobalt-800" aria-hidden /></span>
                    <span className="text-sm text-ink-soft">{x.note(p)}</span>
                </Link></li>
            ))}</ul>
        </>
    );
}

function Breeds({ breeds }: { breeds: Breed[] }) {
    const form = useForm({ name: '', code: '' });
    return (
        <Panel flush>
            <ul className="divide-y divide-rule text-sm">{breeds.map((b) => <BreedRow key={b.id} b={b} />)}</ul>
            <p className="border-t border-rule px-4 py-2 text-xs text-ink-soft">Light Below: a goat with no teeth recorded is aged from its first weighing, about 7 months under this weight and about 10 months at or over it. Leave empty for no estimate.</p>
            <form onSubmit={(e) => { e.preventDefault(); form.post('/settings/breeds', { preserveScroll: true, onSuccess: () => form.reset() }); }} className="flex items-end gap-2 border-t border-rule p-4">
                <div className="flex-1"><Field label="Add a breed" error={form.errors.name}><input className="field" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field></div>
                <div className="w-24"><Field label="Tag code" error={form.errors.code}><input className="field font-mono uppercase" maxLength={8} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} placeholder="Auto" /></Field></div>
                <Button type="submit" disabled={form.processing}>Add</Button>
            </form>
        </Panel>
    );
}

/** D-219: one breed: edit its name and tag code, or delete it when no goat or record uses it. */
function BreedRow({ b }: { b: Breed }) {
    const [editing, setEditing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const form = useForm({ name: b.name, code: b.code });
    const remove = () => {
        if (!window.confirm(`Delete the breed ${b.name}?`)) return;
        router.delete(`/settings/breeds/${b.id}`, { preserveScroll: true, onError: (e) => setError(e.breed ?? 'Could not delete.') });
    };
    if (editing) {
        return (
            <li className="px-4 py-3">
                <form onSubmit={(e) => { e.preventDefault(); form.post(`/settings/breeds/${b.id}/edit`, { preserveScroll: true, onSuccess: () => setEditing(false) }); }} className="flex flex-wrap items-end gap-2">
                    <div className="min-w-48 flex-1"><Field label="Breed name" error={form.errors.name}><input className="field" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus /></Field></div>
                    <div className="w-24"><Field label="Tag code" error={form.errors.code}><input className="field font-mono uppercase" maxLength={8} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} /></Field></div>
                    <Button type="submit" disabled={form.processing || !form.isDirty}>Save</Button>
                    <Button type="button" variant="secondary" onClick={() => { form.reset(); form.clearErrors(); setEditing(false); }}>Cancel</Button>
                </form>
                {b.goats && form.data.code !== b.code ? <p className="mt-1 text-xs text-ink-soft">Goats already tagged keep their Tag ID. New tags will start with {form.data.code || '…'}.</p> : null}
            </li>
        );
    }
    return (
        <li className="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
            <span className="font-medium">{b.name} <span className="ml-1 rounded-[4px] bg-cobalt-100 px-1.5 py-0.5 font-mono text-xs text-cobalt-800">{b.code}</span></span>
            <span className="ml-auto text-right text-sm text-ink-soft">{b.goats} goats · next {b.next}</span>
            <span className="flex gap-1">
                <button type="button" className="inline-flex min-h-9 items-center gap-1 rounded-tile px-2 text-xs font-semibold text-cobalt-800 hover:bg-glaze" onClick={() => setEditing(true)}><Pencil size={14} aria-hidden /> Edit</button>
                <button type="button" className="inline-flex min-h-9 items-center gap-1 rounded-tile px-2 text-xs font-semibold text-crit-700 hover:bg-crit-100 disabled:cursor-not-allowed disabled:text-ink-faint disabled:hover:bg-transparent" disabled={b.used > 0} title={b.used > 0 ? `${b.used} goats or records use this breed, so it stays` : undefined} onClick={remove}><Trash2 size={14} aria-hidden /> Delete</button>
            </span>
            <LightLine id={b.id} value={b.lightBelow} />
            {b.used > 0 && !b.goats ? <p className="w-full text-xs text-ink-faint">Used by {b.used} past goats or records, so it cannot be deleted.</p> : null}
            {error ? <p className="w-full text-xs font-semibold text-crit-700">{error}</p> : null}
        </li>
    );
}

const editBtn = 'inline-flex min-h-9 items-center gap-1 rounded-tile px-2 text-xs font-semibold text-cobalt-800 hover:bg-glaze';
const delBtn = 'inline-flex min-h-9 items-center gap-1 rounded-tile px-2 text-xs font-semibold text-crit-700 hover:bg-crit-100 disabled:cursor-not-allowed disabled:text-ink-faint disabled:hover:bg-transparent';

const GROUP_LABEL: Record<string, string> = { kid: 'Kid', grower: 'Grower', adult: 'Adult' };
type StageForm = { data: { code: string; label: string; age_group: string; starts_at: string; starts_unit: string }; errors: Partial<Record<string, string>>; setData: (k: 'code' | 'label' | 'age_group' | 'starts_at' | 'starts_unit', v: string) => void };
const blankStage = { code: '', label: '', age_group: 'adult', starts_at: '', starts_unit: 'months' };

/** D-229: the farm's age stages. Each starts at an age (days or calendar months) and lasts until the next one starts. */
function AgeStagesPanel({ stages }: { stages: Stage[] }) {
    const add = useForm(blankStage);
    return (
        <Panel flush title="Age Stages" description="Each stage starts at an age and lasts until the next one starts, so a goat moves on by itself. 'Counts as' decides feeding amounts and the goat category. Reaching Mature Adult does not make a goat old or a cull.">
            <div className="overflow-x-auto"><table className="data-table min-w-[640px]">
                <thead><tr><th>ERP Code</th><th>Age Category</th><th>Age</th><th>Counts as</th><th className="text-right">Goats</th><th /></tr></thead>
                <tbody>{stages.map((st) => <StageRow key={st.id} st={st} />)}</tbody>
            </table></div>
            <form onSubmit={(e) => { e.preventDefault(); add.post('/settings/stages', { preserveScroll: true, onSuccess: () => add.reset() }); }} className="flex flex-wrap items-end gap-2 border-t border-rule p-4">
                <StageFields form={add} />
                <Button type="submit" disabled={add.processing}>Add</Button>
            </form>
        </Panel>
    );
}

function StageFields({ form }: { form: StageForm }) {
    return (
        <>
            <div className="w-28"><Field label="ERP Code" error={form.errors.code}><input className="field font-mono uppercase" maxLength={12} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} placeholder="ADT" /></Field></div>
            <div className="w-40"><Field label="Age Category" error={form.errors.label}><input className="field" value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} placeholder="Adult" /></Field></div>
            <div className="w-24"><Field label="Starts at" error={form.errors.starts_at}><input className="field" type="number" min={0} max={3650} inputMode="numeric" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} /></Field></div>
            <div className="w-28"><Field label="Unit" error={form.errors.starts_unit}><select className="field" value={form.data.starts_unit} onChange={(e) => form.setData('starts_unit', e.target.value)}><option value="days">Days</option><option value="months">Months</option></select></Field></div>
            <div className="w-28"><Field label="Counts as" error={form.errors.age_group}><select className="field" value={form.data.age_group} onChange={(e) => form.setData('age_group', e.target.value)}>{Object.entries(GROUP_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field></div>
        </>
    );
}

function StageRow({ st }: { st: Stage }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({ code: st.code, label: st.label, age_group: st.group, starts_at: String(st.at), starts_unit: st.unit });
    if (editing) {
        return (
            <tr><td colSpan={6}>
                <form onSubmit={(e) => { e.preventDefault(); form.post(`/settings/stages/${st.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) }); }} className="flex flex-wrap items-end gap-2 py-1">
                    <StageFields form={form} />
                    <Button type="submit" disabled={form.processing || !form.isDirty}>Save</Button>
                    <Button type="button" variant="secondary" onClick={() => { form.reset(); form.clearErrors(); setEditing(false); }}>Cancel</Button>
                </form>
            </td></tr>
        );
    }
    return (
        <tr>
            <td className="font-mono font-semibold">{st.code}</td>
            <td>{st.label}</td>
            <td>{st.range}</td>
            <td>{GROUP_LABEL[st.group] ?? st.group}</td>
            <td className="text-right">{st.goats}</td>
            <td className="text-right whitespace-nowrap">
                <button type="button" className={editBtn} onClick={() => setEditing(true)}><Pencil size={14} aria-hidden /> Edit</button>
                <button type="button" className={delBtn} onClick={() => { if (window.confirm(`Delete the age stage ${st.code}?`)) router.delete(`/settings/stages/${st.id}`, { preserveScroll: true }); }}><Trash2 size={14} aria-hidden /> Delete</button>
            </td>
        </tr>
    );
}

/** D-229: the farm's dental codes (MLT, T02 …). Teeth come from a mouth check, never from age; no check = UNK. */
function DentalCodesPanel({ teeth }: { teeth: Tooth[] }) {
    const add = useForm({ key: '', label: '', local_term: '', from_months: '', to_months: '', estimate_months: '' });
    return (
        <Panel flush title="Dental Codes" description="Each code's ages decide the teeth a goat shows for its age, and the estimate for a bought goat's birth date. A teeth check by the vet wins over the age. Worn, broken or missing teeth are recorded as the condition on each check.">
            <div className="overflow-x-auto"><table className="data-table min-w-[640px]">
                <thead><tr><th>Code</th><th>Classification</th><th>Farm Term</th><th>About</th><th className="text-right">Estimate</th><th className="text-right">Goats</th><th /></tr></thead>
                <tbody>{teeth.map((t) => <ToothRow key={t.id} t={t} />)}</tbody>
            </table></div>
            <form onSubmit={(e) => { e.preventDefault(); add.transform((d) => ({ ...d, to_months: d.to_months === '' ? null : d.to_months })); add.post('/settings/teeth', { preserveScroll: true, onSuccess: () => add.reset() }); }} className="flex flex-wrap items-end gap-2 border-t border-rule p-4">
                <div className="w-24"><Field label="Code" error={add.errors.key}><input className="field font-mono uppercase" maxLength={8} value={add.data.key} onChange={(e) => add.setData('key', e.target.value.toUpperCase())} placeholder="T10" /></Field></div>
                <ToothFields form={add} />
                <Button type="submit" disabled={add.processing}>Add</Button>
            </form>
        </Panel>
    );
}

type ToothForm = { data: { label: string; local_term: string; from_months: string; to_months: string; estimate_months: string }; errors: Partial<Record<string, string>>; setData: (k: 'label' | 'local_term' | 'from_months' | 'to_months' | 'estimate_months', v: string) => void };

function ToothFields({ form }: { form: ToothForm }) {
    const n = (k: 'from_months' | 'to_months' | 'estimate_months', label: string, ph?: string) => <div className="w-24"><Field label={label} error={form.errors[k]}><input className="field" type="number" min={0} max={240} inputMode="numeric" placeholder={ph} value={form.data[k]} onChange={(e) => form.setData(k, e.target.value)} /></Field></div>;
    return (
        <>
            <div className="w-48"><Field label="Classification" error={form.errors.label}><input className="field" value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} placeholder="2 Permanent Incisors" /></Field></div>
            <div className="w-36"><Field label="Farm term" error={form.errors.local_term}><input className="field" value={form.data.local_term} onChange={(e) => form.setData('local_term', e.target.value)} placeholder="Donda" /></Field></div>
            {n('from_months', 'From (months)')}{n('to_months', 'To (months)', 'Older')}{n('estimate_months', 'Estimate')}
        </>
    );
}

function ToothRow({ t }: { t: Tooth }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({ label: t.label, local_term: t.local ?? '', from_months: String(t.from), to_months: t.to === null ? '' : String(t.to), estimate_months: String(t.estimate) });
    if (editing) {
        return (
            <tr><td colSpan={7}>
                <form onSubmit={(e) => { e.preventDefault(); form.transform((d) => ({ ...d, to_months: d.to_months === '' ? null : d.to_months })); form.post(`/settings/teeth/${t.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) }); }} className="flex flex-wrap items-end gap-2 py-1">
                    <span className="self-center font-mono font-semibold">{t.key}</span>
                    <ToothFields form={form} />
                    <Button type="submit" disabled={form.processing || !form.isDirty}>Save</Button>
                    <Button type="button" variant="secondary" onClick={() => { form.reset(); form.clearErrors(); setEditing(false); }}>Cancel</Button>
                </form>
            </td></tr>
        );
    }
    return (
        <tr>
            <td className="font-mono font-semibold">{t.key}</td>
            <td>{t.label}</td>
            <td>{t.local ?? <span className="text-ink-faint">None</span>}</td>
            <td>{t.to === null ? `${t.from}+ months` : `${t.from}–${t.to} months`}</td>
            <td className="text-right">{t.estimate} mo</td>
            <td className="text-right">{t.goats}</td>
            <td className="text-right whitespace-nowrap">
                <button type="button" className={editBtn} onClick={() => setEditing(true)}><Pencil size={14} aria-hidden /> Edit</button>
                <button type="button" className={delBtn} onClick={() => { if (window.confirm(`Delete the dental code ${t.key}?`)) router.delete(`/settings/teeth/${t.id}`, { preserveScroll: true }); }}><Trash2 size={14} aria-hidden /> Delete</button>
            </td>
        </tr>
    );
}

/** D-110: the breed's light weight line, saved when the field is left or Enter is pressed. */
function LightLine({ id, value }: { id: number; value: number | null }) {
    const [kg, setKg] = useState(value === null ? '' : String(value));
    const save = () => {
        if (kg === (value === null ? '' : String(value))) return;
        router.post(`/settings/breeds/${id}`, { young_below_kg: kg === '' ? null : kg }, { preserveScroll: true });
    };
    return (
        <label className="flex w-full items-center justify-end gap-2 text-xs text-ink-soft">
            Light Below
            <input className="field w-20 py-1 text-right" type="number" min={1} max={150} step={0.5} inputMode="decimal" value={kg} placeholder="None" onChange={(e) => setKg(e.target.value)} onBlur={save} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); save(); } }} />
            KG
        </label>
    );
}
