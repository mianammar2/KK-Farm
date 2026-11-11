import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, ChevronRight } from 'lucide-react';
import { Button, Field, PageHeader, Panel } from '../components/ui';

type Breed = { id: number; name: string; code: string; next: string; gestation: number | null; lightBelow: number | null; goats: number };
type Props = { section: 'breeds' | 'ages' | 'system' | null; ages: { growerFrom: number; adultFrom: number }; breeds: Breed[]; counts: { users: number; fields: number; overrides: number }; environment: Record<string, string> };

/** D-215: each settings page stands on its own; /settings is the master page that links to all of them. */
const PAGES = [
    { href: '/settings/users', title: 'Users', note: (p: Props) => `${p.counts.users} people can sign in. Add people, set role and department, and tick what each person may open or do.` },
    { href: '/settings/access', title: 'Access Rights by Role', note: (p: Props) => `What each role opens and may do. ${p.counts.overrides ? `${p.counts.overrides} people have their own ticks on top.` : 'Nobody has their own ticks yet.'}` },
    { href: '/settings/breeds', title: 'Breeds', note: (p: Props) => `${p.breeds.length} breeds, their tag codes and light weight lines.` },
    { href: '/settings/ages', title: 'Age Groups', note: (p: Props) => `Kid under ${p.ages.growerFrom} months, adult from ${p.ages.adultFrom} months.` },
    { href: '/settings/fields', title: 'Register Fields', note: (p: Props) => `${p.counts.fields} custom fields on the goat register.` },
    { href: '/settings/system', title: 'System', note: () => 'Versions of this installation.' },
];

export default function Settings(p: Props) {
    const back = <Link href="/settings"><Button variant="secondary"><ArrowLeft size={16} aria-hidden /> Settings</Button></Link>;
    if (p.section === 'breeds') return <><Head title="Breeds" /><PageHeader title="Breeds" description="Breed names, the code each tag starts with, and the light weight line used to estimate age." actions={back} /><div className="max-w-2xl"><Breeds breeds={p.breeds} /></div></>;
    if (p.section === 'ages') return <><Head title="Age Groups" /><PageHeader title="Age Groups" description="Worked out from each goat's birth date." actions={back} /><div className="max-w-xl"><Ages ages={p.ages} /></div></>;
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
            <ul className="divide-y divide-rule text-sm">{breeds.map((b) => <li key={b.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2"><span className="font-medium">{b.name} <span className="ml-1 rounded-[4px] bg-cobalt-100 px-1.5 py-0.5 font-mono text-xs text-cobalt-800">{b.code}</span></span><span className="text-right text-sm text-ink-soft">{b.goats} goats · next {b.next}</span><LightLine id={b.id} value={b.lightBelow} /></li>)}</ul>
            <p className="border-t border-rule px-4 py-2 text-xs text-ink-soft">Light Below: a goat with no teeth recorded is aged from its first weighing, about 7 months under this weight and about 10 months at or over it. Leave empty for no estimate.</p>
            <form onSubmit={(e) => { e.preventDefault(); form.post('/settings/breeds', { preserveScroll: true, onSuccess: () => form.reset() }); }} className="flex items-end gap-2 border-t border-rule p-4">
                <div className="flex-1"><Field label="Add a breed" error={form.errors.name}><input className="field" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field></div>
                <div className="w-24"><Field label="Tag code" error={form.errors.code}><input className="field font-mono uppercase" maxLength={8} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} placeholder="Auto" /></Field></div>
                <Button type="submit" disabled={form.processing}>Add</Button>
            </form>
        </Panel>
    );
}

function Ages({ ages }: { ages: Props['ages'] }) {
    const ageForm = useForm({ grower_from_months: ages.growerFrom, adult_from_months: ages.adultFrom });
    return (
        <Panel>
            <form onSubmit={(e) => { e.preventDefault(); ageForm.post('/settings/ages', { preserveScroll: true }); }} className="space-y-3">
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Grower from (months)" error={ageForm.errors.grower_from_months}><input className="field" type="number" min={1} max={24} inputMode="numeric" value={ageForm.data.grower_from_months} onChange={(e) => ageForm.setData('grower_from_months', Number(e.target.value))} /></Field>
                    <Field label="Adult from (months)" error={ageForm.errors.adult_from_months}><input className="field" type="number" min={2} max={60} inputMode="numeric" value={ageForm.data.adult_from_months} onChange={(e) => ageForm.setData('adult_from_months', Number(e.target.value))} /></Field>
                </div>
                <p className="text-sm text-ink-soft">Kid: under {ageForm.data.grower_from_months} months · Grower: {ageForm.data.grower_from_months}–{ageForm.data.adult_from_months} months · Adult: {ageForm.data.adult_from_months} months and older</p>
                <Button type="submit" variant="secondary" disabled={ageForm.processing || !ageForm.isDirty}>Save age groups</Button>
            </form>
        </Panel>
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
