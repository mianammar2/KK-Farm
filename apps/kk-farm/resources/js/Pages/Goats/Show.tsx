import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useConfirm } from '../../components/Confirm';
import PhotoPicker from '../../components/PhotoPicker';
import { Camera, ClipboardPlus, MoveRight, Pencil, Scale, Star, Stethoscope, Trash2, TriangleAlert } from 'lucide-react';
import HealthEntry from '../../components/HealthEntry';
import Sheet from '../../components/Sheet';
import type { CheckupData } from '../../components/Checkups';
import { useMemo, useRef, useState, type ReactNode } from 'react';
import Chart from '../../components/LazyChart';
import { axis } from '../../lib/chartTheme';
import GoatPortrait from '../../components/GoatPortrait';
import PregnancyOutcomeForm, { type OutcomeOptions } from '../../components/PregnancyOutcomeForm';
import UltrasoundForm, { type UltrasoundOptions } from '../../components/UltrasoundForm';
import PedigreeTree, { type PedigreeNode } from '../../components/PedigreeTree';
import { Button, Chip, cx, Empty, Field, GoatLabel, HealthChip, LibIcon, Note, Panel, pkr, ReproChip, SexIcon, Tabs } from '../../components/ui';
import type { GoatCard, SharedProps } from '../../types';
import { AgeBadge, BreedBadge } from '../../components/GoatBadges';

type Goat = GoatCard & { withdrawal: { meat: string | null; milk: string | null } | null; origin: string; birthDate: string | null; estimated: boolean; height: number | null; shed: string | null; penPurpose: string | null; feedingGroup: string | null; exitReason: string | null; exitDate: string | null; adg: number | null; lifetimeAdg: number | null; identity: string; classification: string; teeth: string | null; removal: { by: string | null; on: string | null; reason: string | null; voidedBy: string | null; voidedOn: string | null } | null; sire: { id: string | null; number: string } | null; dam: { id: string | null; number: string } | null };
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
    can: { edit: boolean; remove: boolean; breeding: boolean };
    outcomeOptions: OutcomeOptions;
    scanMating: { id: number; matedOnIso: string; day: number } | null; // D-166
    ultrasoundOptions: UltrasoundOptions;
    pregnancy: Pregnancy | null;
    milking: Milking | null;
    healthEntry: (CheckupData & { conditions: string[]; severities: Record<string, string> }) | null;
};
type Pregnancy = {
    matingId: number; matedOnIso: string; matedOn: string; expected: string | null; sire: string | null; day: number; gestation: number; daysLeft: number | null; alertDays: number;
    checks: Array<{ on: string; day: number; weight: number | null; temp: number | null; ultrasound: string | null; fetuses: number | null; by: string }>;
    weights: Array<{ day: number; kg: number; on: string }>;
};

const TYPE_COLOR: Record<string, string> = { edit: 'bg-cobalt-600', origin: 'bg-cobalt-700', tag: 'bg-turq-600', health: 'bg-treat-400', breeding: 'bg-manganese-600', exit: 'bg-crit-500', weight: 'bg-cobalt-200', move: 'bg-watch-400', photo: 'bg-turq-400' };

export default function GoatShow(props: Props) {
    const { goat, photos, tags, weights, timeline, health, cases, pedigree, offspring, matings, kiddings, cost, pens, can, pregnancy, healthEntry, milking, outcomeOptions, scanMating, ultrasoundOptions } = props;
    const [outcome, setOutcome] = useState(false); // D-161
    const [scan, setScan] = useState(false); // D-166
    const [ask, removeBox] = useConfirm(); // D-133
    const removed = goat.status === 'voided';
    const { auth } = usePage<SharedProps>().props;
    const [tab, setTab] = useState('overview');
    // D-102: opened from a scan with ?do=checkup|pregnancy|entry|weight|move|concern, the right form is already open.
    const todo = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('do') : null;
    const [panel, setPanel] = useState<string | null>(['weight', 'move', 'concern'].includes(todo ?? '') ? todo : null);
    const [entry, setEntry] = useState<string | null>(['checkup', 'pregnancy', 'entry'].includes(todo ?? '') && healthEntry ? (todo === 'entry' ? '' : todo) : null);
    const profile = photos.find((p) => p.isProfile);
    const onFarm = goat.status === 'active';

    // D-102: dates to scale (7 days apart look 7 days apart), each point labelled with its day and weight.
    const chart = useMemo(() => ({
        tooltip: { trigger: 'axis', formatter: (x: Array<{ data: [number, number] }>) => `${new Date(x[0].data[0]).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })}: ${x[0].data[1]} KG` },
        grid: { left: 8, right: 16, top: 24, bottom: 6, containLabel: true },
        xAxis: { type: 'time', axisLabel: { color: '#5e6a5f', formatter: (v: number) => new Date(v).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) }, splitLine: { show: false }, minInterval: 86400000 },
        yAxis: { ...axis.value((v) => `${v} KG`), min: 0 }, // D-105: weight graphs start at 0 KG
        series: [{ type: 'line', data: weights.map((w) => [new Date(`${w.on}T12:00:00`).getTime(), w.kg]), symbolSize: 8, lineStyle: { width: 2.5 }, areaStyle: { opacity: 0.08 },
            label: { show: weights.length <= 12, position: 'top', formatter: (p: { data: [number, number] }) => `${p.data[1]}`, color: '#013624', fontWeight: 600 } }],
    }), [weights]);
    const gain = weights.length > 1 ? (() => {
        const a = weights[0];
        const b = weights[weights.length - 1];
        const days = Math.max(1, Math.round((new Date(`${b.on}T12:00:00`).getTime() - new Date(`${a.on}T12:00:00`).getTime()) / 86400000));
        return { kg: Math.round((b.kg - a.kg) * 10) / 10, days };
    })() : null;

    const fact = (label: string, value: React.ReactNode) => (
        <div className="min-w-0"><dt className="text-sm text-ink-soft">{label}</dt><dd className="truncate font-semibold text-cobalt-900">{value ?? '—'}</dd></div>
    );

    return (
        <>
            {removeBox}
            <Head title={goat.number} />
            {/* D-073: withdrawal from the veterinarian's treatment dates. */}
            {goat.withdrawal ? (
                <div role="alert" className="mb-4 flex flex-wrap items-center gap-2 rounded-tile border border-crit-500/40 bg-crit-100 px-4 py-3 text-crit-700">
                    <strong>Under withdrawal.</strong>
                    {goat.withdrawal.meat ? <span>Do not sell, gift or sacrifice before {goat.withdrawal.meat}.</span> : null}
                    {goat.withdrawal.milk ? <span>Do not use milk before {goat.withdrawal.milk}.</span> : null}
                </div>
            ) : null}
            {/* D-091: a wrong entry is removed in two steps and never deleted; the trail stays on the goat. */}
            {removed ? (
                <div role="alert" className="mb-4 flex flex-wrap items-center gap-2 rounded-tile border border-rule-strong bg-glaze px-4 py-3">
                    <Trash2 size={18} className="text-ink-soft" aria-hidden />
                    <span><strong>Removed as a wrong entry</strong> on {goat.removal?.voidedOn}. Asked by {goat.removal?.by}, confirmed by {goat.removal?.voidedBy}: {goat.removal?.reason}</span>
                    {can.remove ? <Button variant="secondary" className="ml-auto" onClick={() => router.post(`/goats/${goat.id}/restore`, {}, { preserveScroll: true })}>Restore</Button> : null}
                </div>
            ) : goat.removal ? (
                <div role="alert" className="mb-4 flex flex-wrap items-center gap-2 rounded-tile border border-watch-400 bg-watch-100 px-4 py-3 text-watch-700">
                    <Trash2 size={18} aria-hidden />
                    <span><strong>Removal asked</strong> by {goat.removal.by} on {goat.removal.on}: {goat.removal.reason}</span>
                    {can.remove ? (
                        <span className="ml-auto flex gap-2">
                            <Button variant="danger" onClick={async () => { if (await ask({ title: `Remove ${goat.number}?`, message: 'It leaves every list, count and feed plan, and its RFID tag is freed. The record and its history stay and it can be restored.', confirm: 'Yes, remove' })) router.post(`/goats/${goat.id}/removal/decide`, { decision: 'confirm' }); }}>Confirm removal</Button>
                            <Button variant="secondary" onClick={() => router.post(`/goats/${goat.id}/removal/decide`, { decision: 'cancel' }, { preserveScroll: true })}>Keep the goat</Button>
                        </span>
                    ) : <span className="ml-auto text-sm">Waiting for the owner or a manager.</span>}
                </div>
            ) : null}
            <section className="mb-4 overflow-hidden rounded-tile border border-rule bg-tile shadow-tile">
                <div className="flex flex-col gap-4 p-3 sm:flex-row sm:p-4">
                    {/* Phones: a small photo beside the tag; wider screens: the large portrait on the left. */}
                    <div className="max-sm:hidden">{profile ? <img src={`/media/${profile.id}`} alt={`Profile of ${goat.number}`} className="size-36 shrink-0 rounded-tile object-cover" /> : <GoatPortrait number={goat.number} sex={goat.sex} stage={goat.stage} repro={goat.repro} size={144} />}</div>
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="sm:hidden">{profile ? <img src={`/media/${profile.id}`} alt="" className="size-14 rounded-tile object-cover" /> : <GoatPortrait number={goat.number} sex={goat.sex} stage={goat.stage} repro={goat.repro} size={56} />}</span>
                            <h1 className="font-mono text-xl font-bold tracking-tight text-cobalt-900 sm:text-2xl">{goat.number}</h1>
                            <SexIcon sex={goat.sex} />
                            {onFarm ? <HealthChip health={goat.health} /> : removed ? <Chip className="bg-glaze text-ink-soft">Removed (wrong entry)</Chip> : <Chip className="bg-cobalt-100 text-cobalt-800">Left the farm: {goat.exitReason}</Chip>}
                            <ReproChip repro={goat.repro} until={goat.monitorUntil} />
                        </div>
                        <p className="flex flex-wrap items-center gap-1.5 text-sm text-ink-soft sm:text-base">{goat.name ? <span className="font-semibold text-cobalt-800">{goat.name} ·</span> : null}<BreedBadge breed={goat.breed} withName size={22} /><span>·</span><AgeBadge stage={goat.stage} withLabel /><span>{goat.age}</span></p>
                        {goat.classification ? <p className="mt-1 inline-flex rounded-full bg-cobalt-900 px-3 py-1 text-sm font-semibold text-white">{goat.classification}</p> : null}
                        <dl className="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 md:grid-cols-4">
                            {fact('Current weight', goat.weight ? `${goat.weight} KG` : 'No weighing yet')}
                            {fact('Recent daily gain', goat.adg !== null ? `${goat.adg} g/day` : '—')}
                            {fact('Pen', goat.pen ?? '—')}
                            {fact('RFID tag', tags.find((t) => !t.to)?.code ?? 'No open tag')}
                        </dl>
                        {/* Phones: the everyday actions as one tidy grid of icons; rarer ones as links below it. */}
                        {onFarm || (!removed && can.edit) ? (
                            <div className="mt-3 grid grid-cols-4 gap-2 sm:hidden">
                                {onFarm && scanMating ? <ActionTile icon={<LibIcon name="mod-reports" size={26} />} label="Ultrasound" onClick={() => setScan(true)} /> : null}
                                {onFarm && pregnancy && can.breeding ? <ActionTile icon={<LibIcon name="ic-pregnant" size={26} />} label="Pregnancy" primary onClick={() => setOutcome(true)} /> : null}
                                {onFarm && healthEntry?.canCheck ? <ActionTile icon={<LibIcon name="mod-health" size={26} />} label="Checkup" primary onClick={() => setEntry('checkup')} /> : null}
                                {onFarm && healthEntry && (healthEntry.canCheck || healthEntry.canOpenCase) ? <ActionTile icon={<LibIcon name="ic-under-treatment" size={26} />} label="Health Entry" onClick={() => setEntry('')} /> : null}
                                {onFarm ? <ActionTile icon={<LibIcon name="mod-growth" size={26} />} label="Weight" active={panel === 'weight'} onClick={() => setPanel(panel === 'weight' ? null : 'weight')} /> : null}
                                {onFarm ? <ActionTile icon={<Camera size={20} />} label="Photo" active={panel === 'photo'} onClick={() => setPanel(panel === 'photo' ? null : 'photo')} /> : null}
                                {onFarm ? <ActionTile icon={<LibIcon name="ic-sick" size={26} />} label="Concern" active={panel === 'concern'} onClick={() => setPanel(panel === 'concern' ? null : 'concern')} /> : null}
                                {onFarm ? <ActionTile icon={<LibIcon name="ic-transferred" size={26} />} label="Move" active={panel === 'move'} onClick={() => setPanel(panel === 'move' ? null : 'move')} /> : null}
                                {!removed && can.edit ? <ActionTile icon={<LibIcon name="mod-settings" size={26} />} label="Edit" onClick={() => router.visit(`/goats/${goat.id}/edit`)} /> : null}
                            </div>
                        ) : null}
                        <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm sm:hidden">
                            {onFarm && ['ceo', 'manager'].includes(auth?.role ?? '') ? <button type="button" className="font-semibold text-cobalt-800" onClick={() => setPanel(panel === 'birth' ? null : 'birth')}>Correct Birth Date</button> : null}
                            {onFarm && auth?.gates.includes('exit_animal') ? <button type="button" className="font-semibold text-crit-700" onClick={() => setPanel(panel === 'exit' ? null : 'exit')}>Record Exit</button> : null}
                            {!removed && can.edit && !goat.removal ? <button type="button" className="font-semibold text-ink-soft" onClick={() => setPanel(panel === 'remove' ? null : 'remove')}>Remove (Wrong Entry)</button> : null}
                        </div>
                        {!removed && can.edit ? (
                            <div className="mt-3 flex flex-wrap gap-2 max-sm:hidden">
                                <Button onClick={() => router.visit(`/goats/${goat.id}/edit`)}><LibIcon name="mod-settings" size={18} /> Edit details</Button>
                                {!goat.removal ? <Button variant="quiet" onClick={() => setPanel(panel === 'remove' ? null : 'remove')}><Trash2 size={16} aria-hidden /> Remove (wrong entry)</Button> : null}
                            </div>
                        ) : null}
                        {onFarm ? (
                            <div className="mt-3 flex flex-wrap gap-2 max-sm:hidden">
                                {scanMating ? <Button variant="secondary" onClick={() => setScan(true)}><LibIcon name="mod-reports" size={18} /> Ultrasound</Button> : null}
                                {pregnancy && can.breeding ? <Button onClick={() => setOutcome(true)}><LibIcon name="ic-pregnant" size={18} /> Pregnancy Outcome</Button> : null}
                                {healthEntry?.canCheck ? <Button onClick={() => setEntry('checkup')}><LibIcon name="mod-health" size={18} /> Health checkup</Button> : null}
                                {healthEntry && (healthEntry.canCheck || healthEntry.canOpenCase) ? <Button variant="secondary" onClick={() => setEntry('')}><LibIcon name="ic-under-treatment" size={18} /> New health entry</Button> : null}
                                <Button variant="secondary" onClick={() => setPanel(panel === 'weight' ? null : 'weight')}><LibIcon name="mod-growth" size={18} /> Add weight</Button>
                                <Button variant="secondary" onClick={() => setPanel(panel === 'photo' ? null : 'photo')}><Camera size={16} aria-hidden /> Add photo</Button>
                                <Button variant="secondary" onClick={() => setPanel(panel === 'concern' ? null : 'concern')}><LibIcon name="ic-sick" size={18} /> Report concern</Button>
                                <Button variant="secondary" onClick={() => setPanel(panel === 'move' ? null : 'move')}><LibIcon name="ic-transferred" size={18} /> Move</Button>
                                {['ceo', 'manager'].includes(auth?.role ?? '') ? <Button variant="secondary" onClick={() => setPanel(panel === 'birth' ? null : 'birth')}><LibIcon name="ic-farm-born" size={18} /> Correct birth date</Button> : null}
                                {auth?.gates.includes('exit_animal') ? <Button variant="danger" onClick={() => setPanel(panel === 'exit' ? null : 'exit')}><LibIcon name="ic-sold" size={18} /> Record exit</Button> : null}
                            </div>
                        ) : null}
                    </div>
                </div>
                {panel ? <div className="border-t border-rule bg-glaze p-4"><ActionPanel kind={panel} goat={goat} pens={pens} close={() => setPanel(null)} /></div> : null}
            </section>

            {scan && scanMating ? (
                <Sheet onClose={() => setScan(false)}>
                    <UltrasoundForm matingId={scanMating.id} damNumber={goat.number} matedOn={scanMating.matedOnIso} options={ultrasoundOptions} onDone={() => setScan(false)} />
                </Sheet>
            ) : null}
            {outcome && pregnancy ? (
                <Sheet onClose={() => setOutcome(false)}>
                    <PregnancyOutcomeForm matingId={pregnancy.matingId} damNumber={goat.number} matedOn={pregnancy.matedOnIso} options={outcomeOptions} onDone={() => setOutcome(false)} />
                </Sheet>
            ) : null}
            {entry !== null && healthEntry ? (
                <Sheet onClose={() => setEntry(null)}>
                    <HealthEntry data={healthEntry} severities={healthEntry.severities} goatId={goat.id} kind={entry || undefined} onDone={() => setEntry(null)} />
                </Sheet>
            ) : null}
            <Tabs
                value={tab}
                onChange={setTab}
                tabs={[{ id: 'overview', label: 'Overview' }, { id: 'timeline', label: 'Lifecycle', count: timeline.length }, { id: 'health', label: 'Health', count: cases.length + health.length }, { id: 'breeding', label: 'Breeding & pedigree' }, { id: 'photos', label: 'Photos', count: photos.length }, { id: 'tags', label: 'RFID history', count: tags.length }]}
            />

            {tab === 'overview' ? (
                <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <Panel title="Weight history" description={gain && goat.lifetimeAdg !== null ? `${gain.kg >= 0 ? '+' : ''}${gain.kg} KG in ${gain.days} days · ${goat.lifetimeAdg} g a day on average` : undefined}>
                        {weights.length ? <Chart option={chart} label={`Weight history of ${goat.number}`} height={260} /> : <Empty title="No weighings yet">Use Add weight to record the first one.</Empty>}
                    </Panel>
                    <div className="space-y-4">
                        {pregnancy ? <PregnancyPanel p={pregnancy} /> : null}
                        {milking ? <MilkingPanel m={milking} /> : null}
                        <Panel title="Identity">
                            <dl className="space-y-2">
                                {fact('Permanent ID', <span className="text-sm font-medium break-all whitespace-normal">{goat.identity}</span>)}
                                {fact('Born', `${goat.birthDate ?? 'Unknown'}${goat.estimated ? ' (estimated)' : ''}`)}
                                {goat.teeth ? fact('Teeth', goat.teeth) : null}
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
    const exit = useForm({ reason: 'sold', amount: '', destination: '', notes: '' });
    const [ask, confirmBox] = useConfirm(); // D-133
    const photo = useForm<{ photo: File | null; notes: string }>({ photo: null, notes: '' });
    const birth = useForm({ birth_date: '', age_months: '', reason: '' });
    const removal = useForm({ reason: '' });

    if (kind === 'remove') {
        return (
            <form onSubmit={(e) => { e.preventDefault(); removal.post(`/goats/${goat.id}/removal`, { preserveScroll: true, onSuccess: close }); }} className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end">
                <Field label="Why is this goat record wrong?" error={removal.errors.reason} hint="Nothing is deleted. The owner or a manager confirms; the goat then leaves every list and count, and this record keeps who asked, who confirmed and why. It can be restored.">
                    <input className="field" value={removal.data.reason} onChange={(e) => removal.setData('reason', e.target.value)} placeholder="e.g. Registered twice by mistake, same goat as TD-0002" autoFocus />
                </Field>
                <div className="flex gap-2"><Button type="submit" variant="danger" disabled={removal.processing}>Ask to remove</Button><Button type="button" variant="secondary" onClick={close}>Cancel</Button></div>
            </form>
        );
    }
    const options = { preserveScroll: true, onSuccess: close };

    // D-080: correct a wrong birth date (often the purchase date). Kept in the lifecycle with the old date and reason.
    if (kind === 'birth') {
        return (
            <form onSubmit={(e) => { e.preventDefault(); birth.post(`/goats/${goat.id}/birth-date`, options); }} className="grid gap-2 sm:grid-cols-[11rem_9rem_1fr_auto] sm:items-end">
                <Field label="Birth date" error={birth.errors.birth_date}><input type="date" className="field" value={birth.data.birth_date} onChange={(e) => birth.setData('birth_date', e.target.value)} /></Field>
                <Field label="or age (months)" error={birth.errors.age_months}><input inputMode="numeric" className="field" value={birth.data.age_months} onChange={(e) => birth.setData('age_months', e.target.value.replace(/[^0-9]/g, ''))} placeholder="e.g. 24" /></Field>
                <Field label="Reason" error={birth.errors.reason}><input className="field" value={birth.data.reason} onChange={(e) => birth.setData('reason', e.target.value)} placeholder="e.g. purchase date was entered as birth date" /></Field>
                <Button type="submit" disabled={birth.processing}>Save</Button>
            </form>
        );
    }

    if (kind === 'weight') {
        return (
            <form onSubmit={(e) => { e.preventDefault(); weight.post('/weights', options); }} className="flex flex-wrap items-end gap-2">
                <div className="w-44"><Field label="Weight (KG)" error={weight.errors.weight_kg}><input inputMode="decimal" autoFocus className="field" value={weight.data.weight_kg} onChange={(e) => weight.setData('weight_kg', e.target.value)} /></Field></div>
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
        <form onSubmit={async (e) => { e.preventDefault(); if (await ask({ title: `Record ${goat.number} as ${EXIT_WORD[exit.data.reason] ?? 'left the farm'}?`, message: 'It leaves the farm lists and counts. This is kept in its history.', confirm: 'Yes, record exit' })) exit.post(`/goats/${goat.id}/exit`, options); }} className="flex flex-wrap items-end gap-2">
            {confirmBox}
            <div className="w-52"><Field label="Reason" error={exit.errors.reason}><select className="field" value={exit.data.reason} onChange={(e) => exit.setData('reason', e.target.value)}><option value="sold">Sold</option><option value="sacrificed">Qurbani / sacrifice</option><option value="culled">Culled</option><option value="gifted">Gifted</option><option value="transferred">Transferred out</option><option value="died">Died</option><option value="other">Other</option></select></Field></div>
            {exit.data.reason === 'sold' ? <div className="w-40"><Field label="Price (PKR)" error={exit.errors.amount}><input inputMode="decimal" className="field" value={exit.data.amount} onChange={(e) => exit.setData('amount', e.target.value)} /></Field></div> : null}
            {exit.data.reason !== 'died' ? <div className="w-48"><Field label={exit.data.reason === 'sold' ? 'Buyer' : 'Destination'}><input className="field" value={exit.data.destination} onChange={(e) => exit.setData('destination', e.target.value)} /></Field></div> : null}
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

/**
 * D-100: the pregnancy as one thread: day N of the gestation counted from the mating, expected kidding, every check
 * with its day of pregnancy, weight and temperature, and her weight by day of pregnancy.
 */
function PregnancyPanel({ p }: { p: Pregnancy }) {
    const pct = Math.min(100, Math.max(0, Math.round((p.day / p.gestation) * 100)));
    const chart = useMemo(() => (p.weights.length ? {
        tooltip: { trigger: 'axis', formatter: (x: Array<{ data: [number, number] }>) => `Day ${x[0].data[0]}: ${x[0].data[1]} KG` },
        grid: { left: 8, right: 12, top: 14, bottom: 6, containLabel: true },
        xAxis: { type: 'value', name: 'Day', min: 0, max: p.gestation, splitLine: { show: false }, axisLabel: { color: '#5e6a5f' } },
        yAxis: { ...axis.value((v) => `${v} KG`), min: 0 }, // D-105: weight graphs start at 0 KG
        series: [{ type: 'line', data: p.weights.map((w) => [w.day, w.kg]), symbolSize: 8, lineStyle: { width: 2.5, color: '#7a3e8f' }, itemStyle: { color: '#7a3e8f' },
            markLine: { silent: true, symbol: 'none', lineStyle: { type: 'dashed', color: '#e0a526' }, data: [{ xAxis: p.day }], label: { formatter: 'today' } } }],
    } : null), [p]);
    return (
        <Panel title={`Pregnant · day ${p.day} of ${p.gestation}`} description={`Mated ${p.matedOn}${p.sire ? ` with ${p.sire}` : ''} · kidding about ${p.expected ?? '—'}${p.daysLeft !== null ? ` (${p.daysLeft} days)` : ''}`}>
            {/* D-103: from 10 days before the expected kidding */}
            {p.daysLeft !== null && p.daysLeft <= p.alertDays ? (
                <div role="alert" className={cx('mb-3 flex items-start gap-2 rounded-tile border px-3 py-2 text-sm font-semibold', p.daysLeft <= 3 ? 'border-crit-500 bg-crit-100 text-crit-700' : 'border-watch-400 bg-watch-100 text-watch-700')}>
                    <span className={cx('kk-beat mt-1.5 size-2.5 shrink-0 rounded-full', p.daysLeft <= 3 ? 'bg-crit-500' : 'bg-watch-400')} aria-hidden />
                    <span>{p.daysLeft < 0 ? `Kidding overdue by ${-p.daysLeft} day${p.daysLeft === -1 ? '' : 's'}: check her now.` : p.daysLeft === 0 ? 'Kidding expected today.' : `Kidding in ${p.daysLeft} day${p.daysLeft === 1 ? '' : 's'}.`} Kidding pen, clean bedding, colostrum ready; watch her closely.</span>
                </div>
            ) : null}
            <div className="h-2.5 overflow-hidden rounded-full bg-manganese-100" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label="Pregnancy progress">
                <div className="h-full rounded-full bg-manganese-600" style={{ width: `${pct}%` }} />
            </div>
            {chart ? <div className="mt-3"><Chart option={chart} label="Weight by day of pregnancy" height={160} /></div> : null}
            {p.checks.length ? (
                <table className="mt-3 w-full text-sm">
                    <thead className="text-left text-xs text-ink-soft"><tr><th className="py-1">Date</th><th>Day</th><th>KG</th><th>°F</th><th>Ultrasound</th></tr></thead>
                    <tbody className="divide-y divide-rule">{p.checks.map((c, i) => (
                        <tr key={i}><td className="py-1 whitespace-nowrap">{c.on}</td><td className="font-semibold text-manganese-600">{c.day}</td><td>{c.weight ?? '—'}</td><td>{c.temp ?? '—'}</td>
                            <td>{c.ultrasound === 'pregnant' ? `Pregnant${c.fetuses ? ` (${c.fetuses})` : ''}` : c.ultrasound === 'not_pregnant' ? 'Not pregnant' : c.ultrasound ? 'Not clear' : '—'}</td></tr>
                    ))}</tbody>
                </table>
            ) : <p className="mt-3 text-sm text-ink-soft">No checks since mating yet.</p>}
        </Panel>
    );
}

type Milking = { avg7: number | null; high: boolean; standard: string | null; cp: string | null; lactations: Array<{ number: number; started: string; dried: string | null; days: number; total: number; reason: string | null; open: boolean }> };

/** D-117: in milk since kidding (or arrival), her lactations and the nutrition standard that follows from it. */
function MilkingPanel({ m }: { m: Milking }) {
    const now = m.lactations.find((l) => l.open);
    return (
        <Panel title={now ? `In Milk · Lactation ${now.number}, Day ${now.days}` : 'Milking History'} actions={<Link href="/milking" className="text-sm font-semibold text-turq-800 hover:underline">Milking</Link>}>
            {now ? (
                <p className="mb-2 text-sm text-ink-soft">Since {now.started} · {now.total} L so far{m.avg7 !== null ? ` · ${m.avg7} L a day this week` : ''}{m.high ? ' · high-producing' : ''}{m.standard ? <> · feeds as <strong className="text-cobalt-900">{m.standard}</strong>{m.cp ? ` (CP ${m.cp})` : ''}</> : null}</p>
            ) : null}
            <ul className="divide-y divide-rule text-sm">{m.lactations.map((l) => (
                <li key={l.number} className="flex flex-wrap gap-x-3 py-1.5"><span className="font-semibold text-cobalt-900">Lactation {l.number}</span><span className="text-ink-soft">{l.started} to {l.dried ?? 'now'} · {l.days} days · {l.total} L</span>{l.reason ? <span className="w-full text-xs text-ink-faint">{l.reason}</span> : null}</li>
            ))}</ul>
        </Panel>
    );
}

/** Phones: one action as an icon tile with a short label. */
function ActionTile({ icon, label, onClick, primary = false, active = false }: { icon: ReactNode; label: string; onClick: () => void; primary?: boolean; active?: boolean }) {
    return (
        <button type="button" onClick={onClick} className={`flex min-h-16 flex-col items-center justify-center gap-1 rounded-tile border px-1 py-2 text-[11px] font-semibold leading-tight active:scale-[0.97] ${primary ? 'border-cobalt-800 bg-cobalt-800 text-white' : active ? 'border-cobalt-700 bg-cobalt-100 text-cobalt-900' : 'border-rule bg-tile text-cobalt-900'}`}>
            <span aria-hidden>{icon}</span>{label}
        </button>
    );
}

const EXIT_WORD: Record<string, string> = { sold: 'sold', died: 'died', sacrificed: 'Qurbani', culled: 'culled', gifted: 'gifted', transferred: 'transferred out' };
