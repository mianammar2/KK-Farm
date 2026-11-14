import { Head, Link, useForm, usePoll } from '@inertiajs/react';
import { LIVE_MS } from '../lib/live';
import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button, Chip, cx, Empty, Field, Note, PageHeader, Panel, Stat, StatRow } from '../components/ui';
import DataGrid, { type Col } from '../components/DataGrid';
import SaleReasonFields, { saleReasonDefaults, type DiseaseOption } from '../components/SaleReason';
import AddButton from '../components/AddButton';
import Sheet from '../components/Sheet';

type Move = { saleReason?: string | null; saleDisease?: string | null; id: number; on: string; type: string; typeLabel: string; goatId: string; goat: string; from: string | null; to: string | null; reason: string | null; by: string; amount: number | null; evidence: string | null };
type Goat = { id: string; number: string; name: string | null; pen: string | null; penId: number | null; withdrawal: string | null };
type Props = {
    kind: 'transfer' | 'exit';
    movements: Move[]; goats: Goat[]; pens: Array<{ id: number; name: string }>; types: Record<string, { label: string; exit: boolean }>;
    month: Record<string, number>; canExit: boolean; saleCategories: Record<string, string>;
    saleReasons?: Record<string, string>; diseases?: DiseaseOption[]; // D-290
};

const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const TYPE_CLASS: Record<string, string> = { transfer: 'bg-cobalt-100 text-cobalt-800', sale: 'bg-ok-100 text-ok-700', death: 'bg-crit-100 text-crit-700', culling: 'bg-treat-100 text-treat-700', sacrificed: 'bg-turq-100 text-turq-800', gifted: 'bg-glaze text-ink-soft', transferred_out: 'bg-glaze text-ink-soft', other: 'bg-glaze text-ink-soft' };

/** D-076: workbook "Animal Movements": move one goat or a batch, or record exits; every movement is kept. */
export default function Movements({ kind = 'transfer', movements, goats, pens, types, month, canExit, saleCategories, saleReasons, diseases }: Props) {
    usePoll(LIVE_MS, { only: ['movements', 'goats', 'pens', 'month'] }); // D-086: live
    const [adding, setAdding] = useState(false); // D-127: the movement form opens from a + button
    const exits = Object.entries(month).filter(([t]) => t !== 'transfer').reduce((s, [, n]) => s + n, 0);
    const isExit = kind === 'exit';
    // D-285: pen movements and goats leaving the farm are separate pages; both lists are spreadsheets
    const cols: Col<Move>[] = isExit ? [
        { key: 'on', label: 'Date', type: 'text' },
        { key: 'goat', label: 'Goat', type: 'text', total: 'count', render: (m) => <Link href={`/goats/${m.goatId}`} className="font-mono text-cobalt-800 hover:underline">{m.goat}</Link> },
        { key: 'typeLabel', label: 'Exit', type: 'enum', render: (m) => <Chip className={TYPE_CLASS[m.type] ?? 'bg-glaze text-ink-soft'}>{m.typeLabel}</Chip> },
        { key: 'from', label: 'Last PEN', type: 'enum' },
        { key: 'to', label: 'Buyer / destination', type: 'text' },
        { key: 'saleReason', label: 'Why sold', type: 'enum' },
        { key: 'saleDisease', label: 'Disease / reason', type: 'enum' },
        { key: 'reason', label: 'Reason', type: 'text', wrap: true, value: (m) => [m.reason, m.evidence].filter(Boolean).join(' · ') || null },
        { key: 'amount', label: 'Amount (PKR)', type: 'number', total: 'sum', render: (m) => (m.amount !== null ? m.amount.toLocaleString('en-PK') : '—') },
        { key: 'by', label: 'Authorised by', type: 'enum' },
    ] : [
        { key: 'on', label: 'Date', type: 'text' },
        { key: 'goat', label: 'Goat', type: 'text', total: 'count', render: (m) => <Link href={`/goats/${m.goatId}`} className="font-mono text-cobalt-800 hover:underline">{m.goat}</Link> },
        { key: 'from', label: 'From PEN', type: 'enum' },
        { key: 'to', label: 'To PEN', type: 'enum' },
        { key: 'reason', label: 'Reason', type: 'text', wrap: true },
        { key: 'by', label: 'Moved by', type: 'enum' },
    ];

    return (
        <>
            <Head title={isExit ? 'Animal Exit' : 'PEN Movements'} />
            <PageHeader title={isExit ? 'Animal Exit' : 'PEN Movements'}
                description={isExit ? 'Goats leaving the farm: sold, Qurbani, gifted, transferred out, and deaths approved by the administrator. The goat leaves the farm; its record stays.' : 'Goats moved from one PEN to another, with the reason. The goat stays on the farm; its PEN follows the latest move.'}
                actions={isExit && !canExit ? null : <AddButton label={isExit ? 'Record an exit' : 'Move goats'} onClick={() => setAdding(true)} />} />
            {adding ? <Sheet onClose={() => setAdding(false)}><MoveForm goats={goats} pens={pens} types={types} canExit={canExit} saleCategories={saleCategories} saleReasons={saleReasons} diseases={diseases} onDone={() => setAdding(false)} /></Sheet> : null}
            <StatRow>
                <Stat label="Goats on the farm" value={goats.length} />
                {isExit ? <>
                    <Stat label="Left the farm this month" value={exits} />
                    <Stat label="Sold this month" value={month.sale ?? 0} />
                    <Stat label="Deaths this month" value={month.death ?? 0} tone={month.death ? 'alert' : undefined} />
                </> : <>
                    <Stat label="PEN moves this month" value={month.transfer ?? 0} />
                    <Stat label="Goats without a PEN" value={goats.filter((g) => !g.penId).length} tone={goats.some((g) => !g.penId) ? 'alert' : undefined} />
                </>}
            </StatRow>
            <Panel flush title="History">
                <DataGrid<Move> id={`movements-${kind}`} name={isExit ? 'Animal exits' : 'Pen movements'} cols={cols} rows={movements} rowKey={(m) => String(m.id)} empty={isExit ? 'No goat has left the farm yet' : 'No PEN moves yet'} />
            </Panel>
        </>
    );
}

function MoveForm({ goats, pens, types, canExit, saleCategories, saleReasons = {}, diseases = [], onDone }: Omit<Props, 'movements' | 'month' | 'kind'> & { onDone?: () => void }) {
    const [query, setQuery] = useState('');
    const form = useForm({ animal_ids: [] as string[], type: Object.keys(types)[0] ?? 'transfer', moved_on: today(), to_pen_id: '', destination: '', reason: '', authorized_by: '', amount: '', fee: '', evidence_ref: '', notes: '', sale_category: 'live_goat', capacity_override: '', ...saleReasonDefaults() });
    const e = form.errors as Record<string, string>;
    const exit = types[form.data.type]?.exit;
    const picked = goats.filter((g) => form.data.animal_ids.includes(g.id));
    const matches = useMemo(() => {
        const q = query.trim().toLowerCase();
        return goats.filter((g) => !form.data.animal_ids.includes(g.id) && (!q || [g.number, g.name, g.pen].some((v) => v?.toLowerCase().includes(q)))).slice(0, 12);
    }, [goats, query, form.data.animal_ids]);
    const toggle = (id: string) => form.setData('animal_ids', form.data.animal_ids.includes(id) ? form.data.animal_ids.filter((x) => x !== id) : [...form.data.animal_ids, id]);
    const blocked = exit && ['sale', 'culling', 'sacrificed', 'gifted'].includes(form.data.type) ? picked.filter((g) => g.withdrawal) : [];
    const byPen = (penName: string) => form.setData('animal_ids', [...new Set([...form.data.animal_ids, ...goats.filter((g) => g.pen === penName).map((g) => g.id)])]);
    const penNames = [...new Set(goats.map((g) => g.pen).filter(Boolean) as string[])];

    return (
        <div className="space-y-4">
            <Panel title="1. Choose goats" description="Search, tap to add, or add a whole PEN. Scanning with the gun opens the goat itself.">
                <div className="relative mb-3"><Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-ink-faint" aria-hidden /><input className="field pl-9" value={query} onChange={(x) => setQuery(x.target.value)} placeholder="Tag ID, name or PEN" aria-label="Search goats" /></div>
                {penNames.length ? <div className="mb-3 flex flex-wrap gap-1.5"><span className="text-sm text-ink-soft">Add a whole PEN:</span>{penNames.map((p) => <button key={p} type="button" onClick={() => byPen(p)} className="rounded-[4px] border border-rule px-2 py-0.5 font-mono text-xs hover:border-cobalt-600">{p}</button>)}</div> : null}
                <ul className="divide-y divide-rule rounded-tile border border-rule">
                    {matches.map((g) => (
                        <li key={g.id}><button type="button" onClick={() => toggle(g.id)} className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-glaze">
                            <span><span className="font-mono font-semibold text-cobalt-900">{g.number}</span>{g.name ? ` · ${g.name}` : ''}</span>
                            <span className="text-xs text-ink-soft">{g.pen ?? 'No PEN'}{g.withdrawal ? ' · withdrawal' : ''}</span>
                        </button></li>
                    ))}
                    {!matches.length ? <li className="px-3 py-2 text-sm text-ink-soft">No more goats match.</li> : null}
                </ul>
                {e.animal_ids ? <p className="mt-2 text-sm text-crit-700">{e.animal_ids}</p> : null}
            </Panel>

            <Panel title={`2. Movement · ${picked.length} goat${picked.length === 1 ? '' : 's'}`}>
                <form onSubmit={(x) => { x.preventDefault(); form.post('/movements', { preserveScroll: true, onSuccess: () => { form.reset('animal_ids', 'destination', 'reason', 'amount', 'fee', 'evidence_ref', 'notes'); onDone?.(); } }); }} className="space-y-3">
                    {picked.length ? <div className="flex flex-wrap gap-1.5">{picked.map((g) => <button key={g.id} type="button" onClick={() => toggle(g.id)} className={cx('rounded-[4px] px-2 py-0.5 font-mono text-xs', g.withdrawal ? 'bg-crit-100 text-crit-700' : 'bg-cobalt-100 text-cobalt-800')} title="Remove">{g.number} ×</button>)}</div> : <p className="text-sm text-ink-soft">No goats chosen yet.</p>}
                    {Object.keys(types).length > 1 ? (
                        <Field label="Exit" error={e.type}>
                            <select className="field" value={form.data.type} onChange={(x) => form.setData('type', x.target.value)}>
                                {Object.entries(types).filter(([, t]) => canExit || !t.exit).map(([k, t]) => <option key={k} value={k}>{t.label}</option>)}
                            </select>
                        </Field>
                    ) : null}
                    <Field label="Date" error={e.moved_on}><input type="date" max={today()} className="field" value={form.data.moved_on} onChange={(x) => form.setData('moved_on', x.target.value)} /></Field>
                    {!exit ? (
                        <>
                            <Field label="To PEN" error={e.to_pen_id}><select className="field" value={form.data.to_pen_id} onChange={(x) => form.setData('to_pen_id', x.target.value)}><option value="">Choose PEN…</option>{pens.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
                            {e.to_pen_id?.includes(' is full') || form.data.capacity_override ? <Field label="Over-capacity reason (owner or manager)" hint="BR-004: recorded with your name on each move"><input className="field" value={form.data.capacity_override} onChange={(x) => form.setData('capacity_override', x.target.value)} /></Field> : null}
                        </>
                    ) : (
                        <>
                            <Field label={form.data.type === 'sale' ? 'Buyer' : 'Destination'} error={e.destination}><input className="field" value={form.data.destination} onChange={(x) => form.setData('destination', x.target.value)} placeholder={form.data.type === 'sale' ? 'Name or market' : 'Farm, place or person'} /></Field>
                            {form.data.type === 'sale' ? (
                                <>
                                    <div className="flex flex-wrap gap-2"><SaleReasonFields data={form.data} set={(k, v) => form.setData(k, v)} errors={e} reasons={saleReasons} diseases={diseases} /></div>
                                    <div className="grid grid-cols-2 gap-2">
                                        <Field label="Price per goat (PKR)" error={e.amount}><input type="number" min={0} className="field" value={form.data.amount} onChange={(x) => form.setData('amount', x.target.value)} /></Field>
                                        <Field label="Fee / discount (PKR)" error={e.fee}><input type="number" min={0} className="field" value={form.data.fee} onChange={(x) => form.setData('fee', x.target.value)} /></Field>
                                    </div>
                                    <Field label="Sold as" error={e.sale_category}><select className="field" value={form.data.sale_category} onChange={(x) => form.setData('sale_category', x.target.value)}><option value="live_goat">{saleCategories.live_goat}</option><option value="breeding_goat">{saleCategories.breeding_goat}</option></select></Field>
                                    {form.data.amount && picked.length ? <p className="text-sm text-ink-soft">Income: PKR {((Number(form.data.amount) - Number(form.data.fee || 0)) * picked.length).toLocaleString('en-PK')} net for {picked.length} goat{picked.length === 1 ? '' : 's'}. Recorded in Finance → Sales.</p> : null}
                                </>
                            ) : null}
                            <Field label="Receipt / evidence ref" error={e.evidence_ref}><input className="field" value={form.data.evidence_ref} onChange={(x) => form.setData('evidence_ref', x.target.value)} /></Field>
                        </>
                    )}
                    <Field label="Reason" error={e.reason}><input className="field" value={form.data.reason} onChange={(x) => form.setData('reason', x.target.value)} placeholder={exit ? 'e.g. Eid sale, old age' : 'e.g. weaning, sorting'} /></Field>
                    {blocked.length ? <Note>{blocked.map((g) => `${g.number} (until ${g.withdrawal})`).join(', ')} {blocked.length === 1 ? 'is' : 'are'} under meat withdrawal and cannot leave for meat. Remove {blocked.length === 1 ? 'it' : 'them'} to continue.</Note> : null}
                    {e.type ? <p className="text-sm text-crit-700">{e.type}</p> : null}
                    <Button type="submit" variant={exit ? 'danger' : 'primary'} className="w-full" disabled={form.processing || !picked.length || blocked.length > 0}>
                        {exit ? `Record ${types[form.data.type].label.toLowerCase()} for ${picked.length} goat${picked.length === 1 ? '' : 's'}` : `Move ${picked.length} goat${picked.length === 1 ? '' : 's'}`}
                    </Button>
                </form>
            </Panel>
        </div>
    );
}
