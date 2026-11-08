import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import PhotoPicker from '../../components/PhotoPicker';
import { Camera, MoveRight, Scale, Star, TriangleAlert } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import Chart, { axis } from '../../components/Chart';
import GoatPortrait from '../../components/GoatPortrait';
import PedigreeTree, { type PedigreeNode } from '../../components/PedigreeTree';
import { Button, Chip, Empty, Field, GoatLabel, HealthChip, Note, Panel, pkr, ReproChip, SexIcon, Tabs } from '../../components/ui';
import type { GoatCard, SharedProps } from '../../types';

type Goat = GoatCard & { withdrawal: { meat: string | null; milk: string | null } | null; origin: string; birthDate: string | null; estimated: boolean; height: number | null; shed: string | null; penPurpose: string | null; feedingGroup: string | null; exitReason: string | null; exitDate: string | null; adg: number | null; lifetimeAdg: number | null; identity: string; classification: string; sire: { id: string | null; number: string } | null; dam: { id: string | null; number: string } | null };
type Props = {
    goat: Goat;
    photos: Array<{ id: number; notes: string | null; isProfile: boolean; by: string; on: string }>;
    tags: Array<{ code: string; from: string; to: string | null; reason: string; closed: string | null; by: string }>;
    weights: Array<{ on: string; kg: number }>;
    timeline: Array<{ id: number; type: string; summary: string; by: string; on: string }>;
    cases: Array<{ id: number; label: string; condition: string; status: string; active: boolean; severity: string; onset: string; resolved: string | null; diagnosis: string | null; treatments: Array<{ id: number; on: string; text: string; meat: string | null; milk: string | null; by: string }> }>;
    health: Array<{ id: number; kind: string; title: string; notes: string | null; severity: string | null; by: string; role: string; on: string; next: string | null; status: string }>;
    pedigree: PedigreeNode;
    offspring: GoatCard[];
    matings: Array<{ id: number; on: string; partner: string | null; status: string; expected: string | null }>;
    kiddings: Array<{ id: number; on: string; alive: number; stillborn: number }>;
    cost: { direct: number; sharedPerHead: number; total: number } | null;
    pens: Array<{ id: number; name: string; purpose: string }>;
};

const TYPE_COLOR: Record<string, string> = { origin: 'bg-cobalt-700', tag: 'bg-turq-600', health: 'bg-treat-400', breeding: 'bg-manganese-600', exit: 'bg-crit-500', weight: 'bg-cobalt-200', move: 'bg-watch-400', photo: 'bg-turq-400' };

export default function GoatShow(props: Props) {
    const { goat, photos, tags, weights, timeline, health, cases, pedigree, offspring, matings, kiddings, cost, pens } = props;
    const { auth } = usePage<SharedProps>().props;
    const [tab, setTab] = useState('overview');
    const [panel, setPanel] = useState<string | null>(null);
    const profile = photos.find((p) => p.isProfile);
    const onFarm = goat.status === 'active';

    const chart = useMemo(() => ({
        tooltip: { trigger: 'axis', valueFormatter: (v: number) => `${v} kg` },
        xAxis: axis.category(weights.map((w) => new Date(w.on).toLocaleDateString('en-GB', { month: 'short', year: '2-digit' }))),
        yAxis: { ...axis.value((v) => `${v}`), scale: true },
        series: [{ type: 'line', data: weights.map((w) => w.kg), smooth: true, symbolSize: 7, lineStyle: { width: 2.5 }, areaStyle: { opacity: 0.08 } }],
    }), [weights]);

    const fact = (label: string, value: React.ReactNode) => (
        <div className="min-w-0"><dt className="text-sm text-ink-soft">{label}</dt><dd className="truncate font-semibold text-cobalt-900">{value ?? '—'}</dd></div>
    );

    return (
        <>
            <Head title={goat.name ?? goat.number} />
            {/* D-073: withdrawal from the veterinarian's treatment dates. */}
            {goat.withdrawal ? (
                <div role="alert" className="mb-4 flex flex-wrap items-center gap-2 rounded-tile border border-crit-500/40 bg-crit-100 px-4 py-3 text-crit-700">
                    <strong>Under withdrawal.</strong>
                    {goat.withdrawal.meat ? <span>Do not sell, gift or sacrifice before {goat.withdrawal.meat}.</span> : null}
                    {goat.withdrawal.milk ? <span>Do not use milk before {goat.withdrawal.milk}.</span> : null}
                </div>
            ) : null}
            <section className="mb-4 overflow-hidden rounded-tile border border-rule bg-tile shadow-tile">
                <div className="flex flex-col gap-4 p-4 sm:flex-row">
                    {profile ? <img src={`/media/${profile.id}`} alt={`Profile of ${goat.number}`} className="size-36 shrink-0 rounded-tile object-cover" /> : <GoatPortrait number={goat.number} coat={goat.coat} sex={goat.sex} size={144} />}
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-bold tracking-tight text-cobalt-900">{goat.name ?? 'Unnamed goat'}</h1>
                            <SexIcon sex={goat.sex} />
                            {onFarm ? <HealthChip health={goat.health} /> : <Chip className="bg-cobalt-100 text-cobalt-800">Left the farm: {goat.exitReason}</Chip>}
                            <ReproChip repro={goat.repro} />
                        </div>
                        <p className="text-ink-soft">{goat.number} · {goat.breed} · {goat.age}</p>
                        {goat.classification ? <p className="mt-1 inline-flex rounded-full bg-cobalt-900 px-3 py-1 text-sm font-semibold text-white">{goat.classification}</p> : null}
                        <dl className="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 md:grid-cols-4">
                            {fact('Current weight', goat.weight ? `${goat.weight} kg` : 'No weighing yet')}
                            {fact('Recent daily gain', goat.adg !== null ? `${goat.adg} g/day` : '—')}
                            {fact('Pen', goat.pen ?? '—')}
                            {fact('RFID tag', tags.find((t) => !t.to)?.code ?? 'No open tag')}
                        </dl>
                        {onFarm ? (
                            <div className="mt-3 flex flex-wrap gap-2">
                                <Button variant="secondary" onClick={() => setPanel(panel === 'weight' ? null : 'weight')}><Scale size={16} aria-hidden /> Add weight</Button>
                                <Button variant="secondary" onClick={() => setPanel(panel === 'photo' ? null : 'photo')}><Camera size={16} aria-hidden /> Add photo</Button>
                                <Button variant="secondary" onClick={() => setPanel(panel === 'concern' ? null : 'concern')}><TriangleAlert size={16} aria-hidden /> Report concern</Button>
                                <Button variant="secondary" onClick={() => setPanel(panel === 'move' ? null : 'move')}><MoveRight size={16} aria-hidden /> Move</Button>
                                {auth?.gates.includes('exit_animal') ? <Button variant="danger" onClick={() => setPanel(panel === 'exit' ? null : 'exit')}>Record exit</Button> : null}
                            </div>
                        ) : null}
                    </div>
                </div>
                {panel ? <div className="border-t border-rule bg-glaze p-4"><ActionPanel kind={panel} goat={goat} pens={pens} close={() => setPanel(null)} /></div> : null}
            </section>

            <Tabs
                value={tab}
                onChange={setTab}
                tabs={[{ id: 'overview', label: 'Overview' }, { id: 'timeline', label: 'Lifecycle', count: timeline.length }, { id: 'health', label: 'Health', count: cases.length + health.length }, { id: 'breeding', label: 'Breeding & pedigree' }, { id: 'photos', label: 'Photos', count: photos.length }, { id: 'tags', label: 'RFID history', count: tags.length }]}
            />

            {tab === 'overview' ? (
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <Panel title="Weight history" description={goat.lifetimeAdg !== null ? `Average ${goat.lifetimeAdg} g/day across recorded weighings` : undefined}>
                        {weights.length ? <Chart option={chart} label={`Weight history of ${goat.number}`} height={260} /> : <Empty title="No weighings yet">Use Add weight to record the first one.</Empty>}
                    </Panel>
                    <div className="space-y-4">
                        <Panel title="Identity">
                            <dl className="space-y-2">
                                {fact('Permanent ID', <span className="text-sm font-medium break-all whitespace-normal">{goat.identity}</span>)}
                                {fact('Born', `${goat.birthDate ?? 'Unknown'}${goat.estimated ? ' (estimated)' : ''}`)}
                                {goat.height !== null ? fact('Height', `${goat.height} in`) : null}
                                {fact('Origin', goat.origin === 'purchased' ? 'Purchased' : goat.origin === 'transferred_in' ? 'Transferred in' : 'Born on the farm')}
                                {fact('Sire', parentLink(goat.sire))}
                                {fact('Dam', parentLink(goat.dam))}
                                {fact('Feeding group', goat.feedingGroup)}
                            </dl>
                            <p className="mt-2 text-sm text-ink-soft">This ID never changes and is never reused, even when the RFID tag does.</p>
                        </Panel>
                        {cost ? (
                            <Panel title="Lifetime cost">
                                <p className="text-2xl font-bold text-cobalt-900">{pkr(cost.total)}</p>
                                <p className="mb-2 text-sm text-ink-soft">{pkr(cost.direct)} direct, {pkr(cost.sharedPerHead)} shared feed and medicine (12 months)</p>
                                <Note>Illustrative only. The cost allocation rule is not decided yet.</Note>
                            </Panel>
                        ) : null}
                    </div>
                </div>
            ) : null}

            {tab === 'timeline' ? (
                <Panel title="Complete lifecycle">
                    <ol className="relative ml-1 space-y-3 border-l border-rule-strong pl-5">
                        {timeline.map((e) => (
                            <li key={e.id} className="relative">
                                <span className={`absolute top-1.5 -left-[25px] size-2.5 rounded-[2px] ${TYPE_COLOR[e.type] ?? 'bg-cobalt-200'}`} aria-hidden />
                                <p className="font-medium">{e.summary}</p>
                                <p className="text-sm text-ink-soft">{e.on} · {e.by}</p>
                            </li>
                        ))}
                    </ol>
                </Panel>
            ) : null}

            {tab === 'health' && cases.length ? (
                <Panel flush title="Health cases" description="Episodes and the treatments given, with the veterinarian's clear dates" className="mb-4">
                    <ul className="divide-y divide-rule">
                        {cases.map((c) => (
                            <li key={c.id} className="px-4 py-2.5">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-mono text-xs text-ink-soft">{c.label}</span>
                                    <span className="font-semibold text-cobalt-900">{c.condition}</span>
                                    <Chip className={c.active ? 'bg-treat-100 text-treat-700' : 'bg-glaze text-ink-soft'}>{c.status}</Chip>
                                    <span className="text-sm text-ink-soft">{c.severity} · since {c.onset}{c.resolved ? ` · ended ${c.resolved}` : ''}</span>
                                </div>
                                {c.diagnosis ? <p className="text-sm">{c.diagnosis}</p> : null}
                                {c.treatments.length ? (
                                    <ul className="mt-1 space-y-0.5 text-sm text-ink-soft">
                                        {c.treatments.map((t) => <li key={t.id}>{t.on} · <span className="text-ink">{t.text}</span>{t.meat ? ` · meat clear ${t.meat}` : ''}{t.milk ? ` · milk clear ${t.milk}` : ''} · {t.by}</li>)}
                                    </ul>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                </Panel>
            ) : null}

            {tab === 'health' ? (
                <Panel flush title="Medical history" description="Clinical records carry the name of the veterinarian who wrote them">
                    {health.length ? (
                        <ul className="divide-y divide-rule">
                            {health.map((h) => (
                                <li key={h.id} className="flex flex-wrap items-start justify-between gap-2 px-4 py-2.5">
                                    <div className="min-w-0">
                                        <p className="font-semibold text-cobalt-900">{h.title}</p>
                                        <p className="text-sm text-ink-soft first-letter:uppercase">{h.kind} · {h.on} · {h.by}</p>
                                        {h.notes ? <p className="text-sm">{h.notes}</p> : null}
                                    </div>
                                    <div className="text-right text-sm text-ink-soft">
                                        {h.status === 'open' ? <Chip className="bg-treat-100 text-treat-700">Open</Chip> : null}
                                        {h.next ? <div>Next: {h.next}</div> : null}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : <Empty title="No medical records yet" />}
                </Panel>
            ) : null}

            {tab === 'breeding' ? (
                <div className="space-y-4">
                    <Panel title="Family tree" description="Three generations. Select an ancestor to open its record."><PedigreeTree tree={pedigree} /></Panel>
                    <div className="grid gap-4 lg:grid-cols-2">
                        <Panel title={`Offspring (${offspring.length})`} flush>
                            {offspring.length ? <ul className="max-h-80 divide-y divide-rule overflow-auto">{offspring.map((o) => <li key={o.id} className="flex items-center justify-between gap-2 px-4 py-2"><GoatLabel goat={o} size={34} /><span className="text-sm text-ink-soft">{o.age}</span></li>)}</ul> : <Empty title="No offspring recorded" />}
                        </Panel>
                        <Panel title="Matings and kiddings" flush>
                            {matings.length || kiddings.length ? (
                                <ul className="divide-y divide-rule text-sm">
                                    {kiddings.map((k) => <li key={`k${k.id}`} className="flex justify-between px-4 py-2"><span>Kidded on {k.on}</span><span className="text-ink-soft">{k.alive} alive{k.stillborn ? `, ${k.stillborn} stillborn` : ''}</span></li>)}
                                    {matings.map((m) => <li key={m.id} className="flex justify-between gap-2 px-4 py-2"><span>Mated {m.on}{m.partner ? ` with ${m.partner}` : ''}</span><span className="text-ink-soft capitalize">{m.status.replace('_', ' ')}</span></li>)}
                                </ul>
                            ) : <Empty title="No breeding records" />}
                        </Panel>
                    </div>
                </div>
            ) : null}

            {tab === 'photos' ? (
                <Panel title="Photographs" description="Photos stay with this goat's permanent identity, even if its tag changes">
                    {photos.length ? (
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                            {photos.map((p) => (
                                <figure key={p.id} className="overflow-hidden rounded-tile border border-rule">
                                    <img src={`/media/${p.id}`} alt={p.notes ?? `Photo of ${goat.number}`} loading="lazy" className="aspect-square w-full object-cover" />
                                    <figcaption className="p-2 text-sm">
                                        <div className="text-ink-soft">{p.on} · {p.by}</div>
                                        {p.notes ? <div>{p.notes}</div> : null}
                                        {p.isProfile ? <Chip className="mt-1 bg-turq-100 text-turq-800"><Star size={11} aria-hidden /> Profile</Chip> : <button type="button" className="mt-1 font-semibold text-turq-800 hover:underline" onClick={() => router.post(`/goats/${goat.id}/photos/${p.id}/profile`, {}, { preserveScroll: true })}>Make profile picture</button>}
                                    </figcaption>
                                </figure>
                            ))}
                        </div>
                    ) : <Empty title="No photographs yet">The portrait above is generated from this goat's identity until a real photo is added with Add photo.</Empty>}
                </Panel>
            ) : null}

            {tab === 'tags' ? (
                <Panel flush title="RFID assignment history" description="Tags can be reused. Identity and records stay with the goat.">
                    <div className="overflow-x-auto">
                        <table className="data-table min-w-[560px]">
                            <thead><tr><th>Tag</th><th>From</th><th>To</th><th>Assigned</th><th>Closed</th></tr></thead>
                            <tbody>{tags.map((t) => <tr key={t.code + t.from}><td className="font-semibold"><Link href={`/scan?tag=${t.code}`} className="text-cobalt-800 hover:underline">{t.code}</Link></td><td>{t.from}</td><td>{t.to ?? 'Current'}</td><td className="capitalize">{t.reason}</td><td className="capitalize">{t.closed ?? '—'}</td></tr>)}</tbody>
                        </table>
                    </div>
                    {!tags.length ? <Empty title="No tag assigned yet">Assign one from the RFID Scan screen.</Empty> : null}
                </Panel>
            ) : null}
        </>
    );
}

function ActionPanel({ kind, goat, pens, close }: { kind: string; goat: Goat; pens: Props['pens']; close: () => void }) {
    const weight = useForm({ animal_id: goat.id, weight_kg: '' });
    const concern = useForm({ animal_id: goat.id, title: '', severity: 'low', notes: '' });
    const move = useForm({ animal_id: goat.id, pen_id: String(pens[0]?.id ?? '') });
    const exit = useForm({ reason: 'sold' });
    const photo = useForm<{ photo: File | null; notes: string }>({ photo: null, notes: '' });
    const options = { preserveScroll: true, onSuccess: close };

    if (kind === 'weight') {
        return (
            <form onSubmit={(e) => { e.preventDefault(); weight.post('/weights', options); }} className="flex flex-wrap items-end gap-2">
                <div className="w-44"><Field label="Weight (kg)" error={weight.errors.weight_kg}><input inputMode="decimal" autoFocus className="field" value={weight.data.weight_kg} onChange={(e) => weight.setData('weight_kg', e.target.value)} /></Field></div>
                <Button type="submit" disabled={weight.processing}>Save weight</Button>
            </form>
        );
    }
    if (kind === 'concern') {
        return (
            <form onSubmit={(e) => { e.preventDefault(); concern.post('/health/observations', options); }} className="grid gap-2 sm:grid-cols-[1fr_10rem_auto] sm:items-end">
                <Field label="What did you notice?" error={concern.errors.title}><input autoFocus className="field" value={concern.data.title} onChange={(e) => concern.setData('title', e.target.value)} /></Field>
                <Field label="How serious?"><select className="field" value={concern.data.severity} onChange={(e) => concern.setData('severity', e.target.value)}><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select></Field>
                <Button type="submit" disabled={concern.processing}>Send to vet</Button>
            </form>
        );
    }
    if (kind === 'move') {
        return (
            <form onSubmit={(e) => { e.preventDefault(); move.post('/sheds/transfer', options); }} className="flex flex-wrap items-end gap-2">
                <div className="w-64"><Field label="Move to"><select className="field" value={move.data.pen_id} onChange={(e) => move.setData('pen_id', e.target.value)}>{pens.map((p) => <option key={p.id} value={p.id}>{p.name}: {p.purpose}</option>)}</select></Field></div>
                <Button type="submit" disabled={move.processing}>Move goat</Button>
            </form>
        );
    }
    if (kind === 'photo') {
        return (
            <form onSubmit={(e) => { e.preventDefault(); photo.post(`/goats/${goat.id}/photos`, { ...options, forceFormData: true }); }} className="grid gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <PhotoPicker value={photo.data.photo} onChange={(file) => photo.setData('photo', file)} error={photo.errors.photo} />
                <Field label="Note (optional)"><input className="field" value={photo.data.notes} onChange={(e) => photo.setData('notes', e.target.value)} /></Field>
                <Button type="submit" disabled={photo.processing || !photo.data.photo}>Save photo</Button>
            </form>
        );
    }
    return (
        <form onSubmit={(e) => { e.preventDefault(); exit.post(`/goats/${goat.id}/exit`, options); }} className="flex flex-wrap items-end gap-2">
            <div className="w-52"><Field label="Reason"><select className="field" value={exit.data.reason} onChange={(e) => exit.setData('reason', e.target.value)}><option value="sold">Sold</option><option value="gifted">Gifted</option><option value="sacrificed">Sacrificed</option><option value="died">Died</option></select></Field></div>
            <Button type="submit" variant="danger" disabled={exit.processing}>Confirm exit</Button>
            <p className="w-full text-sm text-ink-soft">The RFID tag is released. Nothing about this goat is deleted.</p>
        </form>
    );
}

/** A parent: a link when registered, the ear tag with a note when only known by tag (D-043). */
function parentLink(parent: { id: string | null; number: string } | null | undefined) {
    if (!parent) return null;
    return parent.id ? <Link href={`/goats/${parent.id}`} className="text-cobalt-800 hover:underline">{parent.number}</Link> : <span>{parent.number} <span className="text-sm font-normal text-ink-faint">(not registered)</span></span>;
}
