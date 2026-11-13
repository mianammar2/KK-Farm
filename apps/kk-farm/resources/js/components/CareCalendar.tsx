import { Link, router, useForm } from '@inertiajs/react';
import DeleteButton from './DeleteButton';
import { useState } from 'react';
import { BreedBadge } from './GoatBadges';
import { Button, Chip, Empty, Field, LibIcon, Panel } from './ui';
import { useFive } from '../lib/useFive';

export type CalendarRow = {
    id: number; name: string; kind: string; appliesTo: string; interval: number | null; medicineId: number | null; medicine: string | null; dose: number | null; overdue: number;
    goats: Array<{ id: string; number: string; sex: string; stage: string | null; breed: string | null; pen: string | null; due: string; dueLabel: string; overdue: boolean; last: string | null }>;
};
export type Protocol = { id: number; name: string; kind: string; medicineId: number | null; medicine: string | null; dose: number | null; appliesTo: string; firstAge: number | null; interval: number | null; notes: string | null; active: boolean };

const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
const plus = (d: string, n: number) => { const x = new Date(`${d}T12:00:00`); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); };
const ART: Record<string, string> = { vaccination: 'ic-healthy', deworming: 'app-veterinary', hoof_care: 'app-maintenance', examination: 'app-reports', other_care: 'ic-active' };

/** D-173: Vaccination & Deworming calendar: per protocol, the goats due in the next 30 days; the vet records them in one go. */
export function CareCalendar({ calendar, isVet }: { calendar: CalendarRow[]; isVet: boolean }) {
    if (!calendar.length) return <Panel><Empty title="No protocols yet">{isVet ? 'Add your vaccine and deworming protocols under Protocols; the calendar then lists the goats due.' : 'The vet adds the vaccine and deworming protocols.'}</Empty></Panel>;
    const due = calendar.filter((p) => p.goats.length);
    return (
        <div className="space-y-3">
            {due.length ? due.map((p) => <CalendarCard key={p.id} p={p} isVet={isVet} />) : <Panel><Empty title="Nothing due in the next 30 days">Every goat is up to date with the protocols.</Empty></Panel>}
        </div>
    );
}

function CalendarCard({ p, isVet }: { p: CalendarRow; isVet: boolean }) {
    const [shown, more] = useFive(p.goats);
    const [picked, setPicked] = useState<string[]>(() => p.goats.filter((g) => g.overdue || g.due <= today()).map((g) => g.id));
    const [lot, setLot] = useState('');
    const [busy, setBusy] = useState(false);
    const toggle = (id: string) => setPicked((x) => (x.includes(id) ? x.filter((y) => y !== id) : [...x, id]));
    const record = () => {
        setBusy(true);
        router.post('/health/preventive', {
            animal_ids: picked, kind: p.kind, title: p.medicineId ? null : p.name, medicine_id: p.medicineId, qty_per_goat: p.medicineId ? p.dose ?? 1 : null,
            given_on: today(), next_due_on: p.interval ? plus(today(), p.interval) : null, batch_lot: lot || null, care_status: 'administered', protocol_id: p.id,
        }, { preserveScroll: true, onFinish: () => setBusy(false), onSuccess: () => setPicked([]) });
    };
    return (
        <Panel flush title={p.name} description={`${p.appliesTo}${p.interval ? ` · every ${p.interval} days` : ' · once'}${p.medicine ? ` · ${p.medicine}${p.dose ? ` ${p.dose} per goat` : ''}` : ''}`}
            actions={<span className="flex items-center gap-1.5"><LibIcon name={ART[p.kind] ?? 'ic-healthy'} size={22} />{p.overdue ? <Chip className="bg-crit-100 text-crit-700">{p.overdue} overdue</Chip> : null}<Chip className="bg-watch-100 text-watch-700">{p.goats.length} due</Chip></span>}>
            <ul className="divide-y divide-rule">
                {shown.map((g) => (
                    <li key={g.id} className="flex flex-wrap items-center gap-3 px-4 py-2 text-sm">
                        {isVet ? <input type="checkbox" checked={picked.includes(g.id)} onChange={() => toggle(g.id)} aria-label={`Select ${g.number}`} /> : null}
                        <Link href={`/goats/${g.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{g.number}</Link>
                        <BreedBadge breed={g.breed} />
                        <span className="text-ink-soft">{g.pen ?? 'No pen'}{g.last ? ` · last ${g.last}` : ' · never given'}</span>
                        <span className={`ml-auto font-semibold ${g.overdue ? 'text-crit-700' : 'text-watch-700'}`}>{g.overdue ? `Overdue since ${g.dueLabel}` : `Due ${g.dueLabel}`}</span>
                    </li>
                ))}
            </ul>
            {more}
            {isVet ? (
                <div className="flex flex-wrap items-center gap-2 border-t border-rule px-4 py-3">
                    <span className="text-sm text-ink-soft">{picked.length} selected</span>
                    <input className="field !w-40 !py-1.5" placeholder="Batch / lot (optional)" value={lot} onChange={(e) => setLot(e.target.value)} />
                    <Button disabled={!picked.length || busy} onClick={record}><LibIcon name="ic-healthy" size={18} /> Record Given Today</Button>
                    <Button variant="quiet" onClick={() => setPicked(picked.length ? [] : p.goats.map((g) => g.id))}>{picked.length ? 'Select none' : 'Select all'}</Button>
                </div>
            ) : null}
        </Panel>
    );
}

/** D-173: the vet's vaccine and deworming protocols (Veterinary → Configuration). */
export function Protocols({ protocols, careTypes, applies, medicines, canEdit }: { protocols: Protocol[]; careTypes: Record<string, string>; applies: Record<string, string>; medicines: Array<{ id: number; name: string; unit: string }>; canEdit: boolean }) {
    const [editing, setEditing] = useState<Protocol | 'new' | null>(null);
    return (
        <div className="space-y-3">
            <Panel flush title="Vaccine & Deworming Protocols" description="What is given, to which goats, from what age and how often. The calendar follows these."
                actions={canEdit ? <Button onClick={() => setEditing('new')}>+ New Protocol</Button> : undefined}>
                {protocols.length ? (
                    <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Protocol</th><th>Type</th><th>For</th><th>First at</th><th>Repeat</th><th>Product</th><th /></tr></thead><tbody>
                        {protocols.map((p) => (
                            <tr key={p.id} className={p.active ? '' : 'opacity-60'}>
                                <td className="font-semibold">{p.name}{p.active ? null : <span className="ml-1 text-xs text-ink-soft">(paused)</span>}</td><td>{careTypes[p.kind]}</td><td>{applies[p.appliesTo]}</td>
                                <td>{p.firstAge !== null ? `${p.firstAge} days old` : '—'}</td><td>{p.interval ? `every ${p.interval} days` : 'once'}</td><td>{p.medicine ? `${p.medicine}${p.dose ? ` · ${p.dose}` : ''}` : '—'}</td>
                                <td className="whitespace-nowrap">{canEdit ? <><Button variant="quiet" onClick={() => setEditing(p)}>Edit</Button><DeleteButton type="protocol" id={p.id} name={p.name} /></> : null}</td>
                            </tr>
                        ))}
                    </tbody></table></div>
                ) : <Empty title="No protocols yet">{canEdit ? 'Add one with New Protocol, for example PPR vaccine for all goats every 365 days, or deworming every 90 days.' : 'The vet adds them.'}</Empty>}
            </Panel>
            {editing ? <ProtocolForm p={editing === 'new' ? null : editing} careTypes={careTypes} applies={applies} medicines={medicines} onDone={() => setEditing(null)} /> : null}
        </div>
    );
}

function ProtocolForm({ p, careTypes, applies, medicines, onDone }: { p: Protocol | null; careTypes: Record<string, string>; applies: Record<string, string>; medicines: Array<{ id: number; name: string; unit: string }>; onDone: () => void }) {
    const form = useForm({
        name: p?.name ?? '', kind: p?.kind ?? 'vaccination', medicine_id: p?.medicineId ? String(p.medicineId) : '', dose_per_goat: p?.dose ? String(p.dose) : '',
        applies_to: p?.appliesTo ?? 'all', first_age_days: p?.firstAge !== null && p?.firstAge !== undefined ? String(p.firstAge) : '', interval_days: p?.interval ? String(p.interval) : '', notes: p?.notes ?? '', active: p?.active ?? true,
    });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title={p ? `Edit ${p.name}` : 'New Protocol'}>
            <form className="grid gap-3 p-4 sm:grid-cols-3" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, medicine_id: d.medicine_id || null, dose_per_goat: d.dose_per_goat || null, first_age_days: d.first_age_days || null, interval_days: d.interval_days || null })); form.post(p ? `/health/protocols/${p.id}` : '/health/protocols', { preserveScroll: true, onSuccess: onDone }); }}>
                <Field label="Name" error={e.name}><input className="field" placeholder="e.g. PPR vaccine" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} /></Field>
                <Field label="Type" error={e.kind}><select className="field" value={form.data.kind} onChange={(x) => form.setData('kind', x.target.value)}>{Object.entries(careTypes).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="For" error={e.applies_to}><select className="field" value={form.data.applies_to} onChange={(x) => form.setData('applies_to', x.target.value)}>{Object.entries(applies).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="First dose at age (days)" error={e.first_age_days} hint="Empty: due now for goats never given it"><input type="number" min={0} className="field" value={form.data.first_age_days} onChange={(x) => form.setData('first_age_days', x.target.value)} /></Field>
                <Field label="Repeat every (days)" error={e.interval_days} hint="Empty: given once"><input type="number" min={1} className="field" value={form.data.interval_days} onChange={(x) => form.setData('interval_days', x.target.value)} /></Field>
                <Field label="Product from the medicine store" error={e.medicine_id} hint="Giving it deducts the stock"><select className="field" value={form.data.medicine_id} onChange={(x) => form.setData('medicine_id', x.target.value)}><option value="">None</option>{medicines.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}</select></Field>
                <Field label="Dose per goat" error={e.dose_per_goat}><input type="number" step="0.01" min={0} className="field" value={form.data.dose_per_goat} onChange={(x) => form.setData('dose_per_goat', x.target.value)} /></Field>
                <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                <label className="flex items-end gap-2 pb-2 text-sm font-semibold"><input type="checkbox" checked={form.data.active} onChange={(x) => form.setData('active', x.target.checked)} /> Active</label>
                <div className="flex gap-2 sm:col-span-3"><Button type="submit" disabled={form.processing}>Save Protocol</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}
