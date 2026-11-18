import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, List } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * D-342: step through the goats of the registry's last list (its filters and sort, in that order) from a goat's page:
 * Previous / Next buttons and the ← → keys. The registry saves the list each time it changes; nothing is sent to the server.
 */
const KEY = 'kk.goats.walk';
type Walk = { ids: string[]; numbers: string[]; label: string; back: string };

export function saveGoatWalk(walk: Walk): void {
    try { sessionStorage.setItem(KEY, JSON.stringify(walk)); } catch { /* storage off: no stepping */ }
}

function readWalk(): Walk | null {
    try { const w = JSON.parse(sessionStorage.getItem(KEY) ?? 'null') as Walk | null; return w && Array.isArray(w.ids) ? w : null; } catch { return null; }
}

export default function GoatStepper({ id }: { id: string }) {
    const [walk] = useState(readWalk);
    const at = walk ? walk.ids.indexOf(id) : -1;
    const prev = at > 0 ? at - 1 : -1;
    const next = walk && at >= 0 && at < walk.ids.length - 1 ? at + 1 : -1;
    const go = (i: number) => { if (walk && i >= 0) router.visit(`/goats/${walk.ids[i]}`); };

    useEffect(() => {
        if (at < 0) return;
        const onKey = (e: KeyboardEvent) => {
            const t = e.target as HTMLElement | null;
            if (e.altKey || e.ctrlKey || e.metaKey || (t && (t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(t.tagName)))) return;
            if (document.querySelector('[role="dialog"]')) return; // a pop card is open
            if (e.key === 'ArrowLeft' && prev >= 0) { e.preventDefault(); go(prev); }
            if (e.key === 'ArrowRight' && next >= 0) { e.preventDefault(); go(next); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    if (!walk || at < 0 || walk.ids.length < 2) return null;
    const btn = 'inline-flex min-h-10 items-center gap-1 rounded-tile border border-rule-strong bg-tile px-3 text-sm font-semibold text-cobalt-900 hover:bg-cobalt-100 disabled:opacity-40 disabled:hover:bg-tile';

    return (
        <nav aria-label="Step through the list" className="sticky top-16 z-10 mb-3 flex flex-wrap items-center gap-2 rounded-tile border border-rule bg-tile/95 px-3 py-2 shadow-tile backdrop-blur">
            <Link href={walk.back} className="inline-flex min-h-10 items-center gap-1.5 text-sm font-semibold text-cobalt-800 hover:underline"><List size={16} aria-hidden /> Back to the list</Link>
            <span className="text-sm text-ink-soft"><b className="tabular-nums text-cobalt-900">{at + 1}</b> of <b className="tabular-nums text-cobalt-900">{walk.ids.length}</b>{walk.label ? ` · ${walk.label}` : ''}</span>
            <span className="ml-auto flex items-center gap-2">
                <button type="button" className={btn} disabled={prev < 0} onClick={() => go(prev)} title="Previous goat (← key)">
                    <ChevronLeft size={17} aria-hidden /> {prev >= 0 ? walk.numbers[prev] : 'Previous'}
                </button>
                <button type="button" className={btn} disabled={next < 0} onClick={() => go(next)} title="Next goat (→ key)">
                    {next >= 0 ? walk.numbers[next] : 'Next'} <ChevronRight size={17} aria-hidden />
                </button>
            </span>
        </nav>
    );
}
