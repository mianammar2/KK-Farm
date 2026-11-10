import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { BreedBadge } from './GoatBadges';
import { Button, Chip, Empty, Field, LibIcon, Panel } from './ui';
import { useFive } from '../lib/useFive';

export type HeatRow = { id: string; number: string; name: string | null; breed: string | null; stage: string | null; pen: string | null; last: string | null; next: string | null; times: number; status: 'in_heat' | 'due' | 'watch' | 'unknown' | 'mated' };
export type HeatOptions = { intensity: Record<string, string>; signs: string[]; cycle: number };

const STATUS: Record<HeatRow['status'], { label: string; cls: string }> = {
    in_heat: { label: 'In Heat: Ready for Mating', cls: 'bg-crit-100 text-crit-700' }, due: { label: 'Heat Due', cls: 'bg-watch-100 text-watch-700' },
    watch: { label: 'Watch', cls: 'bg-glaze text-ink-soft' }, unknown: { label: 'Not Observed Yet', cls: 'bg-glaze text-ink-soft' }, mated: { label: 'Mated: Await Check', cls: 'bg-cobalt-100 text-cobalt-800' },
};
const today = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);

/**
 * D-175: Heat Detection: does of breeding age that are not pregnant, ready for mating first (heat seen in the last 2
 * days), then heat due within 3 days (21-day cycle). One tap records a heat with its intensity and signs.
 */
export function HeatDetection({ rows, options, onMate }: { rows: HeatRow[]; options: HeatOptions; onMate: (doeId: string) => void }) {
    const [recording, setRecording] = useState<HeatRow | null>(null);
    const ready = rows.filter((r) => r.status === 'in_heat');
    const due = rows.filter((r) => r.status === 'due');
    const rest = rows.filter((r) => !['in_heat', 'due'].includes(r.status));
    if (!rows.length) return <Panel><Empty title="No does to watch">Does of breeding age that are not pregnant appear here.</Empty></Panel>;
    return (
        <div className="space-y-3">
            {recording ? <HeatForm doe={recording} options={options} onDone={() => setRecording(null)} /> : null}
            <HeatList title={`Ready for Mating · ${ready.length}`} description="Heat seen in the last 2 days. Mate within 12–36 hours of the first signs." rows={ready} onRecord={setRecording} onMate={onMate} empty="No doe in heat right now." />
            <HeatList title={`Heat Due · ${due.length}`} description={`Expected within 3 days (about every ${options.cycle} days). Watch them morning and evening.`} rows={due} onRecord={setRecording} onMate={onMate} empty="No heat expected in the next 3 days." />
            <HeatList title={`Other Does · ${rest.length}`} description="Not observed yet, later heats, or mated and waiting for the pregnancy check." rows={rest} onRecord={setRecording} onMate={onMate} empty="" />
        </div>
    );
}

function HeatList({ title, description, rows, onRecord, onMate, empty }: { title: string; description: string; rows: HeatRow[]; onRecord: (r: HeatRow) => void; onMate: (id: string) => void; empty: string }) {
    const [shown, more] = useFive(rows);
    if (!rows.length && !empty) return null;
    return (
        <Panel flush title={title} description={description}>
            {rows.length ? (
                <ul className="divide-y divide-rule">
                    {shown.map((r) => (
                        <li key={r.id} className="flex flex-wrap items-center gap-2 px-4 py-2.5 text-sm">
                            <Link href={`/goats/${r.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{r.number}</Link>
                            <BreedBadge breed={r.breed} />
                            <Chip className={STATUS[r.status].cls}>{STATUS[r.status].label}</Chip>
                            <span className="text-ink-soft">{r.last ? `Last heat ${r.last}` : 'No heat recorded'}{r.next ? ` · next about ${r.next}` : ''}{r.pen ? ` · ${r.pen}` : ''}</span>
                            <span className="ml-auto flex gap-1.5">
                                <Button variant="secondary" onClick={() => onRecord(r)}><LibIcon name="ic-in-heat" size={16} /> Record Heat</Button>
                                {r.status === 'in_heat' ? <Button onClick={() => onMate(r.id)}><LibIcon name="ic-mated" size={16} /> Record Mating</Button> : null}
                            </span>
                        </li>
                    ))}
                </ul>
            ) : <p className="px-4 py-3 text-sm text-ink-soft">{empty}</p>}
            {more}
        </Panel>
    );
}

function HeatForm({ doe, options, onDone }: { doe: HeatRow; options: HeatOptions; onDone: () => void }) {
    const form = useForm({ dam_id: doe.id, observed_on: today(), intensity: 'moderate', signs: [] as string[], notes: '' });
    const e = form.errors as Record<string, string>;
    const toggle = (s: string) => form.setData('signs', form.data.signs.includes(s) ? form.data.signs.filter((x) => x !== s) : [...form.data.signs, s]);
    return (
        <Panel title={`Record Heat · ${doe.number}`}>
            <form className="space-y-3 p-4" onSubmit={(ev) => { ev.preventDefault(); form.post('/breeding/heat', { preserveScroll: true, onSuccess: onDone }); }}>
                <div className="grid gap-3 sm:grid-cols-3">
                    <Field label="Observed on" error={e.observed_on}><input type="date" max={today()} className="field" value={form.data.observed_on} onChange={(x) => form.setData('observed_on', x.target.value)} /></Field>
                    <Field label="Intensity" error={e.intensity}><select className="field" value={form.data.intensity} onChange={(x) => form.setData('intensity', x.target.value)}>{Object.entries(options.intensity).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                </div>
                <div className="flex flex-wrap gap-1.5">
                    {options.signs.map((s) => <button key={s} type="button" onClick={() => toggle(s)} className={`rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ${form.data.signs.includes(s) ? 'bg-crit-100 text-crit-700 ring-crit-500/40' : 'bg-white text-ink-soft ring-rule'}`}>{s}</button>)}
                </div>
                <div className="flex gap-2"><Button type="submit" disabled={form.processing}>Save Heat</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}

type CalMating = { id: number; status: string; expected: string | null; days: number | null; dam: { id: string; number: string; name: string | null }; sire: { number: string } | null; mated: string };

/** D-175: Pregnancy Calendar: confirmed pregnancies by expected kidding month, the nearest first. */
export function PregnancyCalendar({ matings }: { matings: CalMating[] }) {
    const preg = matings.filter((m) => m.status === 'pregnant' && m.expected).sort((a, b) => (a.days ?? 0) - (b.days ?? 0));
    if (!preg.length) return <Panel><Empty title="No confirmed pregnancies">Confirmed pregnancies appear here by expected kidding month.</Empty></Panel>;
    const months = new Map<string, CalMating[]>();
    for (const m of preg) {
        const key = m.expected!.split(' ').slice(1).join(' ');
        months.set(key, [...(months.get(key) ?? []), m]);
    }
    return (
        <div className="space-y-3">
            {[...months.entries()].map(([month, list]) => (
                <Panel key={month} flush title={`${month} · ${list.length} kidding${list.length === 1 ? '' : 's'}`}>
                    <ul className="divide-y divide-rule">
                        {list.map((m) => (
                            <li key={m.id} className="flex flex-wrap items-center gap-2 px-4 py-2 text-sm">
                                <LibIcon name="ic-pregnant" size={20} />
                                <Link href={`/goats/${m.dam.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{m.dam.number}</Link>
                                <span className="text-ink-soft">mated {m.mated}{m.sire ? ` × ${m.sire.number}` : ''}</span>
                                <span className={`ml-auto font-semibold ${(m.days ?? 0) < 0 ? 'text-crit-700' : (m.days ?? 99) <= 10 ? 'text-watch-700' : 'text-cobalt-900'}`}>{m.expected}{m.days !== null ? (m.days < 0 ? ` · ${-m.days} days over` : ` · in ${m.days} days`) : ''}</span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            ))}
        </div>
    );
}
