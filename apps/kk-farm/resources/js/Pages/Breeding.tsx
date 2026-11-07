import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import GoatPortrait from '../components/GoatPortrait';
import PedigreeTree, { type PedigreeNode } from '../components/PedigreeTree';
import { Button, Empty, Field, Note, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import type { Option, SharedProps } from '../types';

type Pair = { id: number; status: string; dam: { id: string; number: string; name: string | null; coat: string; sex: string }; sire: { id: string; number: string; name: string | null } | null; mated: string; expected: string | null; days: number | null; progress: number };
type Props = {
    pregnant: Pair[];
    open: Pair[];
    recent: Array<{ id: number; goatId: string; dam: string; on: string; alive: number; stillborn: number }>;
    performance: Array<{ id: string; number: string; name: string | null; kiddings: number; alive: number; stillborn: number }>;
    focus: { id: string; number: string; tree: PedigreeNode } | null;
    pairCheck: { shared: number; sire: string; dam: string } | null;
    does: Option[];
    bucks: Option[];
    goats: Option[];
    gestationDays: number;
};

const label = (o: Option) => `${o.farm_number}${o.name ? ` · ${o.name}` : ''}`;

export default function Breeding({ pregnant, open, recent, performance, focus, pairCheck, does, bucks, goats, gestationDays }: Props) {
    const { auth } = usePage<SharedProps>().props;
    const [tab, setTab] = useState('cycle');
    const isVet = auth?.gates.includes('clinical');
    const mating = useForm({ dam_id: String(does[0]?.id ?? ''), sire_id: String(bucks[0]?.id ?? ''), mated_on: new Date().toLocaleDateString('en-CA'), method: 'natural' });
    // The form follows the herd: when goats are registered the first doe/buck become the defaults.

    const checkPair = () => router.get('/breeding', { sire: mating.data.sire_id, dam: mating.data.dam_id }, { preserveState: true, preserveScroll: true, only: ['pairCheck'] });

    return (
        <>
            <Head title="Breeding & Pedigree" />
            <PageHeader title="Breeding & pedigree" description={`Expected dates use a demo ${gestationDays}-day gestation until the veterinarians confirm the real value.`} />
            <StatRow>
                <Stat label="Confirmed pregnant" value={pregnant.length} />
                <Stat label="Awaiting check" value={open.length} />
                <Stat label="Due in 14 days" value={pregnant.filter((p) => (p.days ?? 99) <= 14).length} />
                <Stat label="Recent kiddings" value={recent.length} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'cycle', label: 'Breeding cycle' }, { id: 'pedigree', label: 'Family tree' }, { id: 'performance', label: 'Performance' }]} />

            {tab === 'cycle' ? (
                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_23rem]">
                    <div className="space-y-4">
                        <Panel title="Pregnancies" flush>
                            <ul className="divide-y divide-rule">
                                {pregnant.map((p) => <PregnancyRow key={p.id} pair={p} />)}
                            </ul>
                            {!pregnant.length ? <Empty title="No confirmed pregnancies" /> : null}
                        </Panel>
                        <Panel title="Waiting for a pregnancy check" flush description={isVet ? undefined : 'Only a veterinarian can record this result'}>
                            {open.length ? (
                                <ul className="divide-y divide-rule">
                                    {open.map((p) => (
                                        <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                                            <div className="min-w-0"><Link href={`/goats/${p.dam.id}`} className="font-semibold text-cobalt-800 hover:underline">{p.dam.name ?? p.dam.number}</Link><div className="text-sm text-ink-soft">Mated {p.mated}{p.sire ? ` with ${p.sire.number}` : ''}</div></div>
                                            {isVet ? (
                                                <div className="flex gap-2">
                                                    <Button variant="secondary" onClick={() => router.post(`/breeding/matings/${p.id}/check`, { result: 'pregnant' }, { preserveScroll: true })}>Pregnant</Button>
                                                    <Button variant="quiet" onClick={() => router.post(`/breeding/matings/${p.id}/check`, { result: 'not_pregnant' }, { preserveScroll: true })}>Not pregnant</Button>
                                                </div>
                                            ) : null}
                                        </li>
                                    ))}
                                </ul>
                            ) : <Empty title="Nothing waiting" />}
                        </Panel>
                    </div>

                    <Panel title="Record a mating">
                        <form onSubmit={(e) => { e.preventDefault(); mating.post('/breeding/matings', { preserveScroll: true }); }} className="space-y-3">
                            {!does.length ? <Note>No female goats are registered yet. Register a doe first, then record her mating here.</Note> : null}
                            <Field label="Doe" error={mating.errors.dam_id}><select className="field" value={mating.data.dam_id} onChange={(e) => mating.setData('dam_id', e.target.value)} disabled={!does.length}>{does.map((d) => <option key={d.id} value={d.id}>{label(d)}</option>)}</select></Field>
                            <Field label="Buck" error={mating.errors.sire_id} hint="Not recorded: the buck is unknown or not on this farm"><select className="field" value={mating.data.sire_id} onChange={(e) => mating.setData('sire_id', e.target.value)}><option value="">Not recorded</option>{bucks.map((d) => <option key={d.id} value={d.id}>{label(d)}</option>)}</select></Field>
                            <Field label="Date" error={mating.errors.mated_on}><input type="date" className="field" value={mating.data.mated_on} onChange={(e) => mating.setData('mated_on', e.target.value)} /></Field>
                            <Button variant="secondary" className="w-full" onClick={checkPair}>Check relatedness</Button>
                            {pairCheck ? (pairCheck.shared ? <Note>{pairCheck.sire} and {pairCheck.dam} share {pairCheck.shared} recorded ancestor{pairCheck.shared > 1 ? 's' : ''} within three generations. Your vet decides.</Note> : <p className="text-sm text-ok-700">No shared ancestors recorded within three generations.</p>) : null}
                            <Button type="submit" className="w-full" disabled={mating.processing || !does.length}>Save mating</Button>
                        </form>
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
                <div className="grid gap-4 lg:grid-cols-2">
                    <Panel title="Top does by live kids" flush>
                        <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Doe</th><th>Kiddings</th><th>Born alive</th><th>Stillborn</th></tr></thead><tbody>{performance.map((p) => <tr key={p.id}><td><Link href={`/goats/${p.id}`} className="font-semibold text-cobalt-800 hover:underline">{p.name ?? p.number}</Link></td><td>{p.kiddings}</td><td>{p.alive}</td><td>{p.stillborn}</td></tr>)}</tbody></table></div>
                    </Panel>
                    <Panel title="Recent kiddings" flush>
                        <ul className="divide-y divide-rule text-sm">{recent.map((k) => <li key={k.id} className="flex justify-between px-4 py-2"><Link href={`/goats/${k.goatId}`} className="font-semibold text-cobalt-800 hover:underline">{k.dam}</Link><span className="text-ink-soft">{k.on} · {k.alive} alive{k.stillborn ? `, ${k.stillborn} stillborn` : ''}</span></li>)}</ul>
                    </Panel>
                </div>
            ) : null}
        </>
    );
}

function PregnancyRow({ pair }: { pair: Pair }) {
    const [recording, setRecording] = useState(false);
    const form = useForm({ alive: '1', stillborn: '0', females: '1' });
    return (
        <li className="px-4 py-2.5">
            <div className="flex flex-wrap items-center gap-3">
                <GoatPortrait number={pair.dam.number} coat={pair.dam.coat} sex="female" size={38} />
                <div className="min-w-40 flex-1">
                    <Link href={`/goats/${pair.dam.id}`} className="font-semibold text-cobalt-800 hover:underline">{pair.dam.name ?? pair.dam.number}</Link>
                    <div className="text-sm text-ink-soft">{pair.sire ? `Sire ${pair.sire.number} · ` : ''}Mated {pair.mated}</div>
                    <div className="mt-1 h-1.5 overflow-hidden rounded-sm bg-cobalt-100" role="progressbar" aria-valuenow={pair.progress} aria-valuemin={0} aria-valuemax={100} aria-label="Gestation progress"><div className="h-full bg-manganese-600" style={{ width: `${pair.progress}%` }} /></div>
                </div>
                <div className="text-right text-sm"><div className="font-semibold text-cobalt-900">{pair.expected}</div><div className="text-ink-soft">{(pair.days ?? 0) < 0 ? `${-(pair.days ?? 0)} days over` : `in ${pair.days} days`}</div></div>
                <Button variant="secondary" onClick={() => setRecording(!recording)}>Record kidding</Button>
            </div>
            {recording ? (
                <form onSubmit={(e) => { e.preventDefault(); form.post(`/breeding/matings/${pair.id}/kidding`, { preserveScroll: true }); }} className="mt-2 flex flex-wrap items-end gap-2 rounded-tile bg-glaze p-3">
                    <div className="w-28"><Field label="Born alive" error={form.errors.alive}><input type="number" min="0" max="6" className="field" value={form.data.alive} onChange={(e) => form.setData('alive', e.target.value)} /></Field></div>
                    <div className="w-28"><Field label="Females" error={form.errors.females}><input type="number" min="0" max="6" className="field" value={form.data.females} onChange={(e) => form.setData('females', e.target.value)} /></Field></div>
                    <div className="w-28"><Field label="Stillborn"><input type="number" min="0" max="6" className="field" value={form.data.stillborn} onChange={(e) => form.setData('stillborn', e.target.value)} /></Field></div>
                    <Button type="submit" disabled={form.processing}>Save and register kids</Button>
                </form>
            ) : null}
        </li>
    );
}
