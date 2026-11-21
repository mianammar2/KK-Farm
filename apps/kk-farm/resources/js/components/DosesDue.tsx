import { Link, useForm } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import Sheet from './Sheet';
import { useState } from 'react';
import DataGrid, { type Col } from './DataGrid';
import { Button, cx, Empty, Field, Panel } from './ui';

/**
 * D-304: the doses a vet prescribed, due today or overdue: give (from stock), or record missed / held with why; tick an
 * adverse reaction. Missed, held and reactions go to the prescribing vet (AL-012). Below: the last 14 days.
 */
export type DoseRow = {
    id: number; goatId: string; goat: string | null; pen: string | null; dueIso: string; due: string; slot: string | null; number: number; of: number; product: string; dose: string; route: string;
    qty: number | null; stock: boolean; status: string; doneAt: string | null; doneOn?: string | null; doneTime?: string | null; unit?: string | null; by: string | null; batch: string | null; adverse: boolean; notes: string | null; vet: string; overdue: boolean;
};

export default function DosesDue({ due, done, canGive, canCorrect = false, me = '' }: { due: DoseRow[]; done: DoseRow[]; canGive: boolean; canCorrect?: boolean; me?: string }) {
    const [editing, setEditing] = useState<DoseRow | null>(null); // D-378
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
        { key: 'edit', label: '', value: () => '', render: (d) => (canCorrect || d.by === me ? <button type="button" onClick={() => setEditing(d)} className="inline-flex min-h-9 items-center gap-1 rounded-md border border-rule px-2.5 text-sm text-cobalt-800 hover:bg-glaze" aria-label={`Edit dose ${d.number} of ${d.product} for ${d.goat}`}><Pencil size={14} aria-hidden /> Edit</button> : null) },
    ];
    return (
        <div className="space-y-4">
            <Panel title={`Doses due${goat && list[0] ? ` for ${list[0].goat}` : ''}`} description="Today's and overdue doses of what the vet prescribed. Given takes the medicine from the store (the batch that expires first). Missed or held: say why; the vet is told." flush>
                {list.length ? <ul className="divide-y divide-rule">{list.map((d) => <DoseItem key={d.id} d={d} canGive={canGive} />)}</ul> : <Empty title={goat ? 'No dose due for this goat' : 'No dose due'}>Doses appear here when a vet prescribes a course.</Empty>}
            </Panel>
            <Panel title="Doses of the last 14 days" flush>
                <DataGrid<DoseRow> id="doses-done" name="Doses given missed held" cols={cols} rows={done} rowKey={(d) => String(d.id)} empty="No doses recorded in the last 14 days" />
            </Panel>
            {editing ? <Sheet title={`Edit dose ${editing.number} of ${editing.product} · ${editing.goat ?? ''}`} onClose={() => setEditing(null)}><EditDose d={editing} onDone={() => setEditing(null)} /></Sheet> : null}
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

/** D-378: correct a recorded dose: amount (a store medicine moves the store by the difference), date and time, notes. */
function EditDose({ d, onDone }: { d: DoseRow; onDone: () => void }) {
    const form = useForm({ qty: d.qty != null ? String(d.qty) : '', done_on: d.doneOn ?? '', done_time: d.doneTime ?? '', notes: d.notes ?? '', reason: '' });
    const e = form.errors as Record<string, string>;
    const unit = d.unit === 'ml' ? 'CC' : d.unit ?? '';
    return (
        <form onSubmit={(ev) => { ev.preventDefault(); form.post(`/health/doses/${d.id}/edit`, { preserveScroll: true, onSuccess: onDone }); }} className="space-y-3">
            {d.status === 'given' ? <Field label={`Amount given${unit ? ` (${unit})` : ''}`} error={e.qty} hint={d.stock ? 'A change moves the store by the difference' : undefined}><input type="number" inputMode="decimal" min={0} step="0.1" className="field" value={form.data.qty} onChange={(x) => form.setData('qty', x.target.value)} /></Field> : null}
            <div className="grid grid-cols-2 gap-3">
                <Field label="Date" error={e.done_on}><input type="date" className="field" value={form.data.done_on} onChange={(x) => form.setData('done_on', x.target.value)} /></Field>
                <Field label="Time" error={e.done_time}><input type="time" className="field" value={form.data.done_time} onChange={(x) => form.setData('done_time', x.target.value)} /></Field>
            </div>
            <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
            <Field label="Why the change (optional)" error={e.reason}><input className="field" value={form.data.reason} onChange={(x) => form.setData('reason', x.target.value)} /></Field>
            <p className="text-xs text-ink-soft">The old values stay in the record's history.</p>
            <div className="flex gap-2"><Button type="submit" disabled={form.processing}>Save</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}
