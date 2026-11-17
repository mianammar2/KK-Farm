import { Head, Link, router, useForm } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import AddButton from '../components/AddButton';
import DataGrid, { type Col } from '../components/DataGrid';
import DeleteButton from '../components/DeleteButton';
import Sheet from '../components/Sheet';
import { CodeField, FeedItemForm, FeedStockForm, MedicineAdd, MedicineEdit, MedicineMoveForm, type FeedItem, type MedicineItem, type MoveTypes } from '../components/StockForms';
import { Button, Chip, cx, Empty, Field, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import { useTabParam } from '../lib/useTabParam';

type Kind = 'feed' | 'medicine' | 'store';
type Status = 'ok' | 'low' | 'out' | 'negative';
type Row = {
    kind: Kind; id: number; code: string | null; name: string; category: string; categoryKey: string; unit: string; unitKey: string; qty: number; reorder: number; value: number; cost: number; status: Status;
    active: boolean; supplier: string | null; storage: string | null; notes: string | null; cp: number | null; dm: number | null;
    spec: string | null; useCase: string | null; source: string | null; packUnit: string | null; packSize: number | null; check: string | null; checkNote: string | null; diseaseIds: number[];
};
type Move = { kind: Kind; itemId: number; on: string | null; d: string | null; item: string | null; typeKey: string; type: string; qty: number; unit: string | null; rate: number | null; batch: string | null; party: string | null; reference: string | null; notes: string | null; by: string };
type Batch = { id: number; item: string; batch: string; qty: number; unit: string; expiry: string; days: number; state: 'expired' | 'expiring' | 'ok' };
type Master = { categories: Record<string, string>; units: Record<string, string>; types: MoveTypes; packs?: Record<string, string>; checks?: Record<string, string>; diseases?: Array<{ id: number; name: string }> };
type Props = {
    items: Row[]; expiry: Batch[]; moves: Move[]; range: { from: string; to: string }; stores: Kind[];
    masters: Record<Kind, Master>; can: Partial<Record<Kind, boolean>> & { purchase: boolean };
};

const KIND: Record<Kind, { label: string; chip: string; app: string }> = {
    feed: { label: 'Feed', chip: 'bg-turq-100 text-turq-800', app: 'Feeding' },
    medicine: { label: 'Medicine', chip: 'bg-manganese-100 text-manganese-600', app: 'Veterinary' },
    store: { label: 'General', chip: 'bg-cobalt-100 text-cobalt-800', app: 'Housing, Maintenance and Cleaning' },
};
const STATUS: Record<Status, { label: string; chip: string }> = {
    ok: { label: 'In stock', chip: 'bg-ok-100 text-ok-700' }, low: { label: 'Reorder', chip: 'bg-watch-100 text-watch-700' },
    out: { label: 'Out', chip: 'bg-crit-100 text-crit-700' }, negative: { label: 'Check count', chip: 'bg-crit-100 text-crit-700' },
};
const EXPIRY = { expired: { label: 'Expired', chip: 'bg-crit-100 text-crit-700' }, expiring: { label: 'Expiring soon', chip: 'bg-watch-100 text-watch-700' }, ok: { label: 'In date', chip: 'bg-ok-100 text-ok-700' } };
const CHECK: Record<string, string> = { confirmed: 'Confirmed', to_confirm: 'To confirm', hold: 'On hold' };
const DELETE: Record<Kind, string> = { feed: 'feed-item', medicine: 'medicine', store: 'store-item' };
const num = (n: number) => (Math.round(n * 100) / 100).toLocaleString('en-PK');
const pkr = (n: number) => `PKR ${Math.round(n).toLocaleString('en-PK')}`;
const iso = (d: Date) => new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const today = () => iso(new Date());
const fmt = (d: string) => d.split('-').reverse().join('/');
const asFeed = (r: Row): FeedItem => ({ id: r.id, code: r.code ?? '', name: r.name, category: r.categoryKey, costPerKg: r.cost, cp: r.cp, dm: r.dm, reorder: r.reorder, supplier: r.supplier, notes: r.notes, active: r.active, stock: r.qty });
const asMedicine = (r: Row): MedicineItem => ({ id: r.id, code: r.code ?? '', name: r.name, category: r.categoryKey, unit: r.unitKey, reorder: r.reorder, costPerUnit: r.cost, storage: r.storage, supplier: r.supplier, active: r.active, qty: r.qty, spec: r.spec, useCase: r.useCase, source: r.source, packUnit: r.packUnit, packSize: r.packSize, check: r.check, checkNote: r.checkNote, notes: r.notes, diseaseIds: r.diseaseIds });

type Pop = { what: 'card'; row: Row } | { what: 'new'; kind: Kind | null } | { what: 'edit'; row: Row } | { what: 'move'; kind: Kind | null; itemId?: number };

/**
 * D-178: Inventory: every store in one view. D-331: the full inventory system. Every item of every store (feed,
 * medicine, general) is added, edited, stocked in and out, counted and traced here. Feeding, Veterinary, Purchase and
 * Finance read the same records, so there is one figure for each item everywhere.
 */
export default function Inventory({ items, expiry, moves, range, stores, masters, can }: Props) {
    const [rawTab, setTab] = useTabParam('overview');
    const tab = rawTab === 'general' ? 'items' : rawTab; // older links: General Store is the Items list of the general store
    const [kind, setKind] = useState<Kind | ''>(rawTab === 'general' ? 'store' : '');
    const [pop, setPop] = useState<Pop | null>(null);
    const mine = (stores.filter((k) => can[k]) as Kind[]);
    const active = items.filter((r) => r.active);
    const byKind = (rows: Row[]) => rows.filter((r) => !kind || r.kind === kind);
    const reorder = active.filter((r) => r.status !== 'ok');
    const expiring = expiry.filter((b) => b.state !== 'ok');
    const value = active.reduce((s, r) => s + r.value, 0);
    const close = () => setPop(null);
    const kinds = stores.length > 1 ? (
        <select aria-label="Store" className="field w-auto" value={kind} onChange={(e) => setKind(e.target.value as Kind | '')}>
            <option value="">All stores</option>{stores.map((k) => <option key={k} value={k}>{KIND[k].label}</option>)}
        </select>
    ) : null;
    const open = (r: Row) => setPop({ what: 'card', row: r });

    const stockCols: Col<Row>[] = [
        { key: 'name', label: 'Item', type: 'text', total: 'count', render: (r) => <button type="button" onClick={() => open(r)} className="text-left text-cobalt-800 hover:underline">{r.name}{r.code ? <span className="ml-1.5 font-mono text-ink-faint">{r.code}</span> : null}</button> },
        { key: 'store', label: 'Store', type: 'enum', value: (r) => KIND[r.kind].label, render: (r) => <Chip className={KIND[r.kind].chip}>{KIND[r.kind].label}</Chip> },
        { key: 'category', label: 'Category', type: 'enum' },
        { key: 'qty', label: 'On hand', type: 'number', render: (r) => <span className={cx(r.qty <= 0 && 'text-crit-700')}>{num(r.qty)} {r.unit}</span> },
        { key: 'reorder', label: 'Reorder at', type: 'number', render: (r) => (r.reorder ? `${num(r.reorder)} ${r.unit}` : '—') },
        { key: 'cost', label: 'Cost per unit', type: 'number', render: (r) => `${pkr(r.cost)} / ${r.unit}` },
        { key: 'value', label: 'Value (PKR)', type: 'number', total: 'sum', render: (r) => pkr(r.value) },
        { key: 'status', label: 'Status', type: 'enum', value: (r) => STATUS[r.status].label, render: (r) => <Chip className={STATUS[r.status].chip}>{STATUS[r.status].label}</Chip> },
        ...(Object.values(can).some(Boolean) ? [{ key: 'actions', label: 'Actions', type: 'text' as const, value: () => '', render: (r: Row) => (can[r.kind] ? <button type="button" onClick={() => setPop({ what: 'edit', row: r })} className="inline-flex min-h-9 items-center gap-1 rounded-md border border-rule bg-tile px-2.5 text-sm text-cobalt-900 hover:bg-glaze" aria-label={`Edit ${r.name}`}><Pencil size={14} aria-hidden /> Edit</button> : null) }] : []),
    ];
    // D-334: Edit on every row; a vet confirms a product "to confirm" or "on hold" with one click
    const actions: Col<Row> = { key: 'actions', label: 'Actions', type: 'text', value: () => '', render: (r) => (
        <span className="flex flex-wrap items-center gap-2">
            {can[r.kind] ? <button type="button" onClick={() => setPop({ what: 'edit', row: r })} className="inline-flex min-h-9 items-center gap-1 rounded-md border border-rule bg-tile px-2.5 text-sm text-cobalt-900 hover:bg-glaze" aria-label={`Edit ${r.name}`}><Pencil size={14} aria-hidden /> Edit</button> : null}
        </span>
    ) };
    const itemCols: Col<Row>[] = [
        stockCols[0], actions, stockCols[1], stockCols[2],
        { key: 'unit', label: 'Unit', type: 'enum' },
        { key: 'spec', label: 'Specification', type: 'text', wrap: true, value: (r) => r.spec ?? '' },
        { key: 'pack', label: 'Bought as', type: 'enum', value: (r) => (r.packUnit && r.packSize ? `${r.packUnit[0].toUpperCase()}${r.packUnit.slice(1)} of ${num(r.packSize)} ${r.unit}` : '') },
        { key: 'check', label: 'Vet check', type: 'enum', value: (r) => (r.check ? CHECK[r.check] ?? r.check : ''), render: (r) => (r.check && r.check !== 'confirmed' ? <span className="flex flex-wrap items-center gap-2"><Chip className={r.check === 'hold' ? 'bg-crit-100 text-crit-700' : 'bg-watch-100 text-watch-700'}>{CHECK[r.check]}</Chip>{can.medicine ? <ConfirmButton row={r} /> : null}</span> : r.check ? CHECK[r.check] : '') },
        { key: 'supplier', label: 'Supplier', type: 'enum', value: (r) => r.supplier ?? '' },
        { key: 'used', label: 'Used in', type: 'enum', value: (r) => KIND[r.kind].app },
        { key: 'state', label: 'In use', type: 'enum', value: (r) => (r.active ? 'Yes' : 'No') },
    ];

    return (
        <>
            <Head title="Inventory" />
            <PageHeader title="Inventory" description="Every feed, medicine and store item in one place. Items added here appear in Feeding, Veterinary and Purchase."
                actions={mine.length ? <><AddButton primary={false} label="Stock In / Out" onClick={() => setPop({ what: 'move', kind: mine.length === 1 ? mine[0] : null })} /><AddButton label="New Item" onClick={() => setPop({ what: 'new', kind: mine.length === 1 ? mine[0] : null })} /></> : null} />
            <StatRow>
                <Stat label="Items" value={active.length} note={stores.map((k) => `${active.filter((r) => r.kind === k).length} ${KIND[k].label.toLowerCase()}`).join(' · ')} onClick={() => setTab('items')} />
                <Stat label="Need reorder" value={reorder.length} tone={reorder.length ? 'alert' : undefined} note="Low, out or to recount" onClick={() => setTab('reorder')} />
                {stores.includes('medicine') ? <Stat label="Expiry" value={expiring.length} tone={expiring.length ? 'alert' : undefined} note="Batches expired or expiring" onClick={() => setTab('expiry')} /> : null}
                <Stat label="Stock value" value={pkr(value)} note="At the latest cost" onClick={() => setTab('overview')} />
            </StatRow>
            <Tabs value={tab} onChange={setTab} tabs={[
                { id: 'overview', label: 'All Stock' }, { id: 'items', label: 'Items', count: items.length || undefined },
                { id: 'reorder', label: 'Reorder', count: reorder.length || undefined },
                ...(stores.includes('medicine') ? [{ id: 'expiry', label: 'Expiry', count: expiring.length || undefined }] : []),
                ...(mine.length ? [{ id: 'count', label: 'Stock Count' }] : []),
                { id: 'moves', label: 'Stock Moves' },
            ]} />

            {tab === 'overview' || tab === 'reorder' ? (
                <Panel flush actions={tab === 'overview' ? kinds : null}>
                    {(tab === 'reorder' ? reorder : byKind(active)).length ? (
                        <DataGrid<Row> id={`inventory-${tab}`} name={tab === 'reorder' ? 'Reorder list' : 'Stock'} rows={tab === 'reorder' ? reorder : byKind(active)} rowKey={(r) => `${r.kind}-${r.id}`} cols={stockCols} />
                    ) : <Empty title={tab === 'reorder' ? 'Nothing to reorder' : 'No items yet'}>{tab === 'reorder' ? 'Every item is above its reorder level.' : 'Add the first item with New Item.'}</Empty>}
                    {tab === 'reorder' && reorder.length && can.purchase ? <p className="border-t border-rule p-4 text-sm"><Link href="/purchase" className="text-cobalt-800 hover:underline">Raise a purchase order</Link> for these items. Received orders add to the store by themselves.</p> : null}
                </Panel>
            ) : null}

            {tab === 'items' ? (
                <Panel flush title="Items" description="The one list of everything the farm keeps in store. Click an item to see its card, edit it, or move stock." actions={kinds}>
                    {byKind(items).length ? <DataGrid<Row> id="inventory-items" name="Items" rows={byKind(items)} rowKey={(r) => `${r.kind}-${r.id}`} cols={itemCols} />
                        : <Empty title="No items yet">{mine.length ? 'Add the first item with New Item.' : 'The store keeper adds items.'}</Empty>}
                </Panel>
            ) : null}

            {tab === 'expiry' ? (
                <Panel flush title="Medicine Expiry" description="Every batch in store with its expiry date, earliest first. Treatments use the batch that expires first.">
                    {expiry.length ? <DataGrid<Batch> id="inventory-expiry" name="Medicine expiry" rows={expiry} rowKey={(b) => `${b.id}-${b.batch}`} cols={[
                        { key: 'item', label: 'Medicine', type: 'text', total: 'count', render: (b) => { const r = items.find((x) => x.kind === 'medicine' && x.id === b.id); return r ? <button type="button" onClick={() => open(r)} className="text-cobalt-800 hover:underline">{b.item}</button> : b.item; } },
                        { key: 'batch', label: 'Batch', type: 'text' },
                        { key: 'qty', label: 'In store', type: 'number', render: (b) => `${num(b.qty)} ${b.unit}` },
                        { key: 'expiry', label: 'Expires', type: 'text' },
                        { key: 'days', label: 'Days left', type: 'number', render: (b) => (b.days < 0 ? `${-b.days} days ago` : b.days) },
                        { key: 'state', label: 'Status', type: 'enum', value: (b) => EXPIRY[b.state].label, render: (b) => <Chip className={EXPIRY[b.state].chip}>{EXPIRY[b.state].label}</Chip> },
                    ]} /> : <Empty title="No batches with an expiry date">Enter the batch and expiry when medicine is received.</Empty>}
                </Panel>
            ) : null}

            {tab === 'count' && mine.length ? <StockCount items={active} stores={mine} /> : null}

            {tab === 'moves' ? <Moves moves={moves} range={range} stores={stores} items={items} onOpen={open} /> : null}

            {pop ? (
                <Sheet onClose={close}>
                    {pop.what === 'card' ? <ItemCard row={pop.row} diseases={masters.medicine?.diseases ?? []} can={!!can[pop.row.kind]} purchase={can.purchase} onEdit={() => setPop({ what: 'edit', row: pop.row })} onMove={() => setPop({ what: 'move', kind: pop.row.kind, itemId: pop.row.id })} /> : null}
                    {pop.what === 'new' ? (pop.kind ? <NewItem kind={pop.kind} master={masters[pop.kind]} onDone={close} /> : <PickStore title="New Item" stores={mine} onPick={(k) => setPop({ what: 'new', kind: k })} />) : null}
                    {pop.what === 'edit' ? <EditItem row={pop.row} master={masters[pop.row.kind]} onDone={close} /> : null}
                    {pop.what === 'move' ? (pop.kind ? <MoveStock kind={pop.kind} items={active.filter((r) => r.kind === pop.kind)} master={masters[pop.kind]} itemId={pop.itemId} onDone={close} /> : <PickStore title="Stock In / Out" stores={mine} onPick={(k) => setPop({ what: 'move', kind: k })} />) : null}
                </Sheet>
            ) : null}
        </>
    );
}

/** D-334: the vet confirms the product; who and when is kept on it. */
function ConfirmButton({ row, onDone }: { row: Row; onDone?: () => void }) {
    const [busy, setBusy] = useState(false);
    return (
        <button type="button" disabled={busy} onClick={() => { if (window.confirm(`Confirm ${row.name}?${row.checkNote ? `\n\nTo confirm: ${row.checkNote}` : ''}\n\nIt can then be given to goats.`)) { setBusy(true); router.post(`/inventory/medicines/${row.id}/confirm`, {}, { preserveScroll: true, onSuccess: () => onDone?.(), onFinish: () => setBusy(false) }); } }}
            className="inline-flex min-h-9 items-center rounded-md bg-ok-700 px-2.5 text-sm text-white hover:opacity-90 disabled:opacity-60">Confirm</button>
    );
}

function PickStore({ title, stores, onPick }: { title: string; stores: Kind[]; onPick: (k: Kind) => void }) {
    return (
        <Panel title={title} description="Which store is it for?">
            <div className="grid gap-2 sm:grid-cols-3">
                {stores.map((k) => <button key={k} type="button" onClick={() => onPick(k)} className="min-h-16 rounded-tile border border-rule bg-tile px-3 py-2 text-left hover:bg-glaze"><Chip className={KIND[k].chip}>{KIND[k].label}</Chip><span className="mt-1 block text-sm text-ink-soft">Used in {KIND[k].app}</span></button>)}
            </div>
        </Panel>
    );
}

function NewItem({ kind, master, onDone }: { kind: Kind; master: Master; onDone: () => void }) {
    if (kind === 'feed') return <Panel title="New Feed" description="Appears in Feeding (rations, feeding, TMR) and Purchase."><FeedItemForm categories={master.categories} via="inventory" onDone={onDone} /></Panel>;
    if (kind === 'medicine') return <MedicineAdd categories={master.categories} units={master.units} packs={master.packs} checks={master.checks} diseases={master.diseases} via="inventory" onDone={onDone} />;
    return <StoreItemForm item={null} categories={master.categories} units={master.units} onDone={onDone} />;
}

function EditItem({ row, master, onDone }: { row: Row; master: Master; onDone: () => void }) {
    if (row.kind === 'feed') return <Panel title={`Edit ${row.name}`}><FeedItemForm item={asFeed(row)} categories={master.categories} via="inventory" onDone={onDone} /></Panel>;
    if (row.kind === 'medicine') return <Panel title={`Edit ${row.name}`}><MedicineEdit m={asMedicine(row)} categories={master.categories} units={master.units} packs={master.packs} checks={master.checks} diseases={master.diseases} via="inventory" onDone={onDone} /></Panel>;
    return <StoreItemForm item={row} categories={master.categories} units={master.units} onDone={onDone} />;
}

function MoveStock({ kind, items, master, itemId, onDone }: { kind: Kind; items: Row[]; master: Master; itemId?: number; onDone: () => void }) {
    if (!items.length) return <Panel title="Stock In / Out"><Empty title="No items in this store yet">Add an item first with New Item.</Empty></Panel>;
    if (kind === 'feed') return <FeedStockForm items={items.map(asFeed)} movementTypes={master.types} via="inventory" itemId={itemId} onDone={onDone} />;
    if (kind === 'medicine') return <MedicineMoveForm medicines={items.map(asMedicine)} types={master.types} via="inventory" itemId={itemId} onDone={onDone} />;
    return <StoreMoveForm items={items} types={master.types} itemId={itemId} onDone={onDone} />;
}

function ItemCard({ row, diseases, can, purchase, onEdit, onMove }: { row: Row; diseases: Array<{ id: number; name: string }>; can: boolean; purchase: boolean; onEdit: () => void; onMove: () => void }) {
    const [data, setData] = useState<{ item: Row; batches: Batch[]; moves: Move[] } | null>(null);
    const [failed, setFailed] = useState(false);
    const [reload, setReload] = useState(0);
    useEffect(() => {
        const c = new AbortController();
        fetch(`/inventory/card?kind=${row.kind}&id=${row.id}`, { headers: { Accept: 'application/json' }, signal: c.signal })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status))).then(setData).catch((e) => { if (e?.name !== 'AbortError') setFailed(true); });
        return () => c.abort();
    }, [row.kind, row.id, reload]);
    const r = data?.item ?? row;
    const facts = [
        ['Store', KIND[r.kind].label], ['Category', r.category], ['Unit', r.unit], ['Used in', KIND[r.kind].app],
        ...(r.spec ? [['Specification', r.spec]] : []), ...(r.useCase ? [['Use', r.useCase]] : []),
        ...(r.diseaseIds?.length ? [['Used for', diseases.filter((d) => r.diseaseIds.includes(d.id)).map((d) => d.name).join(', ')]] : []),
        ...(r.packUnit && r.packSize ? [['Bought as', `${r.packUnit[0].toUpperCase()}${r.packUnit.slice(1)} of ${num(r.packSize)} ${r.unit}`]] : []),
        ...(r.check ? [['Vet check', `${CHECK[r.check] ?? r.check}${r.checkNote ? `: ${r.checkNote}` : ''}`]] : []), ...(r.source ? [['Source', r.source]] : []),
        ...(r.spec ? [['Specification', r.spec]] : []), ...(r.useCase ? [['Use', r.useCase]] : []),
        ...(r.diseaseIds?.length ? [['Used for', diseases.filter((d) => r.diseaseIds.includes(d.id)).map((d) => d.name).join(', ')]] : []),
        ...(r.packUnit && r.packSize ? [['Bought as', `${r.packUnit[0].toUpperCase()}${r.packUnit.slice(1)} of ${num(r.packSize)} ${r.unit}`]] : []),
        ...(r.check ? [['Vet check', `${CHECK[r.check] ?? r.check}${r.checkNote ? `: ${r.checkNote}` : ''}`]] : []), ...(r.source ? [['Source', r.source]] : []),
        ...(r.supplier ? [['Supplier', r.supplier]] : []), ...(r.storage ? [['Storage', r.storage]] : []),
        ...(r.cp !== null ? [['CP %', String(r.cp)]] : []), ...(r.dm !== null ? [['Dry matter %', String(r.dm)]] : []), ...(r.notes ? [['Notes', r.notes]] : []),
    ];
    return (
        <Panel title={r.code ? `${r.name} · ${r.code}` : r.name} description={r.active ? undefined : 'No longer in use'}
            actions={<div className="flex flex-wrap gap-2">{can && r.kind === 'medicine' && r.check && r.check !== 'confirmed' ? <ConfirmButton row={r} onDone={() => setReload((n) => n + 1)} /> : null}{can && r.active ? <Button type="button" onClick={onMove}>Stock In / Out</Button> : null}{can ? <Button type="button" variant="secondary" onClick={onEdit}>Edit</Button> : null}{can ? <DeleteButton type={DELETE[r.kind]} id={r.id} name={r.name} /> : null}</div>}>
            <StatRow>
                <Stat label="On hand" value={`${num(r.qty)} ${r.unit}`} tone={r.qty <= 0 ? 'alert' : undefined} />
                <Stat label="Reorder at" value={r.reorder ? `${num(r.reorder)} ${r.unit}` : '—'} />
                <Stat label="Cost per unit" value={pkr(r.cost)} />
                <Stat label="Value" value={pkr(r.value)} note={STATUS[r.status].label} />
            </StatRow>
            <dl className="mt-3 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-2">{facts.map(([k, v]) => <div key={k} className="flex gap-2"><dt className="w-28 shrink-0 text-ink-soft">{k}</dt><dd>{v}</dd></div>)}</dl>
            {r.status !== 'ok' && purchase ? <p className="mt-3 text-sm"><Link href="/purchase" className="text-cobalt-800 hover:underline">Raise a purchase order</Link> to restock.</p> : null}
            {data?.batches.length ? (
                <div className="mt-4"><h3 className="mb-1 text-sm text-ink-soft">Batches in store</h3>
                    <ul className="divide-y divide-rule rounded-tile border border-rule text-sm">{data.batches.map((b) => <li key={b.batch} className="flex flex-wrap items-center gap-2 px-3 py-2"><span className="font-mono">{b.batch}</span><span>{num(b.qty)} {b.unit}</span><span className="text-ink-soft">expires {b.expiry}</span><Chip className={cx('ml-auto', EXPIRY[b.state].chip)}>{EXPIRY[b.state].label}</Chip></li>)}</ul>
                </div>
            ) : null}
            <div className="mt-4">
                <h3 className="mb-1 text-sm text-ink-soft">Every movement</h3>
                {failed ? <p className="text-sm text-crit-700">Could not load the movements. Close and open the card again.</p> : !data ? <p className="text-sm text-ink-soft">Loading…</p> : data.moves.length ? (
                    <DataGrid<Move> id={`inventory-card-${r.kind}`} name={`${r.name} movements`} rows={data.moves} rowKey={(m) => `${m.typeKey}-${m.d}-${m.qty}-${m.reference}-${m.by}-${m.batch}`} cols={moveCols(false)} />
                ) : <p className="text-sm text-ink-soft">No movements yet.</p>}
            </div>
        </Panel>
    );
}

const moveCols = (withItem: boolean, onOpen?: (m: Move) => void): Col<Move>[] => [
    { key: 'on', label: 'Date', type: 'text', value: (m) => m.d ?? '', render: (m) => m.on ?? '—' },
    ...(withItem ? [
        { key: 'item', label: 'Item', type: 'text' as const, total: 'count' as const, render: (m: Move) => (onOpen ? <button type="button" onClick={() => onOpen(m)} className="text-left text-cobalt-800 hover:underline">{m.item}</button> : m.item) },
        { key: 'store', label: 'Store', type: 'enum' as const, value: (m: Move) => KIND[m.kind].label, render: (m: Move) => <Chip className={KIND[m.kind].chip}>{KIND[m.kind].label}</Chip> },
    ] : []),
    { key: 'type', label: 'Movement', type: 'enum' },
    { key: 'qty', label: 'Quantity', type: 'number', render: (m) => <span className={m.qty < 0 ? 'text-crit-700' : 'text-ok-700'}>{m.qty > 0 ? '+' : ''}{num(m.qty)} {m.unit}</span> },
    { key: 'direction', label: 'In / Out', type: 'enum', value: (m) => (m.qty < 0 ? 'Out' : 'In') },
    { key: 'rate', label: 'Rate (PKR)', type: 'number', render: (m) => (m.rate !== null ? pkr(m.rate) : '—') },
    { key: 'batch', label: 'Batch', type: 'text', value: (m) => m.batch ?? '' },
    { key: 'party', label: 'Supplier / to', type: 'enum', value: (m) => m.party ?? '' },
    { key: 'reference', label: 'Reference', type: 'text', value: (m) => m.reference ?? '' },
    { key: 'notes', label: 'Notes', type: 'text', wrap: true, value: (m) => m.notes ?? '' },
    { key: 'by', label: 'By', type: 'enum' },
];

function Moves({ moves, range, stores, items, onOpen }: { moves: Move[]; range: Props['range']; stores: Kind[]; items: Row[]; onOpen: (r: Row) => void }) {
    const [from, setFrom] = useState(range.from);
    const [to, setTo] = useState(range.to);
    const [kind, setKind] = useState<Kind | ''>('');
    const load = (f: string, t: string) => { setFrom(f); setTo(t); router.get('/inventory', { tab: 'moves', from: f, to: t }, { preserveState: true, preserveScroll: true, replace: true, only: ['moves', 'range'] }); };
    const preset = (days: number | 'month') => {
        const t = new Date();
        load(days === 'month' ? iso(new Date(t.getFullYear(), t.getMonth(), 1)) : iso(new Date(t.getFullYear(), t.getMonth(), t.getDate() - days + 1)), iso(t));
    };
    const rows = useMemo(() => moves.filter((m) => !kind || m.kind === kind), [moves, kind]);
    const inQty = rows.filter((m) => m.qty > 0).length;
    const open = (m: Move) => { const r = items.find((x) => x.kind === m.kind && x.id === m.itemId); if (r) onOpen(r); };
    return (
        <Panel flush title="Stock Moves" description="Every receipt, purchase, issue, use, count and adjustment in the stores you see, newest first. Feed given to goats shows once a day per feed.">
            <div className="flex flex-wrap items-end gap-2 border-b border-rule p-4">
                <label className="text-sm text-ink-soft">From<input type="date" className="field mt-1 block w-44" value={from} max={to || undefined} onChange={(e) => load(e.target.value, to)} /></label>
                <label className="text-sm text-ink-soft">To<input type="date" className="field mt-1 block w-44" value={to} min={from || undefined} max={today()} onChange={(e) => load(from, e.target.value)} /></label>
                {([['Today', 1], ['Last 7 days', 7], ['Last 30 days', 30], ['This month', 'month'], ['Last 90 days', 90], ['Last year', 365]] as const).map(([label, d]) => (
                    <button key={label} type="button" onClick={() => preset(d)} className="h-11 rounded-md border border-rule bg-tile px-3 text-sm text-cobalt-900 hover:bg-glaze">{label}</button>
                ))}
                {stores.length > 1 ? <select aria-label="Store" className="field w-auto" value={kind} onChange={(e) => setKind(e.target.value as Kind | '')}><option value="">All stores</option>{stores.map((k) => <option key={k} value={k}>{KIND[k].label}</option>)}</select> : null}
                <span className="ml-auto text-sm text-ink-soft">{fmt(from)} to {fmt(to)} · {rows.length} moves · {inQty} in · {rows.length - inQty} out</span>
            </div>
            {rows.length ? <DataGrid<Move> id="inventory-moves" name="Stock moves" rows={rows} rowKey={(m) => `${m.kind}-${m.itemId}-${m.typeKey}-${m.d}-${m.qty}-${m.reference}-${m.by}-${m.batch}`} cols={moveCols(true, open)} />
                : <p className="p-4 text-sm text-ink-soft">No stock moves in these dates.</p>}
        </Panel>
    );
}

function StockCount({ items, stores }: { items: Row[]; stores: Kind[] }) {
    const [kind, setKind] = useState<Kind>(stores[0]);
    const list = items.filter((r) => r.kind === kind);
    const form = useForm<{ kind: Kind; counted_on: string; notes: string; counts: Record<string, string> }>({ kind, counted_on: today(), notes: '', counts: {} });
    const e = form.errors as Record<string, string>;
    const entered = list.filter((r) => form.data.counts[r.id] !== undefined && form.data.counts[r.id] !== '');
    const diff = (r: Row) => Number(form.data.counts[r.id]) - r.qty;
    const value = entered.reduce((s, r) => s + diff(r) * r.cost, 0);
    const submit = (ev: React.FormEvent) => {
        ev.preventDefault();
        form.transform((d) => ({ kind: d.kind, counted_on: d.counted_on, notes: d.notes, counts: entered.map((r) => ({ id: r.id, counted: d.counts[r.id] })) }));
        form.post('/inventory/count', { preserveScroll: true, onSuccess: () => form.setData('counts', {}) });
    };
    return (
        <Panel flush title="Stock Count" description="Count what is really in the store and enter it. Each difference to the book is posted once as an adjustment (shrinkage when less is found). Leave an item blank to skip it.">
            <form onSubmit={submit}>
                <div className="flex flex-wrap items-end gap-3 border-b border-rule p-4">
                    {stores.length > 1 ? <Field label="Store"><select className="field w-auto" value={kind} onChange={(x) => { setKind(x.target.value as Kind); form.setData((d) => ({ ...d, kind: x.target.value as Kind, counts: {} })); }}>{stores.map((k) => <option key={k} value={k}>{KIND[k].label}</option>)}</select></Field> : null}
                    <Field label="Counted on" error={e.counted_on}><input type="date" className="field w-44" max={today()} value={form.data.counted_on} onChange={(x) => form.setData('counted_on', x.target.value)} /></Field>
                    <div className="min-w-48 flex-1"><Field label="Notes (optional)" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field></div>
                </div>
                {list.length ? (
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[40rem] text-sm">
                            <thead className="bg-cobalt-100/50 text-left text-ink-soft"><tr>{['Item', 'In the book', 'Counted', 'Difference', 'Value (PKR)'].map((h) => <th key={h} className="px-4 py-2 font-normal">{h}</th>)}</tr></thead>
                            <tbody className="divide-y divide-rule">
                                {list.map((r) => {
                                    const has = form.data.counts[r.id] !== undefined && form.data.counts[r.id] !== '';
                                    const d = has ? diff(r) : 0;
                                    return (
                                        <tr key={r.id}>
                                            <td className="px-4 py-2">{r.name}{r.code ? <span className="ml-1.5 font-mono text-ink-faint">{r.code}</span> : null}</td>
                                            <td className="px-4 py-2 tabular-nums">{num(r.qty)} {r.unit}</td>
                                            <td className="px-4 py-2"><input type="number" min={0} step="0.01" inputMode="decimal" aria-label={`Counted ${r.name}`} className="field w-32" value={form.data.counts[r.id] ?? ''} onChange={(x) => form.setData('counts', { ...form.data.counts, [r.id]: x.target.value })} /></td>
                                            <td className={cx('px-4 py-2 tabular-nums', d < 0 && 'text-crit-700', d > 0 && 'text-ok-700')}>{has ? `${d > 0 ? '+' : ''}${num(d)} ${r.unit}` : '—'}</td>
                                            <td className="px-4 py-2 tabular-nums">{has ? pkr(d * r.cost) : '—'}</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                ) : <p className="p-4 text-sm text-ink-soft">No items in this store yet.</p>}
                {e.counts ? <p className="px-4 pt-2 text-sm text-crit-700">{e.counts}</p> : null}
                <div className="flex flex-wrap items-center gap-3 p-4">
                    <Button type="submit" disabled={form.processing || !entered.length}>Save Count</Button>
                    <span className="text-sm text-ink-soft">{entered.length} of {list.length} items counted{entered.length ? ` · difference ${pkr(value)}` : ''}</span>
                </div>
            </form>
        </Panel>
    );
}

function StoreItemForm({ item, categories, units, onDone }: { item: Row | null; categories: Record<string, string>; units: Record<string, string>; onDone: () => void }) {
    const form = useForm({ code: item?.code ?? '', name: item?.name ?? '', category: item?.categoryKey ?? 'bedding', unit: item?.unitKey ?? 'kg', reorder_level: item ? String(item.reorder) : '', cost: item ? String(item.cost) : '', opening: '', notes: item?.notes ?? '', active: item?.active ?? true });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title={item ? `Edit ${item.name}` : 'New Store Item'}>
            <form onSubmit={(ev) => { ev.preventDefault(); item ? form.post(`/inventory/items/${item.id}`, { preserveScroll: true, onSuccess: onDone }) : form.post('/inventory/items', { preserveScroll: true, onSuccess: onDone }); }} className="grid grid-cols-2 gap-3">
                {item ? <Field label="Code" error={e.code}><input className="field font-mono uppercase" value={form.data.code} onChange={(x) => form.setData('code', x.target.value)} required maxLength={20} /></Field> : <CodeField value={form.data.code} name={form.data.name} error={e.code} onChange={(v) => form.setData('code', v)} />}
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

function StoreMoveForm({ items, types, onDone, itemId }: { items: Row[]; types: MoveTypes; onDone: () => void; itemId?: number }) {
    const form = useForm({ store_item_id: String(itemId ?? items[0]?.id ?? ''), type: 'issue', direction: '', qty: '', moved_on: today(), issued_to: '', reference: '', notes: '' });
    const e = form.errors as Record<string, string>;
    const item = items.find((i) => String(i.id) === form.data.store_item_id);
    if (!items.length) return <Panel title="Stock In / Out"><Empty title="No store items yet">Add a store item first.</Empty></Panel>;
    return (
        <Panel title="Stock In / Out" description="Issue items for use, receive items that were not bought, or correct a count. Purchases arrive through Purchase Orders.">
            <form onSubmit={(ev) => { ev.preventDefault(); form.post('/inventory/moves', { preserveScroll: true, onSuccess: onDone }); }} className="grid grid-cols-2 gap-3">
                <div className="col-span-2"><Field label="Item" error={e.store_item_id}><select className="field" value={form.data.store_item_id} onChange={(x) => form.setData('store_item_id', x.target.value)}>{items.map((i) => <option key={i.id} value={i.id}>{i.name} · {num(i.qty)} {i.unit} in store</option>)}</select></Field></div>
                <Field label="What happened" error={e.type}><select className="field" value={form.data.type} onChange={(x) => form.setData('type', x.target.value)}>{Object.entries(types).map(([k, t]) => <option key={k} value={k}>{t.label}</option>)}</select></Field>
                {types[form.data.type]?.direction === 0 ? <Field label="Direction" error={e.direction}><select className="field" value={form.data.direction} onChange={(x) => form.setData('direction', x.target.value)}><option value="">Choose…</option><option value="in">In (more found)</option><option value="out">Out (less found)</option></select></Field> : <span />}
                <Field label={`Quantity${item ? ` (${item.unit})` : ''}`} error={e.qty}><input className="field" type="number" min={0} step="0.01" value={form.data.qty} onChange={(x) => form.setData('qty', x.target.value)} required /></Field>
                <Field label="Date" error={e.moved_on}><input className="field" type="date" max={today()} value={form.data.moved_on} onChange={(x) => form.setData('moved_on', x.target.value)} /></Field>
                {form.data.type === 'issue' ? <div className="col-span-2"><Field label="Issued to (PEN, shed or person)" error={e.issued_to}><input className="field" value={form.data.issued_to} onChange={(x) => form.setData('issued_to', x.target.value)} /></Field></div> : null}
                <Field label="Reference" error={e.reference}><input className="field" value={form.data.reference} onChange={(x) => form.setData('reference', x.target.value)} /></Field>
                <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                <div className="col-span-2 flex gap-2"><Button type="submit" disabled={form.processing}>Save</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}
