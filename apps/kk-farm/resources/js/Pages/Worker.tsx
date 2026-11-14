import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeftRight, ClipboardCheck, Ruler, Smile, Flame, HeartPulse, Milk, Radio, ScanSearch, Scale, Square, Syringe, Thermometer, Venus, Wheat } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { EarTag, GoatFigure } from '../components/FarmScene';
import { AgeBadge, BreedBadge } from '../components/GoatBadges';
import { Button, Field } from '../components/ui';

type Job = 'weigh' | 'temp' | 'health' | 'heat' | 'mating' | 'milk' | 'care' | 'teeth' | 'height' | 'move' | 'lookup';
type Opts = { on?: string; reason?: string; sire_id?: string; sire?: string | null; method?: string; session?: string; care_kind?: string; care_title?: string; medicine_id?: string; medicine?: string | null; qty_per_goat?: string };
type Options = { teeth?: Record<string, string>; teethConditions?: Record<string, string>; bucks: Array<{ id: string; name: string }>; methods: Record<string, string>; sessions: Record<string, string>; careKinds: Record<string, string>; medicines: Array<{ id: number; name: string; unit: string }>; intensity: Record<string, string>; signs: string[] };
type Goat = { ageCode?: string | null; roles?: string[];
    id: string; number: string; name: string | null; sex: string; breed?: string; stage?: string | null; pen?: string | null; age?: string | null; health: string;
    lastWeight: number | null; lastWeighed: string | null; penId: number | null; withdrawal: { meat: string | null; milk: string | null } | null;
};
type Props = {
    jobs: Partial<Record<Job, string>>; job: { job: Job; label: string; penId: number | null; pen: string | null; done: number; opts: Opts } | null; goat: Goat | null; unknown: string | null;
    pens: Array<{ id: number; name: string }>; can: { feed: boolean; tasks: boolean }; myTasks: number; options: Options;
};

const ICON: Record<Job, ReactNode> = { weigh: <Scale size={30} />, temp: <Thermometer size={30} />, health: <HeartPulse size={30} />, heat: <Flame size={30} />, mating: <Venus size={30} />, milk: <Milk size={30} />, care: <Syringe size={30} />, teeth: <Smile size={30} />, height: <Ruler size={30} />, move: <ArrowLeftRight size={30} />, lookup: <ScanSearch size={30} /> };
const HINT: Record<Job, string> = {
    weigh: 'Scan, enter the weight, next goat', temp: 'Scan, enter the temperature, next goat', health: 'Scan, mark fine or report a concern', heat: 'Scan a doe in heat, mark the signs',
    mating: 'Choose the buck, then scan each doe', milk: 'Choose the session, then scan each doe', care: 'Choose the vaccine or dewormer, then scan each goat',
    teeth: 'Scan, pick the teeth stage and condition, next goat', height: 'Scan, enter the height in inches, next goat',
    move: 'Choose a pen, then scan each goat', lookup: 'Scan to see a goat',
};
/** D-255: jobs that need something chosen once for the whole round */
const SETUP: Job[] = ['weigh', 'temp', 'health', 'heat', 'mating', 'milk', 'care', 'teeth', 'height', 'move']; // every job but Look Up asks its date first (D-255)
const CONCERNS = ['Not eating', 'Limping', 'Coughing', 'Diarrhoea', 'Fever / hot', 'Weak / dull', 'Wound', 'Nasal discharge', 'Swollen udder', 'Other'];

/**
 * D-179: the worker app for the RFID gun: big buttons, one job at a time. Pick a job, then scan goat after goat;
 * each scan opens that goat straight in the job's quick form. Typing a tag works too (or a gun set to type tags).
 */
export default function Worker({ jobs, job, goat, unknown, pens, can, myTasks, options }: Props) {
    const [picking, setPicking] = useState<Job | null>(null);

    if (!job && picking) { // D-255: the job's setup takes the screen, so it is in view on the gun
        return (
            <div className="mx-auto max-w-xl space-y-4">
                <Head title={jobs[picking] ?? 'Tasks'} />
                <h1 className="flex items-center gap-3 text-2xl font-extrabold text-cobalt-900"><span className="kk-icon-tile size-12!" aria-hidden>{ICON[picking]}</span>{jobs[picking]}</h1>
                <Setup job={picking} pens={pens} options={options} onBack={() => setPicking(null)} />
            </div>
        );
    }
    if (!job) {
        return (
            <div className="mx-auto max-w-xl space-y-4">
                <Head title="Tasks" />
                <h1 className="text-2xl font-extrabold text-cobalt-900">What are you doing now?</h1>
                <div className="grid grid-cols-2 gap-3">
                    {(Object.keys(jobs) as Job[]).map((j) => (
                        <button key={j} type="button" onClick={() => (SETUP.includes(j) ? setPicking(j) : router.post('/worker/start', { job: j }))}
                            className="kk-sign kk-lift min-h-32 flex-col items-start! p-4! text-left">
                            <span className="kk-icon-tile size-14!" aria-hidden>{ICON[j]}</span>
                            <span className="block text-lg font-extrabold text-cobalt-900">{jobs[j]}</span>
                            <span className="block text-sm text-ink-soft">{HINT[j]}</span>
                        </button>
                    ))}
                    {can.feed ? <Link href="/feeding?tab=now" className="kk-sign kk-lift min-h-32 flex-col items-start! p-4!"><span className="kk-icon-tile size-14!" aria-hidden><Wheat size={30} /></span><span className="block text-lg font-extrabold text-cobalt-900">Feed Pens</span><span className="block text-sm text-ink-soft">Record feed given and leftover</span></Link> : null}
                    {can.tasks ? <Link href="/tasks?tab=mine" className="kk-sign kk-lift min-h-32 flex-col items-start! p-4!"><span className="kk-icon-tile size-14!" aria-hidden><ClipboardCheck size={30} /></span><span className="block text-lg font-extrabold text-cobalt-900">My Tasks{myTasks ? ` · ${myTasks}` : ''}</span><span className="block text-sm text-ink-soft">Due today or overdue</span></Link> : null}
                </div>
            </div>
        );
    }

    return (
        <div className="mx-auto max-w-xl space-y-4">
            <Head title={job.label} />
            <div className="flex items-center gap-3 rounded-tile bg-cobalt-800 px-4 py-3 text-white shadow-tile">
                <span className="grid size-12 place-items-center rounded-xl bg-white/15" aria-hidden>{ICON[job.job]}</span>
                <span className="min-w-0 flex-1"><span className="block text-lg font-extrabold">{job.label}{job.pen ? ` → ${job.pen}` : ''}</span>{summary(job, options) ? <span className="block text-sm text-white/80">{summary(job, options)}</span> : null}<span className="block text-sm text-white/80">{job.done} goat{job.done === 1 ? '' : 's'} done</span></span>
                <button type="button" onClick={() => router.post('/worker/stop')} className="inline-flex min-h-11 items-center gap-1.5 rounded-full bg-white/15 px-4 text-sm font-bold hover:bg-white/25"><Square size={14} aria-hidden />Finish</button>
            </div>
            {unknown ? <p className="rounded-tile bg-watch-100 px-3 py-2 font-semibold text-watch-700" role="alert">No goat found for {unknown}. Check the goat number, or if it is a chip, link it in Goat Registry → Link RFID, then scan again.</p> : null}
            {goat ? <GoatJob job={job} goat={goat} options={options} /> : <ScanPrompt />}
        </div>
    );
}

function ScanPrompt() {
    const [tag, setTag] = useState('');
    const input = useRef<HTMLInputElement>(null);
    useEffect(() => { input.current?.focus(); }, []);
    return (
        <div className="space-y-4 rounded-tile border-2 border-dashed border-turq-600 bg-tile p-5 text-center shadow-tile">
            <span className="relative mx-auto grid size-20 place-items-center rounded-full bg-turq-700 text-white"><Radio size={36} aria-hidden /><span className="kk-ring absolute inset-0 rounded-full border-2 border-turq-600" aria-hidden /></span>
            <p className="text-lg font-extrabold text-cobalt-900">Pull the trigger on the next goat</p>
            <form onSubmit={(e) => { e.preventDefault(); if (tag.trim()) router.visit(`/scan?tag=${encodeURIComponent(tag.trim())}`); }} className="flex gap-2">
                <input ref={input} className="field min-h-12 flex-1 font-mono uppercase" value={tag} onChange={(e) => setTag(e.target.value)} placeholder="Or type the RFID / tag code" autoCapitalize="characters" autoComplete="off" spellCheck={false} />
                <Button type="submit" className="min-h-12">Find</Button>
            </form>
        </div>
    );
}

/** D-255: the job's own choice, shown in the header while scanning */
function summary(job: NonNullable<Props['job']>, o: Options): string {
    const x = job.opts ?? {};
    const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const date = x.on && x.on !== today ? `For ${new Date(`${x.on}T12:00:00`).toLocaleDateString('en-GB', { day: '2-digit', month: '2-digit' })} · ` : '';
    return date + body(job, o, x);
}
function body(job: NonNullable<Props['job']>, o: Options, x: Opts): string {
    if (job.job === 'move') return x.reason ? `Reason: ${x.reason}` : '';
    if (job.job === 'mating') return `${o.methods[x.method ?? ''] ?? ''}${x.sire ? ` with ${x.sire}` : ''}`;
    if (job.job === 'milk') return `${o.sessions[x.session ?? ''] ?? ''} milking`;
    if (job.job === 'care') return `${o.careKinds[x.care_kind ?? ''] ?? ''}: ${x.medicine ?? x.care_title ?? ''}${x.qty_per_goat ? ` · ${x.qty_per_goat} per goat` : ''}`;
    return '';
}

/** D-255: what a job needs before scanning starts (pen and reason, buck and method, session, vaccine or dewormer). */
function Setup({ job, pens, options, onBack }: { job: Job; pens: Props['pens']; options: Options; onBack: () => void }) {
    const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const f = useForm({ job, on: today, pen_id: String(pens[0]?.id ?? ''), reason: '', sire_id: '', method: 'natural', session: 'morning', care_kind: 'vaccination', medicine_id: '', qty_per_goat: '', care_title: '' });
    const e = f.errors as Record<string, string>;
    const big = 'field min-h-12 text-base';
    return (
        <form onSubmit={(ev) => { ev.preventDefault(); f.post('/worker/start'); }} className="space-y-3 rounded-tile border border-rule bg-tile p-4 shadow-tile">
            <Field label="Date" error={e.on} hint="Today unless you are entering an earlier round"><input type="date" className={big} max={today} value={f.data.on} onChange={(x) => f.setData('on', x.target.value)} /></Field>
            {job === 'move' ? <>
                <Field label="Move goats to which pen?" error={e.pen_id}><select className={big} value={f.data.pen_id} onChange={(x) => f.setData('pen_id', x.target.value)}>{pens.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
                <Field label="Reason" error={e.reason}><input className={big} list="w-reasons" required value={f.data.reason} onChange={(x) => f.setData('reason', x.target.value)} placeholder="Why are they moved?" /><datalist id="w-reasons">{['Weaning', 'Breeding group', 'Pregnant does group', 'Kidding / maternity', 'Sick: isolation', 'Back from isolation', 'Feeding group change', 'Fattening batch', 'Sale preparation', 'Pen cleaning or repair', 'Overcrowding'].map((r) => <option key={r} value={r} />)}</datalist></Field>
            </> : null}
            {job === 'mating' ? <>
                <Field label="Buck" error={e.sire_id} hint="Leave empty if the buck is not known (pasture)"><select className={big} value={f.data.sire_id} onChange={(x) => f.setData('sire_id', x.target.value)}><option value="">Not known</option>{options.bucks.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select></Field>
                <Field label="Method" error={e.method}><select className={big} value={f.data.method} onChange={(x) => f.setData('method', x.target.value)}>{Object.entries(options.methods).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            </> : null}
            {job === 'milk' ? (
                <Field label="Session" error={e.session}><div className="grid grid-cols-3 gap-2">{Object.entries(options.sessions).map(([k, v]) => <button key={k} type="button" onClick={() => f.setData('session', k)} className={`min-h-12 rounded-tile border font-bold ${f.data.session === k ? 'border-cobalt-800 bg-cobalt-800 text-white' : 'border-rule bg-tile'}`}>{v}</button>)}</div></Field>
            ) : null}
            {job === 'care' ? <>
                <Field label="What is given" error={e.care_kind}><select className={big} value={f.data.care_kind} onChange={(x) => f.setData('care_kind', x.target.value)}>{Object.entries(options.careKinds).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="From the medicine store" error={e.medicine_id}><select className={big} value={f.data.medicine_id} onChange={(x) => f.setData('medicine_id', x.target.value)}><option value="">Not from the store</option>{options.medicines.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}</select></Field>
                {f.data.medicine_id ? <Field label={`Dose per goat (${options.medicines.find((m) => String(m.id) === f.data.medicine_id)?.unit ?? ''})`} error={e.qty_per_goat}><input className={big} inputMode="decimal" required value={f.data.qty_per_goat} onChange={(x) => f.setData('qty_per_goat', x.target.value.replace(/[^0-9.]/g, ''))} /></Field>
                    : <Field label="Name (e.g. PPR vaccine)" error={e.care_title}><input className={big} value={f.data.care_title} onChange={(x) => f.setData('care_title', x.target.value)} /></Field>}
            </> : null}
            <div className="flex gap-2"><Button type="submit" className="min-h-12 flex-1" disabled={f.processing}>Start</Button><Button type="button" variant="secondary" className="min-h-12" onClick={onBack}>Back</Button></div>
        </form>
    );
}

function GoatJob({ job, goat, options }: { job: NonNullable<Props['job']>; goat: Goat; options: Options }) {
    const form = useForm({ animal_id: goat.id, weight_kg: '', body_score: '', fine: true, title: '', severity: 'low', notes: '', pen_id: job.penId ? String(job.penId) : '' });
    const e = form.errors as Record<string, string>;
    const blocked = goat.withdrawal && (goat.withdrawal.meat || goat.withdrawal.milk);
    return (
        <div className="space-y-4 rounded-tile border border-rule bg-tile p-4 shadow-tile">
            <div className="flex items-center gap-3">
                <span className="kk-worker-goat"><GoatFigure female={goat.sex === 'female'} flag={goat.health !== 'healthy'} delay={0} flip={false} stage={goat.stage} breed={goat.breed} /></span>
                <span className="min-w-0 flex-1 space-y-1">
                    <span className="flex flex-wrap items-center gap-1.5"><EarTag number={goat.number} />{goat.name ? <span className="font-semibold">{goat.name}</span> : null}</span>
                    <span className="flex flex-wrap items-center gap-1.5 text-sm text-ink-soft"><BreedBadge breed={goat.breed} withName /><AgeBadge stage={goat.stage} code={goat.ageCode} withLabel />{goat.roles?.length ? <span className="font-mono text-xs font-semibold text-cobalt-800">{goat.roles.join(" · ")}</span> : null}{goat.pen ? <span className="font-mono text-xs">{goat.pen}</span> : null}</span>
                </span>
            </div>
            {blocked ? <p className="rounded-tile bg-crit-100 px-3 py-2 text-sm font-semibold text-crit-700">Withdrawal: {goat.withdrawal?.meat ? `no meat until ${goat.withdrawal.meat}` : ''}{goat.withdrawal?.meat && goat.withdrawal?.milk ? ' · ' : ''}{goat.withdrawal?.milk ? `no milk until ${goat.withdrawal.milk}` : ''}</p> : null}

            {job.job === 'weigh' ? (
                <form onSubmit={(ev) => { ev.preventDefault(); form.post('/worker/weigh'); }} className="space-y-3">
                    {goat.lastWeight ? <p className="text-sm text-ink-soft">Last: <span className="font-bold text-cobalt-900">{goat.lastWeight} KG</span> on {goat.lastWeighed}</p> : null}
                    <Field label="Weight KG" error={e.weight_kg}><input className="field min-h-14 text-2xl font-bold" type="number" inputMode="decimal" step="0.1" min={0.5} max={200} autoFocus value={form.data.weight_kg} onChange={(x) => form.setData('weight_kg', x.target.value)} required /></Field>
                    <div className="flex flex-wrap gap-2" role="group" aria-label="Body condition (optional)">
                        <span className="w-full text-sm font-semibold">Body condition (optional)</span>
                        {[1, 2, 3, 4, 5].map((n) => <button key={n} type="button" onClick={() => form.setData('body_score', form.data.body_score === String(n) ? '' : String(n))} className={`min-h-12 flex-1 rounded-tile border text-lg font-bold ${form.data.body_score === String(n) ? 'border-cobalt-800 bg-cobalt-800 text-white' : 'border-rule bg-tile'}`}>{n}</button>)}
                    </div>
                    <Button type="submit" className="min-h-14 w-full text-lg" disabled={form.processing || !form.data.weight_kg}>Save Weight</Button>
                </form>
            ) : null}

            {job.job === 'health' ? (
                <div className="space-y-3">
                    <Button type="button" className="min-h-14 w-full bg-ok-700! text-lg" disabled={form.processing} onClick={() => { form.transform((d) => ({ animal_id: d.animal_id, fine: true })); form.post('/worker/health'); }}>Looks Fine</Button>
                    <p className="text-center text-sm font-semibold text-ink-soft">or report a concern</p>
                    <div className="grid grid-cols-2 gap-2">
                        {CONCERNS.map((c) => <button key={c} type="button" onClick={() => form.setData('title', c)} className={`min-h-12 rounded-tile border px-2 text-sm font-semibold ${form.data.title === c ? 'border-crit-700 bg-crit-100 text-crit-700' : 'border-rule bg-tile'}`}>{c}</button>)}
                    </div>
                    {e.title ? <p className="text-sm text-crit-700">{e.title}</p> : null}
                    <div className="grid grid-cols-3 gap-2" role="group" aria-label="How serious">
                        {(['low', 'medium', 'high'] as const).map((s) => <button key={s} type="button" onClick={() => form.setData('severity', s)} className={`min-h-12 rounded-tile border font-bold capitalize ${form.data.severity === s ? 'border-cobalt-800 bg-cobalt-800 text-white' : 'border-rule bg-tile'}`}>{s}</button>)}
                    </div>
                    <input className="field min-h-12" placeholder="Notes (optional)" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} />
                    <Button type="button" className="min-h-14 w-full bg-crit-700! text-lg" disabled={form.processing || !form.data.title} onClick={() => { form.transform((d) => ({ animal_id: d.animal_id, fine: false, title: d.title, severity: d.severity, notes: d.notes })); form.post('/worker/health'); }}>Send to Vet</Button>
                </div>
            ) : null}

            {job.job === 'move' ? (
                <div className="space-y-2">
                    {goat.penId === job.penId ? <p className="rounded-tile bg-glaze px-3 py-2 text-sm font-semibold">Already in {job.pen}.</p> : null}
                    <Button type="button" className="min-h-14 w-full text-lg" disabled={form.processing || goat.penId === job.penId} onClick={() => { form.transform((d) => ({ animal_id: d.animal_id, pen_id: job.penId })); form.post('/worker/move'); }}>Move to {job.pen}</Button>
                </div>
            ) : null}

            {['temp', 'heat', 'mating', 'milk', 'care', 'teeth', 'height'].includes(job.job) ? <QuickJob job={job} goat={goat} options={options} /> : null}

            {job.job === 'lookup' ? (
                <div className="space-y-2 text-sm">
                    <p><span className="font-semibold">Age:</span> {goat.ageCode ? `${goat.ageCode} · ` : ''}{goat.age ?? 'unknown'} · <span className="font-semibold">Last weight:</span> {goat.lastWeight ? `${goat.lastWeight} KG (${goat.lastWeighed})` : 'none'}</p>
                    <Link href={`/goats/${goat.id}`} className="kk-fix min-h-12 w-full">Open Full Profile</Link>
                </div>
            ) : null}
            <button type="button" onClick={() => router.visit('/worker')} className="w-full text-center text-sm font-semibold text-turq-800 hover:underline">Skip this goat</button>
        </div>
    );
}

/**
 * D-255: the new field jobs save through the app's own records (a checkup, a heat record, a mating, a milk record,
 * preventive care), so everything stays the same as when it is entered on its page; then the goat counts and the next is scanned.
 */
function QuickJob({ job, goat, options }: { job: NonNullable<Props['job']>; goat: Goat; options: Options }) {
    const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const o = job.opts ?? {};
    const day = o.on ?? today; // D-255: the round's date
    const f = useForm({ temperature_f: '', outcome: 'healthy', intensity: 'moderate', signs: [] as string[], litres: '', teeth: '', dental_condition: 'normal', height_in: '' });
    const e = f.errors as Record<string, string>;
    const female = goat.sex === 'female';
    const next = { preserveScroll: true, onSuccess: () => router.post('/worker/done') };
    const save = () => {
        if (job.job === 'temp') { f.transform((d) => ({ animal_id: goat.id, checked_on: day, temperature_f: d.temperature_f, outcome: d.outcome })); f.post('/health/checkups', next); }
        if (job.job === 'heat') { f.transform((d) => ({ dam_id: goat.id, observed_on: day, intensity: d.intensity, signs: d.signs })); f.post('/breeding/heat', next); }
        if (job.job === 'mating') { f.transform(() => ({ dam_id: goat.id, sire_id: o.sire_id || null, mated_on: day, method: o.method ?? 'natural' })); f.post('/breeding/matings', next); }
        if (job.job === 'milk') { f.transform((d) => ({ milked_on: day, session: o.session, rows: [{ id: crypto.randomUUID(), animal_id: goat.id, litres: d.litres }] })); f.post('/milking/records', next); }
        if (job.job === 'teeth') { f.transform((d) => ({ teeth: d.teeth, dental_condition: d.dental_condition, examined_on: day })); f.post(`/goats/${goat.id}/teeth`, next); } // D-262
        if (job.job === 'height') { f.transform((d) => ({ height_in: d.height_in, measured_on: day })); f.post(`/goats/${goat.id}/height`, next); } // D-262
        if (job.job === 'care') { f.transform(() => ({ animal_ids: [goat.id], kind: o.care_kind, title: o.care_title || null, medicine_id: o.medicine_id || null, qty_per_goat: o.qty_per_goat || null, given_on: day, care_status: 'administered' })); f.post('/health/preventive', next); }
    };
    if (['heat', 'mating', 'milk'].includes(job.job) && !female) return <p className="rounded-tile bg-watch-100 px-3 py-2 font-semibold text-watch-700">This job is for does. Skip this goat.</p>;
    const t = Number(f.data.temperature_f);
    const errs = Object.values(e);
    return (
        <form onSubmit={(ev) => { ev.preventDefault(); save(); }} className="space-y-3">
            {job.job === 'temp' ? <>
                <Field label="Temperature °F (normal 101.3–103.5)"><input className="field min-h-14 text-2xl font-bold" inputMode="decimal" autoFocus required value={f.data.temperature_f}
                    onChange={(x) => { const v = x.target.value.replace(/[^0-9.]/g, ''); const n = Number(v); f.setData({ ...f.data, temperature_f: v, outcome: v && (n < 101.3 || n > 103.5) ? 'monitor' : 'healthy' }); }} /></Field>
                {f.data.temperature_f ? <p className={`text-sm font-bold ${t >= 101.3 && t <= 103.5 ? 'text-ok-700' : 'text-crit-700'}`}>{t >= 101.3 && t <= 103.5 ? 'Normal' : 'Outside normal: the goat goes on Watch and the vet is told'}</p> : null}
            </> : null}
            {job.job === 'heat' ? <>
                <div className="grid grid-cols-3 gap-2" role="group" aria-label="How strong">{Object.entries(options.intensity).map(([k, v]) => <button key={k} type="button" onClick={() => f.setData('intensity', k)} className={`min-h-12 rounded-tile border font-bold ${f.data.intensity === k ? 'border-cobalt-800 bg-cobalt-800 text-white' : 'border-rule bg-tile'}`}>{v}</button>)}</div>
                <div className="grid grid-cols-2 gap-2">{options.signs.map((sg) => <button key={sg} type="button" onClick={() => f.setData('signs', f.data.signs.includes(sg) ? f.data.signs.filter((x) => x !== sg) : [...f.data.signs, sg])} className={`min-h-11 rounded-tile border px-2 text-sm font-semibold ${f.data.signs.includes(sg) ? 'border-crit-700 bg-crit-100 text-crit-700' : 'border-rule bg-tile'}`}>{sg}</button>)}</div>
            </> : null}
            {job.job === 'mating' ? <p className="text-base font-semibold">Mated today: <b>{options.methods[o.method ?? ''] ?? ''}</b>{o.sire ? <> with <b>{o.sire}</b></> : ' (buck not known)'}</p> : null}
            {job.job === 'milk' ? <Field label={`Litres (${options.sessions[o.session ?? ''] ?? ''})`}><input className="field min-h-14 text-2xl font-bold" inputMode="decimal" autoFocus required value={f.data.litres} onChange={(x) => f.setData('litres', x.target.value.replace(/[^0-9.]/g, ''))} /></Field> : null}
            {job.job === 'care' ? <p className="text-base font-semibold">Give: <b>{options.careKinds[o.care_kind ?? '']}</b> · {o.medicine ?? o.care_title}{o.qty_per_goat ? ` · ${o.qty_per_goat} per goat` : ''}</p> : null}
            {job.job === 'teeth' ? <>
                <Field label="Teeth"><select className="field min-h-14 text-lg font-bold" autoFocus required value={f.data.teeth} onChange={(x) => f.setData('teeth', x.target.value)}><option value="">Choose</option>{Object.entries(options.teeth ?? {}).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <div className="grid grid-cols-3 gap-2" role="group" aria-label="Condition">{Object.entries(options.teethConditions ?? {}).map(([k, v]) => <button key={k} type="button" onClick={() => f.setData('dental_condition', k)} className={`min-h-12 rounded-tile border font-bold ${f.data.dental_condition === k ? 'border-cobalt-800 bg-cobalt-800 text-white' : 'border-rule bg-tile text-cobalt-900'}`}>{v}</button>)}</div>
            </> : null}
            {job.job === 'height' ? <Field label="Height in inches (at the withers)"><input className="field min-h-14 text-2xl font-bold" inputMode="decimal" autoFocus required value={f.data.height_in} onChange={(x) => f.setData('height_in', x.target.value.replace(/[^0-9.]/g, ''))} /></Field> : null}
            {errs.length ? <p className="rounded-tile bg-crit-100 px-3 py-2 text-sm font-semibold text-crit-700" role="alert">{errs.join(' ')}</p> : null}
            <Button type="submit" className="min-h-14 w-full text-lg" disabled={f.processing || (job.job === 'temp' && !f.data.temperature_f) || (job.job === 'milk' && !f.data.litres) || (job.job === 'teeth' && !f.data.teeth) || (job.job === 'height' && !f.data.height_in)}>
                {job.job === 'teeth' ? 'Save Teeth' : job.job === 'height' ? 'Save Height' : job.job === 'temp' ? 'Save Temperature' : job.job === 'heat' ? 'Save Heat' : job.job === 'mating' ? 'Save Mating' : job.job === 'milk' ? 'Save Milk' : 'Mark as Given'}
            </Button>
        </form>
    );
}
