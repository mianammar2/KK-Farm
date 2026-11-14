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
                <label className="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" checked={form.data.contagious} onChange={(x) => form.setData('contagious', x.target.checked)} /> Contagious (restrict the PEN)</label>
                <label className="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" checked={form.data.active} onChange={(x) => form.setData('active', x.target.checked)} /> In use</label>
                <div className="flex gap-2 sm:col-span-3"><Button type="submit" disabled={form.processing}>Save</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}

/** D-292: the Symptom Master: what the vet ticks on a case, by body system, and the diseases each sign points to. */
export type SymptomRow = { id: number; code: string | null; system: string; name: string; local: string | null; urgency: string | null; active: boolean; diseaseIds: number[]; diseases: string[] };
export function SymptomMaster({ symptoms, diseases, canEdit }: { symptoms: SymptomRow[]; diseases: Array<{ id: number; name: string; code?: string | null }>; canEdit: boolean }) {
    const [editing, setEditing] = useState<SymptomRow | 'new' | null>(null);
    return (
        <div className="space-y-3">
            <Panel flush title="Symptom Master" description="What the vet ticks on a health case, by body system, with the local term. Each sign lists the diseases it is associated with: these rank the possible diseases on a case (the vet decides)."
                actions={canEdit ? <Button onClick={() => setEditing('new')}>+ New Symptom</Button> : undefined}>
                <div className="overflow-x-auto"><table className="data-table"><thead><tr><th>Code</th><th>Symptom</th><th>Local term</th><th>System</th><th>Urgency</th><th>Diseases</th><th /></tr></thead><tbody>
                    {symptoms.map((s) => (
                        <tr key={s.id} className={s.active ? '' : 'opacity-60'}>
                            <td className="whitespace-nowrap text-ink-soft">{s.code ?? '—'}</td><td>{s.name}</td><td className="text-ink-soft">{s.local ?? '—'}</td><td>{s.system}</td>
                            <td className="max-w-56 text-ink-soft">{s.urgency ?? ''}</td><td className="max-w-72 text-ink-soft">{s.diseases.join(', ') || '—'}</td>
                            <td className="whitespace-nowrap">{canEdit ? <><Button variant="quiet" onClick={() => setEditing(s)}>Edit</Button><DeleteButton type="symptom" id={s.id} name={s.name} /></> : null}</td>
                        </tr>
                    ))}
                </tbody></table></div>
            </Panel>
            {editing ? <SymptomForm s={editing === 'new' ? null : editing} diseases={diseases} onDone={() => setEditing(null)} /> : null}
        </div>
    );
}

function SymptomForm({ s, diseases, onDone }: { s: SymptomRow | null; diseases: Array<{ id: number; name: string; code?: string | null }>; onDone: () => void }) {
    const form = useForm({ name: s?.name ?? '', local_name: s?.local ?? '', system: s?.system ?? 'General', urgency: s?.urgency ?? '', active: s?.active ?? true, disease_ids: s?.diseaseIds ?? ([] as number[]) });
    const e = form.errors as Record<string, string>;
    const toggle = (id: number) => form.setData('disease_ids', form.data.disease_ids.includes(id) ? form.data.disease_ids.filter((x) => x !== id) : [...form.data.disease_ids, id]);
    return (
        <Panel title={s ? `Edit ${s.name}` : 'New Symptom'}>
            <form className="grid gap-3 p-4 sm:grid-cols-2" onSubmit={(ev) => { ev.preventDefault(); form.post(s ? `/health/symptoms/${s.id}` : '/health/symptoms', { preserveScroll: true, onSuccess: onDone }); }}>
                <Field label="Symptom" error={e.name}><input className="field" value={form.data.name} onChange={(x) => form.setData('name', x.target.value)} /></Field>
                <Field label="Local term (Roman Urdu)" error={e.local_name}><input className="field" value={form.data.local_name} onChange={(x) => form.setData('local_name', x.target.value)} /></Field>
                <Field label="Body system" error={e.system}><input className="field" list="symptom-systems" value={form.data.system} onChange={(x) => form.setData('system', x.target.value)} />
                    <datalist id="symptom-systems">{['General', 'Respiratory', 'Oral', 'Gastrointestinal', 'Mobility', 'Skin', 'Hoof', 'Hematologic', 'Neurological', 'Urinary', 'Udder', 'Reproductive'].map((x) => <option key={x} value={x} />)}</datalist></Field>
                <Field label="Urgency / notes" error={e.urgency}><input className="field" value={form.data.urgency} onChange={(x) => form.setData('urgency', x.target.value)} /></Field>
                <div className="sm:col-span-2"><p className="mb-1 text-sm text-ink">Associated diseases</p>
                    <div className="flex max-h-48 flex-wrap gap-1.5 overflow-y-auto rounded-tile border border-rule p-2">{diseases.map((d) => (
                        <button key={d.id} type="button" onClick={() => toggle(d.id)} aria-pressed={form.data.disease_ids.includes(d.id)} className={`rounded-full border px-2.5 py-1 text-sm ${form.data.disease_ids.includes(d.id) ? 'border-cobalt-800 bg-cobalt-800 text-white' : 'border-rule bg-tile'}`}>{d.code ? `${d.code} · ` : ''}{d.name}</button>
                    ))}</div></div>
                <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.active} onChange={(x) => form.setData('active', x.target.checked)} /> In use</label>
                <div className="flex gap-2 sm:col-span-2"><Button type="submit" disabled={form.processing}>Save</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}
