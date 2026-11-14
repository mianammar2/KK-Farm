import { Link, usePage } from '@inertiajs/react';
import { ScanLine, Search } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { currentApp } from '../lib/apps';
import type { SharedProps } from '../types';
import GoatProfileCard, { type Profile } from './GoatProfileCard';
import { X } from 'lucide-react';

/**
 * D-301: a scanner in every app. The Scan button (or the RFID gun, while inside an app) finds the goat by its RFID
 * tag or Tag ID and opens a popup: the goat's full profile card and that app's own actions for it, each opening the
 * right form with the goat already chosen. Only actions the person has the right to do are offered.
 */
// record: opens on the goat's own page (only for those who may open it); without it the app's own page is used
type Action = { label: string; href: (id: string) => string; gate?: string; female?: boolean; primary?: boolean; record?: boolean; alt?: (id: string) => string };
const goat = (doing: string) => (id: string) => `/goats/${id}?do=${doing}`;
const vetEntry = (kind?: string) => (id: string) => `/health?tab=checkups&new=entry&goat=${id}${kind ? `&kind=${kind}` : ''}`; // D-305: the Veterinary page's own entry form

const ACTIONS: Record<string, Action[]> = {
    livestock: [
        { label: 'Add weight', href: goat('weight'), gate: 'record_weights', primary: true, record: true }, { label: 'Add height', href: goat('height'), gate: 'record_height', record: true },
        { label: 'Move PEN', href: goat('move'), gate: 'move_goats', record: true }, { label: 'Add photo', href: goat('photo'), record: true },
        { label: 'Edit details', href: (id) => `/goats/${id}/edit`, gate: 'edit_goats', record: true }, { label: 'Record exit', href: goat('exit'), gate: 'exit_animal', record: true },
    ],
    veterinary: [
        { label: 'Health checkup', href: goat('checkup'), gate: 'health_checkup', primary: true, record: true, alt: vetEntry('checkup') }, { label: 'New health entry', href: goat('entry'), gate: 'health_entry', record: true, alt: vetEntry() },
        { label: 'Temp check', href: goat('temp'), gate: 'health_checkup', record: true }, { label: 'Give a dose', href: (id) => `/health?tab=doses&goat=${id}`, gate: 'give_doses' },
        { label: 'Report concern', href: goat('concern'), gate: 'report_concern', record: true }, { label: 'Report death', href: goat('death'), gate: 'report_death', record: true },
    ],
    breeding: [
        { label: 'Record a mating', href: (id) => `/breeding?tab=cycle&mate=${id}`, gate: 'record_mating', female: true, primary: true },
        { label: 'Pregnancy check', href: goat('pregnancy'), gate: 'health_checkup', female: true, record: true, alt: vetEntry('pregnancy') }, { label: 'Cull from breeding', href: goat('cull'), gate: 'cull_breeding', record: true },
    ],
    maternity: [
        { label: 'Pregnancy check', href: goat('pregnancy'), gate: 'health_checkup', female: true, primary: true, record: true, alt: vetEntry('pregnancy') },
        { label: 'Record a mating', href: (id) => `/breeding?tab=cycle&mate=${id}`, gate: 'record_mating', female: true }, { label: 'Add weight', href: goat('weight'), gate: 'record_weights', record: true },
    ],
    growth: [{ label: 'Add weight', href: goat('weight'), gate: 'record_weights', primary: true, record: true }, { label: 'Add height', href: goat('height'), gate: 'record_height', record: true }],
    housing: [{ label: 'Move PEN', href: goat('move'), gate: 'move_goats', primary: true, record: true }],
    sales: [{ label: 'Record exit', href: goat('exit'), gate: 'exit_animal', primary: true, record: true }],
    worker: [
        { label: 'Add weight', href: goat('weight'), gate: 'record_weights', primary: true, record: true }, { label: 'Temp check', href: goat('temp'), gate: 'health_checkup', record: true },
        { label: 'Report concern', href: goat('concern'), gate: 'report_concern', record: true }, { label: 'Move PEN', href: goat('move'), gate: 'move_goats', record: true },
    ],
};
const DEFAULT: Action[] = [{ label: 'Add weight', href: goat('weight'), gate: 'record_weights', record: true }, { label: 'Health checkup', href: goat('checkup'), gate: 'health_checkup', record: true }, { label: 'Move PEN', href: goat('move'), gate: 'move_goats', record: true }];

// D-302: how the scanner shows: a box in the top bar, a big button in the app menu, or a small button (phone bar)
export default function AppScan({ listen = true, variant = 'bar' }: { listen?: boolean; variant?: 'bar' | 'field' | 'menu' | 'button' }) { // listen: the one instance that takes RFID gun reads
    const { auth } = usePage<SharedProps>().props;
    const pageUrl = usePage().url;
    const app = currentApp(pageUrl, auth?.modules ?? []);
    const [open, setOpen] = useState(false);
    const [code, setCode] = useState('');
    const [found, setFound] = useState<(Profile & { canOpenRecord?: boolean }) | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const input = useRef<HTMLInputElement>(null);

    const lookUp = useCallback((raw: string) => {
        const c = raw.trim();
        if (!c) return;
        setBusy(true); setError(''); setFound(null);
        fetch(`/lookup/goat?code=${encodeURIComponent(c)}`, { headers: { Accept: 'application/json' } }) // D-305: needs only "Scan goats"
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((d) => setFound(d))
            .catch((status) => setError(status === 403 ? 'Scanning goats is not among your rights. Ask the owner to tick "Scan goats" for you in Access Rights.' : `No goat on the farm has the tag ${c}.`))
            .finally(() => setBusy(false));
    }, []);

    // the RFID gun inside an app: open this popup instead of leaving the app (lib/scanner dispatches kk:scan)
    useEffect(() => {
        if (!app || !listen) return;
        const on = (e: Event) => { e.preventDefault(); const tag = String((e as CustomEvent).detail ?? ''); setOpen(true); setCode(tag); lookUp(tag); };
        window.addEventListener('kk:scan', on);
        return () => window.removeEventListener('kk:scan', on);
    }, [app, lookUp, listen]);
    useEffect(() => { if (open && !found) setTimeout(() => input.current?.focus(), 50); }, [open, found]);

    if (!auth || !app) return null; // D-303: only inside an app
    const gates = auth.gates ?? [];
    const canRecord = found?.canOpenRecord ?? false;
    const actions = (app ? ACTIONS[app.key] ?? DEFAULT : DEFAULT).filter((a) => (!a.gate || gates.includes(a.gate)) && (!a.female || found?.sex === 'female') && (!a.record || canRecord || a.alt))
        .map((a) => (a.record && !canRecord && a.alt ? { ...a, href: a.alt } : a));
    const close = () => { setOpen(false); setFound(null); setError(''); setCode(''); };

    return (
        <>
            {variant === 'bar' ? (
                <form onSubmit={(e) => { e.preventDefault(); const v = (e.currentTarget.elements.namedItem('scan') as HTMLInputElement).value; if (v.trim()) { setCode(v); setOpen(true); lookUp(v); e.currentTarget.reset(); } }}
                    className="mb-4 flex items-center gap-2 rounded-tile border border-cobalt-800/30 bg-tile p-2 shadow-tile">
                    <span className="hidden shrink-0 items-center gap-1.5 ps-2 text-sm text-cobalt-900 sm:inline-flex"><ScanLine size={18} aria-hidden /> {app.label}</span>
                    <input name="scan" className="field min-w-0 flex-1" placeholder="Scan RFID or type Tag ID" autoComplete="off" autoCapitalize="characters" aria-label={`Scan RFID or type Tag ID for ${app.label}`} />
                    <button type="submit" className="inline-flex h-11 shrink-0 items-center gap-1.5 rounded-tile bg-cobalt-800 px-4 text-sm text-white hover:bg-cobalt-900"><ScanLine size={16} aria-hidden /> Scan</button>
                </form>
            ) : variant === 'field' ? (
                <form onSubmit={(e) => { e.preventDefault(); const v = (e.currentTarget.elements.namedItem('scan') as HTMLInputElement).value; if (v.trim()) { setCode(v); setOpen(true); lookUp(v); e.currentTarget.reset(); } }}
                    className="relative w-full max-w-xs" title={app ? `Scan a goat for ${app.label}` : 'Scan a goat'}>
                    <ScanLine size={17} aria-hidden className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-cobalt-800" />
                    <input name="scan" className="field border-cobalt-800 ps-9" placeholder="Scan RFID or type Tag ID" autoComplete="off" autoCapitalize="characters" aria-label="Scan RFID or type Tag ID" />
                </form>
            ) : variant === 'menu' ? (
                <button type="button" onClick={() => setOpen(true)} className="mb-3 flex min-h-11 w-full items-center gap-2 rounded-tile bg-turq-600 px-3 text-sm text-cobalt-950 hover:bg-turq-500">
                    <ScanLine size={18} aria-hidden /> Scan RFID / Tag ID
                </button>
            ) : (
                <button type="button" onClick={() => setOpen(true)} title={app ? `Scan a goat for ${app.label}` : 'Scan a goat'}
                    className="inline-flex h-10 items-center gap-1.5 rounded-tile border border-rule bg-tile px-3 text-sm text-cobalt-800 hover:bg-glaze">
                    <ScanLine size={17} aria-hidden /><span>Scan</span>
                </button>
            )}
            {open ? createPortal(
                <PopCard onClose={close}>
                    <div className="space-y-4 p-4 sm:p-5">
                        <div>
                            <h2 className="text-lg text-cobalt-900">{app ? `${app.label}: scan a goat` : 'Scan a goat'}</h2>
                            <p className="text-sm text-ink-soft">Scan its RFID tag or type its Tag ID. {app ? `You get its profile and what ${app.label} can do with it.` : ''}</p>
                        </div>
                        <form onSubmit={(e) => { e.preventDefault(); lookUp(code); }} className="flex gap-2">
                            <label className="relative flex-1"><Search size={16} aria-hidden className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-soft" />
                                <input ref={input} className="field ps-9" value={code} onChange={(e) => setCode(e.target.value)} placeholder="RFID tag or Tag ID" autoCapitalize="characters" autoComplete="off" aria-label="RFID tag or Tag ID" /></label>
                            <button type="submit" disabled={busy || !code.trim()} className="h-11 rounded-tile bg-cobalt-800 px-4 text-sm text-white disabled:opacity-50">{busy ? 'Finding…' : 'Find'}</button>
                        </form>
                        {error ? (
                            <div className="rounded-tile bg-watch-100 px-3 py-2 text-sm text-watch-700">{error} <Link href={`/scan?tag=${encodeURIComponent(code.trim())}`} className="underline">Open RFID Scan</Link> to link the tag or register a goat.</div>
                        ) : null}
                        {found ? (
                            <>
                                {actions.length ? (
                                    <div className="flex flex-wrap gap-2 rounded-tile border border-rule bg-glaze/60 p-3">
                                        <p className="w-full text-sm text-ink">{app ? `${app.label}: what to do with this goat` : 'What to do with this goat'}</p>
                                        {actions.map((a) => (
                                            <Link key={a.label} href={a.href(found.id)} onClick={close}
                                                className={a.primary ? 'inline-flex h-10 items-center rounded-tile bg-cobalt-800 px-4 text-sm text-white hover:bg-cobalt-900' : 'inline-flex h-10 items-center rounded-tile border border-cobalt-800 bg-tile px-4 text-sm text-cobalt-800 hover:bg-glaze'}>{a.label}</Link>
                                        ))}
                                    </div>
                                ) : null}
                                <GoatProfileCard id={found.id} data={found} />
                            </>
                        ) : null}
                    </div>
                </PopCard>, document.body, // above everything
            ) : null}
        </>
    );
}

/** D-303: the scan result as a card in the middle of the screen (Escape or the background closes it). */
function PopCard({ onClose, children }: { onClose: () => void; children: React.ReactNode }) {
    useEffect(() => { const k = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); }; window.addEventListener('keydown', k); return () => window.removeEventListener('keydown', k); }, [onClose]);
    return (
        <div className="fixed inset-0 z-50 grid place-items-center bg-cobalt-950/45 p-3 sm:p-6" onClick={onClose} role="dialog" aria-modal="true">
            <div className="relative max-h-[92vh] w-full max-w-3xl overflow-y-auto rounded-2xl bg-tile shadow-2xl" onClick={(e) => e.stopPropagation()}>
                <button type="button" onClick={onClose} aria-label="Close" className="absolute top-3 right-3 z-10 grid size-9 place-items-center rounded-full bg-glaze text-ink-soft hover:text-ink"><X size={18} /></button>
                {children}
            </div>
        </div>
    );
}
