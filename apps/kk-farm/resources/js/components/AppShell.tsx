import BrandMark from './BrandMark';
import NotificationBell from './NotificationBell';
import { ArtIcon, NavIcon } from './ArtIcon';
import { canOpen, currentApp, GROUPS, isHere, masterHref, menuHref, rememberApp, sectionsFor, setPageRules } from '../lib/apps';
import { AppStripContext, type StripData } from './AppStrip';
import { Link, router, usePage } from '@inertiajs/react';
import {
    BarChart3, Beef, CalendarCheck, ChevronDown, ChevronRight, ClipboardList, HeartPulse, LayoutGrid, LogOut, Menu, Network, Radio, Scale, Search, Settings, Users, Wallet, Warehouse, BookOpen, Cpu, Wheat, X, ArrowLeftRight, BellRing, TrendingUp, Milk, History } from 'lucide-react';
import { AnimatePresence, motion, useReducedMotion } from 'motion/react';
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { cx } from './ui';
import { ScanCapture } from '../lib/scanner';
import { AppBar, TabBar } from './MobileNav';
import type { SharedProps } from '../types';
import { titleCase } from '../lib/titleCase';

export const NAV: Array<{ heading: string; items: Array<{ href: string; label: string; module: string; icon: typeof Beef }> }> = [
    {
        heading: 'Herd',
        items: [
            { href: '/', label: 'Dashboard', module: 'dashboard', icon: LayoutGrid },
            { href: '/actions', label: 'Action Center', module: 'dashboard', icon: BellRing },
            { href: '/goats', label: 'Goat Registry', module: 'goats', icon: ClipboardList },
            { href: '/scan', label: 'RFID Scan', module: 'scan', icon: Radio },
        ],
    },
    {
        heading: 'Care',
        items: [
            { href: '/breeding', label: 'Breeding & Pedigree', module: 'breeding', icon: Network },
            { href: '/milking', label: 'Milking', module: 'milking', icon: Milk },
            { href: '/health', label: 'Veterinary & Health', module: 'health', icon: HeartPulse },
            { href: '/weights', label: 'Weight & Growth', module: 'weights', icon: Scale },
            { href: '/feeding', label: 'Feeding & Nutrition', module: 'feeding', icon: Wheat },
        ],
    },
    {
        heading: 'Farm',
        items: [
            { href: '/sheds', label: 'Sheds', module: 'sheds', icon: Warehouse },
            { href: '/movements?kind=transfer', label: 'Pen Movements', module: 'sheds', icon: ArrowLeftRight },
            { href: '/tasks', label: 'Tasks & Daily Work', module: 'tasks', icon: CalendarCheck },
            { href: '/employees', label: 'Employees', module: 'employees', icon: Users },
            { href: '/activity', label: 'Activity Log', module: 'dashboard', icon: History },
        ],
    },
    {
        heading: 'Business',
        items: [
            { href: '/value', label: 'Herd Value', module: 'value', icon: TrendingUp },
            { href: '/finance', label: 'Expenses & Finance', module: 'finance', icon: Wallet },
            { href: '/finance/books', label: 'Accounts (books)', module: 'finance', icon: BookOpen },
            { href: '/sensors', label: 'Sensors & cameras', module: 'sensors', icon: Cpu },
            { href: '/reports', label: 'Reports', module: 'reports', icon: BarChart3 },
            { href: '/settings', label: 'Settings', module: 'settings', icon: Settings },
        ],
    },
];

function Brand() {
    // D-155: only the KK Farms logo; its badge already carries the name and the tagline.
    return (
        <Link href="/apps" className="flex justify-center px-2 py-1" aria-label="KK Farms apps">
            <BrandMark size={72} />
        </Link>
    );
}

/**
 * D-278: an app's menu as folders: only the headings (Overview, Operations, Records, Reporting …) show, each closed;
 * click one to open it. The heading of the page you are on opens by itself. What you open is remembered on this device.
 */
function AppMenu({ appKey, sections, active, onNavigate }: { appKey: string; sections: ReturnType<typeof sectionsFor>; active: number; onNavigate?: () => void }) {
    const store = `kk.menu.${appKey}`;
    const activeGroup = active >= 0 ? sections[active]?.group : undefined;
    const [open, setOpen] = useState<string[]>(() => { try { return JSON.parse(localStorage.getItem(store) ?? '[]') as string[]; } catch { return []; } });
    const toggle = (g: string) => {
        const next = open.includes(g) ? open.filter((x) => x !== g) : [...open, g];
        setOpen(next);
        try { localStorage.setItem(store, JSON.stringify(next)); } catch { /* private window */ }
    };
    const link = (x: (typeof sections)[number] & { i: number }) => (
        <Link href={menuHref(x.href)} onClick={() => { rememberApp(appKey); onNavigate?.(); }} aria-current={x.i === active ? 'page' : undefined}
            className={cx('flex min-h-9 items-center rounded-tile px-2.5 py-1.5 text-sm transition-colors duration-150', x.i === active ? 'bg-tile text-cobalt-900 shadow-tile' : 'text-cobalt-100 hover:bg-cobalt-800')}>
            {x.label}
        </Link>
    );
    // D-279: the app's main pages (Overview) sit at the top as plain links, no heading; the other headings are folders
    const top = sections.map((x, i) => ({ ...x, i })).filter((x) => x.group === 'Overview');
    return (
        <ul className="space-y-1">
            {top.map((x) => <li key={`top${x.href}`}>{link(x)}</li>)}
            {GROUPS.filter((g) => g !== 'Overview').map((g) => {
                const items = sections.map((x, i) => ({ ...x, i })).filter((x) => x.group === g);
                if (!items.length) return null;
                const isOpen = open.includes(g) || g === activeGroup;
                const id = `menu-${appKey}-${g.replace(/\W+/g, '-')}`;
                return (
                    <li key={g}>
                        <button type="button" onClick={() => toggle(g)} aria-expanded={isOpen} aria-controls={id}
                            className={cx('flex min-h-10 w-full items-center gap-2 rounded-tile px-2.5 text-start text-sm font-medium transition-colors duration-150 hover:bg-cobalt-800', isOpen ? 'text-white' : 'text-cobalt-100')}>
                            <ChevronRight size={16} aria-hidden className={cx('shrink-0 transition-transform duration-150', isOpen && 'rotate-90')} />
                            <span className="flex-1">{g}</span>
                            <span className="rounded-full bg-white/10 px-1.5 text-xs text-cobalt-200">{items.length}</span>
                        </button>
                        {isOpen ? (
                            <ul id={id} className="mt-0.5 mb-1 ms-4 space-y-0.5 border-s border-white/15 ps-2">
                                {items.map((x) => <li key={`${x.group}${x.href}`}>{link(x)}</li>)}
                            </ul>
                        ) : null}
                    </li>
                );
            })}
        </ul>
    );
}

function NavList({ path, url, modules, onNavigate }: { path: string; url: string; modules: string[]; onNavigate?: () => void }) {
    // D-170: inside an app, only that app's sections and the apps its records link to (Odoo style).
    const app = currentApp(url, modules);
    if (app) {
        const here = sectionsFor(app).findIndex((x) => isHere(x.href, url));
        const samePath = sectionsFor(app).findIndex((x) => x.href.split('#')[0].split('?')[0] === path);
        const active = here >= 0 ? here : samePath;
        // D-241: RBAC §8.1: no Linked Apps; other apps are reached from All Apps only. Only pages this person may open.
        const sections = sectionsFor(app);
        return (
            <nav aria-label={`${app.label} menu`} className="mt-4 flex-1 overflow-y-auto px-2 pb-4">
                <Link href="/apps" onClick={onNavigate} className="mb-3 flex min-h-10 items-center gap-2 rounded-tile px-2.5 text-sm font-semibold text-cobalt-200 hover:bg-cobalt-800 hover:text-white">
                    <LayoutGrid size={17} aria-hidden /> All Apps
                </Link>
                <Link href={masterHref(app.key)} onClick={onNavigate} className="mb-2 flex items-center gap-2.5 rounded-tile bg-white/10 px-2.5 py-2.5 hover:bg-white/15">
                    <ArtIcon name={app.art} size={38} />
                    <span className="min-w-0 text-base leading-tight font-bold text-white">{app.label}</span>
                </Link>
                <AppMenu appKey={app.key} sections={sections} active={active} onNavigate={onNavigate} />
            </nav>
        );
    }
    return (
        <nav aria-label="Main" className="mt-5 flex-1 space-y-5 overflow-y-auto px-2 pb-4">
            {NAV.map((group) => {
                const items = group.items.filter((item) => modules.includes(item.module) && canOpen(item.href)); // D-241
                if (!items.length) {
                    return null;
                }
                return (
                    <div key={group.heading} className="border-t border-white/10 pt-2 first:border-t-0 first:pt-0">
                        <ul className="space-y-0.5">
                            {items.map((item) => (
                                <li key={item.href}>
                                    <Link href={item.href} prefetch onClick={onNavigate} className="flex min-h-10 items-center gap-2.5 rounded-tile px-2.5 py-1.5 text-sm font-medium text-cobalt-100 transition-colors duration-150 hover:bg-cobalt-800">
                                        <NavIcon href={item.href} icon={item.icon} size={26} />
                                        {titleCase(item.label)}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>
                );
            })}
        </nav>
    );
}

function RoleSwitcher({ auth, roles, compact }: { auth: NonNullable<SharedProps['auth']>; roles: SharedProps['roles']; compact?: boolean }) {
    const { demoLogin } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);
    const root = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const close = (event: MouseEvent) => {
            if (!root.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, []);

    return (
        <div className="relative" ref={root}>
            {compact ? (
                <button type="button" aria-label="Account" aria-expanded={open} onClick={() => setOpen(!open)} className="grid size-11 place-items-center rounded-full active:bg-white/15">
                    <span className="grid size-8 place-items-center rounded-full bg-turq-400 text-xs font-bold text-cobalt-950">{(auth.name.match(/[A-Za-z]+/g) ?? ['K']).map((w) => w[0]).join('').slice(0, 2).toUpperCase()}</span>
                </button>
            ) : (
            <button type="button" aria-expanded={open} onClick={() => setOpen(!open)} className="flex min-h-11 items-center gap-2 rounded-tile border border-rule-strong bg-tile px-2.5 text-left hover:bg-cobalt-100">
                <span className="grid size-7 place-items-center rounded-[4px] bg-cobalt-800 text-xs font-bold text-white">{auth.roleLabel.slice(0, 2).toUpperCase()}</span>
                <span className="hidden leading-tight sm:block">
                    <span className="block text-sm font-semibold">{auth.name}</span>
                    <span className="block text-xs text-ink-soft">{auth.roleLabel}</span>
                </span>
                <ChevronDown size={15} className="text-ink-soft" aria-hidden />
            </button>
            )}
            {open ? (
                <div className="absolute right-0 z-40 mt-1.5 w-60 rounded-tile border border-rule bg-tile p-1 text-ink shadow-float">
                    <Link href="/account/password" onClick={() => setOpen(false)} className="flex min-h-10 w-full items-center rounded-[4px] px-2.5 text-left text-sm hover:bg-cobalt-100">Change password</Link>
                    <Link href="/account/app" onClick={() => setOpen(false)} className="flex min-h-10 w-full items-center rounded-[4px] px-2.5 text-left text-sm hover:bg-cobalt-100">App and updates</Link>
                    {demoLogin ? <div className="px-2.5 py-1.5 text-sm text-ink-soft">Local testing: view the app as</div> : null}
                    {demoLogin && Object.entries(roles).map(([role, label]) => (
                        <button
                            key={role}
                            type="button"
                            onClick={() => router.post('/switch-role', { role })}
                            className={cx('flex min-h-10 w-full items-center rounded-[4px] px-2.5 text-left text-sm hover:bg-cobalt-100', role === auth.role && 'bg-cobalt-100 font-semibold text-cobalt-900')}
                        >
                            {label}
                        </button>
                    ))}
                    <div className="my-1 border-t border-rule" />
                    <button type="button" onClick={() => router.post('/logout')} className="flex min-h-10 w-full items-center gap-2 rounded-[4px] px-2.5 text-left text-sm text-crit-700 hover:bg-crit-100">
                        <LogOut size={15} aria-hidden /> Sign out
                    </button>
                </div>
            ) : null}
        </div>
    );
}

export default function AppShell({ children }: { children: ReactNode }) {
    const page = usePage<SharedProps>();
    const { auth, roles, flash } = page.props;
    setPageRules(auth?.pages); // D-241: menus follow the same page rules as the server
    // D-258: on an app's main page (the first page of its menu) its key numbers sit under the title
    const stripApp = auth ? currentApp(page.url, auth.modules) : null;
    const landing = stripApp && stripApp.key !== 'dashboard' && sectionsFor(stripApp)[0] && isHere(sectionsFor(stripApp)[0].href, page.url) ? stripApp.key : null;
    const [strip, setStrip] = useState<StripData | null>(null);
    // a page with its own number row hides the strip; "hidden" belongs to the address it was asked on, so no reset races the page
    const urlNow = useRef(page.url);
    urlNow.current = page.url;
    const [hiddenFor, setHiddenFor] = useState<string | null>(null);
    const hideStrip = useCallback(() => setHiddenFor(urlNow.current), []);
    const stripState = useMemo(() => ({ data: strip, hidden: hiddenFor === page.url, hide: hideStrip }), [strip, hiddenFor, page.url, hideStrip]);
    useEffect(() => {
        setStrip(null);
        if (!landing) return;
        const c = new AbortController();
        fetch(`/dashboard/app/${landing}`, { headers: { Accept: 'application/json' }, signal: c.signal }).then((r) => (r.ok ? r.json() : null)).then((d) => d && setStrip(d)).catch(() => undefined);
        return () => c.abort();
    }, [landing, page.url]);
    const [drawer, setDrawer] = useState(false);
    const [search, setSearch] = useState('');
    const [toast, setToast] = useState<string | null>(null);
    const path = page.url.split('#')[0].split('?')[0]; // D-183: ignore the #anchor (e.g. /settings#users)
    const home = path === '/apps'; // D-168: the app launcher has no sidebar, like Odoo's home
    // D-183: the #section (e.g. /settings#ages) lives in the browser's address, not in Inertia's page URL; follow it.
    const [hash, setHash] = useState(() => (typeof window !== 'undefined' ? window.location.hash : ''));
    useEffect(() => {
        const sync = () => setHash(window.location.hash);
        sync();
        window.addEventListener('hashchange', sync);
        return () => window.removeEventListener('hashchange', sync);
    }, [page.url]);
    const fullUrl = page.url.includes('#') ? page.url : `${page.url}${hash}`;
    const reduced = useReducedMotion();

    useEffect(() => {
        if (!flash) {
            return;
        }
        setToast(flash);
        const timer = setTimeout(() => setToast(null), 4500);
        return () => clearTimeout(timer);
    }, [flash, page.url]);

    if (!auth) {
        return <>{children}</>;
    }

    const submitSearch = (event: React.FormEvent) => {
        event.preventDefault();
        router.get('/goats', search ? { q: search } : {});
    };

    return (
        <div className={cx('min-h-dvh overflow-x-clip bg-glaze', !home && 'lg:pl-60')}>
            {/* The Scan page and the register page have their own scan field. */}
            <ScanCapture disabled={path === '/scan' || path === '/goats/register'} />
            {home ? null : (
                <aside className="fixed inset-y-0 left-0 z-30 hidden w-60 flex-col bg-cobalt-900 pt-4 lg:flex">
                    <Brand />
                    <NavList path={path} url={fullUrl} modules={auth.modules} />
                </aside>
            )}

            <AnimatePresence>
                {drawer ? (
                    <motion.div className="fixed inset-0 z-50 lg:hidden" initial={{ opacity: 0 }} animate={{ opacity: 1 }} exit={{ opacity: 0 }} transition={{ duration: 0.15 }}>
                        <button type="button" aria-label="Close menu" className="absolute inset-0 bg-cobalt-950/60" onClick={() => setDrawer(false)} />
                        <motion.aside initial={{ x: -280 }} animate={{ x: 0 }} exit={{ x: -280 }} transition={{ duration: 0.22, ease: [0.16, 1, 0.3, 1] }} className="absolute inset-y-0 left-0 flex w-68 max-w-[85vw] flex-col bg-cobalt-900 pt-4">
                            <div className="flex items-center justify-between pr-2">
                                <Brand />
                                <button type="button" aria-label="Close menu" onClick={() => setDrawer(false)} className="grid size-11 place-items-center text-white">
                                    <X size={20} />
                                </button>
                            </div>
                            <NavList path={path} url={fullUrl} modules={auth.modules} onNavigate={() => setDrawer(false)} />
                        </motion.aside>
                    </motion.div>
                ) : null}
            </AnimatePresence>

            <AppBar path={path} items={NAV.flatMap((g) => g.items)} right={<div className="flex items-center gap-1"><NotificationBell path={path} onDark /><RoleSwitcher auth={auth} roles={roles} compact /></div>} />
            <header className="sticky top-0 z-20 hidden items-center gap-2 border-b border-rule bg-tile/95 px-3 py-2 backdrop-blur sm:px-5 lg:flex">
                <button type="button" aria-label="Open menu" onClick={() => setDrawer(true)} className="grid size-11 shrink-0 place-items-center rounded-tile text-cobalt-800 hover:bg-cobalt-100 lg:hidden">
                    <Menu size={21} />
                </button>
                {/* D-168: back to the app launcher from anywhere */}
                <Link href="/apps" aria-label="All apps" title="All apps" className={cx('grid size-10 shrink-0 place-items-center rounded-tile text-cobalt-800 hover:bg-cobalt-100', home && 'bg-cobalt-100')}>
                    <LayoutGrid size={20} />
                </Link>
                {home ? <Link href="/apps" className="shrink-0"><BrandMark size={40} /></Link> : null}
                {home || !auth?.modules.includes('goats') ? null : ( // D-241: no goat search for roles without the goat register (RBAC §2.3)
                    <form onSubmit={submitSearch} className="relative min-w-0 flex-1 sm:max-w-sm" role="search">
                        <Search size={16} aria-hidden className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-faint" />
                        <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search goats" aria-label="Search goats by number, name or tag" className="field pl-9" />
                    </form>
                )}
                {page.props.demo ? (
                    <span className="ml-auto hidden items-center gap-1.5 rounded-[4px] bg-watch-100 px-2 py-1 text-xs font-semibold text-watch-700 md:inline-flex">
                        Synthetic demo data
                    </span>
                ) : <span className="ml-auto" />}
                <NotificationBell path={path} />
                <RoleSwitcher auth={auth} roles={roles} />
            </header>

            {/* Page transition: a short slide like a native app; only a fade when the user prefers less motion. */}
            <motion.main
                key={path}
                initial={reduced ? { opacity: 0 } : { opacity: 0, x: 22 }}
                animate={{ opacity: 1, x: 0 }}
                transition={reduced ? { duration: 0.15 } : { type: 'spring', stiffness: 380, damping: 34, mass: 0.7 }}
                className="kk-page mx-auto max-w-[1440px] px-3 pt-4 pb-28 sm:px-5 lg:py-5"
            >
                <AppStripContext.Provider value={stripState}>{children}</AppStripContext.Provider>
            </motion.main>

            <TabBar path={path} modules={auth.modules} items={NAV.flatMap((g) => g.items)} onLogout={() => router.post('/logout')} />

            <AnimatePresence>
                {toast ? (
                    <motion.div role="status" initial={{ opacity: 0, y: 12 }} animate={{ opacity: 1, y: 0 }} exit={{ opacity: 0, y: 12 }} transition={{ duration: 0.2 }} className="fixed right-4 bottom-24 left-4 z-50 rounded-2xl bg-cobalt-900 px-4 py-3 text-sm font-medium text-white shadow-float sm:left-auto sm:max-w-sm lg:bottom-4">
                        {toast}
                    </motion.div>
                ) : null}
            </AnimatePresence>
        </div>
    );
}
