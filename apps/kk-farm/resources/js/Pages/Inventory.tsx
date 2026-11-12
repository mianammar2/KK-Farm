import { Head, Link, useForm } from '@inertiajs/react';
import DeleteButton from '../components/DeleteButton';
import { Pencil } from 'lucide-react';
import { useMemo, useState } from 'react';
import AddButton from '../components/AddButton';
import Sheet from '../components/Sheet';
import { Button, Chip, cx, Empty, Field, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import { useFive } from '../lib/useFive';
import { useTabParam } from '../lib/useTabParam';

type Kind = 'feed' | 'medicine' | 'store';
type Row = { kind: Kind; id: number; code: string | null; name: string; category: string; unit: string; qty: number; reorder: number; value: number; cost: number; status: 'ok' | 'low' | 'out' | 'negative' };
type Move = { kind: Kind; on: string; item: string | null; type: string; qty: number; unit: string | null; party: string | null; reference: string | null; by: string };
type Item = { id: number; code: string; name: string; category: string; unit: string; reorder: number; cost: number; active: boolean; notes: string | null; qty: number; status: Row['status'] };
type Props = { overview: Row[]; moves: Move[]; items: Item[]; categories: Record<string, string>; units: Record<string, string>; moveTypes: Record<string, { label: string; direction: number }>; can: { manage: boolean } };

const KIND: Record<Kind, { label: string; href: string; chip: string }> = {
    feed: { label: 'Feed', href: '/feeding?tab=store', chip: 'bg-turq-100 text-turq-800' },
    medicine: { label: 'Medicine', href: '/health?tab=store', chip: 'bg-manganese-100 text-manganese-600' },
    store: { label: 'General', href: '/inventory?tab=general', chip: 'bg-cobalt-100 text-cobalt-800' },
};
const STATUS: Record<Row['status'], { label: string; chip: string }> = {
    ok: { label: 'In stock', chip: 'bg-ok-100 text-ok-700' }, low: { label: 'Reorder', chip: 'bg-watch-100 text-watch-700' },
    out: { label: 'Out', chip: 'bg-crit-100 text-crit-700' }, negative: { label: 'Check count', chip: 'bg-crit-100 text-crit-700' },
};
const num = (n: number) => (Math.round(n * 100) / 100).toLocaleString('en-PK');
const pkr = (n: number) => `PKR ${Math.round(n).toLocaleString('en-PK')}`;
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

/** D-178: Inventory: every store in one view (feed, medicine, general items), the general store, and all stock moves. */
export default function Inventory({ overview, moves, items, categories, units, moveTypes, can }: Props) {
    const [tab, setTab] = useTabParam('overview');
    const [kind, setKind] = useState<Kind | ''>('');
    const [adding, setAdding] = useState<Item | 'new' | null>(null);
    const [moving, setMoving] = useState(false);
    const rows = useMemo(() => overview.filter((r) => !kind || r.kind === kind).sort((a, b) => (a.status === 'ok' ? 1 : 0) - (b.status === 'ok' ? 1 : 0)), [overview, kind]);
    const reorder = overview.filter((r) => r.status !== 'ok');
    const value = overview.reduce((s, r) => s + r.value, 0);
    const [shownMoves, moreMoves] = useFive(moves, 15);

    return (
        <>
            <Head title="Inventory" />
            <PageHeader title="Inventory" description="Feed, medicine and general store items in one place. Purchases arrive through Purchase Orders."
                actions={can.manage ? <><AddButton primary={false} label="Stock In / Out" onClick={() => setMoving(true)} /><AddButton label="New Store Item" onClick={() => setAdding('new')} /></> : null} />
            <StatRow>
                <Stat label="Items in store" value={overview.length} note={`${overview.filter((r) => r.kind === 'feed').length} feed · ${overview.filter((r) => r.kind === 'medicine').length} medicine · ${overview.filter((r) => r.kind === 'store').length} general`} />
                <Stat label="Need reorder" value={reorder.length} tone={reorder.length ? 'alert' : undefined} note="Low, out or to recount" />
                <Stat label="Stock value" value={pkr(value)} note="At the latest cost" />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'overview', label: 'All Stock' }, { id: 'reorder', label: 'Reorder', count: reorder.length || undefined }, { id: 'general', label: 'General Store', count: items.length || undefined }, { id: 'moves', label: 'Stock Moves' }]} />

            {tab === 'overview' || tab === 'reorder' ? (
                <Panel flush actions={tab === 'overview' ? (
                    <select aria-label="Store" className="field w-auto" value={kind} onChange={(e) => setKind(e.target.value as Kind | '')}>
                        <option value="">All stores</option>{(Object.keys(KIND) as Kind[]).map((k) => <option key={k} value={k}>{KIND[k].label}</option>)}
                    </select>
                ) : null}>
                    {(tab === 'reorder' ? reorder : rows).length ? (
                        <div className="overflow-x-auto">
                            <table className="data-table min-w-[44rem]">
                                <thead><tr><th>Item</th><th>Store</th><th>Category</th><th className="text-right">On hand</th><th className="text-right">Reorder at</th><th className="text-right">Value</th><th>Status</th></tr></thead>
                                <tbody>
                                    {(tab === 'reorder' ? reorder : rows).map((r) => (
                                        <tr key={`${r.kind}-${r.id}`}>
                                            <td><Link href={KIND[r.kind].href} className="font-semibold text-cobalt-800 hover:underline">{r.name}</Link>{r.code ? <span className="ml-1.5 font-mono text-xs text-ink-faint">{r.code}</span> : null}</td>
                                            <td><Chip className={KIND[r.kind].chip}>{KIND[r.kind].label}</Chip></td>
                                            <td className="text-sm text-ink-soft">{r.category}</td>
                                            <td className={cx('text-right font-semibold tabular-nums', r.qty <= 0 && 'text-crit-700')}>{num(r.qty)} {r.unit}</td>
                                            <td className="text-right tabular-nums text-ink-soft">{r.reorder ? `${num(r.reorder)} ${r.unit}` : '—'}</td>
                                            <td className="text-right tabular-nums">{pkr(r.value)}</td>
                                            <td><Chip className={STATUS[r.status].chip}>{STATUS[r.status].label}</Chip></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : <Empty title={tab === 'reorder' ? 'Nothing to reorder' : 'No items in store yet'}>{tab === 'reorder' ? 'Every item is above its reorder level.' : 'Feeds are added in Feeding, medicines in Veterinary, general items here.'}</Empty>}
                </Panel>
            ) : null}

            {tab === 'general' ? (
                <Panel flush title="General Store" description="Bedding, cleaning and disinfection, equipment spares, tools and packaging.">
                    {items.length ? (
                        <ul className="divide-y divide-rule">
                            {items.map((i) => (
                                <li key={i.id} className={cx('flex flex-wrap items-center gap-2 px-4 py-2.5', !i.active && 'opacity-60')}>
                                    <span className="min-w-0 flex-1"><span className="font-semibold text-cobalt-900">{i.name}</span> <span className="font-mono text-xs text-ink-faint">{i.code}</span><span className="block text-sm text-ink-soft">{categories[i.category] ?? i.category} · {pkr(i.cost)} per {units[i.unit] ?? i.unit}{i.notes ? ` · ${i.notes}` : ''}</span></span>
                                    <span className="font-semibold tabular-nums">{num(i.qty)} {units[i.unit] ?? i.unit}</span>
                                    <Chip className={STATUS[i.status].chip}>{STATUS[i.status].label}</Chip>
                                    {can.manage ? <><Button type="button" variant="quiet" onClick={() => setAdding(i)} aria-label={`Edit ${i.name}`}><Pencil size={15} aria-hidden /></Button><DeleteButton type="store-item" id={i.id} name={i.name} /></> : null}
                                </li>
                            ))}
                        </ul>
                    ) : <Empty title="No general items yet">{can.manage ? 'Add bedding, disinfectant, spares or tools you keep in the store.' : 'The manager adds store items.'}</Empty>}
                </Panel>
            ) : null}

            {tab === 'moves' ? (
                <Panel flush title="Stock Moves" description="Every receipt, issue and adjustment in all three stores, newest first.">
                    {moves.length ? (
                        <>
                            <ul className="divide-y divide-rule text-sm">
                                {shownMoves.map((m, i) => (
                                    <li key={i} className="flex flex-wrap items-baseline gap-x-2 px-4 py-2">
                                        <span className="w-20 text-ink-faint">{m.on}</span><Chip className={KIND[m.kind].chip}>{KIND[m.kind].label}</Chip><span className="font-medium">{m.item}</span><span className="text-ink-soft">{m.type}</span>
                                        <span className={cx('ml-auto font-semibold tabular-nums', m.qty < 0 ? 'text-crit-700' : 'text-ok-700')}>{m.qty > 0 ? '+' : ''}{num(m.qty)} {m.unit}</span>
                                        <span className="w-full text-xs text-ink-faint">{[m.party, m.reference, m.by].filter(Boolean).join(' · ')}</span>
                                    </li>
                                ))}
                            </ul>
                            {moreMoves}
                        </>
                    ) : <p className="p-4 text-sm text-ink-soft">No stock moves yet.</p>}
                </Panel>
            ) : null}

            {adding ? <Sheet onClose={() => setAdding(null)}><ItemForm item={adding === 'new' ? null : adding} categories={categories} units={units} onDone={() => setAdding(null)} /></Sheet> : null}
            {moving ? <Sheet onClose={() => setMoving(false)}><MoveForm items={items.filter((i) => i.active)} units={units} types={moveTypes} onDone={() => setMoving(false)} /></Sheet> : null}
        </>
    );
}

function ItemForm({ item, categories, units, onDone }: { item: Item | null; categories: Record<string, string>; units: Record<string, string>; onDone: () => void }) {
    const form = useForm({ code: item?.code ?? '', name: item?.name ?? '', category: item?.category ?? 'bedding', unit: item?.unit ?? 'kg', reorder_level: item ? String(item.reorder) : '', cost: item ? String(item.cost) : '', opening: '', notes: item?.notes ?? '', active: item?.active ?? true });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title={item ? `Edit ${item.name}` : 'New Store Item'}>
            <form onSubmit={(ev) => { ev.preventDefault(); item ? form.post(`/inventory/items/${item.id}`, { preserveScroll: true, onSuccess: onDone }) : form.post('/inventory/items', { preserveScroll: true, onSuccess: onDone }); }} className="grid grid-cols-2 gap-3">
                <Field label="Code" error={e.code}><input className="field font-mono uppercase" value={form.data.code} onChange={(x) => form.setData('code', x.target.value)} required /></Field>
                <Field label="Name" error={e.name}><input className="field" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} required /></Field>
                <Field label="Category" error={e.category}><select className="field" value={form.data.category} onChange={(x) => form.setData('category', x.target.value)}>{Object.entries(categories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Unit" error={e.unit}><select className="field" value={form.data.unit} onChange={(x) => form.setData('unit', x.target.value)}>{Object.entries(units).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Reorder at" error={e.reorder_level}><input className="field" type="number" min={0} value={form.data.reorder_level} onChange={(x) => form.setData('reorder_level', x.target.value)} /></Field>
                <Field label="Cost per unit (PKR)" error={e.cost}><input className="field" type="number" min={0} step="0.01" value={form.data.cost} onChange={(x) => form.setData('cost', x.target.value)} /></Field>
                {!item ? <Field label="Opening stock" error={e.opening}><input className="field" type="number" min={0} value={form.data.opening} onChange={(x) => form.setData('opening', x.target.value)} /></Field> : null}
                <div className="col-span-2"><Field label="Notes (optional)" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field></div>
                {item ? <label className="col-span-2 flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="size-5" checked={form.data.active} onChange={(x) => form.setData('active', x.target.checked)} /> In use</label> : null}
                <div className="col-span-2 flex gap-2"><Button type="submit" disabled={form.processing}>{item ? 'Save Item' : 'Add Item'}</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}

function MoveForm({ items, units, types, onDone }: { items: Item[]; units: Record<string, string>; types: Props['moveTypes']; onDone: () => void }) {
    const form = useForm({ store_item_id: String(items[0]?.id ?? ''), type: 'issue', direction: '', qty: '', moved_on: today(), issued_to: '', reference: '', notes: '' });
    const e = form.errors as Record<string, string>;
    const item = items.find((i) => String(i.id) === form.data.store_item_id);
    if (!items.length) return <Panel title="Stock In / Out"><Empty title="No store items yet">Add a store item first.</Empty></Panel>;
    return (
        <Panel title="Stock In / Out" description="Issue items for use, receive items that were not bought, or correct a count. Purchases arrive through Purchase Orders.">
            <form onSubmit={(ev) => { ev.preventDefault(); form.post('/inventory/moves', { preserveScroll: true, onSuccess: onDone }); }} className="grid grid-cols-2 gap-3">
                <div className="col-span-2"><Field label="Item" error={e.store_item_id}><select className="field" value={form.data.store_item_id} onChange={(x) => form.setData('store_item_id', x.target.value)}>{items.map((i) => <option key={i.id} value={i.id}>{i.name} · {num(i.qty)} {units[i.unit] ?? i.unit} in store</option>)}</select></Field></div>
                <Field label="What happened" error={e.type}><select className="field" value={form.data.type} onChange={(x) => form.setData('type', x.target.value)}>{Object.entries(types).map(([k, t]) => <option key={k} value={k}>{t.label}</option>)}</select></Field>
                {types[form.data.type]?.direction === 0 ? <Field label="Direction" error={e.direction}><select className="field" value={form.data.direction} onChange={(x) => form.setData('direction', x.target.value)}><option value="">Choose…</option><option value="in">In (more found)</option><option value="out">Out (less found)</option></select></Field> : <span />}
                <Field label={`Quantity${item ? ` (${units[item.unit] ?? item.unit})` : ''}`} error={e.qty}><input className="field" type="number" min={0} step="0.01" value={form.data.qty} onChange={(x) => form.setData('qty', x.target.value)} required /></Field>
                <Field label="Date" error={e.moved_on}><input className="field" type="date" max={today()} value={form.data.moved_on} onChange={(x) => form.setData('moved_on', x.target.value)} /></Field>
                {form.data.type === 'issue' ? <div className="col-span-2"><Field label="Issued to (pen, shed or person)" error={e.issued_to}><input className="field" value={form.data.issued_to} onChange={(x) => form.setData('issued_to', x.target.value)} /></Field></div> : null}
                <Field label="Reference" error={e.reference}><input className="field" value={form.data.reference} onChange={(x) => form.setData('reference', x.target.value)} /></Field>
                <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                <div className="col-span-2 flex gap-2"><Button type="submit" disabled={form.processing}>Save</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}
