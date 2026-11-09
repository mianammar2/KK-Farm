import { Head, Link, usePoll } from '@inertiajs/react';
import { AlertOctagon, AlertTriangle, CheckCircle2, ChevronDown, ChevronRight, Info } from 'lucide-react';
import { useState } from 'react';
import { cx, PageHeader } from '../components/ui';
import { titleCase } from '../lib/titleCase';

type Item = { key: string; label: string; severity: 'critical' | 'warning' | 'info'; count: number; action: string; url: string; items: Array<{ text: string; sub: string | null; url: string | null }> };

const LOOK = {
    critical: { icon: AlertOctagon, ring: 'border-crit-500/40', badge: 'bg-crit-100 text-crit-700', label: 'Act now' },
    warning: { icon: AlertTriangle, ring: 'border-watch-400/60', badge: 'bg-watch-100 text-watch-700', label: 'Today' },
    info: { icon: Info, ring: 'border-rule', badge: 'bg-cobalt-100 text-cobalt-800', label: 'Good to know' },
};

/** D-077: the morning check (workbook "Action Center"): every exception, what to do, and where. */
export default function Actions({ items, checkedAt }: { items: Item[]; checkedAt: string }) {
    usePoll(60000);
    const urgent = items.filter((i) => i.severity !== 'info');

    return (
        <>
            <Head title="Action Center" />
            <PageHeader title="Action Center" description={`What needs attention on the farm today. Checked ${checkedAt}, updates every minute.`} />
            {!urgent.length ? (
                <div className="mb-4 flex items-center gap-3 rounded-tile border border-ok-700/30 bg-ok-100 px-4 py-3 text-ok-700">
                    <CheckCircle2 size={22} aria-hidden /><span className="font-semibold">Nothing needs action right now.</span>
                </div>
            ) : null}
            <div className="space-y-3">
                {items.map((item) => <ActionCard key={item.key} item={item} />)}
            </div>
        </>
    );
}

function ActionCard({ item }: { item: Item }) {
    const [open, setOpen] = useState(item.severity === 'critical');
    const look = LOOK[item.severity];
    const Icon = look.icon;
    return (
        <section className={cx('rounded-tile border bg-tile shadow-tile', look.ring)}>
            <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="flex w-full items-center gap-3 px-4 py-3 text-left">
                <span className={cx('grid size-10 shrink-0 place-items-center rounded-full', look.badge)}><Icon size={19} aria-hidden /></span>
                <span className="min-w-0 flex-1">
                    <span className="block font-semibold text-cobalt-900">{titleCase(item.label)}</span>
                    <span className="block text-sm text-ink-soft">{item.action}</span>
                </span>
                <span className="text-2xl font-bold tabular-nums text-cobalt-900">{item.count}</span>
                <span className={cx('hidden rounded-[4px] px-1.5 py-0.5 text-xs font-semibold sm:inline', look.badge)}>{look.label}</span>
                <ChevronDown size={16} className={cx('text-ink-faint transition-transform', open && 'rotate-180')} aria-hidden />
            </button>
            {open ? (
                <div className="border-t border-rule">
                    <ul className="divide-y divide-rule">
                        {item.items.map((row, i) => {
                            const body = <><span className="font-mono font-semibold text-cobalt-900">{row.text}</span>{row.sub ? <span className="text-sm text-ink-soft">{row.sub}</span> : null}</>;
                            return <li key={i}>{row.url ? <Link href={row.url} className="flex flex-wrap items-baseline justify-between gap-x-3 px-4 py-2 hover:bg-glaze">{body}</Link> : <div className="flex flex-wrap items-baseline justify-between gap-x-3 px-4 py-2">{body}</div>}</li>;
                        })}
                    </ul>
                    <Link href={item.url} className="flex items-center justify-end gap-1 border-t border-rule px-4 py-2 text-sm font-semibold text-cobalt-800 hover:bg-glaze">
                        {item.count > item.items.length ? `See all ${item.count}` : 'Open'}<ChevronRight size={15} aria-hidden />
                    </Link>
                </div>
            ) : null}
        </section>
    );
}
