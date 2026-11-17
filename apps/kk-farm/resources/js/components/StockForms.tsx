import { useForm } from '@inertiajs/react';
import DeleteButton from './DeleteButton';
import { Button, cx, Field, Panel } from './ui';

/**
 * D-331: the one set of forms for feed and medicine items and their stock. Feeding, Veterinary and Inventory all use
 * these, posting to the same actions (Inventory through its own routes to the same controllers), so the stores are
 * kept one way everywhere.
 */
export type Via = 'app' | 'inventory';
export type MoveTypes = Record<string, { label: string; direction: number }>;
export type FeedItem = { id: number; code: string; name: string; category: string; costPerKg: number; cp: number | null; dm: number | null; reorder: number; supplier: string | null; notes: string | null; active: boolean; stock: number };
export type MedicineItem = { id: number; code: string; name: string; category: string; unit: string; reorder: number; costPerUnit: number; storage: string | null; supplier: string | null; active: boolean; qty: number };

const URLS: Record<Via, { feedItems: string; feedStock: string; medicines: string; medStock: string }> = {
    app: { feedItems: '/feeding/items', feedStock: '/feeding/stock', medicines: '/health/medicines', medStock: '/health/medicine-stock' },
    inventory: { feedItems: '/inventory/feed-items', feedStock: '/inventory/feed-stock', medicines: '/inventory/medicines', medStock: '/inventory/medicine-stock' },
};
const kg = (n: number) => `${(Math.round(n * 100) / 100).toLocaleString('en-PK')} KG`;
const pkr = (n: number) => `PKR ${Math.round(n).toLocaleString('en-PK')}`;
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

export function FeedItemForm({ item, categories, onDone, via = 'app' }: { item?: FeedItem; categories: Record<string, string>; onDone?: () => void; via?: Via }) {
    const form = useForm({ code: item?.code ?? '', name: item?.name ?? '', category: item?.category ?? 'concentrate', cost_per_kg: item ? String(item.costPerKg) : '', cp_pct: item?.cp != null ? String(item.cp) : '', dm_pct: item?.dm != null ? String(item.dm) : '', reorder_level_kg: item ? String(item.reorder) : '', opening_kg: '', supplier: item?.supplier ?? '', notes: item?.notes ?? '', active: item?.active ?? true });
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(item ? `${URLS[via].feedItems}/${item.id}` : URLS[via].feedItems, { preserveScroll: true, onSuccess: () => { if (!item) form.reset(); onDone?.(); } });
    };

    return (
        <form onSubmit={submit} className={cx('grid gap-3', item ? 'mt-2 sm:grid-cols-3' : '')}>
            {!item ? <Field label="Code" error={form.errors.code} hint="Short, e.g. WS, CONC, BRAN"><input className="field font-mono uppercase" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} maxLength={20} /></Field> : null}
            <Field label="Name" error={form.errors.name}><input className="field" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. Wheat straw (Bhoosa)" /></Field>
            <Field label="Category" error={form.errors.category}><select className="field" value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>{Object.entries(categories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            <Field label="Cost PKR per KG" error={form.errors.cost_per_kg}><input className="field" type="number" inputMode="decimal" min={0} step="0.01" value={form.data.cost_per_kg} onChange={(e) => form.setData('cost_per_kg', e.target.value)} /></Field>
            <Field label="CP % (Bag Label)" error={form.errors.cp_pct} hint="Crude protein printed on the bag, e.g. 32 for CP 32"><input className="field" type="number" inputMode="decimal" min={0.5} max={60} step="0.1" placeholder="Not known" value={form.data.cp_pct} onChange={(e) => form.setData('cp_pct', e.target.value)} /></Field>
            <Field label="Dry Matter %" error={form.errors.dm_pct} hint="e.g. straw (toori) about 90, bran (chokar) about 89, green fodder 20 to 25"><input className="field" type="number" inputMode="decimal" min={5} max={100} step="0.1" placeholder="Not known" value={form.data.dm_pct} onChange={(e) => form.setData('dm_pct', e.target.value)} /></Field>
            <Field label="Reorder when below (KG)" error={form.errors.reorder_level_kg}><input className="field" type="number" inputMode="decimal" min={0} value={form.data.reorder_level_kg} onChange={(e) => form.setData('reorder_level_kg', e.target.value)} /></Field>
            {!item ? <Field label="Stock now (KG)" error={form.errors.opening_kg} hint="Opening stock. Later receipts go through Stock in / out."><input className="field" type="number" inputMode="decimal" min={0} value={form.data.opening_kg} onChange={(e) => form.setData('opening_kg', e.target.value)} /></Field> : null}
            <Field label="Supplier" error={form.errors.supplier}><input className="field" value={form.data.supplier} onChange={(e) => form.setData('supplier', e.target.value)} /></Field>
            {item ? <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="size-5" checked={form.data.active} onChange={(e) => form.setData('active', e.target.checked)} /> Still used</label> : null}
            <div className={cx('flex gap-2', item ? 'sm:col-span-3' : '')}><Button type="submit" disabled={form.processing}>{item ? 'Save' : 'Add feed'}</Button>{item ? <Button type="button" variant="secondary" onClick={onDone}>Cancel</Button> : null}</div>
        </form>
    );
}

export function FeedStockForm({ items, movementTypes, onDone, via = 'app', itemId }: { items: FeedItem[]; movementTypes: MoveTypes; onDone?: () => void; via?: Via; itemId?: number }) {
    const todayIso = new Date().toISOString().slice(0, 10);
    const form = useForm({ feed_item_id: String(itemId ?? items.find((i) => i.active)?.id ?? items[0].id), type: 'purchase', direction: '', qty_kg: '', rate: '', moved_on: todayIso, party: '', reference: '', notes: '' });
    const type = movementTypes[form.data.type];
    const item = items.find((i) => i.id === Number(form.data.feed_item_id));

    return (
        <Panel title="Stock in / out" description="Purchases also go into Expenses automatically.">
            <form onSubmit={(e) => { e.preventDefault(); form.post(URLS[via].feedStock, { preserveScroll: true, onSuccess: () => { form.reset('qty_kg', 'rate', 'party', 'reference', 'notes'); onDone?.(); } }); }} className="space-y-3">
                <Field label="Feed" error={form.errors.feed_item_id}><select className="field" value={form.data.feed_item_id} onChange={(e) => form.setData('feed_item_id', e.target.value)}>{items.map((i) => <option key={i.id} value={i.id}>{i.name} ({kg(i.stock)})</option>)}</select></Field>
                <Field label="What happened" error={form.errors.type}><select className="field" value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>{Object.entries(movementTypes).filter(([k]) => k !== 'opening').map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}</select></Field>
                {type?.direction === 0 ? <Field label="Stock goes" error={form.errors.direction}><select className="field" value={form.data.direction} onChange={(e) => form.setData('direction', e.target.value)}><option value="">Choose…</option><option value="in">In (more than recorded)</option><option value="out">Out (less than recorded)</option></select></Field> : null}
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Quantity KG" error={form.errors.qty_kg}><input className="field" type="number" inputMode="decimal" min={0} step="0.1" value={form.data.qty_kg} onChange={(e) => form.setData('qty_kg', e.target.value)} /></Field>
                    <Field label={form.data.type === 'purchase' ? 'Price PKR / KG' : 'PKR / KG (optional)'} error={form.errors.rate}><input className="field" type="number" inputMode="decimal" min={0} step="0.01" placeholder={item ? String(item.costPerKg) : ''} value={form.data.rate} onChange={(e) => form.setData('rate', e.target.value)} /></Field>
                </div>
                {form.data.type === 'purchase' && form.data.qty_kg && form.data.rate ? <p className="text-sm text-ink-soft">Expense: {pkr(Number(form.data.qty_kg) * Number(form.data.rate))}. New cost per kg: {pkr(Number(form.data.rate))}.</p> : null}
                <Field label="Date" error={form.errors.moved_on}><input className="field" type="date" max={todayIso} value={form.data.moved_on} onChange={(e) => form.setData('moved_on', e.target.value)} /></Field>
                <Field label="Supplier / party" error={form.errors.party}><input className="field" value={form.data.party} onChange={(e) => form.setData('party', e.target.value)} /></Field>
                <Field label="Invoice / reference" error={form.errors.reference}><input className="field" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} /></Field>
                <Button type="submit" className="w-full" disabled={form.processing}>Save</Button>
            </form>
        </Panel>
    );
}

export function MedicineAdd({ categories, units, onDone, via = 'app' }: { categories: Record<string, string>; units: Record<string, string>; onDone?: () => void; via?: Via }) {
    const form = useForm({ code: '', name: '', category: 'antibiotic', unit: 'ml', reorder_level: '', cost_per_unit: '', storage: '', supplier: '', opening_qty: '', batch: '', expiry_on: '' });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title="Add a medicine">
            <form onSubmit={(ev) => { ev.preventDefault(); form.post(URLS[via].medicines, { preserveScroll: true, onSuccess: () => { form.reset(); onDone?.(); } }); }} className="grid grid-cols-2 gap-3">
                <Field label="Code" error={e.code} hint="e.g. OXY-LA"><input className="field font-mono uppercase" value={form.data.code} onChange={(x) => form.setData('code', x.target.value)} maxLength={20} /></Field>
                <Field label="Name" error={e.name}><input className="field" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} /></Field>
                <Field label="Category" error={e.category}><select className="field" value={form.data.category} onChange={(x) => form.setData('category', x.target.value)}>{Object.entries(categories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Stock unit" error={e.unit}><select className="field" value={form.data.unit} onChange={(x) => form.setData('unit', x.target.value)}>{Object.entries(units).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Reorder at" error={e.reorder_level}><input type="number" min={0} className="field" value={form.data.reorder_level} onChange={(x) => form.setData('reorder_level', x.target.value)} /></Field>
                <Field label="PKR per unit" error={e.cost_per_unit}><input type="number" min={0} step="0.01" className="field" value={form.data.cost_per_unit} onChange={(x) => form.setData('cost_per_unit', x.target.value)} /></Field>
                <Field label="Stock now" error={e.opening_qty}><input type="number" min={0} className="field" value={form.data.opening_qty} onChange={(x) => form.setData('opening_qty', x.target.value)} /></Field>
                <Field label="Batch / lot" error={e.batch}><input className="field font-mono" value={form.data.batch} onChange={(x) => form.setData('batch', x.target.value)} /></Field>
                <Field label="Expiry" error={e.expiry_on}><input type="date" className="field" value={form.data.expiry_on} onChange={(x) => form.setData('expiry_on', x.target.value)} /></Field>
                <Field label="Storage" error={e.storage} hint="e.g. Fridge, Vet cabinet"><input className="field" value={form.data.storage} onChange={(x) => form.setData('storage', x.target.value)} /></Field>
                <div className="col-span-2"><Button type="submit" disabled={form.processing}>Add medicine</Button></div>
            </form>
        </Panel>
    );
}

export function MedicineEdit({ m, categories, via = 'app', onDone }: { m: MedicineItem; categories: Record<string, string>; via?: Via; onDone?: () => void }) {
    const form = useForm({ name: m.name, category: m.category, reorder_level: String(m.reorder), cost_per_unit: String(m.costPerUnit), storage: m.storage ?? '', supplier: m.supplier ?? '', active: m.active });
    return (
        <form onSubmit={(ev) => { ev.preventDefault(); form.post(`${URLS[via].medicines}/${m.id}`, { preserveScroll: true, onSuccess: () => onDone?.() }); }} className="mt-2 grid gap-2 border-t border-rule pt-2 sm:grid-cols-3">
            <Field label="Name" error={form.errors.name}><input className="field" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} /></Field>
            <Field label="Category"><select className="field" value={form.data.category} onChange={(x) => form.setData('category', x.target.value)}>{Object.entries(categories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            <Field label={`Reorder at (${m.unit})`}><input type="number" min={0} className="field" value={form.data.reorder_level} onChange={(x) => form.setData('reorder_level', x.target.value)} /></Field>
            <Field label="PKR per unit"><input type="number" min={0} step="0.01" className="field" value={form.data.cost_per_unit} onChange={(x) => form.setData('cost_per_unit', x.target.value)} /></Field>
            <Field label="Storage"><input className="field" value={form.data.storage} onChange={(x) => form.setData('storage', x.target.value)} /></Field>
            <label className="flex items-center gap-2 pt-6 text-sm"><input type="checkbox" className="size-4" checked={form.data.active} onChange={(x) => form.setData('active', x.target.checked)} /> Still used</label>
            <div className="sm:col-span-3"><Button type="submit" variant="secondary" disabled={form.processing}>Save</Button><DeleteButton type="medicine" id={m.id} name={m.name} /></div>
        </form>
    );
}

export function MedicineMoveForm({ medicines, types, onDone, via = 'app', itemId }: { medicines: MedicineItem[]; types: MoveTypes; onDone?: () => void; via?: Via; itemId?: number }) {
    const form = useForm({ medicine_id: String(itemId ?? medicines.find((m) => m.active)?.id ?? medicines[0].id), type: 'purchase', direction: '', qty: '', batch: '', expiry_on: '', rate: '', moved_on: today(), party: '', reference: '', notes: '' });
    const e = form.errors as Record<string, string>;
    const type = types[form.data.type];
    const unit = medicines.find((m) => String(m.id) === form.data.medicine_id)?.unit ?? '';
    const incoming = type?.direction === 1 || (type?.direction === 0 && form.data.direction === 'in');
    return (
        <Panel title="Stock in / out" description="Purchases also go into Expenses. Treatments issue stock by themselves.">
            <form onSubmit={(ev) => { ev.preventDefault(); form.post(URLS[via].medStock, { preserveScroll: true, onSuccess: () => { form.reset('qty', 'batch', 'expiry_on', 'rate', 'party', 'reference', 'notes'); onDone?.(); } }); }} className="grid grid-cols-2 gap-3">
                <Field label="Medicine" error={e.medicine_id}><select className="field" value={form.data.medicine_id} onChange={(x) => form.setData('medicine_id', x.target.value)}>{medicines.map((m) => <option key={m.id} value={m.id}>{m.name} ({m.qty} {m.unit})</option>)}</select></Field>
                <Field label="What happened" error={e.type}><select className="field" value={form.data.type} onChange={(x) => form.setData('type', x.target.value)}>{Object.entries(types).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}</select></Field>
                {type?.direction === 0 ? <Field label="Stock goes" error={e.direction}><select className="field" value={form.data.direction} onChange={(x) => form.setData('direction', x.target.value)}><option value="">Choose…</option><option value="in">In</option><option value="out">Out</option></select></Field> : null}
                <Field label={`Quantity (${unit})`} error={e.qty}><input type="number" min={0} step="0.1" className="field" value={form.data.qty} onChange={(x) => form.setData('qty', x.target.value)} /></Field>
                <Field label="Batch / lot" error={e.batch}><input className="field font-mono" value={form.data.batch} onChange={(x) => form.setData('batch', x.target.value)} /></Field>
                {incoming ? <Field label="Expiry" error={e.expiry_on}><input type="date" className="field" value={form.data.expiry_on} onChange={(x) => form.setData('expiry_on', x.target.value)} /></Field> : null}
                {form.data.type === 'purchase' ? <Field label={`PKR per ${unit}`} error={e.rate}><input type="number" min={0} step="0.01" className="field" value={form.data.rate} onChange={(x) => form.setData('rate', x.target.value)} /></Field> : null}
                <Field label="Date" error={e.moved_on}><input type="date" max={today()} className="field" value={form.data.moved_on} onChange={(x) => form.setData('moved_on', x.target.value)} /></Field>
                <Field label="Supplier / party" error={e.party}><input className="field" value={form.data.party} onChange={(x) => form.setData('party', x.target.value)} /></Field>
                <div className="col-span-2"><Button type="submit" className="w-full" disabled={form.processing}>Save</Button></div>
            </form>
        </Panel>
    );
}

