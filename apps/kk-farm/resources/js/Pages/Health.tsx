import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import GoatSelect from '../components/GoatSelect';
import DeleteButton from '../components/DeleteButton';
import { AlertTriangle, ChevronDown, ClipboardPlus, Pill, Plus, Stethoscope, Syringe, X } from 'lucide-react';
import { useState } from 'react';
import { Button, Chip, cx, Empty, Field, GoatLabel, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import type { Health as HealthState, Option, SharedProps } from '../types';
import Checkups, { type CheckupData } from '../components/Checkups';
import HealthEntry from '../components/HealthEntry';
import Sheet from '../components/Sheet';
import MedicationForm from '../components/MedicationForm';
import { doseFill, doseUnit, hasDose, MedicineAdd, MedicineEdit, MedicineMoveForm, MedicinePicker, type StandardDose, type Suggest } from '../components/StockForms';
import { titleCase } from '../lib/titleCase';
import AddButton from '../components/AddButton';
import { useTabParam } from '../lib/useTabParam';
import { CareCalendar, Protocols, type CalendarRow, type Protocol } from '../components/CareCalendar';
import DosesDue, { type DoseRow } from '../components/DosesDue';
import { DiseaseMaster, Mortality, SymptomMaster, type DiseaseRow, type MortalityData, type SymptomRow } from '../components/Mortality';

type Row = { id: number; goatId: string; number: string; name: string | null; coat: string; sex: 'male' | 'female'; health: HealthState; kind: string; title: string; notes: string | null; severity: string | null; by: string; role: string; on: string; next: string | null; overdue?: boolean };
type Dose = { doses?: { given: number; missed: number; held: number; due: number; total: number }; id: number; on: string; product: string; dose: string; route: string; frequency: string | null; days: number | null; next: string | null; meat: string | null; milk: string | null; cost: number | null; by: string; notes: string | null };
type Case = {
    id: number; label: string; goatId: string; number: string; name: string | null; coat: string; sex: 'male' | 'female';
    onset: string; condition: string; diseaseId: number | null; symptoms: string | null; severity: string; isolated: boolean; status: string; active: boolean; vet: string | null; diagnosis: string | null; notes: string | null;
    resolved: string | null; age: number; by: string; nextDose: string | null; doseAlert: 'overdue' | 'soon' | null; withdrawal: { meat: string | null; milk: string | null } | null; treatments: Dose[];
};
type Medicine = { id: number; code: string; name: string; category: string; unit: string; reorder: number; costPerUnit: number; storage: string | null; supplier: string | null; active: boolean; notes?: string | null;
    spec: string | null; useCase: string | null; source: string | null; packUnit: string | null; packSize: number | null; check: string | null; checkNote: string | null; diseaseIds: number[]; dose?: StandardDose; qty: number; value: number; status: 'negative' | 'expired' | 'reorder' | 'expiring' | 'ok'; batches: Array<{ batch: string; expiry: string | null; expired: boolean; qty: number }> };
type MedMove = { id: number; on: string; medicine: string; unit: string; type: string; qty: number; batch: string | null; expiry: string | null; by: string; party: string | null };
type WithdrawalRow = { goatId: string; number: string; name: string | null; coat: string; sex: 'male' | 'female'; meat: string | null; milk: string | null };
type Props = {
    checkups: CheckupData;
    cases: Case[]; reports: Row[]; care: Row[]; withdrawal: WithdrawalRow[]; counts: Record<string, number>; goats: Option[];
    severities: Record<string, string>; statuses: Record<string, string>; routes: Record<string, string>;
    medicines: Medicine[]; medicineMoves: MedMove[]; medicineCategories: Record<string, string>; medicineUnits: Record<string, string>; medicinePacks: Record<string, string>; medicineChecks: Record<string, string>; suggest: Suggest;
    medicineTypes: Record<string, { label: string; direction: number }>; medicineAlerts: number; canMedicine: boolean;
    calendar: CalendarRow[]; protocols: Protocol[]; applies: Record<string, string>; careTypes: Record<string, string>; // D-173
    symptomList?: SymptomRow[]; // D-292
    dosesDue?: DoseRow[]; dosesDone?: DoseRow[]; canGiveDoses?: boolean; frequencies?: Record<string, string>; // D-304
    diseases: DiseaseRow[]; diseaseCategories: Record<string, string>; mortality: MortalityData | null; // D-174; null when the tab is closed to this person (D-241)
};

const MED_STATUS: Record<Medicine['status'], { label: string; className: string }> = {
    negative: { label: 'Below zero', className: 'bg-crit-100 text-crit-700' },
    expired: { label: 'Expired stock', className: 'bg-crit-100 text-crit-700' },
    reorder: { label: 'Reorder', className: 'bg-watch-100 text-watch-700' },
    expiring: { label: 'Expiring soon', className: 'bg-watch-100 text-watch-700' },
    ok: { label: 'OK', className: 'bg-ok-100 text-ok-700' },
};

const asGoat = (r: { goatId: string; number: string; name: string | null; coat: string; sex: 'male' | 'female' }) => ({ id: r.goatId, number: r.number, name: r.name, coat: r.coat, sex: r.sex });
const label = (o: Option) => `${o.farm_number}${o.name ? ` · ${o.name}` : ''}`;
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const SEVERITY_CLASS: Record<string, string> = { low: 'bg-glaze text-ink-soft', moderate: 'bg-watch-100 text-watch-700', high: 'bg-treat-100 text-treat-700', emergency: 'bg-crit-100 text-crit-700' };
const STATUS_CLASS: Record<string, string> = { open: 'bg-crit-100 text-crit-700', under_treatment: 'bg-treat-100 text-treat-700', monitoring: 'bg-watch-100 text-watch-700', recovered: 'bg-ok-100 text-ok-700', closed: 'bg-glaze text-ink-soft', deceased: 'bg-ink text-white' };

/** D-073: health cases with treatment doses and veterinarian-entered meat/milk withdrawal (workbook "Health Cases" and "Treatments"). */
export default function Health(props: Props) {
    const { cases, reports, care, withdrawal, counts, goats, severities } = props;
    const { auth } = usePage<SharedProps>().props;
    const isVet = !!auth?.gates.includes('clinical');
    const canCase = !!auth?.gates.includes('open_cases'); // D-095: vet or administrator
    // D-097: forms open from + buttons (or a dashboard shortcut: /health?new=case|report|care), not always on the page.
    const [sheet, setSheet] = useState<'entry' | 'case' | 'report' | 'care' | 'med' | null>(() => (new URLSearchParams(window.location.search).get('new') as 'entry' | 'case' | 'report' | 'care' | 'med' | null));
    const action = (label: string, icon: React.ReactNode, onClick: () => void, primary = false) => (
        <button type="button" onClick={onClick} className={cx('kk-pill inline-flex min-h-11 items-center justify-center gap-1.5 rounded-tile border px-2 text-sm font-semibold max-sm:flex-col max-sm:py-2 sm:rounded-full sm:px-4 sm:text-sm', primary ? 'border-cobalt-800 bg-cobalt-800 text-white hover:bg-cobalt-900' : 'border-rule bg-tile text-cobalt-900 hover:border-turq-600')}>
            <span className="flex items-center gap-1"><Plus size={14} aria-hidden className="max-sm:hidden" />{icon}</span>{titleCase(label)}
        </button>
    );
    const [tab, setTab] = useTabParam('cases'); // D-170
    const [fromReport, setFromReport] = useState<Row | null>(null);

    return (
        <>
            <Head title="Veterinary & Health" />
            <PageHeader title="Veterinary & health" description={isVet ? 'Checkups, cases, treatments and withdrawal. Every record carries your name.' : canCase ? 'Checkups and cases. Treatments and withdrawal dates are set by a veterinarian.' : 'Report a concern. A veterinarian decides what it is.'} />
            <div className="mb-4 grid grid-cols-3 gap-2 sm:flex sm:flex-wrap">
                {props.checkups.canCheck ? action('Checkup', <Stethoscope size={16} aria-hidden />, () => setTab('checkups'), true) : null}
                {isVet ? action('Medication', <Pill size={16} aria-hidden />, () => setSheet('med'), true) : null}{/* D-368: scan, when, why, every medicine */}
                {props.checkups.canEntry ? action('New health entry', <ClipboardPlus size={16} aria-hidden />, () => { setFromReport(null); setSheet('entry'); }) : null}
                {auth?.gates.includes('report_concern') ? action('Report concern', <AlertTriangle size={16} aria-hidden />, () => setSheet('report')) : null}
                {auth?.gates.includes('give_care') ? action('Preventive care', <Syringe size={16} aria-hidden />, () => setSheet('care')) : null}
            </div>
            {/* D-119: a number that is zero is not shown. */}
            {counts.active || counts.emergency || counts.doses || counts.withdrawal || counts.overdue ? (
                <StatRow>
                    {counts.active ? <Stat label="Active cases" value={counts.active} /> : null}
                    {counts.emergency ? <Stat label="High or emergency" value={counts.emergency} tone="alert" /> : null}
                    {counts.doses ? <Stat label="Doses due" value={counts.doses} note="Overdue or within 2 days" tone="alert" /> : null}
                    {counts.withdrawal ? <Stat label="Under withdrawal" value={counts.withdrawal} note="Do not sell or use milk" tone="alert" /> : null}
                    {counts.overdue ? <Stat label="Care overdue" value={counts.overdue} note="Vaccine or deworming" tone="alert" /> : null}
                </StatRow>
            ) : null}

            <div>
                <div className="min-w-0">
                    <Tabs value={tab} onChange={setTab} tabs={[
                        { id: 'doses', label: 'Doses Due', count: (props.dosesDue ?? []).length || undefined }, // D-304
                        { id: 'checkups', label: 'Checkups', count: props.checkups.goats.filter((g) => g.due).length || undefined },
                        { id: 'cases', label: 'Cases', count: counts.active },
                        { id: 'reports', label: 'Reported concerns', count: reports.length },
                        { id: 'withdrawal', label: 'Withdrawal', count: withdrawal.length },
                        { id: 'calendar', label: 'Vaccination Calendar', count: props.calendar.reduce((n, p) => n + p.goats.length, 0) || undefined },
                        { id: 'care', label: 'Preventive care', count: care.length },
                        { id: 'protocols', label: 'Protocols', count: props.protocols.length || undefined },
                        { id: 'mortality', label: 'Mortality', count: props.mortality?.rows.length || undefined },
                        { id: 'diseases', label: 'Disease Master' },
                        { id: 'symptoms', label: 'Symptom Master' },
                        { id: 'store', label: 'Medicine store', count: props.medicineAlerts || undefined },
                    ]} />
                    {tab === 'store' ? <MedicineStore {...props} /> : null}
                    {tab === 'mortality' && props.mortality ? <Mortality data={props.mortality} causes={props.diseases.filter((d) => d.active).map((d) => d.name)} isVet={isVet} /> : null}
                    {tab === 'diseases' ? <DiseaseMaster diseases={props.diseases} categories={props.diseaseCategories} canEdit={isVet || auth?.role === 'ceo'} /> : null}
                    {tab === 'doses' ? <DosesDue due={props.dosesDue ?? []} done={props.dosesDone ?? []} canGive={!!props.canGiveDoses} /> : null}
                    {tab === 'symptoms' ? <SymptomMaster symptoms={props.symptomList ?? []} diseases={props.diseases} canEdit={isVet || auth?.role === 'ceo'} /> : null}
                    {tab === 'calendar' ? <CareCalendar calendar={props.calendar} isVet={!!auth?.gates.includes('give_care')} /> : null}
                    {tab === 'protocols' ? <Protocols protocols={props.protocols} careTypes={props.careTypes} applies={props.applies} medicines={props.medicines} canEdit={isVet || auth?.role === 'ceo'} /> : null}
                    {tab === 'checkups' ? <Checkups data={props.checkups} /> : null}
                    {tab === 'cases' ? (
                        <div className="space-y-3">
                            {cases.length ? cases.map((c) => <CaseCard key={c.id} c={c} isVet={isVet} {...props} />) : <Panel><Empty title="No health cases">{isVet ? 'Open a case from a reported concern or with the form.' : 'A veterinarian opens cases.'}</Empty></Panel>}
                        </div>
                    ) : null}
                    {tab === 'reports' ? (
                        <Panel flush>
                            {reports.length ? (
                                <ul className="divide-y divide-rule">
                                    {reports.map((r) => (
                                        <li key={r.id} className="flex flex-wrap items-center gap-3 px-4 py-2.5">
                                            <div className="w-44 min-w-0"><GoatLabel goat={asGoat(r)} /></div>
                                            <div className="min-w-44 flex-1">
                                                <p className="font-semibold text-cobalt-900">{r.title}</p>
                                                <p className="text-sm text-ink-soft">{r.on} · {r.by} ({r.role}){r.severity ? ` · ${r.severity}` : ''}</p>
                                            </div>
                                            {isVet ? (
                                                <div className="flex gap-2">
                                                    <Button onClick={() => { setFromReport(r); setSheet('case'); }}>Open case</Button>
                                                    <Button variant="secondary" onClick={() => router.post(`/health/records/${r.id}/close`, {}, { preserveScroll: true })}>Nothing found</Button>
                                                </div>
                                            ) : null}
                                        </li>
                                    ))}
                                </ul>
                            ) : <Empty title="No open concerns" />}
                        </Panel>
                    ) : null}
                    {tab === 'withdrawal' ? (
                        <Panel flush description="From the veterinarian's clear dates on each treatment. These goats cannot be sold, and milk must not be used, before the dates.">
                            {withdrawal.length ? (
                                <ul className="divide-y divide-rule">
                                    {withdrawal.map((w) => (
                                        <li key={w.goatId} className="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5">
                                            <GoatLabel goat={asGoat(w)} size={34} />
                                            <div className="flex flex-wrap gap-1.5 text-sm">
                                                {w.meat ? <Chip className="bg-crit-100 text-crit-700">Meat until {w.meat}</Chip> : null}
                                                {w.milk ? <Chip className="bg-watch-100 text-watch-700">Milk until {w.milk}</Chip> : null}
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            ) : <Empty title="No goat is under withdrawal" />}
                        </Panel>
                    ) : null}
                    {tab === 'care' ? (
                        <Panel flush>
                            {care.length ? (
                                <ul className="divide-y divide-rule">
                                    {care.map((r) => (
                                        <li key={r.id} className="flex items-center justify-between gap-3 px-4 py-2">
                                            <GoatLabel goat={asGoat(r)} size={34} />
                                            <div className="text-right text-sm"><div className="font-medium">{r.title}</div><div className={cx(r.overdue ? 'font-semibold text-crit-700' : 'text-ink-soft')}>{r.overdue ? `Overdue since ${r.next}` : `Due ${r.next}`}</div></div>
                                        </li>
                                    ))}
                                </ul>
                            ) : <Empty title="Nothing due in the next three weeks" />}
                        </Panel>
                    ) : null}
                </div>

                {sheet ? (
                    <Sheet large={sheet === 'med'} onClose={() => { setSheet(null); setFromReport(null); }}>
                        {sheet === 'med' && isVet ? <MedicationForm cases={props.cases} medicines={props.medicines} suggest={props.suggest} routes={props.routes} frequencies={props.frequencies ?? {}} severities={severities}
                            diseases={props.diseases.filter((d) => d.active).map((d) => ({ id: d.id, name: d.name }))} onDone={() => setSheet(null)} /> : null}
                        {sheet === 'entry' ? <HealthEntry data={{ ...props.checkups, conditions: props.checkups.conditions ?? [] }} severities={severities} onDone={() => { setSheet(null); router.reload(); }}
                            goatId={new URLSearchParams(window.location.search).get('goat') ?? undefined} kind={new URLSearchParams(window.location.search).get('kind') ?? undefined} /> : null}{/* D-305: ?new=entry&goat=…&kind=… from an app's scan */}
                        {sheet === 'case' && canCase ? <CaseForm key={fromReport?.id ?? 'new'} goats={goats} severities={severities} report={fromReport} onDone={() => { setFromReport(null); setSheet(null); setTab('cases'); }} /> : null}
                        {sheet === 'report' ? <ObservationForm goats={goats} onDone={() => setSheet(null)} /> : null}
                        {sheet === 'care' && isVet ? <PreventiveForm goats={goats} medicines={props.medicines} suggest={props.suggest} onDone={() => setSheet(null)} /> : null}
                    </Sheet>
                ) : null}
            </div>
        </>
    );
}

function CaseCard({ c, isVet, statuses, routes, medicines, suggest }: { c: Case; isVet: boolean } & Props) {
    const [open, setOpen] = useState(c.active && c.doseAlert !== null);
    const [mode, setMode] = useState<'none' | 'dose' | 'status'>('none');

    return (
        <section className={cx('rounded-tile border bg-tile shadow-tile', c.active ? 'border-rule' : 'border-rule opacity-80')}>
            <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="flex w-full flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 text-left">
                <div className="w-44 min-w-0" onClick={(e) => e.stopPropagation()}><GoatLabel goat={asGoat(c)} size={34} /></div>
                <div className="min-w-48 flex-1">
                    <p className="font-semibold text-cobalt-900"><span className="font-mono text-xs text-ink-soft">{c.label}</span> {c.condition}</p>
                    <p className="text-sm text-ink-soft">Since {c.onset} · day {c.age}{c.resolved ? ` · ended ${c.resolved}` : ''}{c.nextDose ? ` · next dose ${c.nextDose}` : ''}</p>
                </div>
                <div className="flex flex-wrap items-center gap-1.5">
                    {c.doseAlert ? <Chip className="bg-crit-100 text-crit-700"><AlertTriangle size={12} aria-hidden />{c.doseAlert === 'overdue' ? 'Dose overdue' : 'Dose soon'}</Chip> : null}
                    {c.withdrawal?.meat ? <Chip className="bg-crit-100 text-crit-700">Meat {c.withdrawal.meat}</Chip> : null}
                    {c.withdrawal?.milk ? <Chip className="bg-watch-100 text-watch-700">Milk {c.withdrawal.milk}</Chip> : null}
                    {c.isolated ? <Chip className="bg-manganese-100 text-manganese-600">Isolated</Chip> : null}
                    <Chip className={SEVERITY_CLASS[c.severity]}>{c.severity}</Chip>
                    <Chip className={STATUS_CLASS[c.status]}>{statuses[c.status]}</Chip>
                    <ChevronDown size={16} className={cx('text-ink-faint transition-transform', open && 'rotate-180')} aria-hidden />
                </div>
            </button>
            {open ? (
                <div className="space-y-3 border-t border-rule px-4 py-3 text-sm">
                    <dl className="grid gap-x-4 gap-y-1 sm:grid-cols-2">
                        {c.symptoms ? <div><dt className="text-ink-soft">Symptoms</dt><dd>{c.symptoms}</dd></div> : null}
                        {c.diagnosis ? <div><dt className="text-ink-soft">Diagnosis / test</dt><dd>{c.diagnosis}</dd></div> : null}
                        {c.notes ? <div><dt className="text-ink-soft">Notes</dt><dd>{c.notes}</dd></div> : null}
                        <div><dt className="text-ink-soft">Veterinarian</dt><dd>{c.vet ?? c.by}</dd></div>
                    </dl>
                    <div>
                        <p className="mb-1 font-semibold text-cobalt-900">Treatments</p>
                        {c.treatments.length ? (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[40rem]">
                                    <thead className="text-left text-ink-soft"><tr>{['Date', 'Medicine', 'Dose', 'Route', 'Next dose', 'Meat clear', 'Milk clear', 'By'].map((h) => <th key={h} className="py-1 pr-3 font-semibold">{h}</th>)}</tr></thead>
                                    <tbody className="divide-y divide-rule">
                                        {c.treatments.map((t) => (
                                            <tr key={t.id}>
                                                <td className="py-1.5 pr-3">{t.on}</td><td className="pr-3 font-medium">{t.product}</td><td className="pr-3">{t.dose}{t.frequency ? ` · ${t.frequency}` : ''}{t.days ? ` · ${t.days} days` : ''}{t.doses && t.doses.total > 1 ? <span className="block text-xs text-ink-soft">{t.doses.given} of {t.doses.total} doses given{t.doses.missed ? ` · ${t.doses.missed} missed` : ''}{t.doses.held ? ` · ${t.doses.held} held` : ''}</span> : null}</td>
                                                <td className="pr-3">{t.route}</td><td className="pr-3">{t.next ?? '—'}</td><td className="pr-3">{t.meat ?? '—'}</td><td className="pr-3">{t.milk ?? '—'}</td><td className="pr-3 text-ink-soft">{t.by}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : <p className="text-ink-soft">No treatment yet.</p>}
                    </div>
                    {isVet && c.active ? (
                        <div className="flex flex-wrap gap-2">
                            <Button onClick={() => setMode(mode === 'dose' ? 'none' : 'dose')}><Pill size={15} className="mr-1 inline" aria-hidden />Add treatment</Button>
                            <Button variant="secondary" onClick={() => setMode(mode === 'status' ? 'none' : 'status')}>Update case</Button>
                        </div>
                    ) : null}
                    {/* D-349: the treatment and the case update open as centred pop cards, not inside the list */}
                    {mode === 'dose' ? (
                        <Sheet onClose={() => setMode('none')}>
                            <div className="p-4 sm:p-5">
                                <h2 className="text-lg font-bold text-cobalt-900">Add treatment · {c.number} · {c.label}</h2>
                                <p className="mb-3 text-sm text-ink-soft">{c.condition}</p>
                                <DoseForm c={c} routes={routes} medicines={medicines} suggest={suggest} onDone={() => setMode('none')} />
                            </div>
                        </Sheet>
                    ) : null}
                    {mode === 'status' ? (
                        <Sheet onClose={() => setMode('none')}>
                            <div className="p-4 sm:p-5">
                                <h2 className="text-lg font-bold text-cobalt-900">Update case · {c.number} · {c.label}</h2>
                                <p className="mb-3 text-sm text-ink-soft">{c.condition}</p>
                                <StatusForm c={c} statuses={statuses} onDone={() => setMode('none')} />
                            </div>
                        </Sheet>
                    ) : null}
                </div>
            ) : null}
        </section>
    );
}

function DoseForm({ c, routes, medicines, suggest, onDone }: { c: Case; routes: Record<string, string>; medicines: Medicine[]; suggest: Suggest; onDone: () => void }) {
    const { frequencies = {} } = usePage<{ frequencies?: Record<string, string> }>().props; // D-304
    const form = useForm({ medicine_id: '', qty_used: '', qty_per_dose: '', review_on: '', given_on: today(), given_time: new Date().toTimeString().slice(0, 5), product: '', dose: '', route: 'injectable', frequency: 'once', days_prescribed: '', next_dose_on: '', meat_clear_on: '', milk_clear_on: '', no_withdrawal: false, notes: '' });
    const e = form.errors as Record<string, string>;
    // D-369: choosing a medicine fills its standard dose (set by the vet on the medicine); every field stays editable
    const chosen = medicines.find((m) => String(m.id) === form.data.medicine_id);
    const pick = (id: string) => form.setData((d) => ({ ...d, medicine_id: id, ...doseFill(medicines.find((m) => String(m.id) === id), d.given_on) }));
    return (
        <form onSubmit={(ev) => { ev.preventDefault(); form.post(`/health/cases/${c.id}/treatments`, { preserveScroll: true, onSuccess: onDone }); }} className="grid gap-3 rounded-tile border border-rule p-3 sm:grid-cols-2">
            <Field label="From the medicine store" error={e.medicine_id} hint="Issues it from stock, earliest expiry first">
                <MedicinePicker medicines={medicines} suggest={suggest} diseaseId={c.diseaseId} value={form.data.medicine_id} onChange={pick} />
            </Field>
            {chosen && hasDose(chosen) ? <p className="rounded-md bg-glaze px-2.5 py-1.5 text-xs text-ink-soft sm:col-span-2">Filled from the standard dose set for {chosen.name}. Check each field against the label.</p> : null}
            {form.data.medicine_id ? (
                <Field label={`Quantity used (${doseUnit(medicines.find((m) => String(m.id) === form.data.medicine_id)?.unit)})`} error={e.qty_used}><input type="number" inputMode="decimal" min={0} step="0.1" className="field" value={form.data.qty_used} onChange={(x) => form.setData('qty_used', x.target.value)} /></Field>
            ) : (
                <Field label="Medicine / product" error={e.product}><input className="field" value={form.data.product} onChange={(x) => form.setData('product', x.target.value)} /></Field>
            )}
            <Field label="Dose + unit" error={e.dose} hint="e.g. 5 ml, 2 tablets"><input className="field" value={form.data.dose} onChange={(x) => form.setData('dose', x.target.value)} /></Field>
            <Field label="Route" error={e.route}><select className="field" value={form.data.route} onChange={(x) => form.setData('route', x.target.value)}>{Object.entries(routes).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            <Field label="Date given" error={e.given_on}><input type="date" className="field" max={today()} value={form.data.given_on} onChange={(x) => form.setData('given_on', x.target.value)} /></Field>
            <Field label="Dose time" error={e.given_time} hint="Now; change it if given earlier"><input type="time" className="field" required value={form.data.given_time} onChange={(x) => form.setData('given_time', x.target.value)} /></Field>
            <Field label="How often" error={e.frequency} hint="Every dose is planned; the first is this one"><select className="field" value={form.data.frequency} onChange={(x) => form.setData('frequency', x.target.value)}>{Object.entries(frequencies).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            {form.data.frequency !== 'once' ? <Field label="For how many days" error={e.days_prescribed}><input type="number" min={1} className="field" value={form.data.days_prescribed} onChange={(x) => form.setData('days_prescribed', x.target.value)} required /></Field> : null}
            {form.data.medicine_id && form.data.frequency !== 'once' ? <Field label="Amount per later dose" error={e.qty_per_dose} hint="Taken from stock each time (blank = same as this one)"><input type="number" min={0} step="0.1" className="field" value={form.data.qty_per_dose} onChange={(x) => form.setData('qty_per_dose', x.target.value)} /></Field> : null}
            <Field label="Review date (optional)" error={e.review_on} hint="The vet re-checks the goat"><input type="date" className="field" min={form.data.given_on} value={form.data.review_on} onChange={(x) => form.setData('review_on', x.target.value)} /></Field>
            <div className="rounded-tile bg-watch-100 p-3 sm:col-span-2">
                <p className="mb-2 text-sm font-semibold text-watch-700">Withdrawal: from the product label, decided by you. The app never calculates it.</p>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Meat clear date" error={e.meat_clear_on}><input type="date" className="field" disabled={form.data.no_withdrawal} value={form.data.meat_clear_on} onChange={(x) => form.setData('meat_clear_on', x.target.value)} /></Field>
                    <Field label="Milk clear date" error={e.milk_clear_on}><input type="date" className="field" disabled={form.data.no_withdrawal} value={form.data.milk_clear_on} onChange={(x) => form.setData('milk_clear_on', x.target.value)} /></Field>
                </div>
                <label className="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" className="size-4" checked={form.data.no_withdrawal} onChange={(x) => form.setData((d) => ({ ...d, no_withdrawal: x.target.checked, meat_clear_on: '', milk_clear_on: '' }))} /> No withdrawal for this product</label>
            </div>
            <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
            <div className="flex items-end gap-2"><Button type="submit" disabled={form.processing}>Save treatment</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}

function StatusForm({ c, statuses, onDone }: { c: Case; statuses: Record<string, string>; onDone: () => void }) {
    const form = useForm({ status: c.status, isolated: c.isolated, diagnosis: c.diagnosis ?? '', notes: c.notes ?? '' });
    return (
        <form onSubmit={(ev) => { ev.preventDefault(); form.post(`/health/cases/${c.id}`, { preserveScroll: true, onSuccess: onDone }); }} className="grid gap-3 rounded-tile border border-rule p-3 sm:grid-cols-2">
            <Field label="Case status" error={form.errors.status}><select className="field" value={form.data.status} onChange={(x) => form.setData('status', x.target.value)}>{Object.entries(statuses).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            <label className="flex items-center gap-2 pt-6 text-sm"><input type="checkbox" className="size-4" checked={form.data.isolated} onChange={(x) => form.setData('isolated', x.target.checked)} /> Isolated from the herd</label>
            <Field label="Diagnosis / test" error={form.errors.diagnosis}><textarea rows={2} className="field" value={form.data.diagnosis} onChange={(x) => form.setData('diagnosis', x.target.value)} /></Field>
            <Field label="Notes" error={form.errors.notes}><textarea rows={2} className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
            <div className="flex gap-2 sm:col-span-2"><Button type="submit" disabled={form.processing}>Save</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}

function CaseForm({ goats, severities, report, onDone }: { goats: Option[]; severities: Record<string, string>; report: Row | null; onDone: () => void }) {
    const form = useForm({ animal_id: report?.goatId ?? String(goats[0]?.id ?? ''), onset_on: today(), condition: report?.title ?? '', symptoms: report?.notes ?? '', severity: 'moderate', isolated: false, diagnosis: '', notes: '', report_id: report ? String(report.id) : '' });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title={report ? `Open a case for ${report.number}` : 'Open a health case'} description={report ? `From the concern "${report.title}" (${report.by})` : 'Vet or administrator'}>
            <form onSubmit={(ev) => { ev.preventDefault(); form.post('/health/cases', { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } }); }} className="space-y-3">
                {!report ? <Field label="Goat" error={e.animal_id}><GoatSelect className="field" value={form.data.animal_id} onChange={(x) => form.setData('animal_id', x.target.value)}>{goats.map((g) => <option key={g.id} value={g.id}>{label(g)}</option>)}</GoatSelect></Field> : null}
                <Field label="Disease / condition" error={e.condition}><input className="field" value={form.data.condition} onChange={(x) => form.setData('condition', x.target.value)} /></Field>
                <Field label="Symptoms" error={e.symptoms}><textarea rows={2} className="field" value={form.data.symptoms} onChange={(x) => form.setData('symptoms', x.target.value)} /></Field>
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Severity" error={e.severity}><select className="field" value={form.data.severity} onChange={(x) => form.setData('severity', x.target.value)}>{Object.entries(severities).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Field label="Onset" error={e.onset_on}><input type="date" className="field" max={today()} value={form.data.onset_on} onChange={(x) => form.setData('onset_on', x.target.value)} /></Field>
                </div>
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="size-4" checked={form.data.isolated} onChange={(x) => form.setData('isolated', x.target.checked)} /> Isolated from the herd</label>
                <Field label="Diagnosis / test" error={e.diagnosis}><input className="field" value={form.data.diagnosis} onChange={(x) => form.setData('diagnosis', x.target.value)} /></Field>
                <div className="flex gap-2"><Button type="submit" className="flex-1" disabled={form.processing}>Open case</Button>{report ? <Button type="button" variant="secondary" onClick={onDone}>Cancel</Button> : null}</div>
            </form>
        </Panel>
    );
}

function ObservationForm({ goats, onDone }: { goats: Option[]; onDone?: () => void }) {
    const form = useForm({ animal_id: String(goats[0]?.id ?? ''), title: '', severity: 'low', notes: '' });
    return (
        <Panel title="Report a concern" description="Anyone can report. A veterinarian decides what it is.">
            <form onSubmit={(e) => { e.preventDefault(); form.post('/health/observations', { preserveScroll: true, onSuccess: () => { form.reset('title', 'notes'); onDone?.(); } }); }} className="space-y-3">
                <Field label="Goat" error={form.errors.animal_id}><GoatSelect className="field" value={form.data.animal_id} onChange={(e) => form.setData('animal_id', e.target.value)}>{goats.map((g) => <option key={g.id} value={g.id}>{label(g)}</option>)}</GoatSelect></Field>
                <Field label="What did you notice?" error={form.errors.title}><input className="field" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} /></Field>
                <Field label="How serious?"><select className="field" value={form.data.severity} onChange={(e) => form.setData('severity', e.target.value)}><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select></Field>
                <Button type="submit" className="w-full" disabled={form.processing}>Send to vet</Button>
            </form>
        </Panel>
    );
}

/**
 * Vaccination, deworming and routine examinations (workbook "Preventive Care"). D-333: the product comes from the
 * medicine store, the ones that fit the type first (dewormers for deworming, vaccines for vaccination), and giving it
 * takes the dose from stock; a product not in the store can still be typed.
 */
function PreventiveForm({ goats, medicines, suggest, onDone }: { goats: Option[]; medicines: Medicine[]; suggest: Suggest; onDone?: () => void }) {
    const form = useForm({ animal_id: String(goats[0]?.id ?? ''), kind: 'vaccination', medicine_id: '', qty_per_goat: '', title: '', batch_lot: '', notes: '', next_due_on: '' });
    const e = form.errors as Record<string, string>;
    const med = medicines.find((m) => String(m.id) === form.data.medicine_id);
    const submit = (ev: React.FormEvent) => {
        ev.preventDefault();
        form.transform((d) => ({ animal_ids: [d.animal_id], kind: d.kind, medicine_id: d.medicine_id || null, qty_per_goat: d.medicine_id ? d.qty_per_goat : null, title: d.title || null,
            batch_lot: d.batch_lot || null, notes: d.notes || null, next_due_on: d.next_due_on || null, given_on: today(), care_status: 'administered' }));
        form.post('/health/preventive', { preserveScroll: true, onSuccess: () => { form.reset('medicine_id', 'qty_per_goat', 'title', 'batch_lot', 'notes', 'next_due_on'); onDone?.(); } });
    };
    return (
        <Panel title="Preventive care" description="Vaccines, deworming, routine checks">
            <form onSubmit={submit} className="space-y-3">
                <Field label="Goat" error={e.animal_ids ?? e['animal_ids.0']}><GoatSelect className="field" value={form.data.animal_id} onChange={(x) => form.setData('animal_id', x.target.value)}>{goats.map((g) => <option key={g.id} value={g.id}>{label(g)}</option>)}</GoatSelect></Field>
                <Field label="Type"><select className="field" value={form.data.kind} onChange={(x) => form.setData((d) => ({ ...d, kind: x.target.value, medicine_id: '' }))}><option value="vaccination">Vaccination</option><option value="deworming">Deworming</option><option value="hoof_care">Hoof care</option><option value="examination">Routine examination</option></select></Field>
                {form.data.kind !== 'examination' ? (
                    <Field label="Product from the medicine store" error={e.medicine_id} hint="Giving it takes the dose from stock, earliest expiry first">
                        <MedicinePicker medicines={medicines} suggest={suggest} care={form.data.kind} value={form.data.medicine_id} onChange={(id) => form.setData('medicine_id', id)} />
                    </Field>
                ) : null}
                {med ? <Field label={`Quantity per goat (${med.unit})`} error={e.qty_per_goat}><input type="number" inputMode="decimal" min={0} step="0.1" className="field" value={form.data.qty_per_goat} onChange={(x) => form.setData('qty_per_goat', x.target.value)} /></Field>
                    : <Field label={form.data.kind === 'examination' ? 'Finding' : 'Product (not in the store)'} error={e.title}><input className="field" value={form.data.title} onChange={(x) => form.setData('title', x.target.value)} /></Field>}
                {form.data.kind === 'vaccination' ? <Field label="Batch / lot (optional)" error={e.batch_lot}><input className="field font-mono" value={form.data.batch_lot} onChange={(x) => form.setData('batch_lot', x.target.value)} /></Field> : null}
                <Field label="Next due" error={e.next_due_on}><input type="date" className="field" value={form.data.next_due_on} onChange={(x) => form.setData('next_due_on', x.target.value)} /></Field>
                <Button type="submit" className="w-full" disabled={form.processing}>Save</Button>
            </form>
        </Panel>
    );
}

/** D-074: medicine store (workbook "Medicine Stock" and "Medicine Movements"). */
function MedicineStore({ medicines, medicineMoves, medicineCategories, medicineUnits, medicinePacks, medicineChecks, medicineTypes, canMedicine, suggest }: Props) {
    const [open, setOpen] = useState<number | null>(null);
    const [moves, setMoves] = useState(false);
    const [adding, setAdding] = useState<'' | 'medicine' | 'stock'>(''); // D-127
    return (
        <div className="space-y-4">
            {adding === 'medicine' ? <Sheet onClose={() => setAdding('')}><MedicineAdd categories={medicineCategories} units={medicineUnits} packs={medicinePacks} checks={medicineChecks} diseases={suggest.diseases} onDone={() => setAdding('')} /></Sheet> : null}
            {adding === 'stock' && medicines.length ? <Sheet onClose={() => setAdding('')}><MedicineMoveForm medicines={medicines} types={medicineTypes} onDone={() => setAdding('')} /></Sheet> : null}
            <Panel title="Medicines in store" description="Quantity comes from receipts and issues. Treatments take the batch that expires first." flush actions={canMedicine ? <>
                {medicines.length ? <AddButton primary={false} label="Stock in / out" onClick={() => setAdding('stock')} /> : null}
                <AddButton label="Add a medicine" onClick={() => setAdding('medicine')} />
            </> : null}>
                {medicines.length ? (
                    <ul className="divide-y divide-rule">
                        {medicines.map((m) => (
                            <li key={m.id} className={cx('px-4 py-2.5', !m.active && 'opacity-60')}>
                                <button type="button" onClick={() => setOpen(open === m.id ? null : m.id)} className="flex w-full flex-wrap items-center gap-2 text-left" aria-expanded={open === m.id}>
                                    <span className="font-semibold text-cobalt-900">{m.name}</span>
                                    <span className="font-mono text-xs text-ink-soft">{m.code}</span>
                                    <span className="text-sm text-ink-soft">{medicineCategories[m.category] ?? m.category}</span>
                                    <span className="ml-auto flex items-center gap-2"><span className="font-semibold">{m.qty} {m.unit}</span><Chip className={MED_STATUS[m.status].className}>{MED_STATUS[m.status].label}</Chip><ChevronDown size={15} className={cx('text-ink-faint', open === m.id && 'rotate-180')} aria-hidden /></span>
                                </button>
                                {m.spec || m.useCase ? <p className="text-sm text-ink-soft">{[m.spec, m.useCase].filter(Boolean).join(' · ')}</p> : null}
                                {m.check && m.check !== 'confirmed' ? <p className="flex flex-wrap items-center gap-2 text-sm text-watch-700">{medicineChecks[m.check] ?? m.check}{m.checkNote ? `: ${m.checkNote}` : ''}{canMedicine ? <button type="button" onClick={() => { if (window.confirm(`Confirm ${m.name}? It can then be given to goats.`)) router.post(`/inventory/medicines/${m.id}/confirm`, {}, { preserveScroll: true }); }} className="inline-flex min-h-9 items-center rounded-md bg-ok-700 px-2.5 text-sm text-white hover:opacity-90">Confirm</button> : null}</p> : null}
                                <p className="text-xs text-ink-soft">Reorder at {m.reorder} {m.unit}{m.packUnit && m.packSize ? ` · ${medicinePacks[m.packUnit] ?? m.packUnit} of ${m.packSize} ${m.unit}` : ''} · PKR {m.costPerUnit}/{m.unit} · worth PKR {m.value.toLocaleString('en-PK')}{m.storage ? ` · ${m.storage}` : ''}{m.supplier ? ` · ${m.supplier}` : ''}</p>
                                {open === m.id ? (<Sheet onClose={() => setOpen(null)} title={m.name}>
                                    <div className="mt-2 rounded-tile bg-glaze p-2 text-sm">
                                        {m.batches.length ? (
                                            <table className="w-full"><thead className="text-left text-ink-soft"><tr><th className="py-1 font-semibold">Batch / Lot</th><th className="font-semibold">Expiry</th><th className="text-right font-semibold">In Stock</th></tr></thead>
                                                <tbody>{m.batches.map((b) => <tr key={b.batch}><td className="py-1 font-mono">{b.batch}</td><td className={b.expired ? 'font-semibold text-crit-700' : ''}>{b.expiry ?? '—'}{b.expired ? ' (expired)' : ''}</td><td className="text-right">{b.qty} {m.unit}</td></tr>)}</tbody></table>
                                        ) : <p className="text-ink-soft">No batch numbers recorded.</p>}
                                        {canMedicine ? <MedicineEdit m={m} categories={medicineCategories} units={medicineUnits} packs={medicinePacks} checks={medicineChecks} diseases={suggest.diseases} /> : null}
                                    </div></Sheet>
                                ) : null}
                            </li>
                        ))}
                    </ul>
                ) : <Empty title="No medicines yet">{canMedicine ? 'Add the medicines you keep, with the batch and expiry of what is on the shelf.' : 'The owner, manager or vet adds medicines.'}</Empty>}
            </Panel>
            <Panel title="Stock movements" flush>
                {medicineMoves.length ? (
                    <ul className="divide-y divide-rule text-sm">
                        {(moves ? medicineMoves : medicineMoves.slice(0, 5)).map((mv) => (
                            <li key={mv.id} className="flex flex-wrap gap-x-2 px-4 py-2">
                                <span className="w-14 text-ink-faint">{mv.on}</span><span className="font-medium">{mv.medicine}</span><span className="text-ink-soft">{mv.type}</span>
                                <span className={cx('ml-auto font-semibold', mv.qty < 0 ? 'text-crit-700' : 'text-ok-700')}>{mv.qty > 0 ? '+' : ''}{mv.qty} {mv.unit}</span>
                                <span className="w-full text-ink-faint">{[mv.batch ? `batch ${mv.batch}` : null, mv.expiry ? `expires ${mv.expiry}` : null, mv.party, mv.by].filter(Boolean).join(' · ')}</span>
                            </li>
                        ))}
                        {medicineMoves.length > 5 ? <li><button type="button" onClick={() => setMoves(!moves)} className="w-full px-4 py-2 text-sm font-semibold text-cobalt-800 hover:bg-glaze">{moves ? 'Show less' : `Show ${medicineMoves.length - 5} more`}</button></li> : null}
                    </ul>
                ) : <p className="p-4 text-sm text-ink-soft">Receipts, disposals and treatments appear here.</p>}
            </Panel>
        </div>
    );
}

