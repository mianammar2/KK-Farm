import { Head, Link, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { NavIcon } from '../components/ArtIcon';
import { NAV } from '../components/AppShell';
import { titleCase } from '../lib/titleCase';
import type { SharedProps } from '../types';

/** Notes from the Action Center feed (same as the bell). */
type Note = { key: string; count: number; severity: 'critical' | 'warning' | 'info' };

const greeting = () => {
    const h = new Date().getHours();
    return h < 12 ? 'Good Morning' : h < 17 ? 'Good Afternoon' : 'Good Evening';
};

/**
 * D-168: the app launcher, KK Farms' home after login (Odoo style): every part of the system the user may open, as a
 * large icon tile from the KK Farms icon library, with a search box. Action Center shows how many things need action.
 */
export default function Apps() {
    const { auth } = usePage<SharedProps>().props;
    const [q, setQ] = useState('');
    const [notes, setNotes] = useState<Note[] | null>(null);

    useEffect(() => {
        let alive = true;
        fetch('/notifications', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : null)).then((d) => { if (alive && Array.isArray(d)) setNotes(d); }).catch(() => undefined);
        return () => { alive = false; };
    }, []);

    const apps = useMemo(() => NAV.flatMap((g) => g.items).filter((i) => auth?.modules.includes(i.module))
        .filter((i) => !q.trim() || i.label.toLowerCase().includes(q.trim().toLowerCase())), [auth, q]);
    const badge = (href: string) => (href === '/actions' && notes?.length ? { n: notes.length, urgent: notes.some((x) => x.severity === 'critical') } : null);

    return (
        <>
            <Head title="Apps" />
            <div className="kk-apps relative -mx-3 -mt-4 min-h-[calc(100dvh-4rem)] px-4 pt-8 pb-16 sm:-mx-5 sm:px-8 lg:-my-5">
                <div className="mx-auto max-w-5xl">
                    <div className="mb-8 flex flex-col items-center gap-3 text-center">
                        <h1 className="text-2xl font-extrabold tracking-tight text-cobalt-900 sm:text-3xl">{greeting()}{auth?.name ? `, ${auth.name}` : ''}</h1>
                        <p className="text-sm text-ink-soft">{auth?.roleLabel} · choose an app</p>
                        <label className="relative mt-1 w-full max-w-md">
                            <Search size={17} aria-hidden className="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-ink-faint" />
                            <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search apps" aria-label="Search apps" className="field rounded-full! pl-10 shadow-sm" />
                        </label>
                    </div>
                    <ul className="grid grid-cols-3 gap-x-3 gap-y-7 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6">
                        {apps.map((a, i) => {
                            const b = badge(a.href);
                            return (
                                <li key={a.href} className="kk-app-tile" style={{ animationDelay: `${Math.min(i, 24) * 25}ms` }}>
                                    <Link href={a.href} prefetch className="group flex flex-col items-center gap-2 rounded-2xl p-1 text-center outline-none focus-visible:ring-2 focus-visible:ring-turq-400">
                                        <span className="kk-app-icon relative">
                                            <NavIcon href={a.href} icon={a.icon} size={68} className="kk-app-art !rounded-[22%] !shadow-none !ring-0" />
                                            {b ? <span className={`absolute -top-1.5 -right-1.5 grid min-w-6 place-items-center rounded-full px-1.5 text-xs font-bold leading-6 text-white shadow ${b.urgent ? 'bg-crit-700' : 'bg-watch-700'}`}>{b.n}</span> : null}
                                        </span>
                                        <span className="text-[13px] leading-tight font-semibold text-cobalt-900 sm:text-sm">{titleCase(a.label)}</span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                    {!apps.length ? <p className="mt-6 text-center text-sm text-ink-soft">No app matches “{q}”.</p> : null}
                </div>
            </div>
        </>
    );
}
