import BrandMark from './BrandMark';
import { Link, router } from '@inertiajs/react';
import { ChevronLeft, Smartphone, ClipboardList, HeartPulse, KeyRound, LayoutGrid, LogOut, MoreHorizontal, ScanLine, Search, X, CalendarCheck } from 'lucide-react';
import { NavIcon } from './ArtIcon';
import { AnimatePresence, motion } from 'motion/react';
import { useState, type ReactNode } from 'react';
import { cx } from './ui';
import { titleCase } from '../lib/titleCase';

export type NavItem = { href: string; label: string; module: string; icon: typeof LayoutGrid };

/**
 * App-style navigation for phones and the RFID gun (below the lg breakpoint): a coloured app bar with a
 * back button and the page title, and a bottom tab bar with a raised Scan button. Desktop keeps the sidebar.
 */

const TITLES: Array<[RegExp, string]> = [
    [/^\/goats\/register/, 'Register goat'],
    [/^\/goats\/[^/]+$/, 'Goat'],
    [/^\/settings\/users/, 'Users'],
    [/^\/settings\/fields/, 'Register fields'],
    [/^\/account\/password/, 'Password'],
];

/** Pages one level below a tab show a back arrow instead of the logo. */
const isDetail = (path: string) => path.split('/').filter(Boolean).length > 1 || path.startsWith('/account');

export function pageTitle(path: string, items: NavItem[]): string {
    if (path === '/apps') return 'KK Farms';
    for (const [re, title] of TITLES) {
        if (re.test(path)) return title;
    }
    const item = items.find((i) => (i.href === '/' ? path === '/' : path.startsWith(i.href)));
    return titleCase(item?.label) ?? 'KK Farms';
}

export function AppBar({ path, items, right }: { path: string; items: NavItem[]; right: ReactNode }) {
    const [searching, setSearching] = useState(false);
    const [q, setQ] = useState('');
    const back = () => (window.history.length > 1 ? window.history.back() : router.visit('/'));

    return (
        <header className="sticky top-0 z-30 bg-cobalt-900 pt-[env(safe-area-inset-top)] text-white shadow-[0_6px_20px_-12px_rgb(0_0_0/0.5)] lg:hidden">
            <div className="flex h-14 items-center gap-1 px-2">
                {isDetail(path) ? (
                    <button type="button" aria-label="Back" onClick={back} className="grid size-11 place-items-center rounded-full active:bg-white/15"><ChevronLeft size={24} /></button>
                ) : (
                    <span className="grid size-11 place-items-center" aria-hidden>
                        <BrandMark size={34} />
                    </span>
                )}
                <AnimatePresence mode="wait" initial={false}>
                    <motion.h1 key={path} initial={{ opacity: 0, y: 6 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -6 }} transition={{ duration: 0.18 }} className="min-w-0 flex-1 truncate text-lg font-bold tracking-tight">
                        {pageTitle(path, items)}
                    </motion.h1>
                </AnimatePresence>
                <button type="button" aria-label="Search goats" onClick={() => setSearching(true)} className="grid size-11 place-items-center rounded-full active:bg-white/15"><Search size={21} /></button>
                {right}
            </div>
            <AnimatePresence>
                {searching ? (
                    <motion.form
                        initial={{ opacity: 0, y: -8 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: -8 }} transition={{ duration: 0.16 }}
                        onSubmit={(e) => { e.preventDefault(); setSearching(false); router.get('/goats', q ? { q } : {}); }}
                        className="absolute inset-x-0 top-0 flex h-[calc(3.5rem+env(safe-area-inset-top))] items-end gap-1 bg-cobalt-900 px-2 pb-1.5"
                    >
                        <input autoFocus value={q} onChange={(e) => setQ(e.target.value)} placeholder="Tag, name or breed" className="h-11 min-w-0 flex-1 rounded-full bg-white/12 px-4 text-white placeholder:text-cobalt-200 focus:bg-white/20 focus:outline-none" />
                        <button type="button" aria-label="Close search" onClick={() => setSearching(false)} className="grid size-11 place-items-center rounded-full active:bg-white/15"><X size={21} /></button>
                    </motion.form>
                ) : null}
            </AnimatePresence>
        </header>
    );
}

export function TabBar({ path, modules, items, onLogout }: { path: string; modules: string[]; items: NavItem[]; onLogout: () => void }) {
    const [more, setMore] = useState(false);
    const can = (m: string) => modules.includes(m);
    const tabs = [
        { href: '/apps', label: 'Apps', icon: LayoutGrid }, // D-168: the app launcher
        can('goats') && { href: '/goats', label: 'Goats', icon: ClipboardList },
        can('health') ? { href: '/health', label: 'Health', icon: HeartPulse } : can('tasks') && { href: '/tasks', label: 'Tasks', icon: CalendarCheck },
    ].filter(Boolean) as Array<{ href: string; label: string; icon: typeof LayoutGrid }>;
    const active = (href: string) => (href === '/' ? path === '/' : path.startsWith(href) && !path.startsWith('/goats/register'));
    const tab = (t: { href: string; label: string; icon: typeof LayoutGrid }) => (
        <Link key={t.href} href={t.href} prefetch className="relative flex flex-1 flex-col items-center justify-center gap-0.5 py-1.5 text-[11px] font-semibold">
            {active(t.href) ? <motion.span layoutId="tab-pill" className="absolute top-1 h-8 w-14 rounded-full bg-turq-100" transition={{ type: 'spring', stiffness: 500, damping: 38 }} /> : null}
            <NavIcon href={t.href} icon={t.icon} size={26} className={cx('relative transition-transform', active(t.href) ? 'scale-110' : 'opacity-90')} />
            <span className={cx('relative', active(t.href) ? 'text-cobalt-900' : 'text-ink-soft')}>{t.label}</span>
        </Link>
    );

    return (
        <>
            <nav aria-label="Main" className="fixed inset-x-0 bottom-0 z-30 border-t border-rule bg-tile pb-[env(safe-area-inset-bottom)] lg:hidden">
                <div className="mx-auto flex h-16 max-w-xl items-stretch px-1">
                    {tabs.slice(0, 2).map(tab)}
                    {can('scan') ? (
                        <div className="flex flex-1 justify-center">
                            <Link href="/scan" aria-label="Scan a tag" className="relative -mt-6 grid size-16 place-items-center rounded-full bg-turq-600 text-white shadow-float ring-4 ring-tile transition-transform active:scale-95">
                                {path !== '/scan' ? <span aria-hidden className="kk-pulse absolute inset-0 rounded-full bg-turq-500" /> : null}
                                <ScanLine size={28} className="relative" />
                            </Link>
                        </div>
                    ) : null}
                    {tabs.slice(2).map(tab)}
                    <button type="button" onClick={() => setMore(true)} className="flex flex-1 flex-col items-center justify-center gap-0.5 py-1.5 text-[11px] font-semibold text-ink-soft">
                        <MoreHorizontal size={22} aria-hidden /> More
                    </button>
                </div>
            </nav>

            <AnimatePresence>
                {more ? (
                    <motion.div className="fixed inset-0 z-50 lg:hidden" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }}>
                        <button type="button" aria-label="Close" className="absolute inset-0 bg-cobalt-950/55" onClick={() => setMore(false)} />
                        <motion.div
                            role="dialog" aria-label="More"
                            initial={{ y: '100%' }} animate={{ y: 0 }} exit={{ y: '100%' }} transition={{ type: 'spring', stiffness: 420, damping: 40 }}
                            drag="y" dragConstraints={{ top: 0, bottom: 0 }} dragElastic={{ top: 0, bottom: 0.6 }} onDragEnd={(_, info) => info.offset.y > 90 && setMore(false)}
                            className="absolute inset-x-0 bottom-0 rounded-t-3xl bg-tile px-4 pt-2 pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-float"
                        >
                            <div className="mx-auto mb-3 h-1.5 w-10 rounded-full bg-rule-strong" />
                            <div className="grid grid-cols-3 gap-2">
                                {items.filter((i) => can(i.module)).map((i, n) => (
                                    <motion.div key={i.href} initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.03 * n, duration: 0.2 }}>
                                        <Link href={i.href} onClick={() => setMore(false)} className={cx('flex flex-col items-center gap-1.5 rounded-2xl p-3 text-center text-xs font-semibold transition-transform active:scale-95', active(i.href) ? 'bg-turq-100 text-cobalt-900' : 'bg-glaze text-ink')}>
                                            <NavIcon href={i.href} icon={i.icon} size={44} />
                                            {titleCase(i.label)}
                                        </Link>
                                    </motion.div>
                                ))}
                            </div>
                            <div className="mt-3 grid grid-cols-3 gap-2">
                                <Link href="/account/app" onClick={() => setMore(false)} className="flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-glaze text-sm font-semibold active:scale-95"><Smartphone size={17} aria-hidden /> App</Link>
                                <Link href="/account/password" onClick={() => setMore(false)} className="flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-glaze text-sm font-semibold active:scale-95"><KeyRound size={17} aria-hidden /> Password</Link>
                                <button type="button" onClick={onLogout} className="flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-crit-100 text-sm font-semibold text-crit-700 active:scale-95"><LogOut size={17} aria-hidden /> Sign out</button>
                            </div>
                        </motion.div>
                    </motion.div>
                ) : null}
            </AnimatePresence>
        </>
    );
}
