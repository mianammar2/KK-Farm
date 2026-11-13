import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeftRight, ClipboardCheck, HeartPulse, Radio, ScanSearch, Scale, Square, Wheat } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { EarTag, GoatFigure } from '../components/FarmScene';
import { AgeBadge, BreedBadge } from '../components/GoatBadges';
import { Button, Field } from '../components/ui';

type Job = 'weigh' | 'health' | 'move' | 'lookup';
type Goat = { ageCode?: string | null; roles?: string[];
    id: string; number: string; name: string | null; sex: string; breed?: string; stage?: string | null; pen?: string | null; age?: string | null; health: string;
    lastWeight: number | null; lastWeighed: string | null; penId: number | null; withdrawal: { meat: string | null; milk: string | null } | null;
};
type Props = {
    jobs: Partial<Record<Job, string>>; job: { job: Job; label: string; penId: number | null; pen: string | null; done: number } | null; goat: Goat | null; unknown: string | null;
    pens: Array<{ id: number; name: string }>; can: { feed: boolean; tasks: boolean }; myTasks: number;
};

const ICON: Record<Job, ReactNode> = { weigh: <Scale size={30} />, health: <HeartPulse size={30} />, move: <ArrowLeftRight size={30} />, lookup: <ScanSearch size={30} /> };
const HINT: Record<Job, string> = { weigh: 'Scan, enter the weight, next goat', health: 'Scan, mark fine or report a concern', move: 'Choose a pen, then scan each goat', lookup: 'Scan to see a goat' };
const CONCERNS = ['Not eating', 'Limping', 'Coughing', 'Diarrhoea', 'Fever / hot', 'Weak / dull', 'Wound', 'Nasal discharge', 'Swollen udder', 'Other'];

/**
 * D-179: the worker app for the RFID gun: big buttons, one job at a time. Pick a job, then scan goat after goat;
 * each scan opens that goat straight in the job's quick form. Typing a tag works too (or a gun set to type tags).
 */
export default function Worker({ jobs, job, goat, unknown, pens, can, myTasks }: Props) {
    const [picking, setPicking] = useState<Job | null>(null);
    const [pen, setPen] = useState(String(pens[0]?.id ?? ''));

    if (!job) {
        return (
            <div className="mx-auto max-w-xl space-y-4">
                <Head title="Worker" />
                <h1 className="text-2xl font-extrabold text-cobalt-900">What are you doing now?</h1>
                <div className="grid grid-cols-2 gap-3">
                    {(Object.keys(jobs) as Job[]).map((j) => (
                        <button key={j} type="button" onClick={() => (j === 'move' ? setPicking('move') : router.post('/worker/start', { job: j }))}
                            className="kk-sign kk-lift min-h-32 flex-col items-start! p-4! text-left">
                            <span className="kk-icon-tile size-14!" aria-hidden>{ICON[j]}</span>
                            <span className="block text-lg font-extrabold text-cobalt-900">{jobs[j]}</span>
                            <span className="block text-sm text-ink-soft">{HINT[j]}</span>
                        </button>
                    ))}
                    {can.feed ? <Link href="/feeding?tab=now" className="kk-sign kk-lift min-h-32 flex-col items-start! p-4!"><span className="kk-icon-tile size-14!" aria-hidden><Wheat size={30} /></span><span className="block text-lg font-extrabold text-cobalt-900">Feed Pens</span><span className="block text-sm text-ink-soft">Record feed given and leftover</span></Link> : null}
                    {can.tasks ? <Link href="/tasks?tab=mine" className="kk-sign kk-lift min-h-32 flex-col items-start! p-4!"><span className="kk-icon-tile size-14!" aria-hidden><ClipboardCheck size={30} /></span><span className="block text-lg font-extrabold text-cobalt-900">My Tasks{myTasks ? ` · ${myTasks}` : ''}</span><span className="block text-sm text-ink-soft">Due today or overdue</span></Link> : null}
                </div>
                {picking === 'move' ? (
                    <div className="space-y-3 rounded-tile border border-rule bg-tile p-4 shadow-tile">
                        <Field label="Move goats to which pen?"><select className="field min-h-12 text-base" value={pen} onChange={(e) => setPen(e.target.value)}>{pens.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
                        <div className="flex gap-2"><Button type="button" className="min-h-12 flex-1" onClick={() => router.post('/worker/start', { job: 'move', pen_id: Number(pen) })}>Start Moving</Button><Button type="button" variant="secondary" className="min-h-12" onClick={() => setPicking(null)}>Back</Button></div>
                    </div>
                ) : null}
            </div>
        );
    }

    return (
        <div className="mx-auto max-w-xl space-y-4">
            <Head title={job.label} />
            <div className="flex items-center gap-3 rounded-tile bg-cobalt-800 px-4 py-3 text-white shadow-tile">
                <span className="grid size-12 place-items-center rounded-xl bg-white/15" aria-hidden>{ICON[job.job]}</span>
                <span className="min-w-0 flex-1"><span className="block text-lg font-extrabold">{job.label}{job.pen ? ` → ${job.pen}` : ''}</span><span className="block text-sm text-white/80">{job.done} goat{job.done === 1 ? '' : 's'} done</span></span>
                <button type="button" onClick={() => router.post('/worker/stop')} className="inline-flex min-h-11 items-center gap-1.5 rounded-full bg-white/15 px-4 text-sm font-bold hover:bg-white/25"><Square size={14} aria-hidden />Finish</button>
            </div>
            {unknown ? <p className="rounded-tile bg-watch-100 px-3 py-2 font-semibold text-watch-700" role="alert">No goat found for {unknown}. Check the goat number, or if it is a chip, link it in Goat Registry → Link RFID, then scan again.</p> : null}
            {goat ? <GoatJob job={job} goat={goat} /> : <ScanPrompt />}
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

function GoatJob({ job, goat }: { job: NonNullable<Props['job']>; goat: Goat }) {
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
