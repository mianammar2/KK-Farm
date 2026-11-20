import { router } from '@inertiajs/react';
import { PenLine } from 'lucide-react';
import { useEffect, useState } from 'react';
import { native } from '../lib/native';

/**
 * D-360: put the goat's Tag ID into the RFID tag it already wears, so every scan shows the Tag ID instead of the
 * factory number. Shown on the gun (own UHF reader) when the goat's linked tag still carries a factory number.
 * The gun finds that tag by its number (no touching), writes the Tag ID into it, reads it back, and the link moves
 * to the Tag ID (the old number stays in the tag history).
 */
export default function WriteTagId({ goatId, number, tag, canAssign }: { goatId: string; number: string; tag: string | null | undefined; canAssign: boolean }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [done, setDone] = useState(false);
    const ready = native()?.uhfState?.() === 'ready' && !!native()?.writeUhfTag;
    useEffect(() => {
        const on = (e: Event) => {
            const d = (e as CustomEvent<{ ok: boolean; code: string; chip: string; error: string | null }>).detail;
            if (!busy) return;
            setBusy(false);
            if (!d.ok) { setError(d.error ?? 'The tag could not be written. Try again.'); return; }
            setDone(true);
            router.post(`/goats/${goatId}/tag`, { tag: d.code, chip: d.chip }, { preserveScroll: true });
        };
        window.addEventListener('kk:uhf-written', on);
        return () => window.removeEventListener('kk:uhf-written', on);
    }, [busy, goatId]);

    if (!ready || !canAssign || !tag || tag.toUpperCase() === number.toUpperCase()) return null;
    const write = () => {
        if (!window.confirm(`Write ${number} into tag ${tag}? The tag then reads as ${number}.`)) return;
        setError(null);
        setBusy(true);
        native()?.writeUhfTag?.(number, tag);
    };

    return (
        <div className="mt-3 rounded-tile border border-cobalt-800/30 bg-glaze px-3 py-3">
            {done ? (
                <p className="text-sm font-medium text-ok-700">Written: the tag now reads as {number}.</p>
            ) : (
                <div className="flex flex-wrap items-center gap-3">
                    <button type="button" onClick={write} disabled={busy}
                        className="inline-flex min-h-11 items-center gap-2 rounded-tile bg-cobalt-800 px-4 text-sm font-semibold text-white disabled:opacity-60">
                        <PenLine size={16} aria-hidden /> {busy ? 'Writing…' : `Write ${number} into this tag`}
                    </button>
                    <span className="text-sm text-ink-soft">The tag still carries its factory number {tag}. Keep the gun near the tag.</span>
                </div>
            )}
            {error ? <p className="mt-2 text-sm text-crit-700">{error}</p> : null}
        </div>
    );
}
