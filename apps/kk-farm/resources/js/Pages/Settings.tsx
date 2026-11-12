import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, ChevronRight, Pencil, Trash2 } from 'lucide-react';
import { Button, Field, PageHeader, Panel } from '../components/ui';

type Breed = { id: number; name: string; code: string; next: string; gestation: number | null; lightBelow: number | null; goats: number; used: number };
type Group = { key: string; label: string; goats: number };
type Tooth = { id: number; key: string; label: string; from: number; to: number | null; estimate: number; goats: number };
type Props = { section: 'breeds' | 'ages' | 'system' | null; ages: { growerFrom: number; adultFrom: number; groups: Group[] }; teeth: Tooth[]; breeds: Breed[]; counts: { users: number; fields: number; overrides: number }; environment: Record<string, string> };

/** D-215: each settings page stands on its own; /settings is the master page that links to all of them. */
const PAGES = [
    { href: '/settings/users', title: 'Users', note: (p: Props) => `${p.counts.users} people can sign in. Add people, set role and department, and tick what each person may open or do.` },
    { href: '/settings/access', title: 'Access Rights by Role', note: (p: Props) => `What each role opens and may do. ${p.counts.overrides ? `${p.counts.overrides} people have their own ticks on top.` : 'Nobody has their own ticks yet.'}` },
    { href: '/settings/breeds', title: 'Breeds', note: (p: Props) => `${p.breeds.length} breeds, their tag codes and light weight lines.` },
    { href: '/settings/ages', title: 'Age Groups', note: (p: Props) => `Kid under ${p.ages.growerFrom} months, adult from ${p.ages.adultFrom} months, and the teeth stages.` },
    { href: '/settings/fields', title: 'Register Fields', note: (p: Props) => `${p.counts.fields} custom fields on the goat register.` },
    { href: '/settings/system', title: 'System', note: () => 'Versions of this installation.' },
];

export default function Settings(p: Props) {
    const back = <Link href="/settings"><Button variant="secondary"><ArrowLeft size={16} aria-hidden /> Settings</Button></Link>;
    if (p.section === 'breeds') return <><Head title="Breeds" /><PageHeader title="Breeds" description="Breed names, the code each tag starts with, and the light weight line used to estimate age." actions={back} /><div className="max-w-2xl"><Breeds breeds={p.breeds} /></div></>;
    if (p.section === 'ages') return <><Head title="Age Groups" /><PageHeader title="Age Groups" description="Age groups are worked out from each goat's birth date. Teeth give a bought goat's estimated birth date." actions={back} /><div className="max-w-3xl space-y-4"><Ages ages={p.ages} /><TeethStages teeth={p.teeth} ages={p.ages} /></div></>;
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

const rowCls = 'flex flex-wrap items-center justify-between gap-2 px-4 py-2';
const editBtn = 'inline-flex min-h-9 items-center gap-1 rounded-tile px-2 text-xs font-semibold text-cobalt-800 hover:bg-glaze';
const delBtn = 'inline-flex min-h-9 items-center gap-1 rounded-tile px-2 text-xs font-semibold text-crit-700 hover:bg-crit-100 disabled:cursor-not-allowed disabled:text-ink-faint disabled:hover:bg-transparent';
const range = (from: number, to: number | null) => (to === null ? `${from} months and older` : `${from}–${to} months`);
const groupOf = (months: number, a: Props['ages']) => (months < a.growerFrom ? 'Kid' : months < a.adultFrom ? 'Grower' : 'Adult');

/** D-220: the three age groups as rows, like Breeds. Each row edits its own boundary; Kid, Grower and Adult drive feeding and the goat category, so they are not deleted. */
function Ages({ ages }: { ages: Props['ages'] }) {
    const [editing, setEditing] = useState<string | null>(null);
    const form = useForm({ grower_from_months: ages.growerFrom, adult_from_months: ages.adultFrom });
    const bounds: Record<string, [number, number | null]> = { kid: [0, ages.growerFrom], grower: [ages.growerFrom, ages.adultFrom], adult: [ages.adultFrom, null] };
    const num = (k: 'grower_from_months' | 'adult_from_months', label: string) => (
        <div className="w-36"><Field label={label} error={form.errors[k]}><input className="field" type="number" min={1} max={60} inputMode="numeric" value={form.data[k]} onChange={(e) => form.setData(k, Number(e.target.value))} autoFocus /></Field></div>
    );
    return (
        <Panel flush title="Age Groups">
            <ul className="divide-y divide-rule text-sm">{ages.groups.map((g) => editing === g.key ? (
                <li key={g.key} className="px-4 py-3">
                    <form onSubmit={(e) => { e.preventDefault(); form.post('/settings/ages', { preserveScroll: true, onSuccess: () => setEditing(null) }); }} className="flex flex-wrap items-end gap-2">
                        <strong className="mr-2 self-center">{g.label}</strong>
                        {g.key === 'kid' ? num('grower_from_months', 'Kid until (months)') : null}
                        {g.key === 'grower' ? <>{num('grower_from_months', 'From (months)')}{num('adult_from_months', 'Until (months)')}</> : null}
                        {g.key === 'adult' ? num('adult_from_months', 'From (months)') : null}
                        <Button type="submit" disabled={form.processing || !form.isDirty}>Save</Button>
                        <Button type="button" variant="secondary" onClick={() => { form.reset(); form.clearErrors(); setEditing(null); }}>Cancel</Button>
                    </form>
                </li>
            ) : (
                <li key={g.key} className={rowCls}>
                    <span className="font-medium">{g.label}</span>
                    <span className="ml-auto text-right text-ink-soft">{range(...bounds[g.key])} · {g.goats} goats</span>
                    <button type="button" className={editBtn} onClick={() => { form.reset(); setEditing(g.key); }}><Pencil size={14} aria-hidden /> Edit</button>
                </li>
            ))}</ul>
            <p className="border-t border-rule px-4 py-2 text-xs text-ink-soft">Kid, Grower and Adult set feeding amounts and the goat category, so they can be changed but not deleted. Every goat is regrouped from its birth date when you save.</p>
        </Panel>
    );
}

/** D-220: teeth stages, like Breeds: add, edit, delete when no goat has it. */
function TeethStages({ teeth, ages }: { teeth: Tooth[]; ages: Props['ages'] }) {
    const add = useForm({ label: '', from_months: '', to_months: '', estimate_months: '' });
    return (
        <Panel flush title="Teeth" description="Count the big front teeth on the lower jaw. Picking a stage on a bought goat sets its estimated birth date from the age used.">
            <ul className="divide-y divide-rule text-sm">{teeth.map((t) => <ToothRow key={t.id} t={t} ages={ages} />)}</ul>
            <form onSubmit={(e) => { e.preventDefault(); add.transform((d) => ({ ...d, to_months: d.to_months === '' ? null : d.to_months })); add.post('/settings/teeth', { preserveScroll: true, onSuccess: () => add.reset() }); }} className="flex flex-wrap items-end gap-2 border-t border-rule p-4">
                <ToothFields form={add} />
                <Button type="submit" disabled={add.processing}>Add</Button>
            </form>
        </Panel>
    );
}

type ToothForm = { data: { label: string; from_months: string; to_months: string; estimate_months: string }; errors: Partial<Record<string, string>>; setData: (k: 'label' | 'from_months' | 'to_months' | 'estimate_months', v: string) => void };
function ToothFields({ form, autoFocus = false }: { form: ToothForm; autoFocus?: boolean }) {
    const n = (k: 'from_months' | 'to_months' | 'estimate_months', label: string, placeholder?: string) => (
        <div className="w-24"><Field label={label} error={form.errors[k]}><input className="field" type="number" min={0} max={240} inputMode="numeric" placeholder={placeholder} value={form.data[k]} onChange={(e) => form.setData(k, e.target.value)} /></Field></div>
    );
    return (
        <>
            <div className="min-w-44 flex-1"><Field label="Teeth stage" error={form.errors.label}><input className="field" value={form.data.label} onChange={(e) => form.setData('label', e.target.value)} placeholder="e.g. 2 teeth" autoFocus={autoFocus} /></Field></div>
            {n('from_months', 'From (months)')}{n('to_months', 'To (months)', 'Older')}{n('estimate_months', 'Age used')}
        </>
    );
}

function ToothRow({ t, ages }: { t: Tooth; ages: Props['ages'] }) {
    const [editing, setEditing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const form = useForm({ label: t.label, from_months: String(t.from), to_months: t.to === null ? '' : String(t.to), estimate_months: String(t.estimate) });
    const remove = () => {
        if (!window.confirm(`Delete the teeth stage ${t.label}?`)) return;
        router.delete(`/settings/teeth/${t.id}`, { preserveScroll: true, onError: (e) => setError(e.teeth ?? 'Could not delete.') });
    };
    if (editing) {
        return (
            <li className="px-4 py-3">
                <form onSubmit={(e) => { e.preventDefault(); form.transform((d) => ({ ...d, to_months: d.to_months === '' ? null : d.to_months })); form.post(`/settings/teeth/${t.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) }); }} className="flex flex-wrap items-end gap-2">
                    <ToothFields form={form} autoFocus />
                    <Button type="submit" disabled={form.processing || !form.isDirty}>Save</Button>
                    <Button type="button" variant="secondary" onClick={() => { form.reset(); form.clearErrors(); setEditing(false); }}>Cancel</Button>
                </form>
            </li>
        );
    }
    return (
        <li className={rowCls}>
            <span className="font-medium">{t.label}</span>
            <span className="ml-auto text-right text-ink-soft">{range(t.from, t.to)} · age used {t.estimate} months ({groupOf(t.estimate, ages)}) · {t.goats} goats</span>
            <span className="flex gap-1">
                <button type="button" className={editBtn} onClick={() => setEditing(true)}><Pencil size={14} aria-hidden /> Edit</button>
                <button type="button" className={delBtn} disabled={t.goats > 0} title={t.goats > 0 ? `${t.goats} goats have this stage, so it stays` : undefined} onClick={remove}><Trash2 size={14} aria-hidden /> Delete</button>
            </span>
            {error ? <p className="w-full text-xs font-semibold text-crit-700">{error}</p> : null}
        </li>
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
