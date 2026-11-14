import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import GoatSelect from '../components/GoatSelect';
import { AlertTriangle, ChevronDown } from 'lucide-react';
import { useState } from 'react';
import GoatPortrait from '../components/GoatPortrait';
import PedigreeTree, { type PedigreeNode } from '../components/PedigreeTree';
import { Button, Chip, cx, Empty, Field, Note, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import DataGrid from '../components/DataGrid';
import type { Option, SharedProps } from '../types';
import AddButton from '../components/AddButton';
import Sheet from '../components/Sheet';
import { useConfirm } from '../components/Confirm';
import PregnancyOutcomeForm, { type OutcomeOptions } from '../components/PregnancyOutcomeForm';
import { LibIcon } from '../components/ui';
import ReproKpiTiles from '../components/ReproKpis';
import UltrasoundForm, { type UltrasoundOptions } from '../components/UltrasoundForm';
import { HeatDetection, PregnancyCalendar, type HeatOptions, type HeatRow } from '../components/HeatDetection';
import { KidCare, type KidCareOptions, type KidRow } from '../components/KidCare';
import { useTabParam } from '../lib/useTabParam';

type Check = { id: number; on: string; method: string; result: string; fetuses: number | null; next: string | null; by: string; notes: string | null; status: string | null; day: number | null; fetal: string; viability: string | null; action: string | null };
type MatingRow = {
    id: number; status: string; outcome: string; method: string; heat: string | null; cycle: number | null; handler: string | null; notes: string | null;
    alert: 'check_overdue' | 'recheck_overdue' | 'recheck_due' | 'edd_passed' | 'kidding_soon' | null;
    dam: { id: string; number: string; name: string | null; coat: string }; sire: { id: string; number: string; name: string | null } | null;
    mated: string; matedIso: string; pregnancy: string | null; scan: string; day: number; scanWindow: string; expected: string | null; checkDue: string | null; days: number | null; progress: number; active: boolean; checks: Check[];
};
type KiddingRow = {
    id: number; goatId: string; dam: string; on: string; alive: number; stillborn: number; total: number; litter: string; assisted: string; attendant: string; notes: string | null;
    kids: Array<{ id: string; number: string; sex: string; weak: boolean }>;
    // D-161
    outcome: string | null; method: string | null; mother: string | null; premature: boolean; gestation: number | null; weak: number;
    liveMale: number | null; liveFemale: number | null; stillMale: number | null; stillFemale: number | null;
    cause: string | null; diagnosis: string | null; vet: string | null; followUp: string | null; complications: string | null;
};
type Kpis = { events: number; deliveries: number; abortions: number; stillborn: number; live: number; kiddingRate: number | null; lossRate: number | null; recovery: number | null };
type Due = { id: string; number: string; name: string | null; coat: string; sex: string; dam: string | null; age: number; target: string; penId: number | null; state: 'due' | 'soon' | 'postponed'; postponedTo: string | null };
type Props = {
    matings: MatingRow[]; kiddings: KiddingRow[]; weaning: Due[]; kpis: Kpis; heat: HeatRow[]; heatOptions: HeatOptions; // D-175
    kidCare: KidRow[]; kidCareOptions: KidCareOptions; // D-176
    weaned: Array<{ id: number; kidId: string; kid: string; on: string; age: number | null; weight: number | null; method: string | null; by: string }>;
    matingRecords?: Array<{ id: number; matedIso: string; mated: string; dam: string | null; damId: string; damBreed: string | null; sire: string | null; sireId: string | null; sireBreed: string | null; method: string; heat: string | null; status: string; checkDueIso: string | null; checkDue: string | null; expectedIso: string | null; expected: string | null; handler: string | null; by: string; entered: string | null }>; // D-294
    farmBorn?: Array<{ id: string; number: string; born: string | null; bornIso: string | null; dam: string | null; damId: string | null; sire: string | null; sireId: string | null; breed: string | null; sex: string | null; birthKg: number | null; code: string | null; role: string | null; pen: string | null; status: string; entered: string | null; by: string | null }>;
    performance: Array<{ id: string; number: string; name: string | null; kiddings: number; alive: number; stillborn: number; litter: number }>;
    focus: { id: string; number: string; tree: PedigreeNode } | null; pairCheck: { shared: number; sire: string; dam: string; coi: number; limit: number } | null;
    does: Option[]; bucks: Option[]; goats: Option[]; pens: Array<{ id: number; name: string }>;
    settings: { gestation: number; check: number; alert: number; weaning: number };
    options: { methods: Record<string, string>; checkMethods: Record<string, string>; results: Record<string, string>; assisted: Record<string, string>; weanMethods: Record<string, string>; pregnancy: Record<string, string> } & OutcomeOptions & UltrasoundOptions;
};

const label = (o: Option) => `${o.farm_number}${o.name ? ` · ${o.name}` : ''}`;
/** D-161: pregnancy status colours. */
const PREG_CLASS: Record<string, string> = {
    PSU: 'bg-watch-100 text-watch-700', PCF: 'bg-manganese-100 text-manganese-600', PMN: 'bg-manganese-100 text-manganese-600', NKP: 'bg-treat-100 text-treat-700',
    KIP: 'bg-crit-100 text-crit-700', PCM: 'bg-ok-100 text-ok-700', PLS: 'bg-crit-100 text-crit-700',
};

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
    const { farmBorn = [], matingRecords = [], matings, kiddings, weaning, weaned, performance, focus, goats, settings, kpis, options } = props;
    const { auth } = usePage<SharedProps>().props;
    const [tab, setTab] = useTabParam('cycle'); // D-170
    const [adding, setAdding] = useState(false); // D-127
    const [presetDam, setPresetDam] = useState<string | null>(null); // D-175: mate a doe straight from Heat Detection
    const isVet = !!auth?.gates.includes('clinical');
    const canSettings = !!auth?.modules.includes('settings');
    const active = matings.filter((m) => m.active);
    const due = weaning.filter((w) => w.state === 'due');

    return (
        <>
            <Head title="Breeding & Pedigree" />
            <PageHeader title="Breeding & pedigree" description={`Gestation ${settings.gestation} days · pregnancy check ${settings.check} days after mating · weaning at ${settings.weaning} days. Your vet confirms these.`} actions={auth?.gates.includes('record_mating') && ['heat', 'cycle', 'matings'].includes(tab) ? <AddButton label="Record a mating" onClick={() => setAdding(true)} /> : undefined} /> {/* D-296: only where matings are handled */}
            {adding ? <Sheet onClose={() => { setAdding(false); setPresetDam(null); }}><MatingForm {...props} presetDam={presetDam} onDone={() => { setAdding(false); setPresetDam(null); }} /></Sheet> : null}
            {/* D-296: no number row on every tab: the app's numbers show once, on its main page (the app strip), like the other apps */}
            <Tabs value={tab} onChange={setTab} tabs={[
                { id: 'heat', label: 'Heat Detection', count: props.heat.filter((h) => h.status === 'in_heat').length || undefined },
                { id: 'cycle', label: 'Mating Records', count: active.length },
                { id: 'calendar', label: 'Pregnancy Calendar' },
                { id: 'kidding', label: 'Kidding', count: kiddings.length },
                { id: 'kidcare', label: 'Kid Care', count: props.kidCare.filter((k) => k.colostrumDue || k.weak).length || undefined },
                { id: 'weaning', label: 'Weaning', count: weaning.length || undefined },
                { id: 'pedigree', label: 'Family tree' },
                { id: 'performance', label: 'Performance' },
                ...(canSettings ? [{ id: 'settings', label: 'Settings' }] : []),
            ]} />

            {tab === 'heat' ? <HeatDetection rows={props.heat} options={props.heatOptions} onMate={(id) => { setPresetDam(id); setAdding(true); }} /> : null}
            {tab === 'calendar' ? <PregnancyCalendar matings={matings} /> : null}
            {tab === 'kidcare' ? <KidCare kids={props.kidCare} options={props.kidCareOptions} does={props.does.map((d) => ({ id: String(d.id), farm_number: d.farm_number ?? '' }))} /> : null}
            {tab === 'cycle' || tab === 'matings' ? (
                <div className="space-y-4">
                    <MatingSheet rows={matingRecords} />
                    <Panel title="Pregnancies to follow" description="Open matings and pregnancies: the pregnancy check, the ultrasound and the expected kidding. Done ones move to the sheet above." flush={!active.length}>
                        {active.length ? <div className="min-w-0 space-y-3">{active.map((m) => <MatingCard key={m.id} m={m} isVet={isVet} canClose={['ceo', 'manager', 'vet'].includes(auth?.role ?? '')} {...props} />)}</div>
                            : <Empty title="Nothing to follow">Every mating has its outcome.</Empty>}
                    </Panel>
                </div>
            ) : null}

            {tab === 'kidding' ? (
                <Panel title="Kidding & Pregnancy Outcomes" description="One outcome per pregnancy: delivery, stillbirth or loss. Each live kid is registered and linked to it." flush>
                    {kiddings.length ? (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[60rem] text-sm">
                                <thead className="text-left text-ink-soft"><tr>{['Date', 'Dam', 'Outcome', 'Litter', 'Alive', 'Stillborn', 'Delivery', 'Mother', 'Kids registered'].map((h) => <th key={h} className="px-3 py-2 font-semibold">{h}</th>)}</tr></thead>
                                <tbody className="divide-y divide-rule">
                                    {kiddings.map((k) => (
                                        <tr key={k.id}>
                                            <td className="px-3 py-2">{k.on}</td>
                                            <td className="px-3 py-2"><Link href={`/goats/${k.goatId}`} className="font-semibold text-cobalt-800 hover:underline">{k.dam}</Link></td>
                                            <td className="px-3 py-2">{k.outcome ? <Chip className={options.losses.includes(k.outcome) || k.outcome === 'STB' ? 'bg-crit-100 text-crit-700' : 'bg-ok-100 text-ok-700'}>{options.outcomes[k.outcome]} <span className="opacity-60">{k.outcome}</span></Chip> : '—'}{k.premature ? <span className="ml-1 text-xs text-treat-700">premature</span> : null}{k.cause ? <div className="text-xs text-ink-soft">Cause: {k.cause}</div> : null}</td>
                                            <td className="px-3 py-2">{k.litter}{k.gestation ? <div className="text-xs text-ink-soft">day {k.gestation}</div> : null}</td>
                                            <td className="px-3 py-2">{k.alive}{k.liveMale !== null || k.liveFemale !== null ? <div className="text-xs text-ink-soft">{k.liveFemale ?? 0}♀ {k.liveMale ?? 0}♂{k.weak ? ` · ${k.weak} weak` : ''}</div> : null}</td>
                                            <td className="px-3 py-2">{k.stillborn || '—'}{k.stillMale || k.stillFemale ? <div className="text-xs text-ink-soft">{k.stillFemale ?? 0}♀ {k.stillMale ?? 0}♂</div> : null}</td>
                                            <td className="px-3 py-2">{k.method ? options.deliveryMethods[k.method] : k.assisted}<div className="text-xs text-ink-soft">{k.attendant}</div></td>
                                            <td className="px-3 py-2">{k.mother ? options.mother[k.mother] : '—'}{k.followUp ? <div className="text-xs text-ink-soft">Follow-up {k.followUp}</div> : null}</td>
                                            <td className="px-3 py-2">{k.kids.length ? k.kids.map((kid) => <Link key={kid.id} href={`/goats/${kid.id}`} className="mr-2 font-mono text-cobalt-800 hover:underline">{kid.number}</Link>) : <span className="text-ink-faint">{k.alive ? `${k.alive} not linked` : '—'}</span>}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : <Empty title="No kiddings yet">Record a kidding from a mating in the Breeding cycle tab.</Empty>}
                </Panel>
            ) : null}
            {tab === 'kidding' && farmBorn.length ? (
                <Panel flush title="Farm-Born Kids" description="Every kid born on the farm, created from the kidding record: born on, mother and father, and when it was entered and by whom." className="mt-4">
                    <div className="flex flex-wrap gap-2 border-b border-rule px-3 py-3 text-sm">
                        <span className="rounded-md bg-cobalt-100 px-2.5 py-1 text-cobalt-800">All: {farmBorn.length}</span>
                        <span className="rounded-md bg-glaze px-2.5 py-1 ring-1 ring-rule">Female: {farmBorn.filter((k) => k.sex === 'Female').length}</span>
                        <span className="rounded-md bg-glaze px-2.5 py-1 ring-1 ring-rule">Male: {farmBorn.filter((k) => k.sex === 'Male').length}</span>
                        <span className="rounded-md bg-glaze px-2.5 py-1 ring-1 ring-rule">On farm: {farmBorn.filter((k) => k.status === 'On farm').length}</span>
                    </div>
                    <DataGrid id="farm-born-kids" name="Farm-born kids" rows={farmBorn} rowKey={(k) => k.id} cols={[
                        { key: 'bornIso', label: 'Born on', type: 'date', render: (k) => k.born ?? '—' },
                        { key: 'number', label: 'Tag ID', type: 'text', total: 'count', render: (k) => <Link href={`/goats/${k.id}`} className="font-mono text-cobalt-800 hover:underline">{k.number}</Link> },
                        { key: 'dam', label: 'Mother', type: 'text', render: (k) => (k.damId ? <Link href={`/goats/${k.damId}`} className="font-mono text-cobalt-800 hover:underline">{k.dam}</Link> : '—') },
                        { key: 'sire', label: 'Father', type: 'text', render: (k) => (k.sireId ? <Link href={`/goats/${k.sireId}`} className="font-mono text-cobalt-800 hover:underline">{k.sire}</Link> : '—') },
                        { key: 'breed', label: 'Breed', type: 'enum' },
                        { key: 'sex', label: 'Sex', type: 'enum' },
                        { key: 'birthKg', label: 'Birth weight (KG)', type: 'number', total: 'avg', unit: 'KG' },
                        { key: 'code', label: 'Class code', type: 'enum' },
                        { key: 'role', label: 'Role', type: 'enum' },
                        { key: 'pen', label: 'PEN', type: 'enum' },
                        { key: 'status', label: 'Status', type: 'enum' },
                        { key: 'by', label: 'Entered by', type: 'enum' },
                        { key: 'entered', label: 'Entered on', type: 'text' },
                    ]} />
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
                    <GoatSelect aria-label="Choose goat" className="field w-64" value={focus?.id ?? ''} onChange={(e) => router.get('/breeding', { goat: e.target.value }, { preserveState: true, preserveScroll: true, only: ['focus'] })}>
                        {goats.map((g) => <option key={g.id} value={g.id}>{label(g)}</option>)}
                    </GoatSelect>
                }>
                    {focus ? <PedigreeTree tree={focus.tree} /> : <Empty title="Choose a goat" />}
                </Panel>
            ) : null}

            {tab === 'performance' && kpis.events ? <div className="mb-4"><ReproKpiTiles k={kpis} /></div> : null}
            {tab === 'performance' ? (
                <Panel title="Does by live kids" flush>
                    {performance.length ? <DataGrid id="doe-performance" name="Doe performance" rows={performance} rowKey={(p) => p.id} cols={[
                        { key: 'number', label: 'Doe', type: 'text', total: 'count', render: (p) => <Link href={`/goats/${p.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{p.number}</Link> },
                        { key: 'name', label: 'Name', type: 'text' },
                        { key: 'kiddings', label: 'Kiddings', type: 'number', total: 'sum' },
                        { key: 'alive', label: 'Born alive', type: 'number', total: 'sum' },
                        { key: 'stillborn', label: 'Stillborn', type: 'number', total: 'sum' },
                        { key: 'litter', label: 'Average litter', type: 'number', total: 'avg' },
                    ]} />
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
    const [ask, confirmBox] = useConfirm(); // D-132
    return (
        <section className={cx('rounded-tile border border-rule bg-tile shadow-tile', !m.active && 'opacity-75')}>
            {confirmBox}
            <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="flex w-full flex-wrap items-center gap-3 px-4 py-3 text-left">
                <GoatPortrait number={m.dam.number} coat={m.dam.coat} sex="female" size={38} />
                <div className="min-w-44 flex-1">
                    <p className="font-semibold text-cobalt-900">{m.dam.name ?? m.dam.number}{m.sire ? <span className="font-normal text-ink-soft"> × {m.sire.number}</span> : null}</p>
                    <p className="text-sm text-ink-soft">Mated {m.mated} · {m.method}{m.cycle ? ` · service ${m.cycle}` : ''}{m.status === 'open' && m.checkDue ? ` · check due ${m.checkDue}` : ''}</p>
                    {m.status === 'pregnant' ? <div className="mt-1 h-1.5 overflow-hidden rounded-sm bg-cobalt-100" role="progressbar" aria-valuenow={m.progress} aria-valuemin={0} aria-valuemax={100} aria-label="Gestation progress"><div className="h-full bg-manganese-600" style={{ width: `${m.progress}%` }} /></div> : null}
                </div>
                {m.status === 'pregnant' && m.expected ? <div className="text-right text-sm"><div className="font-semibold text-cobalt-900">{m.expected}</div><div className="text-ink-soft">{(m.days ?? 0) < 0 ? `${-(m.days ?? 0)} days over` : `in ${m.days} days`}</div></div> : null}
                <div className="flex items-center gap-1.5">
                    {m.active ? <Chip className={m.scan === 'NSC' ? 'bg-glaze text-ink-soft' : ['PCF', 'VPG', 'MPG'].includes(m.scan) ? 'bg-ok-100 text-ok-700' : ['CPL', 'SFL', 'HYD', 'ABN'].includes(m.scan) ? 'bg-crit-100 text-crit-700' : 'bg-watch-100 text-watch-700'} ><LibIcon name="app-reports" size={14} />{m.scan === 'NSC' ? 'Not Scanned' : options.ultrasound[m.scan]}</Chip> : null}
                    {m.alert ? <Chip className={ALERT[m.alert].className}><AlertTriangle size={12} aria-hidden />{ALERT[m.alert].label}</Chip> : null}
                    {m.pregnancy ? <Chip className={PREG_CLASS[m.pregnancy] ?? 'bg-glaze text-ink-soft'}><LibIcon name={m.pregnancy === 'PLS' ? 'ic-sick' : m.pregnancy === 'PCM' ? 'ic-kidding' : 'ic-pregnant'} size={14} />{options.pregnancy[m.pregnancy]} <span className="opacity-60">{m.pregnancy}</span></Chip>
                        : <Chip className={OUTCOME_CLASS[m.status] ?? 'bg-glaze text-ink-soft'}>{m.outcome}</Chip>}
                    <ChevronDown size={16} className={cx('text-ink-faint transition-transform', open && 'rotate-180')} aria-hidden />
                </div>
            </button>
            {open ? (
                <div className="space-y-3 border-t border-rule px-4 py-3 text-sm">
                    <p className="text-ink-soft">Heat observed: {m.heat ?? 'not recorded'} · handler {m.handler ?? '—'}{m.notes ? ` · ${m.notes}` : ''}</p>
                    <div>
                        <p className="mb-1 font-semibold text-cobalt-900">Pregnancy checks</p>
                        {m.checks.length ? <ul className="space-y-1.5">{m.checks.map((c) => (
                            <li key={c.id} className="rounded-lg bg-glaze px-2.5 py-1.5">
                                <span className="font-semibold text-cobalt-900">{c.on}{c.day !== null ? ` · day ${c.day}` : ''}</span> · <strong className={c.status && ['CPL', 'SFL', 'HYD', 'ABN'].includes(c.status) ? 'text-crit-700' : c.status && ['PCF', 'VPG', 'MPG'].includes(c.status) ? 'text-ok-700' : ''}>{c.status ? `${options.ultrasound[c.status] ?? c.result} (${c.status})` : c.result}</strong>
                                <span className="text-ink-soft"> · {c.fetal}{c.viability ? ` · ${c.viability}` : ''} · {c.method}{c.next ? ` · repeat ${c.next}` : ''} · {c.by}</span>
                                {c.action ? <div className="text-xs"><span className="font-semibold">Next Action:</span> {c.action}</div> : null}
                            </li>
                        ))}</ul> : <p className="text-ink-soft">Not scanned yet (NSC){isVet ? '' : ' (recorded by a veterinarian)'}. Day {m.day}: {m.scanWindow}.</p>}
                    </div>
                    {m.active ? (
                        <div className="flex flex-wrap gap-2">
                            {isVet ? <Button variant="secondary" onClick={() => setMode(mode === 'check' ? 'none' : 'check')}><LibIcon name="ic-pregnant" size={18} /> Ultrasound</Button> : null}
                            <Button onClick={() => setMode(mode === 'kidding' ? 'none' : 'kidding')}><LibIcon name="ic-kidding" size={18} /> Pregnancy Outcome</Button>
                            {canClose ? <Button variant="quiet" onClick={async () => { if (await ask({ title: 'Close this mating?', message: 'It will be closed without a kidding.', confirm: 'Yes, close mating', danger: false })) router.post(`/breeding/matings/${m.id}/close`, {}, { preserveScroll: true }); }}>Close mating</Button> : null}
                        </div>
                    ) : null}
                    {mode === 'check' ? <UltrasoundForm matingId={m.id} damNumber={m.dam.number} matedOn={m.matedIso} options={options} onDone={() => setMode('none')} /> : null}
                    {mode === 'kidding' ? <PregnancyOutcomeForm matingId={m.id} damNumber={m.dam.number} matedOn={m.matedIso} options={options} onDone={() => setMode('none')} /> : null}
                    {m.status === 'open' && !isVet ? <p className="text-xs text-ink-faint">Pregnancy checks are due {settings.check} days after mating.</p> : null}
                </div>
            ) : null}
        </section>
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
                    <Field label="Move to PEN (optional)" error={e.pen_id}><select className="field" value={form.data.pen_id} onChange={(x) => form.setData('pen_id', x.target.value)}><option value="">Stay in current PEN</option>{pens.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
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

function MatingForm({ does, bucks, pairCheck, options, settings, onDone, presetDam }: Props & { onDone?: () => void; presetDam?: string | null }) {
    const mating = useForm({ dam_id: String(presetDam ?? does[0]?.id ?? ''), sire_id: String(bucks[0]?.id ?? ''), mated_on: today(), method: 'natural', heat_observed: 'yes', handler: '', notes: '', coi_override_reason: '' });
    const checkPair = () => router.get('/breeding', { sire: mating.data.sire_id, dam: mating.data.dam_id }, { preserveState: true, preserveScroll: true, only: ['pairCheck'] });
    const e = mating.errors as Record<string, string>;
    const mated = new Date(`${mating.data.mated_on}T12:00:00`);
    const plus = (days: number) => { const d = new Date(mated); d.setDate(d.getDate() + days); return d.toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit', year: 'numeric' }); };
    return (
        <Panel title="Record a mating">
            <form onSubmit={(ev) => { ev.preventDefault(); mating.post('/breeding/matings', { preserveScroll: true, onSuccess: () => { mating.reset('notes'); onDone?.(); } }); }} className="space-y-3">
                {!does.length ? <Note>No female goats are registered yet. Register a doe first, then record her mating here.</Note> : null}
                <Field label="Doe" error={e.dam_id}><GoatSelect className="field" value={mating.data.dam_id} onChange={(x) => mating.setData('dam_id', x.target.value)} disabled={!does.length}>{does.map((d) => <option key={d.id} value={d.id}>{label(d)}</option>)}</GoatSelect></Field>
                <Field label="Buck" error={e.sire_id} hint="Not recorded: unknown or not on this farm"><GoatSelect className="field" value={mating.data.sire_id} onChange={(x) => mating.setData('sire_id', x.target.value)}><option value="">Not recorded</option>{bucks.map((d) => <option key={d.id} value={d.id}>{label(d)}</option>)}</GoatSelect></Field>
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Service date" error={e.mated_on}><input type="date" max={today()} className="field" value={mating.data.mated_on} onChange={(x) => mating.setData('mated_on', x.target.value)} /></Field>
                    <Field label="Heat observed" error={e.heat_observed}><select className="field" value={mating.data.heat_observed} onChange={(x) => mating.setData('heat_observed', x.target.value)}><option value="yes">Yes</option><option value="no">No</option><option value="unknown">Unknown</option></select></Field>
                </div>
                <Field label="Method" error={e.method}><select className="field" value={mating.data.method} onChange={(x) => mating.setData('method', x.target.value)}>{Object.entries(options.methods).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Handler / vet" error={e.handler} hint="Leave empty for yourself"><input className="field" value={mating.data.handler} onChange={(x) => mating.setData('handler', x.target.value)} /></Field>
                <p className="rounded-tile bg-glaze px-3 py-2 text-sm text-ink-soft">Pregnancy check due <strong className="text-cobalt-900">{plus(settings.check)}</strong> · expected kidding <strong className="text-cobalt-900">{plus(settings.gestation)}</strong></p>
                <Button variant="secondary" className="w-full" onClick={checkPair}>Check relatedness</Button>
                {pairCheck ? <p className={`text-sm font-semibold ${pairCheck.coi > pairCheck.limit ? 'text-crit-700' : 'text-cobalt-900'}`}>Kid inbreeding (COI): {pairCheck.coi}% · farm limit {pairCheck.limit}%</p> : null}
                {e.sire_id?.startsWith('Inbreeding') ? <Field label="Reason to mate anyway (owner, manager or vet)" error={e.coi_override_reason}><input className="field" value={mating.data.coi_override_reason} onChange={(x) => mating.setData('coi_override_reason', x.target.value)} /></Field> : null}
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

/** D-294/D-296: every mating recorded on the farm, as a sheet (the top of Mating Records). */
function MatingSheet({ rows: matingRecords }: { rows: NonNullable<Props['matingRecords']> }) {
    return (
                <Panel flush title="All matings" description="Every mating recorded on the farm, newest first: the doe and the buck, how and when, the pregnancy check and the expected kidding, and who entered it.">
            <div className="flex flex-wrap gap-2 border-b border-rule px-3 py-3 text-sm">
                <span className="rounded-md bg-cobalt-100 px-2.5 py-1 text-cobalt-800">All: {matingRecords.length}</span>
                {[...new Set(matingRecords.map((m) => m.status))].map((st) => <span key={st} className="rounded-md bg-glaze px-2.5 py-1 ring-1 ring-rule">{st}: {matingRecords.filter((m) => m.status === st).length}</span>)}
            </div>
            {matingRecords.length ? <DataGrid id="mating-records" name="Mating records" rows={matingRecords} rowKey={(m) => String(m.id)} cols={[
                { key: 'matedIso', label: 'Mated on', type: 'date', render: (m) => m.mated },
                { key: 'dam', label: 'Doe', type: 'text', total: 'count', render: (m) => <Link href={`/goats/${m.damId}`} className="font-mono text-cobalt-800 hover:underline">{m.dam}</Link> },
                { key: 'damBreed', label: 'Doe breed', type: 'enum' },
                { key: 'sire', label: 'Buck', type: 'text', render: (m) => (m.sireId ? <Link href={`/goats/${m.sireId}`} className="font-mono text-cobalt-800 hover:underline">{m.sire}</Link> : 'Not recorded') },
                { key: 'sireBreed', label: 'Buck breed', type: 'enum' },
                { key: 'method', label: 'Method', type: 'enum' },
                { key: 'heat', label: 'Heat observed', type: 'enum' },
                { key: 'status', label: 'Status', type: 'enum' },
                { key: 'checkDueIso', label: 'Pregnancy check due', type: 'date', render: (m) => m.checkDue ?? '—' },
                { key: 'expectedIso', label: 'Expected kidding', type: 'date', render: (m) => m.expected ?? '—' },
                { key: 'handler', label: 'Handler / vet', type: 'text' },
                { key: 'by', label: 'Entered by', type: 'enum' },
                { key: 'entered', label: 'Entered on', type: 'text' },
            ]} /> : <Empty title="No matings yet">Record a mating to add one.</Empty>}
        </Panel>
    );
}
