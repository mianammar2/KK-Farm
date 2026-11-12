import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader, Panel } from '../components/ui';

type Item = { label: string; value: number | string | null; url?: string | null; unit?: string };
type Group = { key: string; title: string; note?: string; items: Item[] };

const fmt = (i: Item) => (i.unit === 'PKR' ? `PKR ${Number(i.value).toLocaleString('en-PK')}` : `${typeof i.value === 'number' ? i.value.toLocaleString('en-PK') : i.value}${i.unit && i.unit !== 'PKR' ? ` ${i.unit}` : ''}`);

/** D-233: the CEO scorecard. Zero and empty values are hidden (D-119); each group shows 5, then Show More. */
export default function Scorecard({ groups, asOf }: { groups: Group[]; asOf: string }) {
    const shown = groups.map((g) => ({ ...g, items: g.items.filter((i) => i.value !== null && i.value !== 0) })).filter((g) => g.items.length);
    return (
        <>
            <Head title="CEO Scorecard" />
            <PageHeader title="CEO Scorecard" description={`The KK Farms classification framework's indicators, from the live records (${asOf}). Main roles add up to the herd; statuses that overlap (pregnant and milking) are counted on their own. Tap a number to open those goats.`} />
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">{shown.map((g) => <GroupPanel key={g.key} g={g} />)}</div>
        </>
    );
}

function GroupPanel({ g }: { g: Group }) {
    const [all, setAll] = useState(false);
    const items = all ? g.items : g.items.slice(0, 5);
    return (
        <Panel title={g.title} description={g.note}>
            <ul className="divide-y divide-rule">{items.map((i) => (
                <li key={i.label} className="flex items-center justify-between gap-3 py-1.5 text-sm">
                    <span className="text-ink-soft">{i.label}</span>
                    {i.url ? <Link href={i.url} className="font-bold tabular-nums text-cobalt-900 hover:underline">{fmt(i)}</Link> : <span className="font-bold tabular-nums text-cobalt-900">{fmt(i)}</span>}
                </li>
            ))}</ul>
            {g.items.length > 5 ? <button type="button" className="mt-2 text-xs font-semibold text-cobalt-800 hover:underline" onClick={() => setAll(!all)}>{all ? 'Show less' : `Show ${g.items.length - 5} more`}</button> : null}
        </Panel>
    );
}
