import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import { Button, Empty, PageHeader, Panel } from '../components/ui';

type Row = { id: number; actor: string; role: string; action: string; url: string | null; at: string; ago: string; day: string };
type Props = { activity: Row[]; more: boolean; people: string[]; filters: { q: string; who: string; pages: number } };

/** D-122: who did what and when, grouped by day, newest first; moved here from the dashboard. */
export default function Activity({ activity, more, people, filters }: Props) {
    const [q, setQ] = useState(filters.q);
    const go = (next: Partial<Props['filters']>) => router.get('/activity', { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });
    const days = activity.reduce<Array<{ day: string; rows: Row[] }>>((acc, r) => {
        const last = acc[acc.length - 1];
        last && last.day === r.day ? last.rows.push(r) : acc.push({ day: r.day, rows: [r] });
        return acc;
    }, []);

    return (
        <>
            <Head title="Activity Log" />
            <PageHeader title="Activity Log" description="Everything recorded on the farm: who did it and when, newest first." />
            <div className="mb-4 flex flex-wrap gap-2">
                <form onSubmit={(e) => { e.preventDefault(); go({ q, pages: 1 }); }} className="relative min-w-0 flex-1 sm:max-w-sm" role="search">
                    <Search size={16} aria-hidden className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-faint" />
                    <input className="field pl-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search, e.g. TD141026-06 or checkup" aria-label="Search activity" />
                </form>
                <select className="field w-auto" aria-label="Person" value={filters.who} onChange={(e) => go({ who: e.target.value, pages: 1 })}>
                    <option value="">Everyone</option>
                    {people.map((p) => <option key={p} value={p}>{p}</option>)}
                </select>
            </div>
            {days.length ? (
                <div className="space-y-4">
                    {days.map((d) => (
                        <Panel key={d.day} title={d.day} flush>
                            <ul className="divide-y divide-rule">
                                {d.rows.map((a) => {
                                    const body = (
                                        <>
                                            <span className="min-w-0"><span className="font-semibold text-cobalt-900">{a.actor}</span> <span className="text-xs text-ink-faint">{a.role}</span><span className="block text-ink">{a.action}</span></span>
                                            <span className="shrink-0 text-right text-xs text-ink-faint" title={a.at}>{a.at.split(', ')[1]}<span className="block">{a.ago}</span></span>
                                        </>
                                    );
                                    return <li key={a.id}>{a.url ? <Link href={a.url} className="flex items-start justify-between gap-3 px-4 py-2.5 text-sm hover:bg-glaze">{body}</Link> : <div className="flex items-start justify-between gap-3 px-4 py-2.5 text-sm">{body}</div>}</li>;
                                })}
                            </ul>
                        </Panel>
                    ))}
                    {more ? <Button type="button" variant="secondary" className="w-full" onClick={() => go({ pages: filters.pages + 1 })}>Show Older</Button> : null}
                </div>
            ) : <Empty title="Nothing found">{filters.q || filters.who ? 'Try another search or person.' : 'Activity appears here as people record things.'}</Empty>}
        </>
    );
}
