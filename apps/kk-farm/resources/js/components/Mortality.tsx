import { Link, useForm } from '@inertiajs/react';
import DeleteButton from './DeleteButton';
import { useState } from 'react';
import { BreedBadge } from './GoatBadges';
import { Button, Chip, Empty, Field, LibIcon, Panel } from './ui';
import { useFive } from '../lib/useFive';

export type MortalityData = {
    rows: Array<{ id: number; goatId: string; number: string | null; sex: string | null; breed: string | null; age: number | null; on: string; pen: string | null; reason: string | null; notes: string | null;
        postmortem: { cause: string; findings: string | null; by: string; on: string } | null }>;
    byCause: Record<string, number>;
};
export type DiseaseRow = { id: number; name: string; category: string; zoonotic: boolean; notifiable: boolean; contagious?: boolean; notes: string | null; active: boolean };

const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

/** D-174: Mortality register: deaths of the last 12 months, the reason given at exit, the vet's postmortem, and deaths by cause. */
export function Mortality({ data, causes, isVet }: { data: MortalityData; causes: string[]; isVet: boolean }) {
    const [shown, more] = useFive(data.rows);
    const [pm, setPm] = useState<string | null>(null);
    const total = data.rows.length;
    if (!total) return <Panel><Empty title="No deaths in the last 12 months">Deaths are recorded with Record Exit → Death on the goat's page.</Empty></Panel>;
    return (
        <div className="space-y-3">
            <Panel title="Mortality by Cause" description={`${total} death${total === 1 ? '' : 's'} in the last 12 months · the vet's postmortem cause, or the reason given at exit`}>
                <ul className="space-y-1.5 p-4">
                    {Object.entries(data.byCause).map(([cause, n]) => (
                        <li key={cause} className="flex items-center gap-3 text-sm">
                            <span className="w-48 shrink-0 truncate font-semibold text-ink">{cause}</span>
                            <span className="h-2.5 flex-1 overflow-hidden rounded-full bg-rule/60"><span className="block h-full rounded-full bg-crit-500" style={{ width: `${(n / total) * 100}%` }} /></span>
                            <span className="w-8 text-right font-bold tabular-nums">{n}</span>
                        </li>
                    ))}
                </ul>
            </Panel>
            <Panel flush title="Death Records" description="Newest first">
                <ul className="divide-y divide-rule">
                    {shown.map((r) => (
                        <li key={r.id} className="px-4 py-3 text-sm">
                            <div className="flex flex-wrap items-center gap-2">
                                <LibIcon name="ic-died" size={22} />
                                <Link href={`/goats/${r.goatId}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{r.number}</Link>
                                <BreedBadge breed={r.breed} />
                                <span className="text-ink-soft">{r.on}{r.age !== null ? ` · ${r.age} months old` : ''}{r.pen ? ` · ${r.pen}` : ''}</span>
                                {r.postmortem ? <Chip className="bg-cobalt-100 text-cobalt-800">Postmortem: {r.postmortem.cause}</Chip> : <Chip className="bg-watch-100 text-watch-700">No postmortem</Chip>}
                                {isVet && !r.postmortem ? <Button variant="secondary" className="ml-auto" onClick={() => setPm(pm === r.goatId ? null : r.goatId)}>Add Postmortem</Button> : null}
                            </div>
                            <p className="mt-1 text-ink-soft">Reason at exit: {r.reason || '—'}{r.notes ? ` · ${r.notes}` : ''}</p>
                            {r.postmortem?.findings ? <p className="mt-0.5 text-ink-soft">Findings: {r.postmortem.findings} <span className="text-xs">({r.postmortem.by}, {r.postmortem.on})</span></p> : null}
                            {pm === r.goatId ? <PostmortemForm goatId={r.goatId} causes={causes} onDone={() => setPm(null)} /> : null}
                        </li>
                    ))}
                </ul>
                {more}
            </Panel>
        </div>
    );
}

function PostmortemForm({ goatId, causes, onDone }: { goatId: string; causes: string[]; onDone: () => void }) {
    const form = useForm({ animal_id: goatId, cause: '', findings: '', examined_on: today() });
    const e = form.errors as Record<string, string>;
    return (
        <form className="mt-2 grid gap-3 rounded-tile bg-glaze p-3 sm:grid-cols-3" onSubmit={(ev) => { ev.preventDefault(); form.post('/health/postmortem', { preserveScroll: true, onSuccess: onDone }); }}>
            <Field label="Cause of death" error={e.cause}><input className="field" list="pm-causes" value={form.data.cause} onChange={(x) => form.setData('cause', x.target.value)} /><datalist id="pm-causes">{causes.map((c) => <option key={c} value={c} />)}</datalist></Field>
            <Field label="Examined on" error={e.examined_on}><input type="date" max={today()} className="field" value={form.data.examined_on} onChange={(x) => form.setData('examined_on', x.target.value)} /></Field>
            <Field label="Findings" error={e.findings}><input className="field" value={form.data.findings} onChange={(x) => form.setData('findings', x.target.value)} /></Field>
            <div className="flex gap-2 sm:col-span-3"><Button type="submit" disabled={form.processing}>Save Postmortem</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}

/** D-174: the farm's disease master (Veterinary → Configuration). */
export function DiseaseMaster({ diseases, categories, canEdit }: { diseases: DiseaseRow[]; categories: Record<string, string>; canEdit: boolean }) {
    const [editing, setEditing] = useState<DiseaseRow | 'new' | null>(null);
    return (
        <div className="space-y-3">
            <Panel flush title="Disease Master" description="Offered when a case or a postmortem is recorded. Zoonotic diseases can spread to people; notifiable ones must be reported."
                actions={canEdit ? <Button onClick={() => setEditing('new')}>+ New Disease</Button> : undefined}>
                <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Disease</th><th>Category</th><th>Flags</th><th>Notes</th><th /></tr></thead><tbody>
                    {diseases.map((d) => (
                        <tr key={d.id} className={d.active ? '' : 'opacity-60'}>
                            <td className="font-semibold">{d.name}</td><td>{categories[d.category] ?? d.category}</td>
                            <td className="space-x-1">{d.zoonotic ? <Chip className="bg-crit-100 text-crit-700">Zoonotic</Chip> : null}{d.notifiable ? <Chip className="bg-watch-100 text-watch-700">Notifiable</Chip> : null}{d.contagious ? <Chip className="bg-treat-100 text-treat-700">Contagious</Chip> : null}{!d.active ? <Chip className="bg-glaze text-ink-soft">Not used</Chip> : null}</td>
                            <td className="text-ink-soft">{d.notes ?? ''}</td>
                            <td className="whitespace-nowrap">{canEdit ? <><Button variant="quiet" onClick={() => setEditing(d)}>Edit</Button><DeleteButton type="disease" id={d.id} name={d.name} /></> : null}</td>
                        </tr>
                    ))}
                </tbody></table></div>
            </Panel>
            {editing ? <DiseaseForm d={editing === 'new' ? null : editing} categories={categories} onDone={() => setEditing(null)} /> : null}
        </div>
    );
}

function DiseaseForm({ d, categories, onDone }: { d: DiseaseRow | null; categories: Record<string, string>; onDone: () => void }) {
    const form = useForm({ name: d?.name ?? '', category: d?.category ?? 'infectious', zoonotic: d?.zoonotic ?? false, notifiable: d?.notifiable ?? false, contagious: d?.contagious ?? false, notes: d?.notes ?? '', active: d?.active ?? true });
    const e = form.errors as Record<string, string>;
    return (
        <Panel title={d ? `Edit ${d.name}` : 'New Disease'}>
            <form className="grid gap-3 p-4 sm:grid-cols-3" onSubmit={(ev) => { ev.preventDefault(); form.post(d ? `/health/diseases/${d.id}` : '/health/diseases', { preserveScroll: true, onSuccess: onDone }); }}>
                <Field label="Name" error={e.name}><input className="field" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} /></Field>
                <Field label="Category" error={e.category}><select className="field" value={form.data.category} onChange={(x) => form.setData('category', x.target.value)}>{Object.entries(categories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                <label className="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" checked={form.data.zoonotic} onChange={(x) => form.setData('zoonotic', x.target.checked)} /> Zoonotic (spreads to people)</label>
                <label className="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" checked={form.data.notifiable} onChange={(x) => form.setData('notifiable', x.target.checked)} /> Notifiable</label>
                <label className="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" checked={form.data.contagious} onChange={(x) => form.setData('contagious', x.target.checked)} /> Contagious (restrict the pen)</label>
                <label className="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" checked={form.data.active} onChange={(x) => form.setData('active', x.target.checked)} /> In use</label>
                <div className="flex gap-2 sm:col-span-3"><Button type="submit" disabled={form.processing}>Save</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}
