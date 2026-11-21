import { useForm } from '@inertiajs/react';
import { useState } from 'react';
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
export type MedicineItem = { id: number; code: string; name: string; category: string; unit: string; reorder: number; costPerUnit: number; storage: string | null; supplier: string | null; active: boolean; qty: number;
    spec?: string | null; useCase?: string | null; source?: string | null; packUnit?: string | null; packSize?: number | null; check?: string | null; checkNote?: string | null; notes?: string | null; diseaseIds?: number[]; dose?: StandardDose };
/** D-369: a medicine's standard dose, set by the vet from the label; filled in when the medicine is given. */
export type StandardDose = { qty: number | null; route: string | null; frequency: string | null; days: number | null; meatDays: number | null; milkDays: number | null };
/** D-369: the fields a dose form fills from a medicine's standard dose ({} when none is set). Dates from `givenOn`. */
export function doseFill(m: { unit: string; dose?: StandardDose } | undefined, givenOn: string): Record<string, string | boolean> {
    const d = m?.dose;
    if (!d) return {};
    const add = (days: number) => { const x = new Date(`${givenOn}T00:00:00`); x.setDate(x.getDate() + days); return `${x.getFullYear()}-${String(x.getMonth() + 1).padStart(2, '0')}-${String(x.getDate()).padStart(2, '0')}`; };
    const out: Record<string, string | boolean> = {};
    if (d.qty != null) { out.qty_used = String(d.qty); out.dose = `${d.qty} ${doseUnit(m!.unit)}`; }
    if (d.route) out.route = d.route;
    if (d.frequency) { out.frequency = d.frequency; out.days_prescribed = d.frequency !== 'once' && d.days ? String(d.days) : ''; }
    if (d.meatDays != null || d.milkDays != null) {
        const none = (d.meatDays ?? 0) === 0 && (d.milkDays ?? 0) === 0;
        out.no_withdrawal = none;
        out.meat_clear_on = !none && d.meatDays ? add(d.meatDays) : '';
        out.milk_clear_on = !none && d.milkDays ? add(d.milkDays) : '';
    }
    return out;
}
/** D-374: the store counts ml; a dose given is written in CC (1 CC = 1 ml). Other units stay as they are. */
export const doseUnit = (unit: string | undefined) => (unit === 'ml' ? 'CC' : unit ?? '');
export const hasDose = (m?: { dose?: StandardDose }) => !!m?.dose && Object.values(m.dose).some((v) => v !== null && v !== undefined);
export const ROUTES: Record<string, string> = { oral: 'Oral', injectable: 'Injectable', topical: 'Topical', other: 'Other' };
export const FREQUENCIES: Record<string, string> = { once: 'Once (single dose)', sid: 'Once a day', bid: 'Twice a day', tid: 'Three times a day', q48h: 'Every 2 days', q72h: 'Every 3 days', weekly: 'Once a week' };

const URLS: Record<Via, { feedItems: string; feedStock: string; medicines: string; medStock: string }> = {
    app: { feedItems: '/feeding/items', feedStock: '/feeding/stock', medicines: '/health/medicines', medStock: '/health/medicine-stock' },
    inventory: { feedItems: '/inventory/feed-items', feedStock: '/inventory/feed-stock', medicines: '/inventory/medicines', medStock: '/inventory/medicine-stock' },
};
const kg = (n: number) => `${(Math.round(n * 100) / 100).toLocaleString('en-PK')} KG`;
const pkr = (n: number) => `PKR ${Math.round(n).toLocaleString('en-PK')}`;
/** D-333: what the server sends to rank medicines (App\\Support\\MedicineSuggest::data). */
export type Suggest = {
    byDisease: Record<string, string[]>; byCare: Record<string, string[]>; notGiven: string[];
    diseases: Array<{ id: number; name: string; category: string; set: number[]; used: Record<string, number> }>;
};
export type PickMedicine = { id: number; name: string; unit: string; qty: number; category: string; active: boolean; check?: string | null; checkNote?: string | null; spec?: string | null; useCase?: string | null ; dose?: StandardDose};

/**
 * D-333: medicines that can be given, the ones that fit first, each with why: set by the vet for the disease, used
 * before on cases of it, or a type that fits the disease or the kind of care. Suggestions only: the vet decides.
 */
export function rankMedicines<M extends PickMedicine>(medicines: M[], suggest: Suggest | undefined, { diseaseId, care }: { diseaseId?: number | null; care?: string | null }) {
    const givable = medicines.filter((m) => m.active && !(suggest?.notGiven ?? ['consumable', 'equipment']).includes(m.category));
    const disease = diseaseId ? suggest?.diseases.find((d) => d.id === diseaseId) : undefined;
    const why = new Map<number, [number, string]>(); // id → [rank, reason]; lower rank first
    if (disease) {
        disease.set.forEach((id, i) => why.set(id, [i, `set for ${disease.name}`]));
        Object.entries(disease.used).sort((a, b) => b[1] - a[1]).forEach(([id, n], i) => { if (!why.has(Number(id))) why.set(Number(id), [100 + i, `used ${n} time${n === 1 ? '' : 's'} for ${disease.name}`]); });
    }
    const cats = disease ? suggest?.byDisease[disease.category] ?? [] : care ? suggest?.byCare[care] ?? [] : [];
    givable.forEach((m) => { const i = cats.indexOf(m.category); if (i >= 0 && !why.has(m.id)) why.set(m.id, [200 + i, disease ? `fits ${disease.name}` : 'fits this care']); });
    const suggested = givable.filter((m) => why.has(m.id)).sort((a, b) => why.get(a.id)![0] - why.get(b.id)![0] || a.name.localeCompare(b.name)).map((m) => ({ m, why: why.get(m.id)![1] }));
    return { disease, suggested, others: givable.filter((m) => !why.has(m.id)) };
}

const flag = (m: PickMedicine) => (m.check === 'hold' ? ' · on hold' : m.check === 'to_confirm' ? ' · to confirm' : '');

/**
 * D-333: the medicine chooser used by treatments, medication and preventive care: suggested first, then every other
 * medicine. D-375: type to find it (name, ingredient or use); the list narrows as you type; Enter takes the first.
 */
export function MedicinePicker<M extends PickMedicine>({ medicines, suggest, diseaseId, care, value, onChange, empty = 'Not from the store (type below)' }: { medicines: M[]; suggest?: Suggest; diseaseId?: number | null; care?: string | null; value: string; onChange: (id: string) => void; empty?: string }) {
    const { disease, suggested, others } = rankMedicines(medicines, suggest, { diseaseId, care });
    const chosen = medicines.find((m) => String(m.id) === value);
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const words = q.toLowerCase().split(/\s+/).filter(Boolean);
    const fits = (m: M) => !words.length || words.every((w) => `${m.name} ${m.spec ?? ''} ${m.useCase ?? ''}`.toLowerCase().includes(w));
    const list = [...suggested.map(({ m, why: w }) => ({ m, why: w as string | undefined })), ...others.map((m) => ({ m, why: undefined as string | undefined }))].filter(({ m }) => fits(m));
    const take = (id: string) => { onChange(id); setOpen(false); setQ(''); };
    return (
        <>
            <div className="relative">
                <input className="field" role="combobox" aria-expanded={open} aria-label="Medicine" autoComplete="off"
                    placeholder={chosen ? chosen.name : 'Type to find a medicine…'}
                    value={open ? q : chosen ? `${chosen.name} · ${chosen.qty} ${chosen.unit} in stock` : ''}
                    onFocus={() => { setOpen(true); setQ(''); }}
                    onBlur={() => window.setTimeout(() => setOpen(false), 150)}
                    onChange={(x) => { setQ(x.target.value); setOpen(true); }}
                    onKeyDown={(x) => {
                        if (x.key === 'Enter') { x.preventDefault(); const first = list.find(({ m }) => m.check !== 'hold'); if (first) take(String(first.m.id)); }
                        if (x.key === 'Escape') setOpen(false);
                    }} />
                {open ? (
                    <ul className="absolute inset-x-0 top-full z-30 mt-1 max-h-64 overflow-y-auto rounded-tile border border-rule bg-tile shadow-tile" role="listbox">
                        {value ? <li><button type="button" onMouseDown={(x) => x.preventDefault()} onClick={() => take('')} className="block w-full px-3 py-2 text-left text-sm text-ink-soft hover:bg-glaze">{empty}</button></li> : null}
                        {list.length ? list.map(({ m, why: w }, i) => (
                            <li key={m.id}>
                                {i === 0 && w ? <p className="px-3 pt-2 text-xs font-semibold text-ok-700">{disease ? `Suggested for ${disease.name}` : 'Suggested'}</p> : null}
                                {i > 0 && !w && list[i - 1].why ? <p className="border-t border-rule px-3 pt-2 text-xs font-semibold text-ink-soft">Other medicines</p> : null}
                                <button type="button" disabled={m.check === 'hold'} onMouseDown={(x) => x.preventDefault()} onClick={() => take(String(m.id))}
                                    className={cx('block w-full px-3 py-2 text-left text-sm hover:bg-glaze disabled:opacity-50', String(m.id) === value && 'bg-glaze font-semibold')}>
                                    <span className="text-cobalt-900">{m.name}</span>
                                    <span className={cx('ms-1 text-xs', m.qty > 0 ? 'text-ink-soft' : 'text-crit-700')}>· {m.qty} {m.unit} in stock{w ? ` · ${w}` : ''}{flag(m)}</span>
                                </button>
                            </li>
                        )) : <li className="px-3 py-2 text-sm text-ink-soft">No medicine matches “{q}”.</li>}
                    </ul>
                ) : null}
            </div>
            {chosen && (chosen.spec || chosen.useCase || chosen.checkNote) ? <span className="mt-1 block text-sm text-ink-soft">{[chosen.spec, chosen.useCase, chosen.check !== 'confirmed' ? chosen.checkNote : null].filter(Boolean).join(' · ')}</span> : null}
            {suggested.length ? <span className="mt-1 block text-sm text-ink-faint">Suggestions only: the vet decides.</span> : null}
        </>
    );
}

// D-335: same rule as App\Support\ItemCode::fromName; a blank Code is made from the name on save.
const FILLER = new Set(['INJECTION', 'INJ', 'POWDER', 'TABLET', 'TABLETS', 'TAB', 'BOLUS', 'SPRAY', 'SOLUTION', 'ORAL', 'LIQUID', 'SUSPENSION', 'CREAM', 'OINTMENT', 'SYRUP', 'DROPS', 'GEL', 'FOR', 'AND', 'THE', 'OF', 'WITH']);
export function codeFromName(name: string): string {
    const words = name.toUpperCase().split(/[^A-Z0-9]+/).filter(Boolean);
    const kept = words.filter((w) => !FILLER.has(w));
    const w = kept.length ? kept : words;
    return w.length ? w[0].slice(0, 10) + (w[1] ? `-${w[1].slice(0, 6)}` : '') : '';
}
export function CodeField({ value, name, error, onChange }: { value: string; name: string; error?: string; onChange: (v: string) => void }) {
    const auto = codeFromName(name);
    return (
        <Field label="Code" error={error} hint={value ? 'Your own code' : auto ? `Auto: ${auto} (type to use your own)` : 'Made from the name, or type your own'}>
            <input className="field font-mono uppercase" value={value} onChange={(e) => onChange(e.target.value)} placeholder={auto || 'Auto'} maxLength={20} />
        </Field>
    );
}

const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

export function FeedItemForm({ item, categories, onDone, via = 'app' }: { item?: FeedItem; categories: Record<string, string>; onDone?: () => void; via?: Via }) {
    const form = useForm({ code: item?.code ?? '', name: item?.name ?? '', category: item?.category ?? 'concentrate', cost_per_kg: item ? String(item.costPerKg) : '', cp_pct: item?.cp != null ? String(item.cp) : '', dm_pct: item?.dm != null ? String(item.dm) : '', reorder_level_kg: item ? String(item.reorder) : '', opening_kg: '', supplier: item?.supplier ?? '', notes: item?.notes ?? '', active: item?.active ?? true });
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(item ? `${URLS[via].feedItems}/${item.id}` : URLS[via].feedItems, { preserveScroll: true, onSuccess: () => { if (!item) form.reset(); onDone?.(); } });
    };

    return (
        <form onSubmit={submit} className={cx('grid gap-3', item ? 'mt-2 sm:grid-cols-3' : '')}>
            {!item ? <CodeField value={form.data.code} name={form.data.name} error={form.errors.code} onChange={(v) => form.setData('code', v)} /> : null}
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

/** D-332: the purchase pack and the vet's check, defaults for apps that do not pass the lists. */
const PACKS: Record<string, string> = { vial: 'Vial', ampoule: 'Ampoule', bottle: 'Bottle', can: 'Can', sachet: 'Sachet', box: 'Box', piece: 'Piece', tube: 'Tube', pack: 'Pack' };
const CHECKS: Record<string, string> = { confirmed: 'Confirmed', to_confirm: 'To confirm', hold: 'On hold' };
type MedFields = { disease_ids: number[]; dose_qty: string; dose_route: string; dose_frequency: string; dose_days: string; meat_withdrawal_days: string; milk_withdrawal_days: string; spec: string; use_case: string; source: string; pack_unit: string; pack_size: string; check_status: string; check_note: string; notes: string; supplier: string; storage: string };
const medFields = (m?: MedicineItem): MedFields => ({ disease_ids: m?.diseaseIds ?? [], dose_qty: m?.dose?.qty != null ? String(m.dose.qty) : '', dose_route: m?.dose?.route ?? '', dose_frequency: m?.dose?.frequency ?? '', dose_days: m?.dose?.days != null ? String(m.dose.days) : '', meat_withdrawal_days: m?.dose?.meatDays != null ? String(m.dose.meatDays) : '', milk_withdrawal_days: m?.dose?.milkDays != null ? String(m.dose.milkDays) : '', spec: m?.spec ?? '', use_case: m?.useCase ?? '', source: m?.source ?? '', pack_unit: m?.packUnit ?? '', pack_size: m?.packSize != null ? String(m.packSize) : '', check_status: m?.check ?? 'confirmed', check_note: m?.checkNote ?? '', notes: m?.notes ?? '', supplier: m?.supplier ?? '', storage: m?.storage ?? '' });

/** D-332: label details, purchase pack and the vet's check: the same fields when adding and when editing. */
function MedicineDetails({ data, set, setDiseases, diseases, e, unit, packs = PACKS, checks = CHECKS }: { data: MedFields; set: (k: Exclude<keyof MedFields, 'disease_ids'>, v: string) => void; setDiseases: (ids: number[]) => void; diseases?: Array<{ id: number; name: string }>; e: Record<string, string>; unit: string; packs?: Record<string, string>; checks?: Record<string, string> }) {
    return (
        <>
            <div className="col-span-2"><Field label="Specification / active ingredient" error={e.spec} hint="As on the label, e.g. Flunixin meglumine 50 mg/mL"><input className="field" value={data.spec} onChange={(x) => set('spec', x.target.value)} maxLength={200} /></Field></div>
            <div className="col-span-2"><Field label="Use" error={e.use_case} hint="What the farm uses it for"><input className="field" value={data.use_case} onChange={(x) => set('use_case', x.target.value)} maxLength={200} /></Field></div>
            <Field label="Bought as" error={e.pack_unit}><select className="field" value={data.pack_unit} onChange={(x) => set('pack_unit', x.target.value)}><option value="">Single {unit}</option>{Object.entries(packs).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            {data.pack_unit ? <Field label={`${unit} in one ${(packs[data.pack_unit] ?? data.pack_unit).toLowerCase()}`} error={e.pack_size}><input type="number" min={0} step="0.01" className="field" value={data.pack_size} onChange={(x) => set('pack_size', x.target.value)} /></Field> : <span />}
            <Field label="Manufacturer / source" error={e.source}><input className="field" value={data.source} onChange={(x) => set('source', x.target.value)} maxLength={120} /></Field>
            <Field label="Supplier" error={e.supplier}><input className="field" value={data.supplier} onChange={(x) => set('supplier', x.target.value)} maxLength={80} /></Field>
            <Field label="Storage" error={e.storage} hint="e.g. Fridge, Vet cabinet"><input className="field" value={data.storage} onChange={(x) => set('storage', x.target.value)} maxLength={80} /></Field>
            <Field label="Vet check" error={e.check_status} hint="On hold: cannot be given until a vet confirms it"><select className="field" value={data.check_status} onChange={(x) => set('check_status', x.target.value)}>{Object.entries(checks).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            {data.check_status !== 'confirmed' ? <div className="col-span-2"><Field label="What to confirm" error={e.check_note}><input className="field" value={data.check_note} onChange={(x) => set('check_note', x.target.value)} maxLength={200} /></Field></div> : null}
            <div className="col-span-2 rounded-tile border border-rule p-3">{/* D-369 */}
                <p className="mb-2 text-sm font-semibold text-cobalt-900">Standard dose (from the label, set by the vet)</p>
                <p className="mb-2 text-xs text-ink-soft">Filled in when this medicine is given; the vet checks and can change each field.</p>
                <div className="grid grid-cols-2 gap-3">
                    <Field label={`Amount per goat (${doseUnit(unit)})`} error={e.dose_qty}><input type="number" min={0} step="0.01" className="field" value={data.dose_qty} onChange={(x) => set('dose_qty', x.target.value)} /></Field>
                    <Field label="Route" error={e.dose_route}><select className="field" value={data.dose_route} onChange={(x) => set('dose_route', x.target.value)}><option value="">—</option>{Object.entries(ROUTES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Field label="How often" error={e.dose_frequency}><select className="field" value={data.dose_frequency} onChange={(x) => set('dose_frequency', x.target.value)}><option value="">—</option>{Object.entries(FREQUENCIES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    {data.dose_frequency && data.dose_frequency !== 'once' ? <Field label="For how many days" error={e.dose_days}><input type="number" min={1} className="field" value={data.dose_days} onChange={(x) => set('dose_days', x.target.value)} /></Field> : null}
                    <Field label="Meat withdrawal (days, label)" error={e.meat_withdrawal_days}><input type="number" min={0} className="field" value={data.meat_withdrawal_days} onChange={(x) => set('meat_withdrawal_days', x.target.value)} /></Field>
                    <Field label="Milk withdrawal (days, label)" error={e.milk_withdrawal_days}><input type="number" min={0} className="field" value={data.milk_withdrawal_days} onChange={(x) => set('milk_withdrawal_days', x.target.value)} /></Field>
                </div>
            </div>
            {diseases?.length ? (
                <div className="col-span-2"><Field label="Used for (diseases)" error={e.disease_ids} hint="Offered first when a case of these diseases is treated">
                    <div className="flex max-h-44 flex-wrap gap-x-4 gap-y-1 overflow-y-auto rounded-md border border-rule p-2">
                        {diseases.map((d) => <label key={d.id} className="flex min-h-9 items-center gap-2 text-sm"><input type="checkbox" className="size-4" checked={data.disease_ids.includes(d.id)} onChange={(x) => setDiseases(x.target.checked ? [...data.disease_ids, d.id] : data.disease_ids.filter((i) => i !== d.id))} />{d.name}</label>)}
                    </div>
                </Field></div>
            ) : null}
            <div className="col-span-2"><Field label="Notes (optional)" error={e.notes}><input className="field" value={data.notes} onChange={(x) => set('notes', x.target.value)} maxLength={200} /></Field></div>
        </>
    );
}

export function MedicineAdd({ categories, units, packs, checks, diseases, onDone, via = 'app' }: { categories: Record<string, string>; units: Record<string, string>; packs?: Record<string, string>; checks?: Record<string, string>; diseases?: Array<{ id: number; name: string }>; onDone?: () => void; via?: Via }) {
    const form = useForm({ code: '', name: '', category: 'antibiotic', unit: 'ml', reorder_level: '', cost_per_unit: '', opening_qty: '', batch: '', expiry_on: '', ...medFields() });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title="Add a medicine or vet supply">
            <form onSubmit={(ev) => { ev.preventDefault(); form.post(URLS[via].medicines, { preserveScroll: true, onSuccess: () => { form.reset(); onDone?.(); } }); }} className="grid grid-cols-2 gap-3">
                <CodeField value={form.data.code} name={form.data.name} error={e.code} onChange={(v) => form.setData('code', v)} />
                <Field label="Name" error={e.name}><input className="field" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} /></Field>
                <Field label="Category" error={e.category}><select className="field" value={form.data.category} onChange={(x) => form.setData('category', x.target.value)}>{Object.entries(categories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Stock unit" error={e.unit} hint="The unit doses are given in"><select className="field" value={form.data.unit} onChange={(x) => form.setData('unit', x.target.value)}>{Object.entries(units).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <MedicineDetails data={form.data} set={(k, v) => form.setData(k, v)} setDiseases={(ids) => form.setData('disease_ids', ids)} diseases={diseases} e={e} unit={form.data.unit} packs={packs} checks={checks} />
                <Field label={`Reorder at (${form.data.unit})`} error={e.reorder_level}><input type="number" min={0} className="field" value={form.data.reorder_level} onChange={(x) => form.setData('reorder_level', x.target.value)} /></Field>
                <Field label={`PKR per ${form.data.unit}`} error={e.cost_per_unit}><input type="number" min={0} step="0.01" className="field" value={form.data.cost_per_unit} onChange={(x) => form.setData('cost_per_unit', x.target.value)} /></Field>
                <Field label={`Stock now (${form.data.unit})`} error={e.opening_qty}><input type="number" min={0} className="field" value={form.data.opening_qty} onChange={(x) => form.setData('opening_qty', x.target.value)} /></Field>
                <Field label="Batch / lot" error={e.batch}><input className="field font-mono" value={form.data.batch} onChange={(x) => form.setData('batch', x.target.value)} /></Field>
                <Field label="Expiry" error={e.expiry_on}><input type="date" className="field" value={form.data.expiry_on} onChange={(x) => form.setData('expiry_on', x.target.value)} /></Field>
                <div className="col-span-2"><Button type="submit" disabled={form.processing}>Add</Button></div>
            </form>
        </Panel>
    );
}

export function MedicineEdit({ m, categories, units, packs, checks, diseases, via = 'app', onDone }: { m: MedicineItem; categories: Record<string, string>; units?: Record<string, string>; packs?: Record<string, string>; checks?: Record<string, string>; diseases?: Array<{ id: number; name: string }>; via?: Via; onDone?: () => void }) {
    const form = useForm({ name: m.name, category: m.category, unit: m.unit, reorder_level: String(m.reorder), cost_per_unit: String(m.costPerUnit), active: m.active, ...medFields(m) });
    const e = form.errors as Record<string, string>;
    return (
        <form onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => (diseases ? d : { ...d, disease_ids: undefined })); form.post(`${URLS[via].medicines}/${m.id}`, { preserveScroll: true, onSuccess: () => onDone?.() }); }} className="mt-2 grid grid-cols-2 gap-3 border-t border-rule pt-2">
            <Field label="Name" error={e.name}><input className="field" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} /></Field>
            <Field label="Category" error={e.category}><select className="field" value={form.data.category} onChange={(x) => form.setData('category', x.target.value)}>{Object.entries(categories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            {units ? <Field label="Stock unit" error={e.unit} hint="Fixed once stock has moved"><select className="field" value={form.data.unit} onChange={(x) => form.setData('unit', x.target.value)}>{Object.entries(units).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field> : null}
            <MedicineDetails data={form.data} set={(k, v) => form.setData(k, v)} setDiseases={(ids) => form.setData('disease_ids', ids)} diseases={diseases} e={e} unit={form.data.unit} packs={packs} checks={checks} />
            <Field label={`Reorder at (${form.data.unit})`} error={e.reorder_level}><input type="number" min={0} className="field" value={form.data.reorder_level} onChange={(x) => form.setData('reorder_level', x.target.value)} /></Field>
            <Field label={`PKR per ${form.data.unit}`} error={e.cost_per_unit}><input type="number" min={0} step="0.01" className="field" value={form.data.cost_per_unit} onChange={(x) => form.setData('cost_per_unit', x.target.value)} /></Field>
            <label className="col-span-2 flex items-center gap-2 text-sm"><input type="checkbox" className="size-4" checked={form.data.active} onChange={(x) => form.setData('active', x.target.checked)} /> Still used</label>
            <div className="col-span-2 flex gap-2"><Button type="submit" variant="secondary" disabled={form.processing}>Save</Button><DeleteButton type="medicine" id={m.id} name={m.name} /></div>
        </form>
    );
}

export function MedicineMoveForm({ medicines, types, onDone, via = 'app', itemId }: { medicines: MedicineItem[]; types: MoveTypes; onDone?: () => void; via?: Via; itemId?: number }) {
    const form = useForm({ medicine_id: String(itemId ?? medicines.find((m) => m.active)?.id ?? medicines[0].id), type: 'purchase', direction: '', qty: '', batch: '', expiry_on: '', rate: '', moved_on: today(), party: '', reference: '', notes: '' });
    const e = form.errors as Record<string, string>;
    const type = types[form.data.type];
    const med = medicines.find((m) => String(m.id) === form.data.medicine_id);
    const unit = med?.unit ?? '';
    // D-332: bought in packs (a vial of 100 ml), kept and dosed in the stock unit
    const pack = med?.packUnit && med.packSize ? { name: (PACKS[med.packUnit] ?? med.packUnit).toLowerCase(), size: med.packSize } : null;
    const [packs, setPacks] = useState('');
    const [packPrice, setPackPrice] = useState('');
    const incoming = type?.direction === 1 || (type?.direction === 0 && form.data.direction === 'in');
    return (
        <Panel title="Stock in / out" description="Purchases also go into Expenses. Treatments issue stock by themselves.">
            <form onSubmit={(ev) => { ev.preventDefault(); form.post(URLS[via].medStock, { preserveScroll: true, onSuccess: () => { form.reset('qty', 'batch', 'expiry_on', 'rate', 'party', 'reference', 'notes'); onDone?.(); } }); }} className="grid grid-cols-2 gap-3">
                <Field label="Medicine" error={e.medicine_id}><select className="field" value={form.data.medicine_id} onChange={(x) => form.setData('medicine_id', x.target.value)}>{medicines.map((m) => <option key={m.id} value={m.id}>{m.name} ({m.qty} {m.unit})</option>)}</select></Field>
                <Field label="What happened" error={e.type}><select className="field" value={form.data.type} onChange={(x) => form.setData('type', x.target.value)}>{Object.entries(types).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}</select></Field>
                {type?.direction === 0 ? <Field label="Stock goes" error={e.direction}><select className="field" value={form.data.direction} onChange={(x) => form.setData('direction', x.target.value)}><option value="">Choose…</option><option value="in">In</option><option value="out">Out</option></select></Field> : null}
                {pack ? <Field label={`${pack.name[0].toUpperCase()}${pack.name.slice(1)}s`} hint={`1 ${pack.name} = ${pack.size} ${unit}`}><input type="number" min={0} step="1" className="field" value={packs} onChange={(x) => { setPacks(x.target.value); form.setData('qty', x.target.value ? String(Math.round(Number(x.target.value) * pack.size * 100) / 100) : ''); }} /></Field> : null}
                {pack && form.data.type === 'purchase' ? <Field label={`PKR per ${pack.name}`}><input type="number" min={0} step="0.01" className="field" value={packPrice} onChange={(x) => { setPackPrice(x.target.value); form.setData('rate', x.target.value ? String(Math.round((Number(x.target.value) / pack.size) * 10000) / 10000) : ''); }} /></Field> : null}
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

