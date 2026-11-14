import { Link, usePage } from '@inertiajs/react';
import { ScanLine, Search } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { currentApp } from '../lib/apps';
import type { SharedProps } from '../types';
import GoatProfileCard from './GoatProfileCard';
import Sheet from './Sheet';

/**
 * D-301: a scanner in every app. The Scan button (or the RFID gun, while inside an app) finds the goat by its RFID
 * tag or Tag ID and opens a popup: the goat's full profile card and that app's own actions for it, each opening the
 * right form with the goat already chosen. Only actions the person has the right to do are offered.
 */
type Action = { label: string; href: (id: string) => string; gate?: string; female?: boolean; primary?: boolean };
const goat = (doing: string) => (id: string) => `/goats/${id}?do=${doing}`;

const ACTIONS: Record<string, Action[]> = {
    livestock: [
        { label: 'Add weight', href: goat('weight'), gate: 'record_weights', primary: true }, { label: 'Add height', href: goat('height'), gate: 'record_height' },
        { label: 'Move PEN', href: goat('move'), gate: 'move_goats' }, { label: 'Add photo', href: goat('photo') },
        { label: 'Edit details', href: (id) => `/goats/${id}/edit`, gate: 'edit_goats' }, { label: 'Record exit', href: goat('exit'), gate: 'exit_animal' },
    ],
    veterinary: [
        { label: 'Health checkup', href: goat('checkup'), gate: 'health_checkup', primary: true }, { label: 'New health entry', href: goat('entry'), gate: 'health_entry' },
        { label: 'Temp check', href: goat('temp'), gate: 'health_checkup' }, { label: 'Report concern', href: goat('concern'), gate: 'report_concern' },
        { label: 'Report death', href: goat('death'), gate: 'report_death' },
    ],
    breeding: [
        { label: 'Record a mating', href: (id) => `/breeding?tab=cycle&mate=${id}`, gate: 'record_mating', female: true, primary: true },
        { label: 'Pregnancy check', href: goat('pregnancy'), gate: 'health_checkup', female: true }, { label: 'Cull from breeding', href: goat('cull'), gate: 'cull_breeding' },
    ],
    maternity: [
        { label: 'Pregnancy check', href: goat('pregnancy'), gate: 'health_checkup', female: true, primary: true },
        { label: 'Record a mating', href: (id) => `/breeding?tab=cycle&mate=${id}`, gate: 'record_mating', female: true }, { label: 'Add weight', href: goat('weight'), gate: 'record_weights' },
    ],
    growth: [{ label: 'Add weight', href: goat('weight'), gate: 'record_weights', primary: true }, { label: 'Add height', href: goat('height'), gate: 'record_height' }],
    housing: [{ label: 'Move PEN', href: goat('move'), gate: 'move_goats', primary: true }],
    sales: [{ label: 'Record exit', href: goat('exit'), gate: 'exit_animal', primary: true }],
    worker: [
        { label: 'Add weight', href: goat('weight'), gate: 'record_weights', primary: true }, { label: 'Temp check', href: goat('temp'), gate: 'health_checkup' },
        { label: 'Report concern', href: goat('concern'), gate: 'report_concern' }, { label: 'Move PEN', href: goat('move'), gate: 'move_goats' },
    ],
};
const DEFAULT: Action[] = [{ label: 'Add weight', href: goat('weight'), gate: 'record_weights' }, { label: 'Health checkup', href: goat('checkup'), gate: 'health_checkup' }, { label: 'Move PEN', href: goat('move'), gate: 'move_goats' }];

// D-302: how the scanner shows: a box in the top bar, a big button in the app menu, or a small button (phone bar)
export default function AppScan({ listen = true, variant = 'button' }: { listen?: boolean; variant?: 'field' | 'menu' | 'button' }) { // listen: the one instance that takes RFID gun reads
    const { auth } = usePage<SharedProps>().props;
    const pageUrl = usePage().url;
    const app = currentApp(pageUrl, auth?.modules ?? []);
    const [open, setOpen] = useState(false);
    const [code, setCode] = useState('');
    const [found, setFound] = useState<{ id: string; sex?: string } | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const input = useRef<HTMLInputElement>(null);

    const lookUp = useCallback((raw: string) => {
        const c = raw.trim();
        if (!c) return;
        setBusy(true); setError(''); setFound(null);
        fetch(`/goats/find?code=${encodeURIComponent(c)}`, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((d) => fetch(`/goats/${d.id}/profile`, { headers: { Accept: 'application/json' } }).then((r) => r.json()).then((p) => setFound({ id: d.id, sex: p.sex })))
            .catch(() => setError(`No goat on the farm has the tag ${c}.`))
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

    if (!auth) return null;
    const gates = auth.gates ?? [];
    const actions = (app ? ACTIONS[app.key] ?? DEFAULT : DEFAULT).filter((a) => (!a.gate || gates.includes(a.gate)) && (!a.female || found?.sex === 'female'));
    const close = () => { setOpen(false); setFound(null); setError(''); setCode(''); };

    return (
        <>
            {variant === 'field' ? (
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
                <Sheet onClose={close}>
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
                                <GoatProfileCard id={found.id} />
                            </>
                        ) : null}
                    </div>
                </Sheet>, document.body, // above everything, also when its top bar is hidden (phone / computer)
            ) : null}
        </>
    );
}
