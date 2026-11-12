import { Head, Link, router, useForm } from '@inertiajs/react';
import { ClipboardPlus, FileText, MoveRight, Radio, Scale, ScanLine, Stethoscope, TriangleAlert } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { useEffect, useRef, useState } from 'react';
import { useScannerDevice } from '../lib/scanner';
import { haptic } from '../lib/native';
import GoatPortrait from '../components/GoatPortrait';
import { Button, Chip, Field, HealthChip, Note, PageHeader, Panel, ReproChip } from '../components/ui';
import type { GoatCard, Option } from '../types';
import { AttachPrompt } from '../components/RfidAttach';
import { AgeBadge, BreedBadge } from '../components/GoatBadges';

type Result = { code: string; state: 'assigned' | 'free' | 'lost' | 'unknown'; goat: (GoatCard & { withdrawal?: { meat: string | null; milk: string | null } | null; pregnancyDay?: number | null; can?: { health: boolean; check: boolean; weigh: boolean; move: boolean } }) | null; since: string | null; history: Array<{ id: string; number: string; to: string | null; reason: string | null }> };
type Props = { attach?: Parameters<typeof AttachPrompt>[0]['attach'] | null; result: Result | null; samples: { assigned: string[]; free: string[]; new: string[] }; untagged: Option[]; goats: Option[] };

// Inside the KK Farm Android app on the RFID gun (its user agent carries "KKFarmScanner").
const IN_APP = typeof navigator !== 'undefined' && navigator.userAgent.includes('KKFarmScanner');

export default function Scan({ result, samples, goats, attach = null }: Props) {
    const [code, setCode] = useState(result?.code ?? '');
    const [scanning, setScanning] = useState(false);
    useEffect(() => setScanning(false), [result]);

    const scan = (value: string) => {
        const tag = value.trim();
        if (!tag) {
            return;
        }
        setCode(tag);
        setScanning(true);
        router.get('/scan', { tag }, { preserveState: true, preserveScroll: true, replace: true, onFinish: () => setScanning(false) });
    };

    const sample = (label: string, codes: string[]) => (
        <div>
            <div className="mb-1 text-sm text-ink-soft">{label}</div>
            <div className="flex flex-wrap gap-1.5">
                {codes.map((c) => <button key={c} type="button" onClick={() => scan(c)} className="min-h-9 max-w-full break-all rounded-[4px] border border-rule bg-tile px-2 py-1 text-left font-mono text-sm hover:border-turq-600">{c}</button>)}
                {!codes.length ? <span className="text-sm text-ink-faint">None available</span> : null}
            </div>
        </div>
    );

    return (
        <>
            <Head title="RFID Scan" />
            <PageHeader title="RFID scan" description={IN_APP ? "Pull the trigger at a goat's ear tag. A tag with no goat starts the goat register." : "Scan a tag to open the goat. A tag with no goat starts the goat register. This is a simulator: no real reader is connected."} />
            <div className="grid gap-4 lg:grid-cols-[24rem_minmax(0,1fr)]">
                <div className="space-y-4">
                    <Panel title={IN_APP ? 'Type a tag code' : 'Simulated reader'}>
                        <form onSubmit={(e) => { e.preventDefault(); scan(code); }} className="space-y-2">
                            <Field label="Tag code"><input value={code} onChange={(e) => setCode(e.target.value)} placeholder="Scan or type a tag code" className="field" autoFocus /></Field>
                            <Button type="submit" className="w-full" disabled={scanning}><ScanLine size={17} aria-hidden /> {scanning ? 'Reading' : 'Scan tag'}</Button>
                        </form>
                    </Panel>
                    {IN_APP ? null : <ReaderSetup />}
                    {IN_APP ? null : <Panel title="Try a demo tag">
                        <div className="space-y-3">
                            {sample('On a goat', samples.assigned)}
                            {sample('Released by a goat that left', samples.free)}
                            {sample('New, never used', samples.new)}
                            <div>
                                <div className="mb-1 text-sm text-ink-soft">Tag the farm has never seen</div>
                                <button type="button" onClick={() => scan(Array.from({ length: 24 }, () => '0123456789ABCDEF'[Math.floor(Math.random() * 16)]).join(''))} className="min-h-9 rounded-[4px] border border-rule bg-tile px-2 text-sm hover:border-turq-600">Scan a brand-new tag</button>
                            </div>
                        </div>
                    </Panel>}
                </div>

                <AnimatePresence mode="wait">
                    {/* On a phone or the gun the result comes first, above the reader panel. */}
                    <motion.div key={result?.code ?? 'idle'} className="order-first lg:order-none" initial={{ opacity: 0, y: 40 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -12 }} transition={{ type: 'spring', stiffness: 360, damping: 30 }}>
                        {attach ? <AttachPrompt attach={attach} /> : null}
                        {!result && !attach ? <Idle scanning={scanning} /> : null}
                        {result?.state === 'assigned' && result.goat ? <Found goat={result.goat} result={result} /> : null}
                        {result && result.state !== 'assigned' ? <Unassigned result={result} goats={goats} /> : null}
                    </motion.div>
                </AnimatePresence>
            </div>
        </>
    );
}

/** Waiting state: a radar that pulses like the reader is listening. CSS animations only (compositor, no JS per frame). */
function Idle({ scanning }: { scanning?: boolean }) {
    return (
        <div className={`relative overflow-hidden rounded-3xl bg-cobalt-900 px-4 py-10 text-center text-white shadow-float ${scanning ? 'kk-fast' : ''}`}>
            <div className="relative mx-auto grid size-48 place-items-center">
                {[0, 1, 2].map((i) => (
                    <span key={i} aria-hidden className="kk-ring absolute inset-0 rounded-full border-2 border-turq-400" style={{ animationDelay: `${i * 0.8}s` }} />
                ))}
                <span aria-hidden className="kk-sweep absolute inset-6 rounded-full bg-[conic-gradient(from_0deg,transparent_0deg,oklch(0.78_0.12_190/0.45)_60deg,transparent_90deg)]" />
                <span className="relative grid size-20 place-items-center rounded-full bg-turq-500 text-cobalt-950 shadow-float"><Radio size={34} aria-hidden /></span>
            </div>
            <p className="relative mt-6 text-lg font-bold">{scanning ? 'Reading…' : 'Ready to scan'}</p>
            <p className="relative mx-auto mt-1 max-w-[34ch] text-sm text-cobalt-200">{IN_APP ? "Point the gun at a goat's ear tag and pull the trigger." : 'Type or scan a tag code. A tag with no goat starts the goat register.'}</p>
        </div>
    );
}

function Found({ goat, result }: { goat: NonNullable<Result['goat']>; result: Result }) {
    useEffect(() => haptic(40), [result.code]);
    const remove = useForm({ animal_id: goat.id, reason: 'lost' });
    // Releasing a tag cannot be undone from here, so it takes two deliberate taps.
    const [confirmLost, setConfirmLost] = useState(false);
    return (
        <Panel title="Goat found" description={`Tag ${result.code}, on this goat since ${result.since}`}>
            <div className="flex flex-col gap-4 sm:flex-row">
                <motion.div initial={{ scale: 0.6, opacity: 0, rotate: -6 }} animate={{ scale: 1, opacity: 1, rotate: 0 }} transition={{ type: 'spring', stiffness: 320, damping: 18 }} className="shrink-0 self-center sm:self-start">
                    {goat.photo ? <img src={`/media/${goat.photo}`} alt="" className="size-36 rounded-3xl object-cover shadow-float" /> : <GoatPortrait number={goat.number} sex={goat.sex} stage={goat.stage} repro={goat.repro} size={144} />}
                </motion.div>
                <div className="min-w-0">
                    <h2 className="font-mono text-xl font-bold text-cobalt-900">{goat.number}</h2>
                    <p className="flex flex-wrap items-center gap-1.5 text-ink-soft">{goat.name ? <span>{goat.name} ·</span> : null}<BreedBadge breed={goat.breed} withName /><span>·</span><AgeBadge stage={goat.stage} code={goat.ageCode} withLabel /><span>{goat.age}</span></p>
                    <p className="text-ink-soft">{goat.pen}{goat.weight ? ` · ${goat.weight} KG` : ''}</p>
                    <div className="mt-1 flex flex-wrap gap-1"><HealthChip health={goat.health} /><ReproChip repro={goat.repro} until={goat.monitorUntil} />{goat.pregnancyDay ? <span className="rounded-[4px] bg-manganese-100 px-1.5 py-0.5 text-xs font-semibold text-manganese-600">Day {goat.pregnancyDay} of pregnancy</span> : null}</div>
                    {goat.withdrawal ? (
                        <p role="alert" className="mt-2 rounded-tile bg-crit-100 px-2.5 py-1.5 text-sm font-semibold text-crit-700">
                            Under withdrawal{goat.withdrawal.meat ? ` · no sale before ${goat.withdrawal.meat}` : ''}{goat.withdrawal.milk ? ` · no milk before ${goat.withdrawal.milk}` : ''}
                        </p>
                    ) : null}
                    {/* D-102: after a scan, what to do with this goat; each opens on the goat's own page. */}
                    <p className="mt-3 text-sm font-semibold text-cobalt-900">What do you want to do?</p>
                    <div className="mt-1.5 grid grid-cols-2 gap-2 sm:grid-cols-3">
                        {[
                            goat.can?.check ? { href: 'checkup', label: 'Health checkup', icon: <Stethoscope size={18} aria-hidden />, primary: true } : null,
                            goat.can?.check && goat.sex === 'female' ? { href: 'pregnancy', label: 'Pregnancy check', icon: <Stethoscope size={18} aria-hidden /> } : null,
                            goat.can?.health ? { href: 'entry', label: 'New health entry', icon: <ClipboardPlus size={18} aria-hidden /> } : null,
                            goat.can?.health ? { href: 'concern', label: 'Report concern', icon: <TriangleAlert size={18} aria-hidden /> } : null,
                            goat.can?.weigh ? { href: 'weight', label: 'Add weight', icon: <Scale size={18} aria-hidden /> } : null,
                            goat.can?.move ? { href: 'move', label: 'Move pen', icon: <MoveRight size={18} aria-hidden /> } : null,
                            { href: '', label: 'Open record', icon: <FileText size={18} aria-hidden /> },
                        ].filter(Boolean).map((a) => (
                            <Link key={a!.label} href={`/goats/${goat.id}${a!.href ? `?do=${a!.href}` : ''}`}
                                className={`kk-lift flex min-h-14 items-center gap-2 rounded-tile border px-3 py-2 text-sm font-semibold ${a!.primary ? 'border-cobalt-800 bg-cobalt-800 text-white' : 'border-rule bg-tile text-cobalt-900'}`}>
                                {a!.icon}{a!.label}
                            </Link>
                        ))}
                    </div>
                    <div className="mt-4 border-t border-rule pt-3">
                        {!confirmLost ? (
                            <button type="button" className="text-sm text-ink-soft underline-offset-2 hover:underline" onClick={() => setConfirmLost(true)}>The ear tag fell off or is missing…</button>
                        ) : (
                            <div className="space-y-2">
                                <Note>Only if the ear tag is really missing. The tag is removed from {goat.number} and blocked until it is reviewed. The goat keeps its identity and history.</Note>
                                <div className="flex flex-wrap gap-2">
                                    <Button variant="secondary" onClick={() => setConfirmLost(false)}>Cancel</Button>
                                    <Button disabled={remove.processing} className="bg-crit-700 hover:bg-crit-700/90" onClick={() => remove.post('/scan/remove', { preserveScroll: true })}>Yes, mark tag lost</Button>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </Panel>
    );
}

function Unassigned({ result, goats }: { result: Result; goats: Option[] }) {
    const form = useForm({ tag: result.code, animal_id: String(goats[0]?.id ?? ''), reason: result.history.length ? 'reassigned' : 'initial' });

    if (result.state === 'unknown') {
        return <Panel title="Tag not recognised"><Note>{result.code} is not in the tag register. Check the code and scan again.</Note></Panel>;
    }
    if (result.state === 'lost') {
        return <Panel title="Tag marked lost"><Note>{result.code} was reported lost. It cannot be assigned until it is reviewed.</Note></Panel>;
    }
    return (
        <Panel title="Tag is free" description={result.code}>
            {result.history.length ? (
                <div className="mb-3">
                    <p className="mb-1 text-sm text-ink-soft">This tag was used before. Those records stay with that goat and do not move.</p>
                    <ul className="text-sm">
                        {result.history.map((h) => <li key={h.id + h.to}><Link href={`/goats/${h.id}`} className="font-semibold text-cobalt-800 hover:underline">{h.number}</Link> until {h.to} <Chip className="bg-cobalt-100 text-cobalt-800">{h.reason}</Chip></li>)}
                    </ul>
                </div>
            ) : null}
            <form onSubmit={(e) => { e.preventDefault(); form.post('/scan/assign', { preserveScroll: true }); }} className="grid gap-2 sm:grid-cols-[1fr_auto] sm:items-end">
                <Field label="Assign to goat" error={form.errors.tag ?? form.errors.animal_id} hint="A goat with a tag has that tag closed as replaced">
                    <select className="field" value={form.data.animal_id} onChange={(e) => form.setData('animal_id', e.target.value)}>
                        {goats.map((g) => <option key={g.id} value={g.id}>{g.farm_number}{g.name ? ` · ${g.name}` : ''}</option>)}
                    </select>
                </Field>
                <Button type="submit" disabled={form.processing}>Assign tag</Button>
            </form>
        </Panel>
    );
}

/** For the hardware spike: mark this device as a scanner, and see exactly what the gun sends to the browser. */
function ReaderSetup() {
    const [device, setDevice] = useScannerDevice();
    const [log, setLog] = useState<string[]>([]);
    const [open, setOpen] = useState(false);
    const last = useRef(0);

    useEffect(() => {
        if (!open) {
            return;
        }
        const note = (line: string) => {
            const now = performance.now();
            const gap = last.current ? Math.round(now - last.current) : 0;
            last.current = now;
            setLog((l) => [`+${gap}ms ${line}`, ...l].slice(0, 40));
        };
        const key = (e: KeyboardEvent) => note(`${e.type} key=${JSON.stringify(e.key)} code=${e.code} keyCode=${e.keyCode}`);
        const input = (e: Event) => {
            const ie = e as InputEvent;
            note(`input type=${ie.inputType} data=${JSON.stringify(ie.data)}`);
        };
        window.addEventListener('keydown', key, true);
        window.addEventListener('keyup', key, true);
        window.addEventListener('input', input, true);
        return () => {
            window.removeEventListener('keydown', key, true);
            window.removeEventListener('keyup', key, true);
            window.removeEventListener('input', input, true);
        };
    }, [open]);

    return (
        <Panel title="RFID gun">
            <label className="flex min-h-11 items-center gap-2 text-sm">
                <input type="checkbox" className="size-5" checked={device} onChange={(e) => setDevice(e.target.checked)} />
                This device is an RFID gun: scanning works from any screen
            </label>
            <button type="button" className="mt-2 text-sm font-semibold text-cobalt-800 hover:underline" onClick={() => setOpen(!open)}>{open ? 'Hide' : 'Show'} reader diagnostics</button>
            {open ? (
                <div className="mt-2">
                    <p className="mb-1 text-sm text-ink-soft">Pull the trigger. Every key and input event the browser receives is listed, newest first.</p>
                    <pre className="max-h-64 overflow-auto rounded-[4px] bg-cobalt-950 p-2 text-xs leading-5 text-white">{log.join('\n') || 'Waiting for events…'}</pre>
                    <Button variant="secondary" className="mt-2" onClick={() => setLog([])}>Clear</Button>
                </div>
            ) : null}
        </Panel>
    );
}
