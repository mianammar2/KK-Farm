import { Link } from '@inertiajs/react';
import { createContext, useContext, useEffect } from 'react';

/** D-258: an app's key numbers and what needs attention, shown under the title of the app's main page (no separate page). */
export type StripKpi = { label: string; value: number | string; url: string | null; tone: 'good' | 'warning' | 'critical' | null };
export type StripAlert = { key: string; label: string; count: number; severity: string; url: string };
export type StripData = { kpis: StripKpi[]; alerts: StripAlert[] };

/** data for this page, whether the page shows its own number row (then the strip stays out), and how a page says so */
export type StripState = { data: StripData | null; hidden: boolean; hide: () => void };
export const AppStripContext = createContext<StripState>({ data: null, hidden: false, hide: () => undefined });

/** A page's own number row (StatRow) calls this: the strip is not shown twice. */
export function useHideAppStrip() {
    const { hide } = useContext(AppStripContext);
    useEffect(() => { hide(); }, [hide]);
}

const ACCENT: Record<string, string> = { good: 'before:bg-ok-700', warning: 'before:bg-watch-400', critical: 'before:bg-crit-500' };

export function AppStripSlot() {
    const { data, hidden } = useContext(AppStripContext);
    if (!data || hidden || (!data.kpis.length && !data.alerts.length)) return null;
    return (
        <section aria-label="At a glance" className="mb-4 space-y-2 lg:mb-5">
            {data.kpis.length ? (
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-[repeat(auto-fit,minmax(9.5rem,1fr))]">{data.kpis.map((k) => {
                    const body = (
                        <>
                            <span className="block text-xl leading-tight font-bold tabular-nums text-cobalt-900">{typeof k.value === 'number' ? k.value.toLocaleString('en-PK') : k.value}</span>
                            <span className="block text-xs leading-snug text-ink-soft">{k.label}</span>
                        </>
                    );
                    const cls = `relative overflow-hidden rounded-tile border border-rule bg-tile py-2 ps-4 pe-3 shadow-tile before:absolute before:inset-y-2 before:start-1.5 before:w-1 before:rounded-full ${k.tone ? ACCENT[k.tone] : 'before:bg-cobalt-600'}`;
                    return k.url ? <Link key={k.label} href={k.url} className={`${cls} kk-lift`}>{body}</Link> : <div key={k.label} className={cls}>{body}</div>;
                })}</div>
            ) : null}
            {data.alerts.length ? (
                <div className="flex flex-wrap items-center gap-1.5 text-sm">
                    <span className="font-semibold text-ink-soft">Needs attention:</span>
                    {data.alerts.map((a) => (
                        <Link key={a.key} href={a.url} className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${a.severity === 'critical' ? 'bg-crit-100 text-crit-700' : a.severity === 'warning' ? 'bg-watch-100 text-watch-700' : 'bg-cobalt-100 text-cobalt-800'}`}>
                            {a.label}<span className="rounded-full bg-white/70 px-1.5 tabular-nums">{a.count}</span>
                        </Link>
                    ))}
                </div>
            ) : null}
        </section>
    );
}
