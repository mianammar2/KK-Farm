import { Head, Link, router } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import { Button, Empty, PageHeader, Panel } from '../components/ui';
import DataGrid, { type Col } from '../components/DataGrid';

type Row = { id: number; actor: string; role: string; action: string; url: string | null; at: string; ago: string; day: string };
type Props = { activity: Row[]; more: boolean; people: string[]; filters: { q: string; who: string; pages: number } };

/** D-273: the log as a spreadsheet: sort, filter by anything, export. */
const COLS: Col<Row>[] = [
    { key: 'day', label: 'Date', type: 'text' },
    { key: 'time', label: 'Time', type: 'text', value: (a) => a.at.split(', ')[1] ?? a.at },
    { key: 'actor', label: 'Who', type: 'enum', render: (a) => <b className="text-cobalt-900">{a.actor}</b> },
    { key: 'role', label: 'Role', type: 'enum' },
    { key: 'action', label: 'What', type: 'text', wrap: true, render: (a) => (a.url ? <Link href={a.url} className="text-cobalt-800 hover:underline">{a.action}</Link> : a.action), total: 'count' },
    { key: 'ago', label: 'When', type: 'text' },
];

/** D-122: who did what and when, grouped by day, newest first; moved here from the dashboard. */
export default function Activity({ activity, more, people, filters }: Props) {
    const [q, setQ] = useState(filters.q);
    const go = (next: Partial<Props['filters']>) => router.get('/activity', { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });

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
            {activity.length ? (
                <Panel flush>
                    <DataGrid<Row> id="activity" name="Activity log" cols={COLS} rows={activity} pageSize={50} />
                    {more ? <div className="border-t border-rule p-3"><Button type="button" variant="secondary" className="w-full" onClick={() => go({ pages: filters.pages + 1 })}>Load older activity</Button></div> : null}
                </Panel>
            ) : <Empty title="Nothing found">{filters.q || filters.who ? 'Try another search or person.' : 'Activity appears here as people record things.'}</Empty>}
        </>
    );
}
