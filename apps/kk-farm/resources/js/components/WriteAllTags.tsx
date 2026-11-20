import { router } from '@inertiajs/react';
import { PenLine } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { native } from '../lib/native';

type Item = { id: string; number: string; tag: string };
type Outcome = { number: string; ok: boolean; text: string };

const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice(11) ?? '');

/**
 * D-361: the goats tagged before, whose tags still carry the factory number: write all their Tag IDs in one go.
 * Walk along the PEN with the gun; each tag in reach is written with its own goat's Tag ID (found by its number, so
 * no tag can get another goat's ID), read back, and its link moves to the Tag ID. Tags out of reach are left for the
 * next round. On the gun only (own UHF reader), for whoever may assign tags.
 */
export default function WriteAllTags({ canAssign }: { canAssign: boolean }) {
    const ready = native()?.uhfState?.() === 'ready' && !!native()?.writeUhfTag;
    const [items, setItems] = useState<Item[] | null>(null);
    const [running, setRunning] = useState(false);
    const [now, setNow] = useState<string | null>(null);
    const [log, setLog] = useState<Outcome[]>([]);
    const stop = useRef(false);

    const load = () => fetch('/goats/factory-tags', { headers: { Accept: 'application/json' } }).then((r) => (r.ok ? r.json() : [])).then(setItems).catch(() => setItems([]));
    useEffect(() => { if (ready && canAssign) void load(); }, [ready, canAssign]);
    if (!ready || !canAssign || !items?.length && !log.length) return null;

    /** One tag: the gun writes it and answers with kk:uhf-written. */
    const writeOne = (item: Item) => new Promise<{ ok: boolean; code: string; chip: string; error: string | null }>((resolve) => {
        const on = (e: Event) => { window.removeEventListener('kk:uhf-written', on); resolve((e as CustomEvent).detail); };
        window.addEventListener('kk:uhf-written', on);
        native()?.writeUhfTag?.(item.number, item.tag);
    });
    const link = async (item: Item, chip: string): Promise<string | null> => {
        const r = await fetch(`/goats/${item.id}/tag`, { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'text/html, application/xhtml+xml', 'X-Requested-With': 'XMLHttpRequest', 'X-Inertia': 'true', 'X-XSRF-TOKEN': xsrf() },
            body: JSON.stringify({ tag: item.number, chip }) }).catch(() => null);
        if (!r || r.status >= 400) return 'written, but the link could not be saved (no internet?)';
        const page = await r.json().catch(() => null) as { props?: { errors?: Record<string, string> } } | null;
        const errors = page?.props?.errors ? Object.values(page.props.errors) : [];
        return errors.length ? errors.join(' ') : null;
    };

    const run = async () => {
        if (!items?.length || !window.confirm(`Write the Tag ID into ${items.length} tag${items.length === 1 ? '' : 's'} that still carry factory numbers? Only tags in reach of the gun are written; each gets its own goat's Tag ID.`)) return;
        stop.current = false;
        setRunning(true);
        setLog([]);
        for (const item of items) {
            if (stop.current) break;
            setNow(item.number);
            const d = await writeOne(item);
            if (!d.ok) { setLog((l) => [...l, { number: item.number, ok: false, text: d.error ?? 'not written' }]); continue; }
            const problem = await link(item, d.chip);
            setLog((l) => [...l, { number: item.number, ok: !problem, text: problem ?? 'written and linked' }]);
        }
        setNow(null);
        setRunning(false);
        await load();
        router.reload();
    };

    const written = log.filter((o) => o.ok).length;
    return (
        <div className="mb-4 rounded-tile border border-cobalt-800/30 bg-glaze px-3 py-3">
            <div className="flex flex-wrap items-center gap-3">
                {items?.length ? (
                    <button type="button" onClick={run} disabled={running}
                        className="inline-flex min-h-11 items-center gap-2 rounded-tile bg-cobalt-800 px-4 text-sm font-semibold text-white disabled:opacity-60">
                        <PenLine size={16} aria-hidden /> {running ? `Writing ${now ?? ''}…` : `Write Tag IDs into ${items.length} old tag${items.length === 1 ? '' : 's'}`}
                    </button>
                ) : null}
                {running ? <button type="button" onClick={() => { stop.current = true; }} className="text-sm underline">Stop after this tag</button> : null}
                <span className="text-sm text-ink-soft">
                    {items?.length ? `${items.length} goat${items.length === 1 ? '' : 's'} still scan with the factory number. Hold the gun near their tags.` : 'Every linked tag carries its Tag ID.'}
                </span>
            </div>
            {log.length ? (
                <div className="mt-2 text-sm">
                    <p className="font-medium">{written} of {log.length} written.</p>
                    <ul className="mt-1 space-y-0.5">
                        {log.map((o) => <li key={o.number} className={o.ok ? 'text-ok-700' : 'text-crit-700'}>{o.number}: {o.text}</li>)}
                    </ul>
                </div>
            ) : null}
        </div>
    );
}
