import { Head, Link, router, useForm } from '@inertiajs/react';
import { Check, Circle } from 'lucide-react';
import { useState } from 'react';
import { BreedBadge } from '../components/GoatBadges';
import GoatPortrait from '../components/GoatPortrait';
import { Button, Empty, Field, LibIcon, PageHeader, Panel } from '../components/ui';
import { useConfirm } from '../components/Confirm';

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
export default function Intake({ goats, days, canClear, canConfigure }: { goats: Row[]; days: number; canClear: boolean; canConfigure: boolean }) {
    const [ask, confirmBox] = useConfirm();
    const [editing, setEditing] = useState(false);
    const settings = useForm({ quarantine_days: String(days) });

    const clear = async (g: Row) => {
        const early = !g.ready;
        if (await ask({ title: `Clear ${g.number} for the herd?`, message: early ? `She is on day ${g.day} of ${g.days} of quarantine. Clear her early only if you have examined her.` : 'Her quarantine is complete. She becomes healthy and can be allocated to the herd.', confirm: 'Yes, clear her', danger: false })) {
            router.post(`/intake/${g.caseId}/clear`, {}, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title="Intake & Quarantine" />
            {confirmBox}
            <PageHeader title="Intake & Quarantine" description={`Purchased and transferred goats stay in quarantine for ${days} days until the vet clears them: Registration → RFID → Quarantine → Veterinary Clearance.`}
                actions={canConfigure ? <Button variant="secondary" onClick={() => setEditing(!editing)}><LibIcon name="mod-settings" size={18} /> Quarantine Days</Button> : undefined} />
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
                                    {canClear ? <Button variant={g.ready ? 'primary' : 'secondary'} onClick={() => clear(g)}><LibIcon name="ic-healthy" size={18} /> Vet Clearance</Button>
                                        : <span className="text-xs text-ink-soft">{g.ready ? 'Waiting for the vet' : 'In quarantine'}</span>}
                                </div>
                            </li>
                        ))}
                    </ul>
                ) : <Empty title="No goats in quarantine">New purchased or transferred goats appear here when they are registered.</Empty>}
            </Panel>
        </>
    );
}
