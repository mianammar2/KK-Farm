import { Link, useForm } from '@inertiajs/react';
import GoatSelect from './GoatSelect';
import { useState } from 'react';
import { BreedBadge } from './GoatBadges';
import { Button, Chip, Empty, Field, LibIcon, Panel } from './ui';
import { useFive } from '../lib/useFive';

export type KidRow = {
    id: string; number: string; sex: string; breed: string | null; pen: string | null; born: string; ageDays: number; dam: string | null; damId: string | null;
    colostrum: string | null; colostrumDue: boolean; weak: boolean; orphan: boolean; fosteredTo: string | null; lastCheck: string | null; feeds: number;
    history: Array<{ kind: string; at: string; ml: number | null; by: string }>;
};
export type KidCareOptions = { kinds: Record<string, string>; sources: Record<string, string>; suckling: Record<string, string> };

const now = () => { const d = new Date(Date.now() - new Date().getTimezoneOffset() * 60000); return d.toISOString().slice(0, 16); };

/**
 * D-176: Kid Care (Maternity → Operations): kids up to 14 days old. Colostrum not recorded comes first (it must be given
 * in the first hours of life), then weak kids and orphans. Each kid has its care history and a quick care form.
 */
export function KidCare({ kids, options, does }: { kids: KidRow[]; options: KidCareOptions; does: Array<{ id: string; farm_number: string }> }) {
    const [open, setOpen] = useState<{ kid: KidRow; kind: string } | null>(null);
    const [shown, more] = useFive(kids);
    if (!kids.length) return <Panel><Empty title="No newborn kids">Kids up to 14 days old appear here with their colostrum, feeding and nursing checks.</Empty></Panel>;
    const due = kids.filter((k) => k.colostrumDue).length;
    return (
        <div className="space-y-3">
            {open ? <CareForm kid={open.kid} kind={open.kind} options={options} does={does} onDone={() => setOpen(null)} /> : null}
            <Panel flush title={`Newborn Kids · ${kids.length}`} description={due ? `${due} still need colostrum recorded. Give colostrum within the first 6 hours of life.` : 'Up to 14 days old'}>
                <ul className="divide-y divide-rule">
                    {shown.map((k) => (
                        <li key={k.id} className="px-4 py-3 text-sm">
                            <div className="flex flex-wrap items-center gap-2">
                                <LibIcon name="cls-kid" size={28} />
                                <Link href={`/goats/${k.id}`} className="font-mono font-semibold text-cobalt-800 hover:underline">{k.number}</Link>
                                <BreedBadge breed={k.breed} />
                                <span className="text-ink-soft">born {k.born} ({k.ageDays} days){k.dam ? ` · mother ${k.dam}` : ''}{k.pen ? ` · ${k.pen}` : ''}</span>
                                {k.colostrumDue ? <Chip className="bg-crit-100 text-crit-700">Colostrum not recorded</Chip> : k.colostrum ? <Chip className="bg-ok-100 text-ok-700">Colostrum {k.colostrum}</Chip> : null}
                                {k.weak ? <Chip className="bg-watch-100 text-watch-700">Weak</Chip> : null}
                                {k.orphan ? <Chip className="bg-cobalt-100 text-cobalt-800">Orphan{k.fosteredTo ? ` · fostered to ${k.fosteredTo}` : ''}</Chip> : null}
                                {k.lastCheck ? <span className="text-xs text-ink-soft">Last check: {k.lastCheck}</span> : null}
                            </div>
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                {Object.entries(options.kinds).map(([key, label]) => (
                                    <Button key={key} variant={key === 'colostrum' && k.colostrumDue ? 'primary' : 'secondary'} onClick={() => setOpen({ kid: k, kind: key })}>{label}</Button>
                                ))}
                            </div>
                            {k.history.length ? <p className="mt-1.5 text-xs text-ink-soft">{k.history.map((h) => `${h.kind}${h.ml ? ` ${h.ml} ml` : ''} · ${h.at}`).join('  |  ')}</p> : null}
                        </li>
                    ))}
                </ul>
                {more}
            </Panel>
        </div>
    );
}

function CareForm({ kid, kind, options, does, onDone }: { kid: KidRow; kind: string; options: KidCareOptions; does: Array<{ id: string; farm_number: string }>; onDone: () => void }) {
    const form = useForm({ kid_id: kid.id, kind, given_at: now(), amount_ml: '', source: kind === 'colostrum' ? 'dam' : kind === 'bottle_feed' ? 'replacer' : '', suckling: 'good', foster_dam_id: '', notes: '' });
    const e = form.errors as Record<string, string>;
    const feed = kind === 'colostrum' || kind === 'bottle_feed';
    return (
        <Panel title={`${options.kinds[kind]} · ${kid.number}`}>
            <form className="grid gap-3 p-4 sm:grid-cols-3" onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, amount_ml: d.amount_ml || null, source: d.source || null, suckling: kind === 'nursing_check' ? d.suckling : null, foster_dam_id: d.foster_dam_id || null })); form.post('/breeding/kid-care', { preserveScroll: true, onSuccess: onDone }); }}>
                <Field label="When" error={e.given_at}><input type="datetime-local" max={now()} className="field" value={form.data.given_at} onChange={(x) => form.setData('given_at', x.target.value)} /></Field>
                {feed ? <Field label="Amount (ml)" error={e.amount_ml}><input type="number" min={5} className="field" value={form.data.amount_ml} onChange={(x) => form.setData('amount_ml', x.target.value)} /></Field> : null}
                {feed ? <Field label="Source" error={e.source}><select className="field" value={form.data.source} onChange={(x) => form.setData('source', x.target.value)}>{Object.entries(options.sources).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field> : null}
                {kind === 'nursing_check' ? <Field label="Suckling" error={e.suckling}><select className="field" value={form.data.suckling} onChange={(x) => form.setData('suckling', x.target.value)}>{Object.entries(options.suckling).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field> : null}
                {kind === 'fostered' ? <Field label="Foster doe" error={e.foster_dam_id}><GoatSelect className="field" value={form.data.foster_dam_id} onChange={(x) => form.setData('foster_dam_id', x.target.value)}><option value="">Choose…</option>{does.filter((d) => d.id !== kid.damId).map((d) => <option key={d.id} value={d.id}>{d.farm_number}</option>)}</GoatSelect></Field> : null}
                <Field label="Notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
                <div className="flex gap-2 sm:col-span-3"><Button type="submit" disabled={form.processing}>Save</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}
