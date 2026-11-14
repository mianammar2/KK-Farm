import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useConfirm } from '../../components/Confirm';
import PhotoPicker from '../../components/PhotoPicker';
import { Camera, ClipboardPlus, MoveRight, Pencil, Scale, Star, Stethoscope, Trash2, Thermometer, TriangleAlert } from 'lucide-react';
import HealthEntry from '../../components/HealthEntry';
import Sheet from '../../components/Sheet';
import type { CheckupData } from '../../components/Checkups';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import Chart from '../../components/LazyChart';
import { axis } from '../../lib/chartTheme';
import GoatPortrait from '../../components/GoatPortrait';
import { usePaged } from '../../components/Pager';
import PregnancyOutcomeForm, { type OutcomeOptions } from '../../components/PregnancyOutcomeForm';
import UltrasoundForm, { type UltrasoundOptions } from '../../components/UltrasoundForm';
import PedigreeTree, { type PedigreeNode } from '../../components/PedigreeTree';
import { Button, Chip, cx, Empty, Field, GoatLabel, HealthChip, LibIcon, Note, Panel, pkr, ReproChip, SexIcon, Tabs } from '../../components/ui';
import type { GoatCard, SharedProps } from '../../types';
import { AgeBadge, BreedBadge } from '../../components/GoatBadges';

type Dental = { code: string; fromAge?: boolean; condition: string | null; checkedOn: string | null; history: Array<{ on: string; code: string; condition: string | null; by: string | null; source: string }> };
type Goat = GoatCard & { arrivedOn?: string | null; arrivedEntered?: boolean; sourceFarm?: string | null; inBreeding?: boolean; ageStage?: { code: string; label: string; range: string } | null; dental?: Dental; withdrawal: { meat: string | null; milk: string | null } | null; origin: string; birthDate: string | null; estimated: boolean; height: number | null; shed: string | null; penPurpose: string | null; feedingGroup: string | null; exitReason: string | null; exitDate: string | null; adg: number | null; lifetimeAdg: number | null; identity: string; classification: string; teeth: string | null; removal: { by: string | null; on: string | null; reason: string | null; voidedBy: string | null; voidedOn: string | null } | null; sire: { id: string | null; number: string } | null; dam: { id: string | null; number: string } | null };
type Props = {
    goat: Goat;
    photos: Array<{ id: number; notes: string | null; isProfile: boolean; by: string; on: string }>;
    tags: Array<{ code: string; from: string; to: string | null; reason: string; closed: string | null; by: string }>;
    weights: Array<{ on: string; kg: number }>;
    records: GoatRecord[]; // D-249
    death: DeathData | null; // D-260
    recovery: { state: 'improving' | 'not_improving' | 'no_recheck'; since: string; title: string; then: number | null; now: number | null; nowOn: string | null; goal: number | null; temp: number | null }
        | { state: 'recovered'; on: string; why: string } | null; // D-251
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
    dentalOptions: { codes: Record<string, string>; conditions: Record<string, string> };
    roleLabels: Record<string, string>;
    statuses: Record<string, { code: string; label: string; note?: string | null; blockers?: string[] }> | null;
    statusHistory: Array<{ dimension: string; from: string; to: string; rule: string; at: string }>;
    eligibility: { status: string; label: string; review: boolean; recommendation: string | null; failed: string[] } | null;
    castration: { on: string | null; methods: Record<string, string>; can: boolean } | null;
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
    const { death, recovery, records, goat, photos, tags, weights, timeline, health, cases, pedigree, offspring, matings, kiddings, cost, pens, can, pregnancy, healthEntry, milking, outcomeOptions, scanMating, ultrasoundOptions, dentalOptions, roleLabels, castration, eligibility, statuses, statusHistory } = props;
    const [outcome, setOutcome] = useState(false); // D-161
    const [scan, setScan] = useState(false); // D-166
    const [ask, removeBox] = useConfirm(); // D-133
    const removed = goat.status === 'voided';
    const { auth } = usePage<SharedProps>().props;
    const [tab, setTab] = useState('overview');
    // D-102: opened from a scan with ?do=checkup|pregnancy|entry|weight|move|concern, the right form is already open.
    const todo = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('do') : null;
    const [panel, setPanel] = useState<string | null>(['weight', 'move', 'concern', 'temp'].includes(todo ?? '') ? todo : null);
    const [entry, setEntry] = useState<string | null>(['checkup', 'pregnancy', 'entry'].includes(todo ?? '') && healthEntry ? (todo === 'entry' ? '' : todo) : null);
    const profile = photos.find((p) => p.isProfile);
    const onFarm = goat.status === 'active';

    // D-102: dates to scale (7 days apart look 7 days apart), each point labelled with its day and weight.
    const gain = weights.length > 1 ? (() => {
        const a = weights[0];
        const b = weights[weights.length - 1];
        const days = Math.max(1, Math.round((new Date(`${b.on}T12:00:00`).getTime() - new Date(`${a.on}T12:00:00`).getTime()) / 86400000));
        return { kg: Math.round((b.kg - a.kg) * 10) / 10, days };
    })() : null;
    // D-254: the latest change, stock style: ▲/▼ KG and %, over how many days, and per day
    const last = weights.length > 1 ? (() => {
        const a = weights[weights.length - 2];
        const b = weights[weights.length - 1];
        const days = Math.max(1, Math.round((new Date(`${b.on}T12:00:00`).getTime() - new Date(`${a.on}T12:00:00`).getTime()) / 86400000));
        const kg = Math.round((b.kg - a.kg) * 10) / 10;
        return { kg, pct: a.kg ? Math.round(((b.kg - a.kg) / a.kg) * 1000) / 10 : 0, days, perDay: Math.round(((b.kg - a.kg) * 1000) / days) };
    })() : null;

    const fact = (label: string, value: React.ReactNode) => (
        <div className="min-w-0"><dt className="text-sm text-ink-soft">{label}</dt><dd className="text-base font-semibold break-words text-cobalt-900">{value ?? '—'}</dd></div>
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
                        <p className="flex flex-wrap items-center gap-1.5 text-sm text-ink-soft sm:text-base">{goat.name ? <span className="font-semibold text-cobalt-800">{goat.name} ·</span> : null}<BreedBadge breed={goat.breed} withName size={22} /><span>·</span><AgeBadge stage={goat.stage} code={goat.ageCode} withLabel /><span>{goat.age}</span></p>
                        {Object.keys(roleLabels).length ? <p className="mt-1.5 flex flex-wrap gap-1.5" aria-label="Role codes">{Object.entries(roleLabels).map(([c, l], i) => <span key={c} className={`rounded-full px-3 py-1 text-sm font-semibold ${i === 0 ? 'bg-turq-100 text-turq-800' : 'bg-glaze text-cobalt-800 ring-1 ring-rule'}`}><span className="font-mono">{c}</span> · {l}</span>)}</p> : null}
                        {statuses ? <StatusStrip statuses={statuses} history={statusHistory} /> : null}
                        <dl className="mt-3 grid grid-cols-2 gap-x-6 gap-y-2 md:grid-cols-4">
                            {fact('Current weight', goat.weight ? `${goat.weight} KG` : 'No weighing yet')}
                            {fact('Latest gain', last ? <Ticker kg={last.kg} pct={last.pct} days={last.days} perDay={last.perDay} /> : '—')}
                            {fact('PEN', goat.pen ?? '—')}
                            {fact('RFID tag', tags.find((t) => !t.to)?.code ?? 'No open tag')}
                        </dl>
                        {/* Phones: the everyday actions as one tidy grid of icons; rarer ones as links below it. */}
                        {onFarm || (!removed && can.edit) ? (
                            <div className="mt-3 grid grid-cols-4 gap-2 sm:hidden">
                                {onFarm && scanMating ? <ActionTile icon={<LibIcon name="app-reports" size={26} />} label="Ultrasound" onClick={() => setScan(true)} /> : null}
                                {onFarm && pregnancy && can.breeding ? <ActionTile icon={<LibIcon name="ic-pregnant" size={26} />} label="Pregnancy" primary onClick={() => setOutcome(true)} /> : null}
                                {onFarm && healthEntry?.canCheck ? <ActionTile icon={<LibIcon name="app-veterinary" size={26} />} label="Checkup" primary onClick={() => setEntry('checkup')} /> : null}
                                {onFarm && healthEntry?.canEntry ? <ActionTile icon={<LibIcon name="ic-under-treatment" size={26} />} label="Health Entry" onClick={() => setEntry('')} /> : null}
                                {onFarm && auth?.gates.includes('record_weights') ? <ActionTile icon={<LibIcon name="app-growth" size={26} />} label="Weight" active={panel === 'weight'} onClick={() => setPanel(panel === 'weight' ? null : 'weight')} /> : null}
                                {onFarm && auth?.gates.includes('record_height') ? <ActionTile icon={<LibIcon name="app-growth" size={26} />} label="Height" active={panel === 'height'} onClick={() => setPanel(panel === 'height' ? null : 'height')} /> : null}
                                {onFarm ? <ActionTile icon={<Camera size={20} />} label="Photo" active={panel === 'photo'} onClick={() => setPanel(panel === 'photo' ? null : 'photo')} /> : null}
                                {onFarm && auth?.gates.includes('health_checkup') ? <ActionTile icon={<Thermometer size={20} aria-hidden />} label="Temp check" active={panel === 'temp'} onClick={() => setPanel(panel === 'temp' ? null : 'temp')} /> : null}
                                {onFarm && auth?.gates.includes('move_goats') ? <ActionTile icon={<LibIcon name="ic-transferred" size={26} />} label="Move" active={panel === 'move'} onClick={() => setPanel(panel === 'move' ? null : 'move')} /> : null}
                                {!removed && can.edit ? <ActionTile icon={<LibIcon name="app-settings" size={26} />} label="Edit" onClick={() => router.visit(`/goats/${goat.id}/edit`)} /> : null}
                            </div>
                        ) : null}
                        <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm sm:hidden">
                            {onFarm && auth?.gates.includes('edit_goats') ? <button type="button" className="font-semibold text-cobalt-800" onClick={() => setPanel(panel === 'birth' ? null : 'birth')}>Correct Birth Date</button> : null}
                            {onFarm && auth?.gates.includes('report_death') && death?.report?.status !== 'pending' ? <button type="button" className="font-semibold text-crit-700" onClick={() => setPanel(panel === 'death' ? null : 'death')}>Report Death</button> : null}
                            {onFarm && auth?.gates.includes('exit_animal') ? <button type="button" className="font-semibold text-crit-700" onClick={() => setPanel(panel === 'exit' ? null : 'exit')}>Record Exit</button> : null}
                            {!removed && can.edit && !goat.removal ? <button type="button" className="font-semibold text-ink-soft" onClick={() => setPanel(panel === 'remove' ? null : 'remove')}>Remove (Wrong Entry)</button> : null}
                        </div>
                        {onFarm || (!removed && can.edit) ? (
                            <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-rule pt-3 max-sm:hidden" role="toolbar" aria-label="Actions">
                                {onFarm ? <>
                                    {scanMating ? <Button variant="secondary" onClick={() => setScan(true)}><LibIcon name="app-reports" size={18} /> Ultrasound</Button> : null}
                                    {pregnancy && can.breeding ? <Button onClick={() => setOutcome(true)}><LibIcon name="ic-pregnant" size={18} /> Pregnancy Outcome</Button> : null}
                                    {healthEntry?.canCheck ? <Button onClick={() => setEntry('checkup')}><LibIcon name="app-veterinary" size={18} /> Health checkup</Button> : null}
                                    {healthEntry?.canEntry ? <Button variant="secondary" onClick={() => setEntry('')}><LibIcon name="ic-under-treatment" size={18} /> New health entry</Button> : null}
                                    {auth?.gates.includes('record_weights') ? <Button variant="secondary" onClick={() => setPanel(panel === 'weight' ? null : 'weight')}><LibIcon name="app-growth" size={18} /> Add weight</Button> : null}
                                    {auth?.gates.includes('record_height') ? <Button variant="secondary" onClick={() => setPanel(panel === 'height' ? null : 'height')}><LibIcon name="app-growth" size={18} /> Add height</Button> : null}
                                    {auth?.gates.includes('health_checkup') ? <Button variant="secondary" onClick={() => setPanel(panel === 'temp' ? null : 'temp')}><Thermometer size={16} aria-hidden /> Temp check</Button> : null}
                                    <Button variant="secondary" onClick={() => setPanel(panel === 'photo' ? null : 'photo')}><Camera size={16} aria-hidden /> Add photo</Button>
                                    {auth?.gates.includes('move_goats') ? <Button variant="secondary" onClick={() => setPanel(panel === 'move' ? null : 'move')}><LibIcon name="ic-transferred" size={18} /> Move</Button> : null}
                                </> : null}
                                {onFarm ? <>
                                    {goat.inBreeding && auth?.gates.includes('cull_breeding') ? <Button variant="secondary" onClick={() => setPanel(panel === 'cull' ? null : 'cull')}><LibIcon name="ic-sold" size={18} /> Cull from breeding</Button> : null}
                                    {auth?.gates.includes('edit_goats') ? <Button variant="secondary" onClick={() => setPanel(panel === 'birth' ? null : 'birth')}><LibIcon name="ic-farm-born" size={18} /> Correct birth date</Button> : null}
                                </> : null}
                                {!removed && can.edit ? <Button variant="secondary" onClick={() => router.visit(`/goats/${goat.id}/edit`)}><LibIcon name="app-settings" size={18} /> Edit details</Button> : null}
                                {onFarm ? <>
                                    {auth?.gates.includes('report_death') && death?.report?.status !== 'pending' ? <Button variant="danger" onClick={() => setPanel(panel === 'death' ? null : 'death')}><LibIcon name="ic-died" size={18} /> Report death</Button> : null}
                                    {auth?.gates.includes('exit_animal') ? <Button variant="danger" onClick={() => setPanel(panel === 'exit' ? null : 'exit')}><LibIcon name="ic-sold" size={18} /> Record exit</Button> : null}
                                </> : null}
                                {!removed && can.edit && !goat.removal ? <Button variant="quiet" onClick={() => setPanel(panel === 'remove' ? null : 'remove')}><Trash2 size={16} aria-hidden /> Remove (wrong entry)</Button> : null}
                            </div>
                        ) : null}
                    </div>
                </div>
                {/* D-171: a kid born on the farm was created by the kidding record; the vet only completes its details. */}
                {onFarm && goat.origin === 'born_on_farm' && !goat.weight && panel !== 'weight' ? (
                    <div className="flex flex-wrap items-center gap-3 border-t border-rule bg-turq-100/60 px-4 py-3 text-sm">
                        <LibIcon name="cls-kid" size={34} />
                        <span className="min-w-0 flex-1"><strong className="text-cobalt-900">Newborn: complete its details.</strong> It was added from the kidding record (mother, father, breed, birth date and PEN are already filled). Enter the birth weight; add teeth or a photo when ready.</span>
                        <Button onClick={() => setPanel('weight')}><LibIcon name="app-growth" size={18} /> Enter birth weight</Button>
                    </div>
                ) : null}
                {panel ? <div className="border-t border-rule bg-glaze p-4"><ActionPanel kind={panel} goat={goat} pens={pens} close={() => setPanel(null)} conditions={healthEntry?.conditions ?? []} /></div> : null}
                {death?.report && death.report.status !== 'approved' ? <DeathBanner r={death.report} canDecide={!!auth?.gates.includes('approve_death')} /> : null}
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
                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="min-w-0 space-y-4">
                    <Panel title="Overview" description={gain && goat.lifetimeAdg !== null ? `${gain.kg > 0 ? '▲ +' : gain.kg < 0 ? '▼ ' : ''}${gain.kg} KG in ${gain.days} days · ${goat.lifetimeAdg} g a day on average` : undefined}>
                        {death?.review ? <DeathReview d={death} /> : null}
                        {recovery ? <RecoveryNote r={recovery} /> : null}
                        <GrowthView rows={records} number={goat.number} />
                    </Panel>
                    {records.length ? <Panel flush title="Measurements and care" description={`${records.length} ${records.length === 1 ? 'day' : 'days'}: what was measured, what was found and what was done`}><div className="px-4 pb-1"><RecordsTable rows={records} goatId={goat.id} /></div></Panel> : null}
                    </div>
                    <div className="space-y-4">
                        {pregnancy ? <PregnancyPanel p={pregnancy} /> : null}
                        {milking ? <MilkingPanel m={milking} /> : null}
                        <Panel title="Identity">
                            <dl className="space-y-2">
                                {fact('Permanent ID (tag ID)', <span className="font-mono">{goat.number}</span>)}
                                {fact('Born', `${goat.birthDate ?? 'Unknown'}${goat.estimated ? ' (estimated)' : ''}`)}
                                {goat.ageCode ? fact('Classification code', <span className="font-mono font-semibold text-cobalt-900">{goat.ageCode}</span>) : null}
                                {eligibility && eligibility.status !== 'breeder' ? fact('Breeding eligibility', <span><Link href="/breeding/eligibility" className="font-semibold text-cobalt-800 hover:underline">{eligibility.label}</Link>{eligibility.review ? <span className="mt-0.5 block text-sm font-medium text-watch-700">Maturity review: look at this goat (not an approval)</span> : null}{eligibility.recommendation ? <span className="mt-0.5 block text-sm font-medium text-watch-700">{eligibility.recommendation}</span> : null}{eligibility.failed.length ? <span className="mt-0.5 block text-sm font-normal text-ink-soft">Not yet: {eligibility.failed.join('; ')}</span> : null}</span>) : null}
                                {castration ? fact('Castration', <Castration goatId={goat.id} c={castration} onFarm={onFarm} />) : null}
                                {goat.dental ? fact('Teeth', <DentalFact dental={goat.dental} teeth={goat.teeth} goatId={goat.id} canEdit={can.edit || !!auth?.gates.includes('health_checkup') || !!auth?.gates.includes('check_teeth')} options={dentalOptions} />) : null}
                                {goat.height !== null ? fact('Height', `${goat.height} in`) : null}
                                {fact('Origin', <span>{goat.origin === 'purchased' ? 'Purchased' : goat.origin === 'transferred_in' ? 'Transferred in' : 'Born on the farm'}
                                    {goat.origin === 'born_on_farm' ? (goat.birthDate ? <span className="block text-sm font-normal text-ink-soft">on {goat.birthDate}</span> : null)
                                        : goat.arrivedOn ? <span className="block text-sm font-normal text-ink-soft">on {goat.arrivedOn}{goat.arrivedEntered ? '' : ' (date registered)'}{goat.sourceFarm ? ` · from ${goat.sourceFarm}` : ''}</span> : null}</span>)}
                                {fact('Sire', parentLink(goat.sire))}
                                {fact('Dam', parentLink(goat.dam))}
                                {fact('Feeding group', goat.feedingGroup)}
                            </dl>
                            <p className="mt-2 text-sm text-ink-soft">The tag ID is the goat's permanent ID: it never changes and is never reused, even when the RFID tag does. The classification code gives its age.</p>
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

function ActionPanel({ kind, goat, pens, close, conditions = [] }: { kind: string; goat: Goat; pens: Props['pens']; close: () => void; conditions?: string[] }) {
    const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const weight = useForm({ animal_id: goat.id, weight_kg: '', measured_on: today }); // D-252: the day it was weighed
    const concern = useForm({ animal_id: goat.id, title: '', severity: 'low', notes: '' });
    // D-253: a quick temperature reading with its date, saved as a checkup (the vet can change the result)
    const temp = useForm({ animal_id: goat.id, temperature_f: '', checked_on: today, outcome: 'healthy' });
    const move = useForm({ animal_id: goat.id, pen_id: String(pens[0]?.id ?? ''), capacity_override: '', reason: '' });
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
                <div className="w-44"><Field label="Weighed on" error={weight.errors.measured_on}><input type="date" className="field" max={today} value={weight.data.measured_on} onChange={(e) => weight.setData('measured_on', e.target.value)} /></Field></div>
                <Button type="submit" disabled={weight.processing}>Save weight</Button>
            </form>
        );
    }
    if (kind === 'height') return <HeightForm goatId={goat.id} onDone={close} />;
    if (kind === 'cull') return <CullForm goatId={goat.id} onDone={close} />;
    if (kind === 'death') return <DeathForm goatId={goat.id} conditions={conditions} onDone={close} />;
    if (kind === 'temp') {
        const t = Number(temp.data.temperature_f);
        const normal = temp.data.temperature_f !== '' && t >= 101.3 && t <= 103.5;
        const reading = (v: string) => { const n = Number(v); temp.setData({ ...temp.data, temperature_f: v, outcome: v !== '' && (n < 101.3 || n > 103.5) ? 'monitor' : 'healthy' }); };
        return (
            <form onSubmit={(e) => { e.preventDefault(); temp.post('/health/checkups', { ...options, onSuccess: () => temp.reset('temperature_f') }); }} className="flex flex-wrap items-end gap-2">
                <div className="w-40"><Field label="Temperature (°F)" error={temp.errors.temperature_f} hint="Normal 101.3–103.5"><input inputMode="decimal" autoFocus required className="field" value={temp.data.temperature_f} onChange={(e) => reading(e.target.value.replace(/[^0-9.]/g, ''))} /></Field></div>
                <div className="w-44"><Field label="Checked on" error={temp.errors.checked_on}><input type="date" className="field" max={today} value={temp.data.checked_on} onChange={(e) => temp.setData('checked_on', e.target.value)} /></Field></div>
                <div className="w-36"><Field label="Result" error={temp.errors.outcome}><select className="field" value={temp.data.outcome} onChange={(e) => temp.setData('outcome', e.target.value)}><option value="healthy">Healthy</option><option value="monitor">Watch</option><option value="sick">Sick</option></select></Field></div>
                <Button type="submit" disabled={temp.processing || temp.data.temperature_f === ''}>Save temperature</Button>
                {temp.data.temperature_f !== '' ? <span className={`pb-2.5 text-sm font-semibold ${normal ? 'text-ok-700' : 'text-crit-700'}`}>{normal ? 'Normal' : t > 103.5 ? 'High: the goat goes on Watch' : 'Low: the goat goes on Watch'}</span> : null}
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
                <div className="w-64"><Field label="Move to" error={(move.errors as Record<string, string>).to_pen_id}><select className="field" value={move.data.pen_id} onChange={(e) => move.setData('pen_id', e.target.value)}>{pens.map((p) => <option key={p.id} value={p.id}>{p.name}: {p.purpose}</option>)}</select></Field></div>
                <div className="w-64"><Field label="Reason" error={move.errors.reason}><input className="field" list="move-reasons" required value={move.data.reason} onChange={(e) => move.setData('reason', e.target.value)} placeholder="Why is it moved?" /></Field>
                    <datalist id="move-reasons">{['Weaning', 'Breeding group', 'Pregnant does group', 'Kidding / maternity', 'Sick: isolation', 'Back from isolation', 'Feeding group change', 'Fattening batch', 'Sale preparation', 'PEN cleaning or repair', 'Overcrowding'].map((r) => <option key={r} value={r} />)}</datalist></div>
                {(move.errors as Record<string, string>).to_pen_id?.includes(' is full') || move.data.capacity_override ? <div className="w-64"><Field label="Over-capacity reason (owner or manager)"><input className="field" value={move.data.capacity_override} onChange={(e) => move.setData('capacity_override', e.target.value)} /></Field></div> : null}
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
            <div className="w-52"><Field label="Reason" error={exit.errors.reason}><select className="field" value={exit.data.reason} onChange={(e) => exit.setData('reason', e.target.value)}><option value="sold">Sold</option><option value="sacrificed">Qurbani / sacrifice</option><option value="gifted">Gifted</option><option value="transferred">Transferred out</option><option value="other">Other</option></select></Field></div>
            {exit.data.reason === 'sold' ? <div className="w-40"><Field label="Price (PKR)" error={exit.errors.amount}><input inputMode="decimal" className="field" value={exit.data.amount} onChange={(e) => exit.setData('amount', e.target.value)} /></Field></div> : null}
            {exit.data.reason !== 'died' ? <div className="w-48"><Field label={exit.data.reason === 'sold' ? 'Buyer' : 'Destination'}><input className="field" value={exit.data.destination} onChange={(e) => exit.setData('destination', e.target.value)} /></Field></div> : null}
            <Button type="submit" variant="danger" disabled={exit.processing}>Confirm exit</Button>
            <p className="w-full text-sm text-ink-soft">The RFID tag is released. Nothing about this goat is deleted. A death is reported by the vet and approved by the administrator; culling is done from the breeding lists.</p>
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
                    <span>{p.daysLeft < 0 ? `Kidding overdue by ${-p.daysLeft} day${p.daysLeft === -1 ? '' : 's'}: check her now.` : p.daysLeft === 0 ? 'Kidding expected today.' : `Kidding in ${p.daysLeft} day${p.daysLeft === 1 ? '' : 's'}.`} Kidding PEN, clean bedding, colostrum ready; watch her closely.</span>
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
        <button type="button" onClick={onClick} className={`flex min-h-16 flex-col items-center justify-center gap-1 rounded-tile border px-1 py-2 text-xs font-semibold leading-tight active:scale-[0.97] ${primary ? 'border-cobalt-800 bg-cobalt-800 text-white' : active ? 'border-cobalt-700 bg-cobalt-100 text-cobalt-900' : 'border-rule bg-tile text-cobalt-900'}`}>
            <span aria-hidden>{icon}</span>{label}
        </button>
    );
}

const EXIT_WORD: Record<string, string> = { sold: 'sold', died: 'died', sacrificed: 'Qurbani', culled: 'culled', gifted: 'gifted', transferred: 'transferred out' };

/** D-229: the latest teeth examination (dental code and condition), its history, and recording a new one. */
function DentalFact({ dental, teeth, goatId, canEdit, options }: { dental: Dental; teeth: string | null; goatId: string; canEdit: boolean; options: { codes: Record<string, string>; conditions: Record<string, string> } }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ teeth: dental.code === 'UNK' ? '' : dental.code, dental_condition: 'normal', examined_on: new Date().toISOString().slice(0, 10) });
    return (
        <span className="block">
            {dental.code === 'UNK' ? <span className="text-ink-soft">Not known (no age, no check)</span> : dental.fromAge ? <span>{dental.code} <span className="text-sm font-normal text-ink-soft">(from age, not checked)</span></span> : <span>{teeth ?? dental.code}{dental.condition && dental.condition !== 'Normal' ? <Chip className="ml-1.5 bg-watch-100 text-watch-700">{dental.condition}</Chip> : null}</span>}
            {dental.checkedOn ? <span className="block text-sm font-normal text-ink-soft">Checked {dental.checkedOn}{dental.history.length > 1 ? ` · ${dental.history.length} checks on record` : ''}</span> : null}
            {canEdit && !open ? <button type="button" className="mt-1 block text-sm font-semibold text-cobalt-800 underline-offset-2 hover:underline" onClick={() => setOpen(true)}>Record a teeth check</button> : null}
            {open ? (
                <form className="mt-2 grid gap-2 rounded-tile bg-glaze p-2" onSubmit={(e) => { e.preventDefault(); form.post(`/goats/${goatId}/teeth`, { preserveScroll: true, onSuccess: () => setOpen(false) }); }}>
                    <Field label="Teeth (dental code)" error={form.errors.teeth}><select className="field" value={form.data.teeth} onChange={(e) => form.setData('teeth', e.target.value)}><option value="">Choose</option>{Object.entries(options.codes).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Field label="Condition" error={form.errors.dental_condition}><select className="field" value={form.data.dental_condition} onChange={(e) => form.setData('dental_condition', e.target.value)}>{Object.entries(options.conditions).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Field label="Checked on" error={form.errors.examined_on}><input className="field" type="date" max={new Date().toISOString().slice(0, 10)} value={form.data.examined_on} onChange={(e) => form.setData('examined_on', e.target.value)} /></Field>
                    <span className="flex gap-2"><Button type="submit" disabled={form.processing || !form.data.teeth}>Save check</Button><Button type="button" variant="secondary" onClick={() => setOpen(false)}>Cancel</Button></span>
                </form>
            ) : null}
            {dental.history.length > 1 ? <details className="mt-1 text-xs text-ink-soft"><summary className="cursor-pointer">Earlier checks</summary><ul className="mt-1 space-y-0.5">{dental.history.slice(1).map((h, i) => <li key={i}>{h.on}: {h.code}{h.condition ? ` · ${h.condition}` : ''}{h.by ? ` · ${h.by}` : ''}</li>)}</ul></details> : null}
        </span>
    );
}

/** D-230: a recorded castration makes the goat a wether (WTH) and blocks it as a sire; only the vet records it. */
function Castration({ goatId, c, onFarm }: { goatId: string; c: { on: string | null; methods: Record<string, string>; can: boolean }; onFarm: boolean }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ castrated_on: new Date().toISOString().slice(0, 10), method: Object.keys(c.methods)[0] ?? '', notes: '' });
    if (c.on) return <span>Castrated {c.on} · wether, cannot be a sire</span>;
    return (
        <span className="block">
            <span className="text-ink-soft">Intact</span>
            {c.can && onFarm && !open ? <button type="button" className="ml-2 text-xs font-semibold text-cobalt-800 hover:underline" onClick={() => setOpen(true)}>Record castration</button> : null}
            {open ? (
                <form className="mt-2 grid gap-2 rounded-tile bg-glaze p-2" onSubmit={(e) => { e.preventDefault(); form.post(`/goats/${goatId}/castration`, { preserveScroll: true, onSuccess: () => setOpen(false) }); }}>
                    <Field label="Done on" error={form.errors.castrated_on}><input className="field" type="date" max={new Date().toISOString().slice(0, 10)} value={form.data.castrated_on} onChange={(e) => form.setData('castrated_on', e.target.value)} /></Field>
                    <Field label="Method" error={form.errors.method}><select className="field" value={form.data.method} onChange={(e) => form.setData('method', e.target.value)}>{Object.entries(c.methods).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Field label="Notes" error={form.errors.notes}><input className="field" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field>
                    <Note>The goat becomes a wether and can no longer be chosen as a sire. A breeding purpose is cleared. A recovery check is due in 7 days.</Note>
                    <span className="flex gap-2"><Button type="submit" disabled={form.processing}>Save castration</Button><Button type="button" variant="secondary" onClick={() => setOpen(false)}>Cancel</Button></span>
                </form>
            ) : null}
        </span>
    );
}

/** D-261: a status shows only when it is a "yes" (Pregnant, Lactating, In fattening, Under treatment, Sale ready …), not "Not ready", "Dry", "Not for sale". */
const NO_STATE: Record<string, string[]> = { breeding: ['not_ready', 'not_breeding', 'blocked', 'open'], lactation: ['dry'], fattening: ['none'], health: ['healthy'], sale: ['not_for_sale'] };

const DIM_LABEL: Record<string, string> = { breeding: 'Breeding', lactation: 'Milk', fattening: 'Fattening', health: 'Health', sale: 'Sale', age: 'Age stage', teeth: 'Teeth', role: 'Role' };

/** D-232: the goat's independent statuses (several can be true at once) and their recent changes with the rule. */
function StatusStrip({ statuses, history }: { statuses: Record<string, { code: string; label: string; note?: string | null; blockers?: string[] }>; history: Array<{ dimension: string; from: string; to: string; rule: string; at: string }> }) {
    const tone = (d: string, c: string) => (['blocked', 'vet_hold', 'treatment', 'critical'].includes(c) ? 'bg-crit-100 text-crit-700' : ['market_weight', 'pregnant', 'sale_ready', 'ready'].includes(c) ? 'bg-ok-100 text-ok-700' : d === 'sale' && c === 'not_for_sale' ? 'bg-glaze text-ink-soft' : 'bg-glaze text-cobalt-800');
    return (
        <div className="mt-2">
            <ul className="flex flex-wrap gap-1.5" aria-label="Statuses">{Object.entries(statuses).filter(([d, s]) => !(NO_STATE[d] ?? []).includes(s.code)).map(([d, s]) => (
                <li key={d} className={`rounded-md px-2.5 py-1 text-sm ring-1 ring-rule ${tone(d, s.code)}`} title={[s.note, ...(s.blockers ?? [])].filter(Boolean).join(' · ') || undefined}>
                    <span className="font-semibold">{DIM_LABEL[d] ?? d}:</span> {s.label}{s.note ? <span className="block text-xs opacity-80">{s.note}</span> : null}{s.blockers?.length ? <span className="block text-xs">{s.blockers.join('; ')}</span> : null}
                </li>
            ))}</ul>
            {history.length ? <details className="mt-1.5 text-sm text-ink-soft"><summary className="cursor-pointer">Status changes ({history.length})</summary><ul className="mt-1 space-y-0.5">{history.map((h, i) => <li key={i}>{h.at} · {DIM_LABEL[h.dimension] ?? h.dimension}: {h.from} → <span className="font-semibold">{h.to}</span> <span className="font-mono">({h.rule})</span></li>)}</ul></details> : null}
        </div>
    );
}

/** D-249: one goat's measurements and care, one row per day, newest first. */
export type GoatRecord = {
    on: string; weight: number | null; change: number | null; adg: number | null; bcs: number | null; height: number | null;
    pct: number | null; days: number | null; // D-254: change in % and the days since the weighing before
    temp: number | null; tempFlag: boolean; heart: number | null; breaths: number | null; teeth: string | null; ultrasound: string | null;
    care: string[]; treatments: string[]; actions: string[]; result: string | null; by: string[]; // D-262: care = what was found, actions = what was done
};
/** D-266: what was done that day, as short chips (the full detail on hover). */
type Done = { label: string; tone: string; detail?: string };
const DONE_TONE = { measure: 'bg-cobalt-100 text-cobalt-800', vet: 'bg-turq-100 text-turq-800', care: 'bg-ok-100 text-ok-700', move: 'bg-glaze text-ink ring-1 ring-rule', off: 'bg-ok-100 text-ok-700' };
function performed(r: GoatRecord): Done[] {
    const out: Done[] = [];
    if (r.weight !== null) out.push({ label: 'Weighed', tone: DONE_TONE.measure });
    if (r.height !== null) out.push({ label: 'Height', tone: DONE_TONE.measure });
    if (r.result !== null) out.push({ label: 'Checkup', tone: DONE_TONE.vet });
    else if (r.temp !== null) out.push({ label: 'Temp check', tone: DONE_TONE.vet });
    if (r.teeth) out.push({ label: 'Teeth check', tone: DONE_TONE.vet, detail: r.teeth });
    if (r.ultrasound) out.push({ label: 'Ultrasound', tone: DONE_TONE.vet, detail: r.ultrasound });
    for (const a of r.actions ?? []) {
        const [label, tone] = a.startsWith('Treated') ? ['Treatment', DONE_TONE.care] : /^Vaccination/i.test(a) ? ['Vaccinated', DONE_TONE.care] : /^Deworming/i.test(a) ? ['Dewormed', DONE_TONE.care]
            : a.startsWith('Moved') ? ['Moved', DONE_TONE.move] : a.startsWith('Taken off Watch') ? ['Off Watch', DONE_TONE.off] : [a.split(':')[0], DONE_TONE.care];
        out.push({ label, tone, detail: a });
    }
    return out;
}
const dash = <span className="text-ink-faint">—</span>;

/** D-249/D-266: one row per day: what was done, the measurements, what was found and the checkup's result. */
function RecordsTable({ rows, goatId }: { rows: GoatRecord[]; goatId: string }) {
    const { rows: shown, pager } = usePaged(rows, { size: 10, id: 'goat-records' }); // D-263: numbered pages
    const [day, setDay] = useState<string | null>(null); // D-270: a day opened in full
    if (!rows.length) return null;
    const date = (on: string) => new Date(`${on}T12:00:00`).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' });
    const resultTone = (x: string) => (x === 'Sick' ? 'bg-crit-100 text-crit-700' : x === 'Watch' ? 'bg-watch-100 text-watch-700' : 'bg-ok-100 text-ok-700');
    const th = 'px-2.5 py-2.5 text-xs font-medium whitespace-nowrap text-ink-soft';
    const td = 'px-2.5 py-3 align-middle';
    return (
        <div>
            <div className="-mx-4 overflow-x-auto sm:mx-0">
                <table className="w-full min-w-[680px] border-collapse text-sm">
                    <thead className="border-b border-rule bg-glaze/60">
                        <tr><th className={`${th} text-start`}>Date</th><th className={`${th} text-start`}>Performed</th><th className={`${th} text-end`}>Weight</th><th className={`${th} text-end`}>Change</th>
                            <th className={`${th} text-end`}>Height</th><th className={`${th} text-end`}>Temp °F</th><th className={`${th} text-start`}>Found</th><th className={`${th} text-start`}>Result</th></tr>
                    </thead>
                    <tbody className="divide-y divide-rule">{shown.map((r) => (
                        <tr key={r.on} className="cursor-pointer hover:bg-glaze/40" onClick={() => setDay(r.on)} title={`Open everything recorded on ${date(r.on)}`}>
                            <td className={`${td} whitespace-nowrap text-cobalt-900`} title={r.by.length ? `Recorded by ${r.by.join(', ')}` : undefined}>{date(r.on)}</td>
                            <td className={td}><span className="flex flex-wrap gap-1">{performed(r).map((d, i) => <span key={i} title={d.detail} className={`rounded px-1.5 py-0.5 text-xs font-medium ${d.tone}`}>{d.label}</span>)}</span></td>
                            <td className={`${td} text-end tabular-nums whitespace-nowrap`} title={r.weight !== null && r.bcs !== null ? `Body score ${r.bcs}` : undefined}>{r.weight !== null ? `${r.weight} KG` : dash}</td>
                            <td className={`${td} text-end whitespace-nowrap`} title={r.change !== null && r.weight !== null ? `${r.change > 0 ? 'Gained' : r.change < 0 ? 'Lost' : 'No change:'} ${Math.abs(r.change)} KG since ${Math.round((r.weight - r.change) * 10) / 10} KG, ${r.days} ${r.days === 1 ? 'day' : 'days'} before (${r.adg ?? 0} g a day)` : undefined}>{r.change !== null ? <Ticker kg={r.change} pct={r.pct} days={r.days} perDay={r.adg ?? undefined} small /> : dash}</td>
                            <td className={`${td} text-end tabular-nums whitespace-nowrap`} title={r.height !== null ? `${Math.round(r.height * 2.54)} cm at the withers` : undefined}>{r.height !== null ? `${r.height} in` : dash}</td>
                            <td className={`${td} text-end tabular-nums`} title={r.temp !== null ? `Normal 101.3–103.5 °F${r.heart !== null ? ` · heart ${r.heart}/min` : ''}${r.breaths !== null ? ` · breaths ${r.breaths}/min` : ''}` : undefined}>{r.temp !== null ? <span className={r.tempFlag ? 'rounded bg-crit-100 px-1.5 py-0.5 text-crit-700' : ''} title={r.tempFlag ? 'Outside 101.3–103.5 °F' : undefined}>{r.temp}</span> : dash}</td>
                            <td className={`${td} min-w-[9rem] text-ink`}>{r.care.length ? r.care.join('; ') : dash}</td>
                            <td className={td}>{r.result ? <span className={`rounded px-1.5 py-0.5 text-xs font-medium ${resultTone(r.result)}`}>{r.result}</span> : dash}</td>
                        </tr>
                    ))}</tbody>
                </table>
            </div>
            <div className="-mx-4 sm:mx-0">{pager}</div>
            {day ? <Sheet onClose={() => setDay(null)}><DayDetail goatId={goatId} on={day} /></Sheet> : null}
        </div>
    );
}

/** D-251: how this goat is doing since it went on Watch, or when it came off. */
function RecoveryNote({ r }: { r: NonNullable<Props['recovery']> }) {
    if (r.state === 'recovered') {
        return <p className="mb-3 rounded-tile border-l-4 border-ok-700 bg-ok-100 px-3 py-2 text-sm text-ok-700"><b>Off Watch on {r.on}:</b> {r.why}.</p>;
    }
    const tone = r.state === 'improving' ? 'border-turq-600 bg-turq-100/60 text-turq-800' : r.state === 'not_improving' ? 'border-crit-500 bg-crit-100 text-crit-700' : 'border-watch-400 bg-watch-100 text-watch-700';
    const head = r.state === 'improving' ? 'Improving' : r.state === 'not_improving' ? 'Not improving' : 'Waiting for a recheck';
    const weight = r.then !== null && r.now !== null ? ` Weight ${r.then} KG at the watch → ${r.now} KG on ${r.nowOn}.` : '';
    const goal = r.goal !== null ? ` Comes off Watch at ${r.goal} KG${r.now !== null && r.now < r.goal ? ` (${Math.round((r.goal - r.now) * 10) / 10} KG to go)` : ''}.` : '';
    const temp = r.temp !== null ? ` Last temperature ${r.temp} °F.` : '';
    return (
        <p className={`mb-3 rounded-tile border-l-4 px-3 py-2 text-sm ${tone}`}>
            <b>On Watch since {r.since}: {head}.</b> {r.title}.{weight}{temp}{goal}{r.state === 'no_recheck' ? ' Weigh it or check its temperature to see if it is getting better.' : ''}
        </p>
    );
}

/** D-254: a weight change shown like a share price: ▲ green up, ▼ red down, KG and %, over how many days. */
function Ticker({ kg, pct, days, perDay, small, unit = 'KG' }: { kg: number; pct?: number | null; days?: number | null; perDay?: number; small?: boolean; unit?: string }) {
    const up = kg > 0, down = kg < 0;
    const tone = up ? 'text-ok-700' : down ? 'text-crit-700' : 'text-ink-soft';
    return (
        <span className={`inline-flex flex-col ${small ? 'items-end' : ''}`}>
            <span className={`inline-flex items-center gap-1 tabular-nums ${tone}`}>
                <span aria-hidden className={small ? 'text-xs' : 'text-xs'}>{up ? '▲' : down ? '▼' : '■'}</span>
                {up ? '+' : ''}{kg} {unit}{pct !== null && pct !== undefined ? <span className={`rounded px-1 ${small ? 'text-xs' : 'text-xs'} ${up ? 'bg-ok-100' : down ? 'bg-crit-100' : 'bg-glaze'}`}>{pct > 0 ? '+' : ''}{pct}%</span> : null}
            </span>
            {days ? <span className={`font-normal text-ink-soft ${small ? 'text-xs' : 'text-xs'}`}>in {days} {days === 1 ? 'day' : 'days'}{perDay !== undefined ? ` · ${perDay > 0 ? '+' : ''}${perDay} g/day` : ''}</span> : null}
            <span className="sr-only">{up ? 'gained' : down ? 'lost' : 'no change'}</span>
        </span>
    );
}

/** D-260: the death report and, once approved, the review of the goat's last 60 days. */
type DeathData = {
    report: { id: number; status: 'pending' | 'approved' | 'rejected'; diedAt: string; cause: string; circumstances: string | null; postmortem: string | null; reportedBy: string; decidedBy: string | null; decidedAt: string | null; note: string | null } | null;
    review: {
        from: string;
        cases: Array<{ label: string; condition: string; onset: string; status: string; severity: string; vet: string | null; diagnosis: string | null }>;
        treatments: Array<{ on: string; product: string; dose: string | null; route: string | null; by: string }>;
        checkups: Array<{ on: string; temp: number | null; result: string; by: string; findings: string | null }>;
        care: Array<{ on: string; what: string; by: string }>;
        weights: Array<{ on: string; kg: number }>;
    } | null;
};

function DeathForm({ goatId, conditions, onDone }: { goatId: string; conditions: string[]; onDone: () => void }) {
    const now = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    const f = useForm({ died_at: now, cause: '', circumstances: '', postmortem: '' });
    return (
        <form onSubmit={(e) => { e.preventDefault(); f.post(`/goats/${goatId}/death`, { preserveScroll: true, onSuccess: onDone }); }} className="grid gap-3 sm:grid-cols-2">
            <Field label="Time of death" error={f.errors.died_at}><input type="datetime-local" className="field" max={now} value={f.data.died_at} onChange={(e) => f.setData('died_at', e.target.value)} required /></Field>
            <Field label="Cause" error={f.errors.cause} hint="From the Disease Master, or type it"><input className="field" list="death-causes" value={f.data.cause} onChange={(e) => f.setData('cause', e.target.value)} required /><datalist id="death-causes">{conditions.map((c) => <option key={c} value={c} />)}</datalist></Field>
            <div className="sm:col-span-2"><Field label="What was seen" error={f.errors.circumstances} hint="Signs, when and where it was found, what was done"><textarea className="field min-h-20" value={f.data.circumstances} onChange={(e) => f.setData('circumstances', e.target.value)} required /></Field></div>
            <div className="sm:col-span-2"><Field label="Postmortem findings (if done)" error={f.errors.postmortem}><textarea className="field min-h-16" value={f.data.postmortem} onChange={(e) => f.setData('postmortem', e.target.value)} /></Field></div>
            <div className="flex flex-wrap items-center gap-2 sm:col-span-2"><Button type="submit" variant="danger" disabled={f.processing}>Send to the administrator</Button><span className="text-sm text-ink-soft">The goat stays on the farm until the administrator approves.</span></div>
        </form>
    );
}

function DeathBanner({ r, canDecide }: { r: NonNullable<DeathData['report']>; canDecide: boolean }) {
    const f = useForm({ decision: 'approved', note: '' });
    const [returning, setReturning] = useState(false);
    const send = (decision: string) => { f.transform((d) => ({ ...d, decision })); f.post(`/death-reports/${r.id}`, { preserveScroll: true }); };
    return (
        <div className={`border-t px-4 py-3 text-sm ${r.status === 'pending' ? 'border-crit-500/30 bg-crit-100' : 'border-watch-400/40 bg-watch-100'}`} role="status">
            <p className="font-semibold text-crit-700">{r.status === 'pending' ? `Death reported by ${r.reportedBy}, waiting for approval` : `Death report returned by ${r.decidedBy}`}</p>
            <p className="mt-0.5 text-ink">Died {r.diedAt} · <b>{r.cause}</b>{r.circumstances ? ` · ${r.circumstances}` : ''}</p>
            {r.status === 'rejected' && r.note ? <p className="mt-0.5 text-watch-700">Note: {r.note}. Report it again with the correction.</p> : null}
            {r.status === 'pending' && canDecide ? (
                <div className="mt-2 flex flex-wrap items-end gap-2">
                    <Button variant="danger" disabled={f.processing} onClick={() => send('approved')}>Approve: record the death</Button>
                    {returning ? <>
                        <div className="w-72"><Field label="Why it goes back to the vet" error={f.errors.note}><input className="field" autoFocus value={f.data.note} onChange={(e) => f.setData('note', e.target.value)} /></Field></div>
                        <Button variant="secondary" disabled={f.processing || !f.data.note} onClick={() => send('rejected')}>Send back</Button>
                    </> : <Button variant="secondary" onClick={() => setReturning(true)}>Send back to the vet</Button>}
                </div>
            ) : null}
        </div>
    );
}

function DeathReview({ d }: { d: DeathData }) {
    const r = d.report;
    const v = d.review!;
    const first = v.weights[0]?.kg;
    const last = v.weights[v.weights.length - 1]?.kg;
    const list = (title: string, rows: Array<{ k: string; on: string; text: ReactNode; by?: string | null }>) => rows.length ? (
        <div><p className="mb-1 text-xs font-semibold tracking-wide text-ink-soft uppercase">{title}</p>
            <ul className="space-y-1">{rows.map((x) => <li key={x.k} className="flex gap-3 text-sm"><span className="w-14 shrink-0 font-mono text-xs text-ink-soft">{x.on}</span><span className="flex-1">{x.text}</span>{x.by ? <span className="text-xs text-ink-faint">{x.by}</span> : null}</li>)}</ul></div>
    ) : null;
    return (
        <div className="mb-4 rounded-tile border border-crit-500/30 bg-crit-100/50 p-4">
            <p className="text-base font-bold text-crit-700">Death review</p>
            {r ? <p className="mt-1 text-sm">Died <b>{r.diedAt}</b> · cause <b>{r.cause}</b> · reported by {r.reportedBy}{r.decidedBy ? `, approved by ${r.decidedBy} on ${r.decidedAt}` : ''}</p> : null}
            {r?.circumstances ? <p className="mt-1 text-sm text-ink"><span className="font-semibold">Seen:</span> {r.circumstances}</p> : null}
            {r?.postmortem ? <p className="mt-1 text-sm text-ink"><span className="font-semibold">Postmortem:</span> {r.postmortem}</p> : null}
            <p className="mt-2 text-xs text-ink-soft">The 60 days before the death (from {v.from}){first !== undefined && last !== undefined && v.weights.length > 1 ? ` · weight ${first} → ${last} KG` : ''}</p>
            <div className="mt-2 grid gap-3 md:grid-cols-2">
                {list('Health cases', v.cases.map((c, i) => ({ k: `c${i}`, on: c.onset, text: <><b>{c.condition}</b> · {c.status}{c.diagnosis ? ` · ${c.diagnosis}` : ''}</>, by: c.vet })))}
                {list('Medicines and treatments', v.treatments.map((t, i) => ({ k: `t${i}`, on: t.on, text: <><b>{t.product}</b>{t.dose ? ` ${t.dose}` : ''}{t.route ? ` · ${t.route}` : ''}</>, by: t.by })))}
                {list('Checkups', v.checkups.map((c, i) => ({ k: `k${i}`, on: c.on, text: <>{c.temp !== null ? <b>{c.temp} °F</b> : null}{c.temp !== null ? ' · ' : ''}{c.result}{c.findings ? ` · ${c.findings}` : ''}</>, by: c.by })))}
                {list('Care and observations', v.care.map((c, i) => ({ k: `h${i}`, on: c.on, text: c.what, by: c.by })))}
            </div>
            {!v.cases.length && !v.treatments.length && !v.checkups.length && !v.care.length ? <p className="mt-2 text-sm text-ink-soft">No health records in the 60 days before the death.</p> : null}
        </div>
    );
}

/** D-260: out of breeding (a Cull goat, for sale); it stays on the farm and on record. */
function CullForm({ goatId, onDone }: { goatId: string; onDone: () => void }) {
    const f = useForm({ reason: '' });
    return (
        <form onSubmit={(e) => { e.preventDefault(); f.post(`/goats/${goatId}/cull`, { preserveScroll: true, onSuccess: onDone }); }} className="flex flex-wrap items-end gap-3">
            <div className="w-80"><Field label="Why it leaves breeding" error={f.errors.reason}><input className="field" placeholder="e.g. repeat breeder, poor kids, udder problem" autoFocus value={f.data.reason} onChange={(e) => f.setData('reason', e.target.value)} required /></Field></div>
            <Button type="submit" variant="danger" disabled={f.processing}>Cull from breeding</Button>
            <p className="w-full text-sm text-ink-soft">It becomes a Cull goat (for sale) and leaves the breeding lists. It stays on the farm and on record until it is sold.</p>
        </form>
    );
}

/** D-262: a height with its date (the vet's or the administrator's). */
function HeightForm({ goatId, onDone }: { goatId: string; onDone: () => void }) {
    const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const f = useForm({ height_in: '', measured_on: today });
    return (
        <form onSubmit={(e) => { e.preventDefault(); f.post(`/goats/${goatId}/height`, { preserveScroll: true, onSuccess: onDone }); }} className="flex flex-wrap items-end gap-2">
            <div className="w-44"><Field label="Height in inches (withers)" error={f.errors.height_in}><input inputMode="decimal" autoFocus className="field" value={f.data.height_in} onChange={(e) => f.setData('height_in', e.target.value.replace(/[^0-9.]/g, ''))} required /></Field></div>
            <div className="w-44"><Field label="Measured on" error={f.errors.measured_on}><input type="date" className="field" max={today} value={f.data.measured_on} onChange={(e) => f.setData('measured_on', e.target.value)} required /></Field></div>
            <Button type="submit" disabled={f.processing}>Save height</Button>
        </form>
    );
}

/**
 * D-262: weight and height like a share price: one bar per measurement, green when it went up since the one before,
 * red when it went down, blue for the first and for no change. Switch between Weight and Height, and Chart and Table.
 */
const UP = '#2f8f4e', DOWN = '#c8453b', FLAT = '#3b6fb6';
function GrowthView({ rows, number }: { rows: GoatRecord[]; number: string }) {
    const hasWeight = rows.some((r) => r.weight !== null);
    const [metric, setMetric] = useState<'weight' | 'height'>(hasWeight ? 'weight' : 'height');
    const [view, setView] = useState<'chart' | 'table'>('chart');
    const unit = metric === 'weight' ? 'KG' : 'in';
    const pts = useMemo(() => {
        const list = [...rows].filter((r) => r[metric] !== null).sort((a, b) => a.on.localeCompare(b.on));
        return list.map((r, i) => {
            const v = r[metric] as number;
            const prev = i ? (list[i - 1][metric] as number) : null;
            const ch = prev === null ? null : Math.round((v - prev) * 10) / 10;
            const days = i ? Math.max(1, Math.round((new Date(`${r.on}T12:00:00`).getTime() - new Date(`${list[i - 1].on}T12:00:00`).getTime()) / 86400000)) : null;
            return { on: r.on, v, ch, pct: prev ? Math.round(((v - prev) / prev) * 1000) / 10 : null, days, color: ch === null || ch === 0 ? FLAT : ch > 0 ? UP : DOWN };
        });
    }, [rows, metric]);
    const label = (on: string, year = false) => new Date(`${on}T12:00:00`).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', ...(year ? { year: 'numeric' } : {}) });
    const option = useMemo(() => ({
        tooltip: { trigger: 'item', formatter: (x: { dataIndex: number }) => { const p = pts[x.dataIndex]; return `<b>${label(p.on, true)}</b><br/>${p.v} ${unit}${p.ch !== null ? `<br/><span style="color:${p.color}">${p.ch > 0 ? '▲ +' : p.ch < 0 ? '▼ ' : '■ '}${p.ch} ${unit} (${p.pct! > 0 ? '+' : ''}${p.pct}%) in ${p.days} ${p.days === 1 ? 'day' : 'days'}</span>` : ''}`; } },
        grid: { left: 8, right: 12, top: 28, bottom: 6, containLabel: true },
        xAxis: { type: 'category', data: pts.map((p) => label(p.on)), axisLabel: { color: '#5e6a5f' }, axisTick: { show: false }, axisLine: { lineStyle: { color: '#d9ddd5' } } },
        yAxis: { ...axis.value((v) => `${v} ${unit}`), min: 0 },
        series: [{ type: 'bar', barMaxWidth: 34, data: pts.map((p) => ({ value: p.v, itemStyle: { color: p.color, borderRadius: [4, 4, 0, 0] } })),
            label: { show: pts.length <= 16, position: 'top', color: '#1f2a22', fontWeight: 600, formatter: (x: { dataIndex: number }) => { const p = pts[x.dataIndex]; return p.ch === null ? `${p.v}` : `${p.v} ${p.ch > 0 ? '▲' : p.ch < 0 ? '▼' : '■'}`; } } }],
    }), [pts, unit]);
    const seg = (on: boolean) => cx('min-h-9 px-3 text-sm font-semibold', on ? 'bg-cobalt-800 text-white' : 'bg-tile text-cobalt-800 hover:bg-glaze');
    const last = pts[pts.length - 1];
    const first = pts[0];
    return (
        <div>
            <div className="mb-2 flex flex-wrap items-center gap-2">
                <div className="inline-flex overflow-hidden rounded-tile border border-rule" role="group" aria-label="Measurement">
                    <button type="button" aria-pressed={metric === 'weight'} className={seg(metric === 'weight')} onClick={() => setMetric('weight')}>Weight</button>
                    <button type="button" aria-pressed={metric === 'height'} className={seg(metric === 'height')} onClick={() => setMetric('height')}>Height</button>
                </div>
                {last && first && pts.length > 1 ? <span className="text-sm"><Ticker kg={Math.round((last.v - first.v) * 10) / 10} pct={first.v ? Math.round(((last.v - first.v) / first.v) * 1000) / 10 : null} unit={unit} small /> <span className="text-ink-soft">since {label(first.on)}</span></span> : null}
                <span className="ms-auto flex items-center gap-3 text-xs text-ink-soft">
                    <span className="inline-flex items-center gap-1"><span className="size-2.5 rounded-sm" style={{ background: UP }} />Gain</span>
                    <span className="inline-flex items-center gap-1"><span className="size-2.5 rounded-sm" style={{ background: DOWN }} />Loss</span>
                    <span className="inline-flex items-center gap-1"><span className="size-2.5 rounded-sm" style={{ background: FLAT }} />First / no change</span>
                </span>
                <div className="inline-flex overflow-hidden rounded-tile border border-rule" role="group" aria-label="View">
                    <button type="button" aria-pressed={view === 'chart'} className={seg(view === 'chart')} onClick={() => setView('chart')}>Chart</button>
                    <button type="button" aria-pressed={view === 'table'} className={seg(view === 'table')} onClick={() => setView('table')}>Table</button>
                </div>
            </div>
            {!pts.length ? <Empty title={`No ${metric} recorded yet`}>{metric === 'height' ? 'Use Add height above (vet or Administrator), or the Measure Height job in Tasks.' : 'Use Add weight above, or the Weigh Goats job in Tasks.'}</Empty> : view === 'chart' ? <Chart option={option} label={`${metric === 'weight' ? 'Weight' : 'Height'} of ${number}`} height={250} /> : (
                <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
                    <table className="data-table text-sm">
                        <thead><tr><th className="text-start">Date</th><th className="text-end">{metric === 'weight' ? 'Weight' : 'Height'}</th><th className="text-end">Change</th></tr></thead>
                        <tbody>{[...pts].reverse().map((p) => (
                            <tr key={p.on}><td className="font-medium whitespace-nowrap">{label(p.on, true)}</td><td className="text-end tabular-nums">{p.v} {unit}</td>
                                <td className="text-end">{p.ch === null ? <span className="text-ink-soft">First</span> : metric === 'weight' ? <Ticker kg={p.ch} pct={p.pct} days={p.days} small /> : <span className={cx('font-semibold tabular-nums', p.ch > 0 ? 'text-ok-700' : p.ch < 0 ? 'text-crit-700' : 'text-ink-soft')}>{p.ch > 0 ? '▲ +' : p.ch < 0 ? '▼ ' : '■ '}{p.ch} in <span className="font-normal text-ink-soft">in {p.days} {p.days === 1 ? 'day' : 'days'}</span></span>}</td></tr>
                        ))}</tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

/** D-270: everything recorded for this goat on one day, fetched when the day is opened. */
type DayData = {
    weights: Array<{ kg: number; bcs: number | null; height: number | null; context: string | null; by: string; at: string | null }>;
    heights: Array<{ in: number; by: string; at: string | null }>;
    moves: Array<{ type: string; from: string | null; to: string | null; reason: string | null; by: string | null; at: string | null }>;
    teeth: Array<{ code: string | null; condition: string | null; by: string | null }>;
    checkups: Array<{ temp: number | null; fever: boolean; heart: number | null; breaths: number | null; result: string; findings: string | null; by: string; at: string | null }>;
    care: Array<{ kind: string; title: string; notes: string | null; status: string; by: string; at: string | null }>;
    treatments: Array<{ product: string; dose: string | null; route: string | null; meat: string | null; milk: string | null; by: string; at: string | null }>;
};
function DayDetail({ goatId, on }: { goatId: string; on: string }) {
    const [d, setD] = useState<DayData | null>(null);
    useEffect(() => { fetch(`/goats/${goatId}/day/${on}`, { headers: { Accept: 'application/json' } }).then((r) => r.json()).then(setD); }, [goatId, on]);
    const title = new Date(`${on}T12:00:00`).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' });
    const block = (label: string, items: ReactNode[]) => (items.length ? (
        <section className="mt-4"><h3 className="mb-1.5 text-xs font-semibold tracking-wide text-ink-soft uppercase">{label}</h3>
            <ul className="divide-y divide-rule rounded-tile bg-tile ring-1 ring-rule">{items.map((x, i) => <li key={i} className="px-3 py-2.5 text-sm">{x}</li>)}</ul></section>
    ) : null);
    const who = (by: string | null, at: string | null) => (by || at ? <span className="block text-xs text-ink-soft">{[by, at].filter(Boolean).join(' · ')}</span> : null);
    return (
        <div className="p-4 sm:p-6">
            <h2 className="text-lg font-bold text-cobalt-900">{title}</h2>
            <p className="text-sm text-ink-soft">Everything recorded for this goat on this day</p>
            {!d ? <p className="mt-4 text-sm text-ink-soft">Loading…</p> : <>
                {block('Weighed', d.weights.map((w) => <><b>{w.kg} KG</b>{w.bcs !== null ? ` · body score ${w.bcs}` : ''}{w.height !== null ? ` · height ${w.height} in` : ''}{w.context ? ` · ${w.context}` : ''}{who(w.by, w.at)}</>))}
                {block('Height', d.heights.map((h) => <><b>{h.in} in</b>{who(h.by, h.at)}</>))}
                {block('Checkup', d.checkups.map((c) => <>{c.temp !== null ? <b className={c.fever ? 'text-crit-700' : ''}>{c.temp} °F</b> : null}{c.heart !== null ? ` · heart ${c.heart}/min` : ''}{c.breaths !== null ? ` · breaths ${c.breaths}/min` : ''} · <b>{c.result}</b>{c.findings ? <span className="block">{c.findings}</span> : null}{who(c.by, c.at)}</>))}
                {block('Found and care', d.care.map((h) => <><b>{h.kind}:</b> {h.title}{h.status === 'open' ? <span className="ms-1 rounded bg-watch-100 px-1.5 text-xs font-semibold text-watch-700">Open</span> : null}{h.notes ? <span className="block whitespace-pre-line text-ink-soft">{h.notes}</span> : null}{who(h.by, h.at)}</>))}
                {block('Treatment', d.treatments.map((t) => <><b>{t.product}</b>{t.dose ? ` ${t.dose}` : ''}{t.route ? ` · ${t.route}` : ''}{t.meat || t.milk ? <span className="block text-ink-soft">Withdrawal: {[t.meat ? `meat clear ${t.meat}` : '', t.milk ? `milk clear ${t.milk}` : ''].filter(Boolean).join(', ')}</span> : null}{who(t.by, t.at)}</>))}
                {block('Teeth', d.teeth.map((t) => <><b>{t.code ?? '—'}</b>{t.condition && t.condition !== 'normal' ? ` · ${t.condition}` : ''}{who(t.by, null)}</>))}
                {block('Moved', d.moves.map((m) => <>{m.type}: {m.from ?? '—'} → <b>{m.to ?? '—'}</b>{m.reason ? ` · ${m.reason}` : ''}{who(m.by, m.at)}</>))}
                {![d.weights, d.heights, d.checkups, d.care, d.treatments, d.teeth, d.moves].some((x) => x.length) ? <p className="mt-4 text-sm text-ink-soft">Nothing else recorded on this day.</p> : null}
            </>}
        </div>
    );
}
