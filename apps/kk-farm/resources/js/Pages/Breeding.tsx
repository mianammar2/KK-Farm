import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, ChevronDown } from 'lucide-react';
import { useState } from 'react';
import GoatPortrait from '../components/GoatPortrait';
import PedigreeTree, { type PedigreeNode } from '../components/PedigreeTree';
import { Button, Chip, cx, Empty, Field, Note, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import type { Option, SharedProps } from '../types';

type Check = { id: number; on: string; method: string; result: string; fetuses: number | null; next: string | null; by: string; notes: string | null };
type MatingRow = {
    id: number; status: string; outcome: string; method: string; heat: string | null; cycle: number | null; handler: string | null; notes: string | null;
    alert: 'check_overdue' | 'recheck_overdue' | 'recheck_due' | 'edd_passed' | 'kidding_soon' | null;
    dam: { id: string; number: string; name: string | null; coat: string }; sire: { id: string; number: string; name: string | null } | null;
    mated: string; expected: string | null; checkDue: string | null; days: number | null; progress: number; active: boolean; checks: Check[];
};
type KiddingRow = { id: number; goatId: string; dam: string; on: string; alive: number; stillborn: number; total: number; litter: string; assisted: string; attendant: string; notes: string | null; kids: Array<{ id: string; number: string }> };
type Due = { id: string; number: string; name: string | null; coat: string; sex: string; dam: string | null; age: number; target: string; penId: number | null; state: 'due' | 'soon' | 'postponed'; postponedTo: string | null };
type Props = {
    matings: MatingRow[]; kiddings: KiddingRow[]; weaning: Due[];
    weaned: Array<{ id: number; kidId: string; kid: string; on: string; age: number | null; weight: number | null; method: string | null; by: string }>;
    performance: Array<{ id: string; number: string; name: string | null; kiddings: number; alive: number; stillborn: number; litter: number }>;
    focus: { id: string; number: string; tree: PedigreeNode } | null; pairCheck: { shared: number; sire: string; dam: string } | null;
    does: Option[]; bucks: Option[]; goats: Option[]; pens: Array<{ id: number; name: string }>;
    settings: { gestation: number; check: number; alert: number; weaning: number };
    options: { methods: Record<string, string>; checkMethods: Record<string, string>; results: Record<string, string>; assisted: Record<string, string>; weanMethods: Record<string, string> };
};

const label = (o: Option) => `${o.farm_number}${o.name ? ` · ${o.name}` : ''}`;
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const ALERT: Record<NonNullable<MatingRow['alert']>, { label: string; className: string }> = {
    check_overdue: { label: 'Check overdue', className: 'bg-crit-100 text-crit-700' },
    recheck_overdue: { label: 'Recheck overdue', className: 'bg-crit-100 text-crit-700' },
    recheck_due: { label: 'Recheck due', className: 'bg-watch-100 text-watch-700' },
    edd_passed: { label: 'Due date passed', className: 'bg-crit-100 text-crit-700' },
    kidding_soon: { label: 'Kidding soon', className: 'bg-watch-100 text-watch-700' },
};
const OUTCOME_CLASS: Record<string, string> = { open: 'bg-cobalt-100 text-cobalt-800', pregnant: 'bg-manganese-100 text-manganese-600', inconclusive: 'bg-watch-100 text-watch-700', kidded: 'bg-ok-100 text-ok-700', not_pregnant: 'bg-glaze text-ink-soft', aborted: 'bg-crit-100 text-crit-700', closed: 'bg-glaze text-ink-soft' };

/** D-075: workbook "Breeding", "Pregnancy Checks", "Kidding" and "Weaning". */
export default function Breeding(props: Props) {
    const { matings, kiddings, weaning, weaned, performance, focus, goats, settings } = props;
    const { auth } = usePage<SharedProps>().props;
    const [tab, setTab] = useState('cycle');
    const isVet = !!auth?.gates.includes('clinical');
    const canSettings = !!auth?.modules.includes('settings');
    const active = matings.filter((m) => m.active);
    const due = weaning.filter((w) => w.state === 'due');

    return (
        <>
            <Head title="Breeding & Pedigree" />
            <PageHeader title="Breeding & pedigree" description={`Gestation ${settings.gestation} days · pregnancy check ${settings.check} days after mating · weaning at ${settings.weaning} days. Your vet confirms these.`} />
            <StatRow>
                <Stat label="Confirmed pregnant" value={active.filter((m) => m.status === 'pregnant').length} />
                <Stat label="Waiting for a check" value={active.filter((m) => m.status === 'open' || m.status === 'inconclusive').length} note={`${active.filter((m) => m.alert === 'check_overdue' || m.alert === 'recheck_overdue').length} overdue`} tone={active.some((m) => m.alert === 'check_overdue' || m.alert === 'recheck_overdue') ? 'alert' : undefined} />
                <Stat label={`Kidding in ${settings.alert} days`} value={active.filter((m) => m.alert === 'kidding_soon' || m.alert === 'edd_passed').length} tone={active.some((m) => m.alert === 'edd_passed') ? 'alert' : undefined} note={active.some((m) => m.alert === 'edd_passed') ? 'Some past the due date' : undefined} />
                <Stat label="Due for weaning" value={due.length} tone={due.length ? 'alert' : undefined} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[
                { id: 'cycle', label: 'Breeding cycle', count: active.length },
                { id: 'kidding', label: 'Kidding', count: kiddings.length },
                { id: 'weaning', label: 'Weaning', count: weaning.length || undefined },
                { id: 'pedigree', label: 'Family tree' },
                { id: 'performance', label: 'Performance' },
                ...(canSettings ? [{ id: 'settings', label: 'Settings' }] : []),
            ]} />

            {tab === 'cycle' ? (
                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_23rem]">
                    <div className="min-w-0 space-y-3">
                        {matings.length ? matings.map((m) => <MatingCard key={m.id} m={m} isVet={isVet} canClose={['ceo', 'manager', 'vet'].includes(auth?.role ?? '')} {...props} />)
                            : <Panel><Empty title="No matings yet">Record a mating with the form.</Empty></Panel>}
                    </div>
                    <MatingForm {...props} />
                </div>
            ) : null}

            {tab === 'kidding' ? (
                <Panel title="Kiddings" description="One birth event per doe. Each live kid is registered and linked to it." flush>
                    {kiddings.length ? (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[46rem] text-sm">
                                <thead className="text-left text-ink-soft"><tr>{['Date', 'Dam', 'Litter', 'Alive', 'Stillborn', 'Assisted', 'Attendant', 'Kids registered'].map((h) => <th key={h} className="px-3 py-2 font-semibold">{h}</th>)}</tr></thead>
                                <tbody className="divide-y divide-rule">
                                    {kiddings.map((k) => (
                                        <tr key={k.id}>
                                            <td className="px-3 py-2">{k.on}</td>
                                            <td className="px-3 py-2"><Link href={`/goats/${k.goatId}`} className="font-semibold text-cobalt-800 hover:underline">{k.dam}</Link></td>
                                            <td className="px-3 py-2">{k.litter}</td><td className="px-3 py-2">{k.alive}</td><td className="px-3 py-2">{k.stillborn || '—'}</td>
                                            <td className="px-3 py-2">{k.assisted}</td><td className="px-3 py-2 text-ink-soft">{k.attendant}</td>
                                            <td className="px-3 py-2">{k.kids.length ? k.kids.map((kid) => <Link key={kid.id} href={`/goats/${kid.id}`} className="mr-2 font-mono text-cobalt-800 hover:underline">{kid.number}</Link>) : <span className="text-ink-faint">{k.alive ? `${k.alive} not linked` : '—'}</span>}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : <Empty title="No kiddings yet">Record a kidding from a mating in the Breeding cycle tab.</Empty>}
                </Panel>
            ) : null}

            {tab === 'weaning' ? (
                <div className="space-y-4">
                    <Panel title="Kids for weaning" description={`Kids within two weeks of ${settings.weaning} days old, or past it, not weaned yet. Decide on health, weight and intake with your vet.`} flush>
                        {weaning.length ? <ul className="divide-y divide-rule">{weaning.map((w) => <WeaningRow key={w.id} w={w} {...props} />)}</ul> : <Empty title="No kids due for weaning" />}
                    </Panel>
                    <Panel title="Weaned" flush>
                        {weaned.length ? (
                            <ul className="divide-y divide-rule text-sm">{weaned.map((w) => <li key={w.id} className="flex flex-wrap justify-between gap-2 px-4 py-2"><Link href={`/goats/${w.kidId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{w.kid}</Link><span className="text-ink-soft">{w.on}{w.age !== null ? ` · ${w.age} days` : ''}{w.weight ? ` · ${w.weight} KG` : ''}{w.method ? ` · ${w.method}` : ''} · {w.by}</span></li>)}</ul>
                        ) : <p className="p-4 text-sm text-ink-soft">Weaned kids appear here.</p>}
                    </Panel>
                </div>
            ) : null}

            {tab === 'pedigree' ? (
                <Panel title="Family tree" actions={
                    <select aria-label="Choose goat" className="field w-64" value={focus?.id ?? ''} onChange={(e) => router.get('/breeding', { goat: e.target.value }, { preserveState: true, preserveScroll: true, only: ['focus'] })}>
                        {goats.map((g) => <option key={g.id} value={g.id}>{label(g)}</option>)}
                    </select>
                }>
                    {focus ? <PedigreeTree tree={focus.tree} /> : <Empty title="Choose a goat" />}
                </Panel>
            ) : null}

            {tab === 'performance' ? (
                <Panel title="Does by live kids" flush>
                    {performance.length ? <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Doe</th><th>Kiddings</th><th>Born alive</th><th>Stillborn</th><th>Average litter</th></tr></thead><tbody>{performance.map((p) => <tr key={p.id}><td><Link href={`/goats/${p.id}`} className="font-semibold text-cobalt-800 hover:underline">{p.name ?? p.number}</Link></td><td>{p.kiddings}</td><td>{p.alive}</td><td>{p.stillborn}</td><td>{p.litter}</td></tr>)}</tbody></table></div>
                        : <Empty title="No kiddings yet" />}
                </Panel>
            ) : null}

            {tab === 'settings' ? <BreedingSettings settings={settings} /> : null}
        </>
    );
}

function MatingCard({ m, isVet, canClose, settings, options }: { m: MatingRow; isVet: boolean; canClose: boolean } & Props) {
    const [open, setOpen] = useState(m.active && m.alert !== null);
    const [mode, setMode] = useState<'none' | 'check' | 'kidding'>('none');
    return (
        <section className={cx('rounded-tile border border-rule bg-tile shadow-tile', !m.active && 'opacity-75')}>
            <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="flex w-full flex-wrap items-center gap-3 px-4 py-3 text-left">
                <GoatPortrait number={m.dam.number} coat={m.dam.coat} sex="female" size={38} />
                <div className="min-w-44 flex-1">
                    <p className="font-semibold text-cobalt-900">{m.dam.name ?? m.dam.number}{m.sire ? <span className="font-normal text-ink-soft"> × {m.sire.number}</span> : null}</p>
                    <p className="text-sm text-ink-soft">Mated {m.mated} · {m.method}{m.cycle ? ` · service ${m.cycle}` : ''}{m.status === 'open' && m.checkDue ? ` · check due ${m.checkDue}` : ''}</p>
                    {m.status === 'pregnant' ? <div className="mt-1 h-1.5 overflow-hidden rounded-sm bg-cobalt-100" role="progressbar" aria-valuenow={m.progress} aria-valuemin={0} aria-valuemax={100} aria-label="Gestation progress"><div className="h-full bg-manganese-600" style={{ width: `${m.progress}%` }} /></div> : null}
                </div>
                {m.status === 'pregnant' && m.expected ? <div className="text-right text-sm"><div className="font-semibold text-cobalt-900">{m.expected}</div><div className="text-ink-soft">{(m.days ?? 0) < 0 ? `${-(m.days ?? 0)} days over` : `in ${m.days} days`}</div></div> : null}
                <div className="flex items-center gap-1.5">
                    {m.alert ? <Chip className={ALERT[m.alert].className}><AlertTriangle size={12} aria-hidden />{ALERT[m.alert].label}</Chip> : null}
                    <Chip className={OUTCOME_CLASS[m.status] ?? 'bg-glaze text-ink-soft'}>{m.outcome}</Chip>
                    <ChevronDown size={16} className={cx('text-ink-faint transition-transform', open && 'rotate-180')} aria-hidden />
                </div>
            </button>
            {open ? (
                <div className="space-y-3 border-t border-rule px-4 py-3 text-sm">
                    <p className="text-ink-soft">Heat observed: {m.heat ?? 'not recorded'} · handler {m.handler ?? '—'}{m.notes ? ` · ${m.notes}` : ''}</p>
                    <div>
                        <p className="mb-1 font-semibold text-cobalt-900">Pregnancy checks</p>
                        {m.checks.length ? <ul className="space-y-0.5">{m.checks.map((c) => <li key={c.id}>{c.on} · <strong>{c.result}</strong> · {c.method}{c.fetuses ? ` · ${c.fetuses} fetuses` : ''}{c.next ? ` · recheck ${c.next}` : ''} <span className="text-ink-soft">· {c.by}</span></li>)}</ul> : <p className="text-ink-soft">None yet{isVet ? '' : ' (recorded by a veterinarian)'}.</p>}
                    </div>
                    {m.active ? (
                        <div className="flex flex-wrap gap-2">
                            {isVet ? <Button variant="secondary" onClick={() => setMode(mode === 'check' ? 'none' : 'check')}>Pregnancy check</Button> : null}
                            <Button onClick={() => setMode(mode === 'kidding' ? 'none' : 'kidding')}>Record kidding</Button>
                            {canClose ? <Button variant="quiet" onClick={() => { if (confirm('Close this mating without a kidding?')) router.post(`/breeding/matings/${m.id}/close`, {}, { preserveScroll: true }); }}>Close mating</Button> : null}
                        </div>
                    ) : null}
                    {mode === 'check' ? <CheckForm m={m} options={options} onDone={() => setMode('none')} /> : null}
                    {mode === 'kidding' ? <KiddingForm m={m} options={options} onDone={() => setMode('none')} /> : null}
                    {m.status === 'open' && !isVet ? <p className="text-xs text-ink-faint">Pregnancy checks are due {settings.check} days after mating.</p> : null}
                </div>
            ) : null}
        </section>
    );
}

function CheckForm({ m, options, onDone }: { m: MatingRow; options: Props['options']; onDone: () => void }) {
    const form = useForm({ result: 'pregnant', method: 'ultrasound', checked_on: today(), fetuses: '', next_check_on: '', notes: '' });
    const e = form.errors as Record<string, string>;
    return (
        <form onSubmit={(ev) => { ev.preventDefault(); form.post(`/breeding/matings/${m.id}/check`, { preserveScroll: true, onSuccess: onDone }); }} className="grid gap-3 rounded-tile bg-glaze p-3 sm:grid-cols-3">
            <Field label="Result" error={e.result}><select className="field" value={form.data.result} onChange={(x) => form.setData('result', x.target.value)}>{Object.entries(options.results).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            <Field label="Method" error={e.method}><select className="field" value={form.data.method} onChange={(x) => form.setData('method', x.target.value)}>{Object.entries(options.checkMethods).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            <Field label="Date" error={e.checked_on}><input type="date" max={today()} className="field" value={form.data.checked_on} onChange={(x) => form.setData('checked_on', x.target.value)} /></Field>
            {form.data.result === 'pregnant' ? <Field label="Fetuses (estimate)" error={e.fetuses}><input type="number" min={1} max={6} className="field" value={form.data.fetuses} onChange={(x) => form.setData('fetuses', x.target.value)} /></Field> : null}
            {form.data.result === 'inconclusive' ? <Field label="Recheck on" error={e.next_check_on}><input type="date" className="field" value={form.data.next_check_on} onChange={(x) => form.setData('next_check_on', x.target.value)} /></Field> : null}
            <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
            <div className="flex items-end gap-2"><Button type="submit" disabled={form.processing}>Save check</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}

function KiddingForm({ m, options, onDone }: { m: MatingRow; options: Props['options']; onDone: () => void }) {
    const form = useForm({ alive: '1', stillborn: '0', females: '1', kidded_on: today(), assisted: 'no', attendant: '', notes: '' });
    const e = form.errors as Record<string, string>;
    const total = Number(form.data.alive || 0) + Number(form.data.stillborn || 0);
    return (
        <form onSubmit={(ev) => { ev.preventDefault(); form.post(`/breeding/matings/${m.id}/kidding`, { preserveScroll: true, onSuccess: onDone }); }} className="grid gap-3 rounded-tile bg-glaze p-3 sm:grid-cols-3">
            <Field label="Born alive" error={e.alive}><input type="number" min={0} max={6} className="field" value={form.data.alive} onChange={(x) => form.setData('alive', x.target.value)} /></Field>
            <Field label="Of them female" error={e.females}><input type="number" min={0} max={6} className="field" value={form.data.females} onChange={(x) => form.setData('females', x.target.value)} /></Field>
            <Field label="Stillborn" error={e.stillborn}><input type="number" min={0} max={6} className="field" value={form.data.stillborn} onChange={(x) => form.setData('stillborn', x.target.value)} /></Field>
            <Field label="Kidding date" error={e.kidded_on}><input type="date" max={today()} className="field" value={form.data.kidded_on} onChange={(x) => form.setData('kidded_on', x.target.value)} /></Field>
            <Field label="Assisted birth" error={e.assisted}><select className="field" value={form.data.assisted} onChange={(x) => form.setData('assisted', x.target.value)}>{Object.entries(options.assisted).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            <Field label="Attendant" error={e.attendant} hint="Leave empty for yourself"><input className="field" value={form.data.attendant} onChange={(x) => form.setData('attendant', x.target.value)} /></Field>
            <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
            <div className="flex items-end gap-2 sm:col-span-2">
                <Button type="submit" disabled={form.processing}>Save and register {form.data.alive} kid{form.data.alive === '1' ? '' : 's'}</Button>
                <Button type="button" variant="secondary" onClick={onDone}>Cancel</Button>
                <span className="text-sm text-ink-soft">{total ? `${['', 'Single', 'Twin', 'Triplet', 'Quadruplet'][total] ?? '5+'} · each kid goes into the mother's pen` : ''}</span>
            </div>
        </form>
    );
}

function WeaningRow({ w, pens, options }: { w: Due } & Props) {
    const [mode, setMode] = useState<'none' | 'wean' | 'postpone'>('none');
    const form = useForm({ kid_id: w.id, status: 'completed', weaned_on: today(), weight_kg: '', method: 'gradual', pen_id: w.penId ? String(w.penId) : '', postponed_to: '', notes: '' });
    const e = form.errors as Record<string, string>;
    const submit = (status: 'completed' | 'postponed') => { form.transform((d) => ({ ...d, status })); form.post('/breeding/weanings', { preserveScroll: true, onSuccess: () => setMode('none') }); };
    return (
        <li className="px-4 py-2.5">
            <div className="flex flex-wrap items-center gap-3">
                <GoatPortrait number={w.number} coat={w.coat} sex={w.sex} size={34} />
                <div className="min-w-40 flex-1">
                    <Link href={`/goats/${w.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{w.number}</Link>{w.dam ? <span className="text-sm text-ink-soft"> · dam {w.dam}</span> : null}
                    <div className="text-sm text-ink-soft">{w.age} days old · target {w.target}{w.postponedTo ? ` · postponed to ${w.postponedTo}` : ''}</div>
                </div>
                <Chip className={w.state === 'due' ? 'bg-crit-100 text-crit-700' : w.state === 'postponed' ? 'bg-glaze text-ink-soft' : 'bg-watch-100 text-watch-700'}>{w.state === 'due' ? 'Due' : w.state === 'postponed' ? 'Postponed' : 'Soon'}</Chip>
                <Button onClick={() => setMode(mode === 'wean' ? 'none' : 'wean')}>Wean</Button>
                <Button variant="quiet" onClick={() => setMode(mode === 'postpone' ? 'none' : 'postpone')}>Postpone</Button>
            </div>
            {mode === 'wean' ? (
                <div className="mt-2 grid gap-3 rounded-tile bg-glaze p-3 sm:grid-cols-3">
                    <Field label="Weaning date" error={e.weaned_on}><input type="date" max={today()} className="field" value={form.data.weaned_on} onChange={(x) => form.setData('weaned_on', x.target.value)} /></Field>
                    <Field label="Weight KG" error={e.weight_kg} hint="Saved to the weight log"><input type="number" step="0.1" min={1} className="field" value={form.data.weight_kg} onChange={(x) => form.setData('weight_kg', x.target.value)} /></Field>
                    <Field label="Method" error={e.method}><select className="field" value={form.data.method} onChange={(x) => form.setData('method', x.target.value)}>{Object.entries(options.weanMethods).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Field label="Move to pen (optional)" error={e.pen_id}><select className="field" value={form.data.pen_id} onChange={(x) => form.setData('pen_id', x.target.value)}><option value="">Stay in current pen</option>{pens.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
                    <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                    <div className="flex items-end"><Button disabled={form.processing} onClick={() => submit('completed')}>Save weaning</Button></div>
                </div>
            ) : null}
            {mode === 'postpone' ? (
                <div className="mt-2 flex flex-wrap items-end gap-2 rounded-tile bg-glaze p-3">
                    <div className="w-44"><Field label="New date" error={e.postponed_to}><input type="date" className="field" value={form.data.postponed_to} onChange={(x) => form.setData('postponed_to', x.target.value)} /></Field></div>
                    <div className="min-w-48 flex-1"><Field label="Reason" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} placeholder="e.g. underweight, unwell" /></Field></div>
                    <Button disabled={form.processing} onClick={() => submit('postponed')}>Postpone</Button>
                </div>
            ) : null}
        </li>
    );
}

function MatingForm({ does, bucks, pairCheck, options, settings }: Props) {
    const mating = useForm({ dam_id: String(does[0]?.id ?? ''), sire_id: String(bucks[0]?.id ?? ''), mated_on: today(), method: 'natural', heat_observed: 'yes', handler: '', notes: '' });
    const checkPair = () => router.get('/breeding', { sire: mating.data.sire_id, dam: mating.data.dam_id }, { preserveState: true, preserveScroll: true, only: ['pairCheck'] });
    const e = mating.errors as Record<string, string>;
    const mated = new Date(`${mating.data.mated_on}T12:00:00`);
    const plus = (days: number) => { const d = new Date(mated); d.setDate(d.getDate() + days); return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }); };
    return (
        <Panel title="Record a mating">
            <form onSubmit={(ev) => { ev.preventDefault(); mating.post('/breeding/matings', { preserveScroll: true, onSuccess: () => mating.reset('notes') }); }} className="space-y-3">
                {!does.length ? <Note>No female goats are registered yet. Register a doe first, then record her mating here.</Note> : null}
                <Field label="Doe" error={e.dam_id}><select className="field" value={mating.data.dam_id} onChange={(x) => mating.setData('dam_id', x.target.value)} disabled={!does.length}>{does.map((d) => <option key={d.id} value={d.id}>{label(d)}</option>)}</select></Field>
                <Field label="Buck" error={e.sire_id} hint="Not recorded: unknown or not on this farm"><select className="field" value={mating.data.sire_id} onChange={(x) => mating.setData('sire_id', x.target.value)}><option value="">Not recorded</option>{bucks.map((d) => <option key={d.id} value={d.id}>{label(d)}</option>)}</select></Field>
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Service date" error={e.mated_on}><input type="date" max={today()} className="field" value={mating.data.mated_on} onChange={(x) => mating.setData('mated_on', x.target.value)} /></Field>
                    <Field label="Heat observed" error={e.heat_observed}><select className="field" value={mating.data.heat_observed} onChange={(x) => mating.setData('heat_observed', x.target.value)}><option value="yes">Yes</option><option value="no">No</option><option value="unknown">Unknown</option></select></Field>
                </div>
                <Field label="Method" error={e.method}><select className="field" value={mating.data.method} onChange={(x) => mating.setData('method', x.target.value)}>{Object.entries(options.methods).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Handler / vet" error={e.handler} hint="Leave empty for yourself"><input className="field" value={mating.data.handler} onChange={(x) => mating.setData('handler', x.target.value)} /></Field>
                <p className="rounded-tile bg-glaze px-3 py-2 text-sm text-ink-soft">Pregnancy check due <strong className="text-cobalt-900">{plus(settings.check)}</strong> · expected kidding <strong className="text-cobalt-900">{plus(settings.gestation)}</strong></p>
                <Button variant="secondary" className="w-full" onClick={checkPair}>Check relatedness</Button>
                {pairCheck ? (pairCheck.shared ? <Note>{pairCheck.sire} and {pairCheck.dam} share {pairCheck.shared} recorded ancestor{pairCheck.shared > 1 ? 's' : ''} within three generations. Your vet decides.</Note> : <p className="text-sm text-ok-700">No shared ancestors recorded within three generations.</p>) : null}
                <Button type="submit" className="w-full" disabled={mating.processing || !does.length}>Save mating</Button>
            </form>
        </Panel>
    );
}

function BreedingSettings({ settings }: { settings: Props['settings'] }) {
    const form = useForm({ gestation: settings.gestation, check: settings.check, alert: settings.alert, weaning: settings.weaning });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title="Breeding settings" description="Planning values for this farm. Change them with your vet. New matings use them." className="max-w-xl">
            <form onSubmit={(ev) => { ev.preventDefault(); form.post('/breeding/settings', { preserveScroll: true }); }} className="grid grid-cols-2 gap-3">
                <Field label="Gestation (days)" error={e.gestation} hint="Typical 145–155"><input type="number" className="field" value={form.data.gestation} onChange={(x) => form.setData('gestation', Number(x.target.value))} /></Field>
                <Field label="Pregnancy check after (days)" error={e.check}><input type="number" className="field" value={form.data.check} onChange={(x) => form.setData('check', Number(x.target.value))} /></Field>
                <Field label="Kidding alert (days before)" error={e.alert}><input type="number" className="field" value={form.data.alert} onChange={(x) => form.setData('alert', Number(x.target.value))} /></Field>
                <Field label="Weaning target age (days)" error={e.weaning}><input type="number" className="field" value={form.data.weaning} onChange={(x) => form.setData('weaning', Number(x.target.value))} /></Field>
                <div className="col-span-2"><Button type="submit" disabled={form.processing}>Save</Button></div>
            </form>
        </Panel>
    );
}
