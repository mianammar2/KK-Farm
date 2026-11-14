import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import DataGrid, { type Col } from './DataGrid';
import { Button, cx, Empty, Field, Panel } from './ui';

/**
 * D-304: the doses a vet prescribed, due today or overdue: give (from stock), or record missed / held with why; tick an
 * adverse reaction. Missed, held and reactions go to the prescribing vet (AL-012). Below: the last 14 days.
 */
export type DoseRow = {
    id: number; goatId: string; goat: string | null; pen: string | null; dueIso: string; due: string; slot: string | null; number: number; of: number; product: string; dose: string; route: string;
    qty: number | null; stock: boolean; status: string; doneAt: string | null; by: string | null; batch: string | null; adverse: boolean; notes: string | null; vet: string; overdue: boolean;
};

export default function DosesDue({ due, done, canGive }: { due: DoseRow[]; done: DoseRow[]; canGive: boolean }) {
    const goat = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('goat') : null; // from an app's scan
    const list = goat ? due.filter((d) => d.goatId === goat) : due;
    const cols: Col<DoseRow>[] = [
        { key: 'doneAt', label: 'When', type: 'text' },
        { key: 'goat', label: 'Goat', type: 'text', total: 'count', render: (d) => <Link href={`/goats/${d.goatId}`} className="font-mono text-cobalt-800 hover:underline">{d.goat}</Link> },
        { key: 'product', label: 'Medicine', type: 'enum' },
        { key: 'numberText', label: 'Dose', type: 'text', value: (d) => `${d.number} of ${d.of}` },
        { key: 'status', label: 'Result', type: 'enum', value: (d) => (d.status === 'given' ? (d.adverse ? 'Given · reaction' : 'Given') : d.status === 'missed' ? 'Missed' : 'Held'),
            render: (d) => <span className={cx('rounded px-1.5 py-0.5 text-xs', d.status === 'given' && !d.adverse ? 'bg-ok-100 text-ok-700' : 'bg-crit-100 text-crit-700')}>{d.status === 'given' ? (d.adverse ? 'Given · reaction' : 'Given') : d.status === 'missed' ? 'Missed' : 'Held'}</span> },
        { key: 'qty', label: 'Amount', type: 'number' },
        { key: 'batch', label: 'Batch', type: 'text' },
        { key: 'by', label: 'By', type: 'enum' },
        { key: 'vet', label: 'Prescribed by', type: 'enum' },
        { key: 'notes', label: 'Notes', type: 'text', wrap: true },
    ];
    return (
        <div className="space-y-4">
            <Panel title={`Doses due${goat && list[0] ? ` for ${list[0].goat}` : ''}`} description="Today's and overdue doses of what the vet prescribed. Given takes the medicine from the store (the batch that expires first). Missed or held: say why; the vet is told." flush>
                {list.length ? <ul className="divide-y divide-rule">{list.map((d) => <DoseItem key={d.id} d={d} canGive={canGive} />)}</ul> : <Empty title={goat ? 'No dose due for this goat' : 'No dose due'}>Doses appear here when a vet prescribes a course.</Empty>}
            </Panel>
            <Panel title="Doses of the last 14 days" flush>
                <DataGrid<DoseRow> id="doses-done" name="Doses given missed held" cols={cols} rows={done} rowKey={(d) => String(d.id)} empty="No doses recorded in the last 14 days" />
            </Panel>
        </div>
    );
}

function DoseItem({ d, canGive }: { d: DoseRow; canGive: boolean }) {
    const [mode, setMode] = useState<'missed' | 'held' | null>(null);
    const form = useForm({ action: 'given', qty: d.qty !== null ? String(d.qty) : '', adverse: false, notes: '' });
    const send = (action: string) => { form.transform((x) => ({ ...x, action })); form.post(`/health/doses/${d.id}`, { preserveScroll: true }); };
    return (
        <li className={cx('px-4 py-3', d.overdue && 'bg-crit-100/40')}>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <Link href={`/goats/${d.goatId}`} className="font-mono text-cobalt-800 hover:underline">{d.goat}</Link>
                <span className="text-sm">{d.product} · {d.dose} · {d.route}</span>
                <span className="text-sm text-ink-soft">Dose {d.number} of {d.of} · {d.slot ?? ''} {d.due}{d.pen ? ` · ${d.pen}` : ''}</span>
                {d.overdue ? <span className="rounded bg-crit-100 px-1.5 py-0.5 text-xs text-crit-700">Overdue</span> : null}
            </div>
            {canGive ? (
                <div className="mt-2 flex flex-wrap items-end gap-2">
                    {d.stock ? <div className="w-28"><Field label="Amount" error={form.errors.qty}><input className="field" inputMode="decimal" value={form.data.qty} onChange={(e) => form.setData('qty', e.target.value)} /></Field></div> : null}
                    <label className="flex h-11 items-center gap-2 text-sm"><input type="checkbox" checked={form.data.adverse} onChange={(e) => form.setData('adverse', e.target.checked)} /> Reaction to the medicine</label>
                    {form.data.adverse || mode ? <div className="min-w-56 flex-1"><Field label={mode ? 'Why not given' : 'What happened'} error={form.errors.notes}><input className="field" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} /></Field></div> : null}
                    {mode ? <>
                        <Button disabled={form.processing || !form.data.notes} onClick={() => send(mode)}>Save as {mode}</Button>
                        <Button variant="secondary" onClick={() => setMode(null)}>Cancel</Button>
                    </> : <>
                        <Button disabled={form.processing} onClick={() => send('given')}>Given</Button>
                        <Button variant="secondary" onClick={() => setMode('missed')}>Missed</Button>
                        <Button variant="secondary" onClick={() => setMode('held')}>Held</Button>
                    </>}
                </div>
            ) : null}
        </li>
    );
}
