import { Head, router, useForm } from '@inertiajs/react';
import DeleteButton from '../components/DeleteButton';
import { PackageCheck, Pencil, Send, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import AddButton from '../components/AddButton';
import { useConfirm } from '../components/Confirm';
import Sheet from '../components/Sheet';
import { Button, Chip, cx, Empty, Field, Note, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';

type Kind = 'feed' | 'medicine' | 'store';
type Line = { kind: Kind; item: string; unit: string; qty: number; rate: number; batch: string | null; expiry: string | null };
type Order = { id: number; no: string; supplier: string; supplierId: number; status: 'draft' | 'ordered' | 'received' | 'cancelled'; ordered: string; expected: string | null; received: string | null; receivedBy: string | null; notes: string | null; total: number; overdue: boolean; lines: Line[] };
type Supplier = { id: number; name: string; phone: string | null; city: string | null; supplies: string | null; notes: string | null; active: boolean; orders: number; spent: number };
type CatalogItem = { id: number; name: string; unit: string };
type Props = { orders: Order[]; suppliers: Supplier[]; catalog: Record<Kind, CatalogItem[]>; kinds: Record<Kind, string>; statuses: Record<string, string>; can: { manage: boolean } };

const STATUS: Record<Order['status'], string> = { draft: 'bg-glaze text-ink-soft', ordered: 'bg-cobalt-100 text-cobalt-800', received: 'bg-ok-100 text-ok-700', cancelled: 'bg-glaze text-ink-faint line-through' };
const pkr = (n: number) => `PKR ${Math.round(n).toLocaleString('en-PK')}`;
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

/** D-178: Purchase: purchase orders (draft → ordered → received) and suppliers. Receiving puts goods into their store. */
export default function Purchase({ orders, suppliers, catalog, kinds, statuses, can }: Props) {
    const [tab, setTab] = useTabParam('orders');
    const [newOrder, setNewOrder] = useState(false);
    const [supplier, setSupplier] = useState<Supplier | 'new' | null>(null);
    const [receiving, setReceiving] = useState<Order | null>(null);
    const [ask, confirmBox] = useConfirm();
    const open = orders.filter((o) => o.status === 'ordered' || o.status === 'draft');
    const status = async (o: Order, to: 'ordered' | 'cancelled') => {
        if (to === 'cancelled' && !(await ask({ title: `Cancel ${o.no}?`, message: 'Nothing is received and nothing is paid.', confirm: 'Yes, cancel order' }))) return;
        router.post(`/purchase/orders/${o.id}/status`, { status: to }, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Purchase" />
            {confirmBox}
            <PageHeader title="Purchase" description="Order feed, medicine and store items from suppliers. When goods arrive, Receive puts them in store and records the cost."
                actions={can.manage ? <><AddButton primary={false} label="New Supplier" onClick={() => setSupplier('new')} /><AddButton label="New Purchase Order" onClick={() => setNewOrder(true)} /></> : null} />
            <StatRow>
                <Stat label="Open orders" value={open.length} note={`${orders.filter((o) => o.overdue).length} past expected date`} tone={orders.some((o) => o.overdue) ? 'alert' : undefined} />
                <Stat label="On order" value={pkr(open.reduce((s, o) => s + o.total, 0))} />
                <Stat label="Received this month" value={pkr(orders.filter((o) => o.status === 'received' && o.received && new Date(o.received).getMonth() === new Date().getMonth()).reduce((s, o) => s + o.total, 0))} />
                <Stat label="Suppliers" value={suppliers.filter((s) => s.active).length} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[{ id: 'orders', label: 'Purchase Orders', count: open.length || undefined }, { id: 'suppliers', label: 'Suppliers', count: suppliers.length || undefined }]} />

            {tab === 'orders' ? (
                orders.length ? (
                    <div className="space-y-3">
                        {orders.map((o) => (
                            <section key={o.id} className="rounded-tile border border-rule bg-tile p-3 shadow-tile">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-mono font-semibold text-cobalt-900">{o.no}</span><Chip className={STATUS[o.status]}>{statuses[o.status]}</Chip>{o.overdue ? <Chip className="bg-crit-100 text-crit-700">Past expected date</Chip> : null}
                                    <span className="font-semibold">{o.supplier}</span>
                                    <span className="ml-auto font-bold tabular-nums">{pkr(o.total)}</span>
                                </div>
                                <p className="mt-0.5 text-sm text-ink-soft">Ordered {o.ordered}{o.expected ? ` · expected ${o.expected}` : ''}{o.received ? ` · received ${o.received} by ${o.receivedBy}` : ''}{o.notes ? ` · ${o.notes}` : ''}</p>
                                <table className="mt-2 w-full text-sm"><tbody className="divide-y divide-rule">
                                    {o.lines.map((l, i) => <tr key={i}><td className="py-1"><Chip className="mr-1.5 bg-glaze text-ink-soft">{kinds[l.kind]}</Chip>{l.item}{l.batch ? <span className="text-ink-faint"> · batch {l.batch}</span> : null}{l.expiry ? <span className="text-ink-faint"> · exp {l.expiry}</span> : null}</td><td className="py-1 text-right tabular-nums">{l.qty} {l.unit}</td><td className="py-1 text-right tabular-nums">{pkr(l.rate)}</td><td className="py-1 text-right font-semibold tabular-nums">{pkr(l.qty * l.rate)}</td></tr>)}
                                </tbody></table>
                                {can.manage && (o.status === 'draft' || o.status === 'ordered') ? (
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {o.status === 'draft' ? <Button type="button" variant="secondary" onClick={() => status(o, 'ordered')}><Send size={15} className="mr-1 inline" aria-hidden />Mark Ordered</Button> : null}
                                        <Button type="button" onClick={() => setReceiving(o)}><PackageCheck size={15} className="mr-1 inline" aria-hidden />Receive Goods</Button>
                                        <Button type="button" variant="quiet" onClick={() => status(o, 'cancelled')}><X size={15} className="mr-1 inline" aria-hidden />Cancel</Button>
                                    </div>
                                ) : null}
                            </section>
                        ))}
                    </div>
                ) : <Panel><Empty title="No purchase orders yet">{can.manage ? (suppliers.length ? 'Tap New Purchase Order to order from a supplier.' : 'Add a supplier first, then make a purchase order.') : 'The manager or accounts make purchase orders.'}</Empty></Panel>
            ) : null}

            {tab === 'suppliers' ? (
                <Panel flush>
                    {suppliers.length ? (
                        <ul className="divide-y divide-rule">
                            {suppliers.map((s) => (
                                <li key={s.id} className={cx('flex flex-wrap items-center gap-2 px-4 py-2.5', !s.active && 'opacity-60')}>
                                    <span className="min-w-0 flex-1"><span className="font-semibold text-cobalt-900">{s.name}</span><span className="block text-sm text-ink-soft">{[s.supplies, s.city, s.phone].filter(Boolean).join(' · ') || 'No details yet'}</span></span>
                                    <span className="text-sm text-ink-soft">{s.orders} order{s.orders === 1 ? '' : 's'} · {pkr(s.spent)} received</span>
                                    {can.manage ? <><Button type="button" variant="quiet" onClick={() => setSupplier(s)} aria-label={`Edit ${s.name}`}><Pencil size={15} aria-hidden /></Button><DeleteButton type="supplier" id={s.id} name={s.name} /></> : null}
                                </li>
                            ))}
                        </ul>
                    ) : <Empty title="No suppliers yet">{can.manage ? 'Add the feed mills, medicine dealers and shops you buy from.' : ''}</Empty>}
                </Panel>
            ) : null}

            {newOrder ? <Sheet onClose={() => setNewOrder(false)}><OrderForm suppliers={suppliers.filter((s) => s.active)} catalog={catalog} kinds={kinds} onDone={() => setNewOrder(false)} /></Sheet> : null}
            {supplier ? <Sheet onClose={() => setSupplier(null)}><SupplierForm supplier={supplier === 'new' ? null : supplier} onDone={() => setSupplier(null)} /></Sheet> : null}
            {receiving ? <Sheet onClose={() => setReceiving(null)}><ReceiveForm order={receiving} onDone={() => setReceiving(null)} /></Sheet> : null}
        </>
    );
}

function OrderForm({ suppliers, catalog, kinds, onDone }: { suppliers: Supplier[]; catalog: Props['catalog']; kinds: Props['kinds']; onDone: () => void }) {
    const blank = { kind: 'feed' as Kind, item_id: '', qty: '', rate: '' };
    const form = useForm({ supplier_id: String(suppliers[0]?.id ?? ''), ordered_on: today(), expected_on: '', status: 'ordered', notes: '', lines: [blank] });
    const e = form.errors as Record<string, string>;
    const setLine = (i: number, k: keyof typeof blank, v: string) => form.setData('lines', form.data.lines.map((l, j) => (j === i ? { ...l, [k]: v, ...(k === 'kind' ? { item_id: '' } : {}) } : l)));
    const total = form.data.lines.reduce((s, l) => s + (Number(l.qty) || 0) * (Number(l.rate) || 0), 0);
    if (!suppliers.length) return <Panel title="New Purchase Order"><Empty title="No suppliers yet">Add a supplier first.</Empty></Panel>;
    return (
        <Panel title="New Purchase Order">
            <form onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, lines: d.lines.map((l) => ({ kind: l.kind, item_id: Number(l.item_id), qty: Number(l.qty), rate: Number(l.rate) })) })); form.post('/purchase/orders', { preserveScroll: true, onSuccess: onDone }); }} className="space-y-3">
                <Field label="Supplier" error={e.supplier_id}><select className="field" value={form.data.supplier_id} onChange={(x) => form.setData('supplier_id', x.target.value)}>{suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Ordered on" error={e.ordered_on}><input className="field" type="date" max={today()} value={form.data.ordered_on} onChange={(x) => form.setData('ordered_on', x.target.value)} /></Field>
                    <Field label="Expected on" error={e.expected_on}><input className="field" type="date" value={form.data.expected_on} onChange={(x) => form.setData('expected_on', x.target.value)} /></Field>
                </div>
                <div className="space-y-2">
                    {form.data.lines.map((l, i) => (
                        <div key={i} className="space-y-2 rounded-tile border border-rule p-2">
                            <div className="grid grid-cols-[7rem_minmax(0,1fr)_auto] gap-2">
                                <select className="field" aria-label="Store" value={l.kind} onChange={(x) => setLine(i, 'kind', x.target.value)}>{(Object.keys(kinds) as Kind[]).map((k) => <option key={k} value={k}>{kinds[k]}</option>)}</select>
                                <select className="field" aria-label="Item" value={l.item_id} onChange={(x) => setLine(i, 'item_id', x.target.value)}><option value="">Choose an item…</option>{catalog[l.kind].map((c) => <option key={c.id} value={c.id}>{c.name} ({c.unit})</option>)}</select>
                                <button type="button" onClick={() => form.setData('lines', form.data.lines.filter((_, j) => j !== i))} disabled={form.data.lines.length <= 1} className="grid size-10 place-items-center rounded-full text-ink-soft hover:bg-glaze disabled:opacity-30" aria-label="Remove line"><Trash2 size={16} aria-hidden /></button>
                            </div>
                            {e[`lines.${i}.item_id`] ? <p className="text-sm text-crit-700">{e[`lines.${i}.item_id`]}</p> : null}
                            <div className="grid grid-cols-2 gap-2">
                                <Field label={`Quantity${l.item_id ? ` (${catalog[l.kind].find((c) => String(c.id) === l.item_id)?.unit ?? ''})` : ''}`} error={e[`lines.${i}.qty`]}><input className="field" type="number" min={0} step="0.01" value={l.qty} onChange={(x) => setLine(i, 'qty', x.target.value)} /></Field>
                                <Field label="Rate per unit (PKR)" error={e[`lines.${i}.rate`]}><input className="field" type="number" min={0} step="0.01" value={l.rate} onChange={(x) => setLine(i, 'rate', x.target.value)} /></Field>
                            </div>
                        </div>
                    ))}
                    <button type="button" onClick={() => form.setData('lines', [...form.data.lines, blank])} className="text-sm font-semibold text-turq-800 hover:underline">+ Add a line</button>
                </div>
                <p className="text-right font-bold">Total {pkr(total)}</p>
                <Field label="Notes (optional)" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                <div className="flex flex-wrap gap-2">
                    <Button type="submit" disabled={form.processing} onClick={() => form.setData('status', 'ordered')}>Save as Ordered</Button>
                    <Button type="submit" variant="secondary" disabled={form.processing} onClick={() => form.setData('status', 'draft')}>Save as Draft</Button>
                </div>
            </form>
        </Panel>
    );
}

function ReceiveForm({ order, onDone }: { order: Order; onDone: () => void }) {
    const form = useForm({ received_on: today(), paid: false, lines: order.lines.map(() => ({ batch: '', expiry_on: '' })) });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title={`Receive ${order.no}`} description={`${order.supplier} · ${pkr(order.total)}`}>
            <form onSubmit={(ev) => { ev.preventDefault(); form.post(`/purchase/orders/${order.id}/receive`, { preserveScroll: true, onSuccess: onDone }); }} className="space-y-3">
                <Field label="Received on" error={e.received_on}><input className="field" type="date" max={today()} value={form.data.received_on} onChange={(x) => form.setData('received_on', x.target.value)} /></Field>
                {order.lines.map((l, i) => (
                    <div key={i} className="rounded-tile border border-rule p-2 text-sm">
                        <div className="font-semibold">{l.item} · {l.qty} {l.unit}</div>
                        {l.kind === 'medicine' ? (
                            <div className="mt-1 grid grid-cols-2 gap-2">
                                <Field label="Batch" error={e[`lines.${i}.batch`]}><input className="field" value={form.data.lines[i].batch} onChange={(x) => form.setData('lines', form.data.lines.map((v, j) => (j === i ? { ...v, batch: x.target.value } : v)))} /></Field>
                                <Field label="Expiry" error={e[`lines.${i}.expiry_on`]}><input className="field" type="date" value={form.data.lines[i].expiry_on} onChange={(x) => form.setData('lines', form.data.lines.map((v, j) => (j === i ? { ...v, expiry_on: x.target.value } : v)))} /></Field>
                            </div>
                        ) : null}
                    </div>
                ))}
                <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="size-5" checked={form.data.paid} onChange={(x) => form.setData('paid', x.target.checked)} /> Paid now (otherwise it is owed to {order.supplier})</label>
                <Note>Each line goes into its store (feed, medicine or general) at this price, and the cost is recorded in the books.</Note>
                <div className="flex gap-2"><Button type="submit" disabled={form.processing}>Receive Goods</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}

function SupplierForm({ supplier, onDone }: { supplier: Supplier | null; onDone: () => void }) {
    const form = useForm({ name: supplier?.name ?? '', phone: supplier?.phone ?? '', city: supplier?.city ?? '', supplies: supplier?.supplies ?? '', notes: supplier?.notes ?? '', active: supplier?.active ?? true });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title={supplier ? `Edit ${supplier.name}` : 'New Supplier'}>
            <form onSubmit={(ev) => { ev.preventDefault(); supplier ? form.post(`/purchase/suppliers/${supplier.id}`, { preserveScroll: true, onSuccess: onDone }) : form.post('/purchase/suppliers', { preserveScroll: true, onSuccess: onDone }); }} className="space-y-3">
                <Field label="Name" error={e.name}><input className="field" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} required /></Field>
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Phone" error={e.phone}><input className="field" inputMode="tel" value={form.data.phone} onChange={(x) => form.setData('phone', x.target.value)} /></Field>
                    <Field label="City" error={e.city}><input className="field" value={form.data.city} onChange={(x) => form.setData('city', x.target.value)} /></Field>
                </div>
                <Field label="Supplies" error={e.supplies}><input className="field" value={form.data.supplies} onChange={(x) => form.setData('supplies', x.target.value)} placeholder="e.g. Feed, wanda, minerals" /></Field>
                <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                {supplier ? <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="size-5" checked={form.data.active} onChange={(x) => form.setData('active', x.target.checked)} /> Active</label> : null}
                <div className="flex gap-2"><Button type="submit" disabled={form.processing}>{supplier ? 'Save Supplier' : 'Add Supplier'}</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}
