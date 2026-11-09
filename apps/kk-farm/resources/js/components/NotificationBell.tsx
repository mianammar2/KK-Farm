import { Link } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { titleCase } from '../lib/titleCase';

type Note = { key: string; label: string; count: number; severity: 'critical' | 'warning' | 'info'; url: string; action: string };

const DOT: Record<Note['severity'], string> = { critical: 'bg-crit-700', warning: 'bg-watch-700', info: 'bg-cobalt-700' };

/**
 * D-121: what needs action (the Action Center) behind a bell in the top bar. It loads after the page has opened, so
 * no page waits for it, and refreshes every minute and on each page change. Each item opens its own page.
 */
export default function NotificationBell({ path, onDark = false }: { path: string; onDark?: boolean }) {
    const [notes, setNotes] = useState<Note[] | null>(null);
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        let alive = true;
        const load = () => fetch('/notifications', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : null)).then((d) => { if (alive && Array.isArray(d)) setNotes(d); }).catch(() => undefined);
        load();
        const t = window.setInterval(load, 60_000);
        return () => { alive = false; window.clearInterval(t); };
    }, [path]);

    useEffect(() => {
        setOpen(false);
    }, [path]);

    useEffect(() => {
        if (!open) return;
        const close = (e: MouseEvent) => { if (box.current && !box.current.contains(e.target as Node)) setOpen(false); };
        const esc = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', esc);
        return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', esc); };
    }, [open]);

    const count = notes?.length ?? 0;
    const urgent = notes?.some((n) => n.severity === 'critical');

    return (
        <div ref={box} className="relative">
            <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} aria-label={count ? `${count} things need action` : 'Notifications'}
                className={`relative grid size-11 place-items-center rounded-full ${onDark ? 'text-white active:bg-white/15' : 'text-cobalt-800 hover:bg-cobalt-100'}`}>
                <Bell size={20} aria-hidden className={urgent ? 'kk-shake' : undefined} key={urgent ? `u${count}` : 'n'} />
                {count ? <span key={count} className={`kk-pop absolute top-1 right-1 grid min-w-5 place-items-center rounded-full px-1 text-[11px] font-bold leading-5 text-white ${urgent ? 'bg-crit-700' : 'bg-watch-700'}`}>{count}</span> : null}
            </button>
            {open ? (
                <div className="kk-drop absolute right-0 z-40 mt-1 w-[min(22rem,calc(100vw-1.5rem))] overflow-hidden rounded-tile border border-rule bg-tile shadow-tile" role="dialog" aria-label="Notifications">
                    <div className="border-b border-rule px-4 py-2.5 text-sm font-semibold text-cobalt-900">{count ? `${count} Thing${count === 1 ? '' : 's'} Need Action` : 'Nothing Needs Action'}</div>
                    {notes === null ? <p className="px-4 py-3 text-sm text-ink-soft">Loading…</p> : count ? (
                        <ul className="max-h-[60vh] divide-y divide-rule overflow-y-auto">
                            {notes.map((n) => (
                                <li key={n.key}>
                                    <Link href={n.url} className="flex items-start gap-2.5 px-4 py-2.5 hover:bg-glaze">
                                        <span className={`mt-1.5 size-2 shrink-0 rounded-full ${DOT[n.severity]}`} aria-hidden />
                                        <span className="min-w-0 flex-1"><span className="block text-sm font-semibold text-cobalt-900">{titleCase(n.label)}</span><span className="block truncate text-xs text-ink-soft">{n.action}</span></span>
                                        <span className="shrink-0 rounded-full bg-glaze px-2 text-sm font-bold tabular-nums text-cobalt-900">{n.count}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    ) : <p className="px-4 py-3 text-sm text-ink-soft">All clear for now.</p>}
                    <Link href="/actions" className="block border-t border-rule px-4 py-2.5 text-center text-sm font-semibold text-turq-800 hover:bg-glaze">Open Action Center</Link>
                </div>
            ) : null}
        </div>
    );
}
