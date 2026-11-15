import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import DataGrid, { type Col } from './DataGrid';
import GoatProfileCard from './GoatProfileCard';
import Sheet from './Sheet';
import { CountUp, LibIcon } from './ui';

export type MortalityRow = {
    id: string; goatId: string; number: string | null; breed: string | null; sex: string | null; age: string | null; pen: string | null; kind: 'Death' | 'Slaughter'; on: string;
    reason: string; seen: string | null; postmortem: string | null; reportedBy: string | null; approvedBy: string | null; status: string;
};
export type MortalitySummary = { deaths: number; slaughtered: number; waiting: number; thisYear: number; byReason: Array<{ kind: string; reason: string; count: number }>; rows: MortalityRow[] };

type Pick = { title: string; keep: (r: MortalityRow) => boolean };

/**
 * D-326: the dashboard's Mortality section: goats that died or were slaughtered, by reason. A tile or a reason opens the
 * records in a pop card: the reason, what was seen, the postmortem, who reported it and who approved it; a goat opens its
 * profile card in the same pop card. Anything that is zero is hidden.
 */
export default function MortalityBoard({ m }: { m: MortalitySummary }) {
    const [pick, setPick] = useState<Pick | null>(null);
    const tiles = [
        { art: 'ic-died', label: 'Deaths', value: m.deaths, pick: { title: 'Deaths', keep: (r: MortalityRow) => r.kind === 'Death' && r.status === 'Recorded' } },
        { art: 'ic-sick', label: 'Slaughtered', value: m.slaughtered, pick: { title: 'Slaughtered', keep: (r: MortalityRow) => r.kind === 'Slaughter' && r.status === 'Recorded' } },
        { art: 'ic-on-hold', label: 'Waiting for Approval', value: m.waiting, pick: { title: 'Waiting for approval', keep: (r: MortalityRow) => r.status !== 'Recorded' } },
    ].filter((t) => t.value);
    const most = Math.max(1, ...m.byReason.map((b) => b.count));
    return (
        <div className="space-y-3">
            <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                {tiles.map((t) => (
                    <button key={t.label} type="button" onClick={() => setPick(t.pick)} className="kk-sign kk-rise kk-lift text-start">
                        <span className="kk-icon-tile" aria-hidden><LibIcon name={t.art} size={36} className="bg-transparent" /></span>
                        <span className="kk-sign-paper min-w-0">
                            <span className="block truncate text-xs font-semibold text-[#4d6356]">{t.label}</span>
                            <span className="block text-xl font-extrabold tabular-nums text-crit-700 sm:text-2xl"><CountUp to={t.value} /></span>
                        </span>
                    </button>
                ))}
            </div>
            {m.byReason.length ? (
                <ul className="space-y-1">
                    {m.byReason.map((b) => (
                        <li key={`${b.kind}|${b.reason}`}>
                            <button type="button" onClick={() => setPick({ title: `${b.kind}: ${b.reason}`, keep: (r) => r.status === 'Recorded' && r.kind === b.kind && r.reason === b.reason })}
                                className="flex w-full items-center gap-3 rounded-md px-1.5 py-1 text-start text-sm hover:bg-tile">
                                <span className={`w-20 shrink-0 text-xs font-semibold ${b.kind === 'Death' ? 'text-crit-700' : 'text-[#8a5a12]'}`}>{b.kind}</span>
                                <span className="w-40 shrink-0 truncate font-semibold text-ink sm:w-56" title={b.reason}>{b.reason}</span>
                                <span className="h-2.5 flex-1 overflow-hidden rounded-full bg-rule/60"><span className={`block h-full rounded-full ${b.kind === 'Death' ? 'bg-crit-500' : 'bg-[#d9a441]'}`} style={{ width: `${(b.count / most) * 100}%` }} /></span>
                                <span className="w-8 text-end font-bold tabular-nums">{b.count}</span>
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
            {pick ? <MortalityPop pick={pick} rows={m.rows.filter(pick.keep)} onClose={() => setPick(null)} /> : null}
        </div>
    );
}

function MortalityPop({ pick, rows, onClose }: { pick: Pick; rows: MortalityRow[]; onClose: () => void }) {
    const [goatId, setGoatId] = useState<string | null>(null);
    const cols: Col<MortalityRow>[] = [
        { key: 'on', label: 'Date', type: 'text' },
        { key: 'number', label: 'Goat', type: 'text', total: 'count', render: (r) => <button type="button" onClick={() => setGoatId(r.goatId)} className="font-mono text-cobalt-800 hover:underline">{r.number}</button> },
        { key: 'kind', label: 'Type', type: 'enum' }, { key: 'reason', label: 'Reason', type: 'enum' },
        { key: 'seen', label: 'What was seen', type: 'text', wrap: true }, { key: 'postmortem', label: 'Postmortem', type: 'text', wrap: true },
        { key: 'reportedBy', label: 'Entered by', type: 'enum' }, { key: 'approvedBy', label: 'Approved by', type: 'enum' }, { key: 'status', label: 'Status', type: 'enum' },
        { key: 'breed', label: 'Breed', type: 'enum' }, { key: 'sex', label: 'Sex', type: 'enum' }, { key: 'age', label: 'Age', type: 'text' }, { key: 'pen', label: 'PEN', type: 'enum' },
    ];
    return (
        <Sheet onClose={onClose}>
            <div className="space-y-3">
                {goatId ? <>
                    <button type="button" onClick={() => setGoatId(null)} className="inline-flex items-center gap-1.5 text-sm text-cobalt-800 hover:underline"><ArrowLeft size={15} aria-hidden /> Back to {pick.title}</button>
                    <GoatProfileCard id={goatId} />
                </> : <>
                    <div className="pe-10"><h2 className="text-lg text-cobalt-900">{pick.title}</h2><p className="text-sm text-ink-soft">{rows.length} {rows.length === 1 ? 'record' : 'records'}</p></div>
                    <div className="-mx-4 sm:-mx-5"><DataGrid<MortalityRow> id="mortality-pop" name={pick.title} cols={cols} rows={rows} rowKey={(r) => r.id} pageSize={10} empty="Nothing here" /></div>
                </>}
            </div>
        </Sheet>
    );
}
