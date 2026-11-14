import { Head, Link, useForm } from '@inertiajs/react';
import { Check, Circle } from 'lucide-react';
import { useState } from 'react';
import { BreedBadge } from '../components/GoatBadges';
import GoatPortrait from '../components/GoatPortrait';
import { Button, Empty, Field, LibIcon, PageHeader, Panel } from '../components/ui';

type Row = {
    caseId: number; goatId: string; number: string; name: string | null; sex: string; coat: string; breed: string | null; stage: string | null; origin: string;
    arrived: string; day: number; days: number; ready: boolean; pen: string | null; rfid: boolean; weighed: boolean; checked: boolean;
};

const Step = ({ done, label, href }: { done: boolean; label: string; href?: string }) => {
    const body = <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold ${done ? 'bg-ok-100 text-ok-700' : 'bg-glaze text-ink-soft ring-1 ring-rule'}`}>{done ? <Check size={12} aria-hidden /> : <Circle size={10} aria-hidden />}{label}</span>;
    return href && !done ? <Link href={href} className="hover:opacity-80">{body}</Link> : body;
};

/**
 * D-172: Intake & Quarantine. Purchased and transferred goats: Intake → Registration → RFID → Quarantine → Veterinary
 * Clearance before they join the herd. Each new arrival shows its checklist and the quarantine day; the vet clears it.
 */
type PenOpt = { id: number; name: string };

export default function Intake({ goats, days, canClear, canConfigure, pens }: { goats: Row[]; days: number; canClear: boolean; canConfigure: boolean; pens: PenOpt[] }) {
    const [editing, setEditing] = useState(false);
    const [deciding, setDeciding] = useState<number | null>(null);
    const settings = useForm({ quarantine_days: String(days) });

    return (
        <>
            <Head title="Intake & Quarantine" />
            <PageHeader title="Intake & Quarantine" description={`Purchased and transferred goats stay in quarantine for ${days} days until the vet clears them: Registration → RFID → Quarantine → Veterinary Clearance.`}
                actions={canConfigure ? <Button variant="secondary" onClick={() => setEditing(!editing)}><LibIcon name="app-settings" size={18} /> Quarantine Days</Button> : undefined} />
            {editing ? (
                <Panel className="mb-4" title="Configuration" description="How long a new arrival is kept apart before clearance">
                    <form className="flex flex-wrap items-end gap-3 p-4" onSubmit={(e) => { e.preventDefault(); settings.post('/intake/settings', { preserveScroll: true, onSuccess: () => setEditing(false) }); }}>
                        <Field label="Quarantine days" error={settings.errors.quarantine_days}><input type="number" min={1} max={90} className="field w-32" value={settings.data.quarantine_days} onChange={(e) => settings.setData('quarantine_days', e.target.value)} /></Field>
                        <Button type="submit" disabled={settings.processing}>Save</Button>
                    </form>
                </Panel>
            ) : null}
            <Panel flush title={`In Quarantine · ${goats.length}`} description="Sorted by arrival. A goat ready for clearance has finished its quarantine days.">
                {goats.length ? (
                    <ul className="divide-y divide-rule">
                        {goats.map((g) => (
                            <li key={g.caseId} className="flex flex-wrap items-center gap-3 px-4 py-3">
                                <GoatPortrait number={g.number} sex={g.sex} stage={g.stage} size={48} />
                                <div className="min-w-48 flex-1">
                                    <Link href={`/goats/${g.goatId}`} className="font-mono font-bold text-cobalt-900 hover:underline">{g.number}</Link>
                                    <span className="ml-2 inline-flex align-middle"><BreedBadge breed={g.breed} /></span>
                                    <div className="text-xs text-ink-soft">{g.origin === 'transferred_in' ? 'Transferred in' : 'Purchased'} {g.arrived}{g.pen ? ` · ${g.pen}` : ''}</div>
                                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                                        <Step done label="Registered" />
                                        <Step done={g.rfid} label="RFID Linked" href="/goats" />
                                        <Step done={g.weighed} label="Weighed" href={`/goats/${g.goatId}?do=weight`} />
                                        <Step done={g.checked} label="Health Check" href={`/goats/${g.goatId}?do=checkup`} />
                                        <Step done={g.ready} label={g.ready ? 'Quarantine Done' : `Quarantine Day ${g.day} of ${g.days}`} />
                                    </div>
                                </div>
                                <div className="flex w-full items-center gap-3 sm:w-auto">
                                    <div className="h-2 w-32 overflow-hidden rounded-full bg-rule/60"><div className={`h-full rounded-full ${g.ready ? 'bg-ok-700' : 'bg-watch-400'}`} style={{ width: `${Math.min(100, (g.day / g.days) * 100)}%` }} /></div>
                                    {canClear ? <Button variant={g.ready ? 'primary' : 'secondary'} onClick={() => setDeciding(deciding === g.caseId ? null : g.caseId)}><LibIcon name="ic-healthy" size={18} /> Vet Clearance</Button>
                                        : <span className="text-xs text-ink-soft">{g.ready ? 'Waiting for the vet' : 'In quarantine'}</span>}
                                </div>
                                {deciding === g.caseId ? <Clearance g={g} pens={pens} close={() => setDeciding(null)} /> : null}
                            </li>
                        ))}
                    </ul>
                ) : <Empty title="No goats in quarantine">New purchased or transferred goats appear here when they are registered.</Empty>}
            </Panel>
        </>
    );
}

/** D-190 (journey A): veterinary clearance granted? Yes → transfer into an approved operating pen. No → extend quarantine and recheck. */
function Clearance({ g, pens, close }: { g: Row; pens: PenOpt[]; close: () => void }) {
    const [granted, setGranted] = useState(true);
    const form = useForm({ pen_id: '', notes: '', days: '7', reason: '' });
    const e = form.errors as Record<string, string>;
    const submit = () => {
        if (granted) {
            form.transform((d) => ({ pen_id: d.pen_id || null, notes: d.notes || null }));
            form.post(`/intake/${g.caseId}/clear`, { preserveScroll: true, onSuccess: close });
        } else {
            form.transform((d) => ({ days: d.days, reason: d.reason }));
            form.post(`/intake/${g.caseId}/extend`, { preserveScroll: true, onSuccess: close });
        }
    };
    return (
        <div className="grid w-full gap-2 rounded-tile bg-glaze p-3 sm:grid-cols-4">
            <Field label="Veterinary clearance granted?"><select className="field" value={granted ? 'yes' : 'no'} onChange={(x) => setGranted(x.target.value === 'yes')}><option value="yes">Yes: clear for the herd</option><option value="no">No: extend / treat / investigate</option></select></Field>
            {granted ? <>
                <Field label="Move to operating PEN" error={e.pen_id ?? e.to_pen_id} hint={g.ready ? undefined : `Early: day ${g.day} of ${g.days}`}><select className="field" value={form.data.pen_id} onChange={(x) => form.setData('pen_id', x.target.value)}><option value="">Keep in {g.pen ?? 'current PEN'}</option>{pens.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select></Field>
                <Field label="Clearance notes" error={e.notes}><input className="field" value={form.data.notes} onChange={(x) => form.setData('notes', x.target.value)} /></Field>
            </> : <>
                <Field label="Extend by (days)" error={e.days}><input type="number" min={1} max={60} className="field" value={form.data.days} onChange={(x) => form.setData('days', x.target.value)} /></Field>
                <Field label="Reason / treatment" error={e.reason}><input className="field" value={form.data.reason} onChange={(x) => form.setData('reason', x.target.value)} placeholder="e.g. Nasal discharge, treating, recheck" /></Field>
            </>}
            <div className="flex items-end gap-2"><Button disabled={form.processing} onClick={submit}>{granted ? 'Clear' : 'Extend Quarantine'}</Button><Button variant="secondary" onClick={close}>Cancel</Button></div>
        </div>
    );
}
