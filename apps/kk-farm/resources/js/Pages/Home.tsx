import { Head, Link } from '@inertiajs/react';
import { Empty, PageHeader, Panel } from '../components/ui';

type Num = { label: string; value: number | string; url: string; severity: string };
type Props = {
    role: string; numbers: Num[]; links: Array<{ href: string; label: string }>;
    tasks: Array<{ id: number; title: string; due: string | null; overdue: boolean; priority: string }>;
    alerts: Array<{ key: string; label: string; count: number; url: string; severity: string; action: string }>;
};
const tone = (s: string) => (s === 'critical' ? 'bg-crit-500' : s === 'warning' ? 'bg-watch-700' : 'bg-cobalt-600');

/** D-241 (RBAC §6): the role's own home: only its work. */
export default function Home({ role, numbers: all, links, tasks, alerts }: Props) {
    const numbers = all.filter((n) => n.value !== 0 && n.value !== 'PKR 0'); // hide zeros (dashboard rule)
    return (
        <>
            <Head title="My Home" />
            <PageHeader title="My Home" description={`${role}: your tasks, the alerts for your work and the pages you use most.`} />
            {numbers.length ? <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">{numbers.map((n) => (
                <Link key={n.label} href={n.url} className="kk-lift rounded-tile border border-rule bg-tile p-3 shadow-tile">
                    <span className={`block h-1 w-8 rounded-full ${tone(n.severity)}`} aria-hidden />
                    <span className="mt-2 block text-2xl font-bold tabular-nums text-cobalt-900">{n.value}</span>
                    <span className="text-sm text-ink-soft">{n.label}</span>
                </Link>
            ))}</div> : null}
            <div className="grid gap-4 lg:grid-cols-3">
                <Panel title="My Tasks Today" description="Given to you or your department, due today or late." className="lg:col-span-2" flush>
                    {tasks.length ? <ul className="divide-y divide-rule">{tasks.map((t) => (
                        <li key={t.id} className="flex items-center gap-3 px-4 py-2.5 text-sm"><span className="flex-1 font-medium text-cobalt-900">{t.title}</span>
                            <span className={t.overdue ? 'font-semibold text-crit-700' : 'text-ink-soft'}>{t.overdue ? `Late · ${t.due}` : 'Today'}</span></li>
                    ))}</ul> : <Empty title="Nothing due today" />}
                    <div className="border-t border-rule px-4 py-2"><Link href="/tasks?tab=mine" className="text-sm font-semibold text-cobalt-800 hover:underline">All my tasks</Link></div>
                </Panel>
                <Panel title="My Pages">
                    <ul className="space-y-1">{links.map((l) => <li key={l.href}><Link href={l.href} className="block rounded-tile px-2 py-1.5 text-sm font-semibold text-cobalt-800 hover:bg-glaze">{l.label}</Link></li>)}</ul>
                </Panel>
            </div>
            <Panel title="Alerts for You" className="mt-4" flush>
                {alerts.length ? <ul className="divide-y divide-rule">{alerts.map((a) => (
                    <li key={a.key}><Link href={a.url} className="flex items-center gap-3 px-4 py-2.5 text-sm hover:bg-glaze">
                        <span className={`size-2 shrink-0 rounded-full ${tone(a.severity)}`} aria-hidden /><span className="flex-1"><span className="font-semibold text-cobalt-900">{a.label}</span><span className="block text-xs text-ink-soft">{a.action}</span></span>
                        <span className="font-bold tabular-nums text-cobalt-900">{a.count}</span></Link></li>
                ))}</ul> : <Empty title="No alerts for you right now" />}
            </Panel>
        </>
    );
}
