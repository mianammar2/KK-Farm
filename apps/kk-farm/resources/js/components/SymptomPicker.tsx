import { AlertTriangle, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { cx } from './ui';

/**
 * D-292: the vet ticks what they see from the Symptom Master (by body system, with the local term). The diseases
 * associated with those signs are ranked as possibilities (the vet decides; nothing is diagnosed here), and the
 * workbook's triage rules light up when their signs are present.
 */
export type SymptomDef = { id: number; code: string | null; system: string; name: string; local: string | null; urgency: string | null };
export type DiseaseLink = { id: number; name: string; code: string | null; local: string | null; priority: string | null; isolation: string | null; symptomIds: number[] };
export type TriageRule = { code: string; trigger: string; level: string; action: string; all: number[]; any: number[]; fever: boolean };

const LEVEL: Record<string, string> = { Emergency: 'border-crit-500 bg-crit-100 text-crit-700', Critical: 'border-crit-500 bg-crit-100 text-crit-700', High: 'border-watch-400 bg-watch-100 text-watch-700' };

export function triageHits(rules: TriageRule[], picked: number[], fever: boolean): TriageRule[] {
    return rules.filter((r) => (r.all.length || r.any.length) && r.all.every((id) => picked.includes(id)) && (!r.any.length || r.any.some((id) => picked.includes(id)) || (r.fever && fever)));
}

export default function SymptomPicker({ symptoms, diseases, rules, picked, onChange, fever, condition, onCondition }: {
    symptoms: SymptomDef[]; diseases: DiseaseLink[]; rules: TriageRule[]; picked: number[]; onChange: (ids: number[]) => void; fever: boolean;
    condition: string; onCondition: (name: string) => void;
}) {
    const [q, setQ] = useState('');
    const toggle = (id: number) => onChange(picked.includes(id) ? picked.filter((x) => x !== id) : [...picked, id]);
    const systems = useMemo(() => {
        const m = new Map<string, SymptomDef[]>();
        for (const s of symptoms) if (!q || `${s.name} ${s.local ?? ''}`.toLowerCase().includes(q.toLowerCase())) m.set(s.system, [...(m.get(s.system) ?? []), s]);
        return [...m.entries()];
    }, [symptoms, q]);
    const feverId = symptoms.find((s) => s.code === 'SYM-001')?.id;
    const signs = fever && feverId && !picked.includes(feverId) ? [...picked, feverId] : picked;
    const ranked = useMemo(() => diseases.map((d) => ({ d, hit: d.symptomIds.filter((id) => signs.includes(id)).length }))
        .filter((x) => x.hit > 0).sort((a, b) => b.hit - a.hit || b.hit / b.d.symptomIds.length - a.hit / a.d.symptomIds.length).slice(0, 6), [diseases, signs]);
    const hits = triageHits(rules, picked, fever);

    return (
        <div className="space-y-3">
            <div>
                <div className="mb-1.5 flex items-center justify-between gap-2">
                    <span className="text-sm font-medium text-ink">What do you see? <span className="font-normal text-ink-soft">({picked.length} ticked)</span></span>
                    <label className="relative w-48"><Search size={14} aria-hidden className="pointer-events-none absolute top-1/2 left-2 -translate-y-1/2 text-ink-soft" />
                        <input className="h-8 w-full rounded-md border border-rule bg-tile ps-7 pe-2 text-sm" placeholder="Find a symptom" value={q} onChange={(e) => setQ(e.target.value)} /></label>
                </div>
                <div className="max-h-72 space-y-2 overflow-y-auto rounded-tile border border-rule bg-glaze/40 p-2">
                    {systems.map(([system, list]) => (
                        <div key={system}>
                            <p className="mb-1 text-xs text-ink-soft">{system}</p>
                            <div className="flex flex-wrap gap-1.5">{list.map((s) => (
                                <button key={s.id} type="button" onClick={() => toggle(s.id)} aria-pressed={picked.includes(s.id)} title={s.urgency ?? undefined}
                                    className={cx('rounded-full border px-2.5 py-1 text-sm', picked.includes(s.id) ? 'border-cobalt-800 bg-cobalt-800 text-white' : 'border-rule bg-tile text-ink hover:border-cobalt-200')}>
                                    {s.name}{s.local ? <span className={cx('ms-1', picked.includes(s.id) ? 'text-white/75' : 'text-ink-soft')}>· {s.local}</span> : null}
                                </button>
                            ))}</div>
                        </div>
                    ))}
                </div>
            </div>
            {hits.map((r) => (
                <div key={r.code} role="alert" className={cx('flex items-start gap-2 rounded-tile border px-3 py-2 text-sm', LEVEL[r.level] ?? LEVEL.High)}>
                    <AlertTriangle size={16} aria-hidden className="mt-0.5 shrink-0" />
                    <span><b className="font-medium">{r.level}: {r.trigger}.</b> {r.action}</span>
                </div>
            ))}
            {ranked.length ? (
                <div className="rounded-tile border border-rule p-2">
                    <p className="mb-1.5 text-sm text-ink">Possible diseases <span className="text-ink-soft">(from the signs ticked; the vet decides)</span></p>
                    <ul className="space-y-1">{ranked.map(({ d, hit }) => (
                        <li key={d.id} className="flex flex-wrap items-center gap-2 text-sm">
                            <span className="min-w-0 flex-1">{d.code ? <span className="text-ink-soft">{d.code} · </span> : null}{d.name}{d.local ? <span className="text-ink-soft"> ({d.local})</span> : null}
                                <span className="ms-2 text-xs text-ink-soft">{hit} of {d.symptomIds.length} signs{d.priority ? ` · ${d.priority}` : ''}{d.isolation === 'Yes' ? ' · isolate' : ''}</span></span>
                            <button type="button" onClick={() => onCondition(d.name)} className={cx('rounded-md border px-2 py-0.5 text-xs', condition === d.name ? 'border-ok-700 bg-ok-100 text-ok-700' : 'border-rule text-cobalt-800 hover:bg-glaze')}>
                                {condition === d.name ? 'Chosen' : 'Use as condition'}
                            </button>
                        </li>
                    ))}</ul>
                </div>
            ) : null}
        </div>
    );
}
