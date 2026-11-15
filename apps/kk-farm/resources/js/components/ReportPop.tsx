import { ArrowLeft } from 'lucide-react';
import { useEffect, useState } from 'react';
import DataGrid, { type Col } from './DataGrid';
import GoatProfileCard from './GoatProfileCard';
import Sheet from './Sheet';
import { pkr } from './ui';

/**
 * D-322: what is behind a figure on Farm Reports, in a pop card on the same page: the goats, kiddings, a buck's kids or
 * a spending category's payments. A goat opens its profile card inside the pop card (Back returns to the list).
 */
export type Drill = { title: string; q: Record<string, string> };
type R = Record<string, string | number | null>;

const goat = (open: (id: string) => void) => (r: R) => r.goatId || r.id ? <button type="button" onClick={() => open(String(r.goatId ?? r.id))} className="font-mono text-cobalt-800 hover:underline">{r.number}</button> : '—';

export default function ReportPop({ drill, onClose }: { drill: Drill; onClose: () => void }) {
    const [rows, setRows] = useState<R[] | null>(null);
    const [failed, setFailed] = useState(false);
    const [goatId, setGoatId] = useState<string | null>(null);
    useEffect(() => {
        const c = new AbortController();
        setRows(null); setFailed(false);
        fetch(`/reports/drill?${new URLSearchParams(drill.q).toString()}`, { headers: { Accept: 'application/json' }, signal: c.signal })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status))).then((d) => setRows(d.rows)).catch((e) => { if (e?.name !== 'AbortError') setFailed(true); });
        return () => c.abort();
    }, [drill]);
    const what = drill.q.what;
    const cols: Col<R>[] = what === 'kiddings' ? [
        { key: 'on', label: 'Date', type: 'text' }, { key: 'number', label: 'Doe', type: 'text', total: 'count', render: goat(setGoatId) },
        { key: 'alive', label: 'Born alive', type: 'number', total: 'sum' }, { key: 'stillborn', label: 'Stillborn', type: 'number', total: 'sum' },
        { key: 'delivery', label: 'Delivery', type: 'enum' }, { key: 'by', label: 'By', type: 'enum' },
    ] : what === 'spending' ? [
        { key: 'on', label: 'Date', type: 'text' }, { key: 'amount', label: 'Amount (PKR)', type: 'number', total: 'sum', render: (r) => pkr(Number(r.amount)) },
        { key: 'party', label: 'Paid to', type: 'enum' }, { key: 'what', label: 'For', type: 'text', wrap: true }, { key: 'by', label: 'By', type: 'enum' },
    ] : [
        { key: 'number', label: 'Goat', type: 'text', total: 'count', render: goat(setGoatId) }, { key: 'breed', label: 'Breed', type: 'enum' }, { key: 'sex', label: 'Sex', type: 'enum' },
        { key: 'code', label: 'Class code', type: 'enum' }, { key: 'age', label: 'Age', type: 'text' }, ...(what === 'offspring' ? [{ key: 'dam', label: 'Dam', type: 'text' as const }] : []),
        { key: 'pen', label: 'PEN', type: 'enum' }, { key: 'health', label: 'Health', type: 'enum' }, { key: 'status', label: 'Status', type: 'enum' },
        ...(drill.q.exit ? [{ key: 'left', label: 'Left on', type: 'text' as const }] : []),
    ];
    return (
        <Sheet onClose={onClose}>
            <div className="space-y-3">
                {goatId ? <>
                    <button type="button" onClick={() => setGoatId(null)} className="inline-flex items-center gap-1.5 text-sm text-cobalt-800 hover:underline"><ArrowLeft size={15} aria-hidden /> Back to {drill.title}</button>
                    <GoatProfileCard id={goatId} />
                </> : <>
                    <div className="pe-10"><h2 className="text-lg text-cobalt-900">{drill.title}</h2>{rows ? <p className="text-sm text-ink-soft">{rows.length} {rows.length === 1 ? 'record' : 'records'}</p> : null}</div>
                    {failed ? <p className="text-sm text-crit-700">This list could not be loaded.</p> : !rows ? <p className="text-sm text-ink-soft">Loading…</p>
                        : <div className="-mx-4 sm:-mx-5"><DataGrid<R> id={`report-pop-${what}`} name={drill.title} cols={cols} rows={rows} rowKey={(r) => String(r.id)} pageSize={10} empty="Nothing here" /></div>}
                </>}
            </div>
        </Sheet>
    );
}
