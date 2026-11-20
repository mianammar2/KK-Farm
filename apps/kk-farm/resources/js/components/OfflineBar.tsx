import { CloudOff, RefreshCw, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { discardQueued, sendQueued, useOffline } from '../lib/offline';

/**
 * D-359: at the top of every page, while there is no internet or saves are waiting: what the app is doing without the
 * internet and what will be sent when it is back. Nothing shows when online with nothing waiting.
 */
export default function OfflineBar() {
    const { online, queued } = useOffline();
    const [just, setJust] = useState<string | null>(null);
    useEffect(() => {
        const on = (e: Event) => { setJust(String((e as CustomEvent).detail ?? '')); window.setTimeout(() => setJust(null), 4000); };
        window.addEventListener('kk:queued', on);
        return () => window.removeEventListener('kk:queued', on);
    }, []);
    const waiting = queued.filter((q) => !q.error);
    const refused = queued.filter((q) => q.error);
    if (online && !queued.length && !just) return null;

    return (
        <div className="mb-3 space-y-2 rounded-tile border border-watch-400 bg-watch-100 px-3 py-2 text-sm text-watch-700" role="status">
            {!online ? <p className="flex items-center gap-2 font-medium"><CloudOff size={16} aria-hidden /> No internet. Pages and scans use the copy kept on this device; saves wait here.</p> : null}
            {just ? <p>Saved on this device: {just || 'your entry'}. It is sent when the internet is back.</p> : null}
            {waiting.length ? (
                <p className="flex flex-wrap items-center gap-2">
                    {waiting.length === 1 ? '1 save' : `${waiting.length} saves`} waiting to be sent.
                    {online ? <button type="button" onClick={() => void sendQueued()} className="inline-flex items-center gap-1 underline"><RefreshCw size={14} aria-hidden /> Send now</button> : null}
                </p>
            ) : null}
            {refused.map((q) => (
                <p key={q.id} className="flex flex-wrap items-center gap-2 text-crit-700">
                    Not saved: {q.label} ({q.at}). {q.error}
                    <button type="button" onClick={() => discardQueued(q.id)} className="inline-flex items-center gap-1 underline"><X size={14} aria-hidden /> Remove</button>
                </p>
            ))}
        </div>
    );
}
