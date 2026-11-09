import { Head, router, useForm, usePoll } from '@inertiajs/react';
import { LIVE_MS } from '../lib/live';
import { Check, Clock, Plus, Trash2, Wheat } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button, Chip, cx, Empty, Field, Note, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';

type Meal = 'morning' | 'midday' | 'evening';
type Status = 'done' | 'late' | 'missed' | 'due' | 'upcoming' | 'none';
type Usage = { id: string; offered: number; refused: number | null; by: string; at: string };
type Line = { feedItemId: number; code: string; name: string; planned: number; min: number; max: number; usage: Usage | null; stock: number };
type PenRow = { id: number; name: string; shed: string | null; headcount: number; unplanned: number; noWeight: number; onMilk: number; auto: number; balance: 'over' | 'short' | 'ok' | null; diff: number; minKg: number; maxKg: number; day: { planned: number; given: number; balance: 'over' | 'short' | 'ok' | null; diff: number }; plannedKg: number; offeredKg: number; status: Status; meals: Record<Meal, Status>; lines: Line[] };
type Plan = { id: number; from: string; fromIso: string; status: string; basis: 'per_head' | 'body_weight'; split: Record<Meal, number>; lines: Array<{ feedItemId: number; name: string; code: string; kg: number | null; share: number | null; cp: number | null }>; cpMix: number | null; kgPerHead: number; costPerHead: number; costPerKg: number; notes: string | null; createdBy: string; approvedBy: string | null; approvedOn: string | null };
type Group = { id: number; name: string; criteria: string | null; head: number; cpTarget: number | null; current: Plan | null; upcoming: Plan | null; draft: Plan | null };
type Item = { id: number; code: string; name: string; category: string; costPerKg: number; cp: number | null; reorder: number; supplier: string | null; notes: string | null; active: boolean; stock: number; value: number; status: 'ok' | 'low' | 'out' | 'negative'; daysLeft: number | null };
type Movement = { id: number; on: string; item: string; type: string; qty: number; rate: number | null; party: string | null; reference: string | null; by: string };
type History = { id: string; on: string; meal: Meal; pen: string; feed: string; headcount: number; planned: number; offered: number; refused: number | null; consumed: number; perHead: number | null; cost: number; by: string; corrected: boolean; off: boolean };
type Props = {
    today: string; meal: Meal; meals: Record<Meal, string>; mealTimes: Record<Meal, string>;
    settings: { late: number; window: number; tolerance: number; timezone: string; tracking: boolean; intake: { kid: [number, number] | null; grower: [number, number]; adult: [number, number] }; cp: { kid: number | null; grower: number | null; adult: number | null } };
    board: PenRow[]; groups: Group[]; items: Item[]; movements: Movement[]; history: History[];
    categories: Record<string, string>; movementTypes: Record<string, { label: string; direction: number }>;
    can: { store: boolean; plan: boolean; approve: boolean; settings: boolean };
};

const MEALS: Meal[] = ['morning', 'midday', 'evening'];
const kg = (n: number) => `${(Math.round(n * 100) / 100).toLocaleString('en-PK')} KG`;
const pkr = (n: number) => `PKR ${Math.round(n).toLocaleString('en-PK')}`;
/** Client-generated ids make a resubmitted entry harmless (offline retries, double taps). */
const uuid = () => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = (Math.random() * 16) | 0; return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16); }));

const STATUS: Record<Status, { label: string; className: string }> = {
    done: { label: 'Fed', className: 'bg-ok-100 text-ok-700' },
    late: { label: 'Fed late', className: 'bg-watch-100 text-watch-700' },
    missed: { label: 'Missed', className: 'bg-crit-100 text-crit-700' },
    due: { label: 'Due now', className: 'bg-cobalt-100 text-cobalt-800' },
    upcoming: { label: 'Later', className: 'bg-rule text-ink-soft' },
    none: { label: 'No ration', className: 'bg-rule text-ink-soft' },
};
const STOCK: Record<Item['status'], { label: string; className: string }> = {
    ok: { label: 'OK', className: 'bg-ok-100 text-ok-700' },
    low: { label: 'Low', className: 'bg-watch-100 text-watch-700' },
    out: { label: 'Out', className: 'bg-crit-100 text-crit-700' },
    negative: { label: 'Below zero', className: 'bg-crit-100 text-crit-700' },
};

export default function Feeding(props: Props) {
    // D-086: live. Moves on other screens change pen plans; this picks them up within 10 seconds.
    usePoll(LIVE_MS, { only: ['board', 'items', 'history'] });
    const { today, meal, meals, mealTimes, board, groups, items, can } = props;
    const [tab, setTab] = useState(() => (typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('tab')) || 'now');
    const fed = board.filter((p) => p.status === 'done' || p.status === 'late').length;
    const expected = board.filter((p) => p.status !== 'none' && p.status !== 'upcoming').length;
    const unplanned = board.reduce((sum, p) => sum + p.unplanned, 0);
    const lowStock = items.filter((i) => i.active && i.status !== 'ok').length;

    const tabs = [
        { id: 'now', label: 'Feed now' },
        { id: 'rations', label: 'Rations', count: groups.filter((g) => g.draft).length || undefined },
        { id: 'store', label: 'Feed store', count: lowStock || undefined },
        { id: 'history', label: 'History' },
        ...(can.settings ? [{ id: 'times', label: 'Settings' }] : []),
    ];

    return (
        <>
            <Head title="Feeding & Nutrition" />
            <PageHeader title="Feeding" description={`${today} · ${meals[meal]} meal (${mealTimes[meal]})`} />
            <StatRow>
                <Stat label={`${meals[meal]} meal`} value={`${fed}/${expected}`} note="pens fed" tone={expected && fed === expected ? 'good' : undefined} />
                <Stat label="Planned / given" value={kg(board.reduce((s, p) => s + p.offeredKg, 0))} note={`of ${kg(board.reduce((s, p) => s + p.plannedKg, 0))} planned`} />
                <Stat label="Feeds low or out" value={lowStock} tone={lowStock ? 'alert' : undefined} note={`${items.filter((i) => i.active).length} feeds in store`} />
                <Stat label="Goats not planned" value={unplanned} tone={unplanned ? 'alert' : undefined} note={`need a weight · ${board.reduce((s, p) => s + p.auto, 0)} planned automatically`} />
            </StatRow>
            <Tabs tabs={tabs} value={tab} onChange={setTab} />
            {tab === 'now' ? <FeedNow {...props} onTab={setTab} /> : null}
            {tab === 'rations' ? <Rations {...props} /> : null}
            {tab === 'store' ? <Store {...props} /> : null}
            {tab === 'history' ? <HistoryTab {...props} /> : null}
            {tab === 'times' ? <MealTimes {...props} /> : null}
        </>
    );
}

// ---------- Feed now ----------

function FeedNow(props: Props & { onTab: (t: string) => void }) {
    const { meal, meals, mealTimes, board, items, groups, onTab } = props;
    const [open, setOpen] = useState<number | null>(null);
    const noRations = !groups.some((g) => g.current);

    return (
        <div className="space-y-3">
            <div className="flex gap-1.5 overflow-x-auto" role="group" aria-label="Meal">
                {MEALS.map((m) => (
                    <button key={m} type="button" aria-pressed={meal === m} onClick={() => router.get('/feeding', { meal: m }, { preserveScroll: true, preserveState: true, replace: true })}
                        className={cx('min-h-11 rounded-tile border px-3 text-sm font-semibold', meal === m ? 'border-cobalt-700 bg-cobalt-700 text-white' : 'border-rule bg-tile text-cobalt-900')}>
                        {meals[m]} <span className="font-normal opacity-75">{mealTimes[m]}</span>
                    </button>
                ))}
            </div>
            {!props.settings.tracking ? <Note>Feeding is not being recorded yet, so there are no meal or missed-feeding alerts. The plan below is what the goats need. Turn recording on in Settings when you start.</Note> : null}
            {!items.length ? <Note>No feeds yet. Add your feeds in the <button type="button" className="font-semibold underline" onClick={() => onTab('store')}>Feed store</button> first.</Note> : null}
            {items.length && noRations ? <Note>No approved ration yet, so each goat is planned automatically: body weight × the farm's intake % for its age (grower 3–4%, adult 2–3%), split 40/20/40 over the meals. You can change the KG given. For a ration of your own, use the <button type="button" className="font-semibold underline" onClick={() => onTab('rations')}>Rations</button> tab.</Note> : null}
            {!board.length ? <Empty title="No goats in any pen">Goats appear here once they are registered into a pen.</Empty> : null}
            {board.map((pen) => (
                <section key={pen.id} className="rounded-tile border border-rule bg-tile shadow-tile">
                    <button type="button" className="flex w-full items-center gap-3 px-4 py-3 text-left" aria-expanded={open === pen.id} onClick={() => setOpen(open === pen.id ? null : pen.id)}>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-baseline gap-x-2"><span className="font-mono font-semibold text-cobalt-900">{pen.name}</span></div>
                            <div className="text-sm text-ink-soft">{pen.headcount} goats · plan {kg(pen.plannedKg)}{pen.offeredKg ? ` · given ${kg(pen.offeredKg)}` : ''}{pen.day.given ? ` · today ${kg(pen.day.given)} of ${kg(pen.day.planned)}` : ''}{pen.day.balance === 'over' ? ` (over by ${kg(pen.day.diff)})` : ''}{pen.auto ? ` · ${pen.auto} automatic` : ''}{pen.onMilk ? ` · ${pen.onMilk} kid${pen.onMilk === 1 ? '' : 's'} on milk` : ''}{pen.unplanned ? ` · ${pen.unplanned} not planned${pen.noWeight ? ` (${pen.noWeight} need a weight)` : ''}` : ''}</div>
                        </div>
                        <div className="flex items-center gap-1" aria-label="Meals today">{MEALS.map((m) => <span key={m} title={`${meals[m]}: ${STATUS[pen.meals[m]].label}`} className={cx('size-2.5 rounded-full', pen.meals[m] === 'done' ? 'bg-ok-700' : pen.meals[m] === 'late' ? 'bg-watch-400' : pen.meals[m] === 'missed' ? 'bg-crit-500' : 'bg-rule-strong')} />)}</div>
                        {pen.balance === 'over' || pen.balance === 'short' ? <Chip className={pen.balance === 'over' ? 'bg-crit-100 text-crit-700' : 'bg-watch-100 text-watch-700'}>{pen.balance === 'over' ? 'Over' : 'Short'} {kg(Math.abs(pen.diff))}</Chip> : null}
                        <Chip className={STATUS[pen.status].className}>{STATUS[pen.status].label}</Chip>
                    </button>
                    {open === pen.id ? <PenFeed key={`${pen.id}-${meal}-${pen.lines.map((l) => `${l.usage?.id}:${l.planned}`).join()}`} pen={pen} meal={meal} items={items} onDone={() => setOpen(null)} /> : null}
                </section>
            ))}
        </div>
    );
}

function PenFeed({ pen, meal, items, onDone }: { pen: PenRow; meal: Meal; items: Item[]; onDone: () => void }) {
    const recorded = pen.lines.some((l) => l.usage);
    const [lines, setLines] = useState(() => pen.lines.map((l) => ({ ...l, offered: String(l.usage?.offered ?? l.planned), refused: l.usage?.refused != null ? String(l.usage.refused) : '' })));
    const [editing, setEditing] = useState(!recorded);
    const form = useForm({});
    const errors = form.errors as Record<string, string>;
    const others = items.filter((i) => i.active && !lines.some((l) => l.feedItemId === i.id));

    const save = () => {
        form.transform(() => ({ pen_id: pen.id, meal, lines: lines.map((l) => ({ id: uuid(), feed_item_id: l.feedItemId, offered_kg: Number(l.offered || 0) })) }));
        form.post('/feeding/record', { preserveScroll: true, onSuccess: () => setEditing(false) });
    };
    const saveLeftover = () => {
        form.transform(() => ({ lines: lines.filter((l) => l.usage && l.refused !== '').map((l) => ({ id: uuid(), usage_id: l.usage!.id, refused_kg: Number(l.refused) })) }));
        form.post('/feeding/leftover', { preserveScroll: true, onSuccess: onDone });
    };
    const set = (i: number, key: 'offered' | 'refused', value: string) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, [key]: value } : l)));
    const first = pen.lines.find((l) => l.usage)?.usage;
    // D-084: what can be given = what is in store + what this meal already took (a correction only needs the extra).
    const available = (l: (typeof lines)[number]) => Math.round((Math.max(0, items.find((x) => x.id === l.feedItemId)?.stock ?? l.stock ?? 0) + (l.usage?.offered ?? 0)) * 100) / 100;
    const overStock = (l: (typeof lines)[number]) => editing && Number(l.offered || 0) > available(l) + 0.0001;

    return (
        <div className="border-t border-rule px-4 py-3">
            {recorded && first ? <p className="mb-2 flex items-center gap-1.5 text-sm text-ink-soft"><Check size={15} className="text-ok-700" aria-hidden /> Given at {first.at} by {first.by}</p> : null}
            {!lines.length ? <p className="mb-2 text-sm text-ink-soft">No ration planned for this pen. Add the feed you gave below.</p> : null}
            <div className="space-y-2">
                {lines.map((l, i) => (
                    <div key={l.feedItemId} className="grid grid-cols-[minmax(0,1fr)_6.5rem_6.5rem] items-end gap-2">
                        <div className="min-w-0 pb-2"><div className="truncate font-medium text-cobalt-900">{l.name}</div><div className="text-xs text-ink-soft">Plan {kg(l.planned)}{l.max > l.min ? ` (${kg(l.min)}–${kg(l.max)})` : ''}</div><div className={cx('text-xs', overStock(l) ? 'font-semibold text-crit-700' : 'text-ink-faint')}>In store {kg(available(l))}</div></div>
                        <Field label="Given KG" error={errors[`lines.${i}.offered_kg`] ?? (overStock(l) ? `Only ${kg(available(l))} in store` : undefined)}><input className="field" type="number" inputMode="decimal" min={0} max={available(l)} step="0.1" disabled={!editing} value={l.offered} onChange={(e) => set(i, 'offered', e.target.value)} /></Field>
                        <Field label="Leftover KG" error={errors[`lines.${i}.refused_kg`]}><input className="field" type="number" inputMode="decimal" min={0} step="0.1" disabled={editing || !l.usage} placeholder={l.usage ? '0' : '—'} value={l.refused} onChange={(e) => set(i, 'refused', e.target.value)} /></Field>
                    </div>
                ))}
            </div>
            {editing && others.length ? (
                <select className="field mt-2" value="" aria-label="Add another feed" onChange={(e) => { const it = items.find((x) => x.id === Number(e.target.value)); if (it) setLines((ls) => [...ls, { feedItemId: it.id, code: it.code, name: it.name, planned: 0, min: 0, max: 0, usage: null, stock: it.stock, offered: '', refused: '' }]); }}>
                    <option value="">+ Add another feed…</option>
                    {others.map((i) => <option key={i.id} value={i.id}>{i.name}</option>)}
                </select>
            ) : null}
            <div className="mt-3 flex flex-wrap gap-2">
                {editing ? (
                    <>
                        <Button type="button" onClick={save} disabled={form.processing || !lines.length || lines.some(overStock)}>{recorded ? 'Save changes' : 'Save feed given'}</Button>
                        {recorded ? <Button type="button" variant="secondary" onClick={() => setEditing(false)}>Cancel</Button> : null}
                    </>
                ) : (
                    <>
                        <Button type="button" onClick={saveLeftover} disabled={form.processing || !lines.some((l) => l.usage && l.refused !== '')}>Save leftover</Button>
                        <Button type="button" variant="secondary" onClick={() => setEditing(true)}>Change amounts given</Button>
                    </>
                )}
            </div>
        </div>
    );
}

// ---------- Rations ----------

function Rations({ groups, items, can, settings }: Props) {
    const [editing, setEditing] = useState<Group | null>(null);
    const approve = (plan: Plan) => router.post(`/feeding/plans/${plan.id}/approve`, {}, { preserveScroll: true });

    return (
        <div className="space-y-3">
            <p className="text-sm text-ink-soft">Each goat is in a feeding group automatically, from its category, female status and health. A ration is KG per goat per day, split over the meals. A veterinarian approves each ration before it is used.</p>
            {editing ? <RationForm key={editing.id} group={editing} items={items} settings={settings} onDone={() => setEditing(null)} /> : null}
            {groups.map((g) => (
                <Panel key={g.id} title={`${g.name} · ${g.head} goats`} description={g.criteria ?? undefined}
                    actions={can.plan && editing?.id !== g.id ? <Button type="button" variant="secondary" onClick={() => setEditing(g)}>{g.draft ? 'Edit draft' : g.current ? 'Change ration' : 'Set ration'}</Button> : null}>
                    {g.current ? <PlanView plan={g.current} label="In use" target={g.cpTarget} /> : <p className="text-sm text-ink-soft">No approved ration. {g.head ? `${g.head} goats in this group get no planned feed.` : ''}</p>}
                    {g.upcoming ? <div className="mt-3"><PlanView plan={g.upcoming} label={`Starts ${g.upcoming.from}`} target={g.cpTarget} /></div> : null}
                    {g.draft ? (
                        <div className="mt-3 rounded-tile border border-dashed border-watch-400 p-3">
                            <PlanView plan={g.draft} label={`Draft by ${g.draft.createdBy}, from ${g.draft.from}`} target={g.cpTarget} />
                            {can.approve ? <Button type="button" className="mt-2" onClick={() => approve(g.draft!)}><Check size={16} className="mr-1 inline" aria-hidden />Approve ration</Button> : <p className="mt-2 flex items-center gap-1.5 text-sm text-watch-700"><Clock size={14} aria-hidden /> Waiting for veterinary approval</p>}
                        </div>
                    ) : null}
                </Panel>
            ))}
        </div>
    );
}

function PlanView({ plan, label, target = null }: { plan: Plan; label: string; target?: number | null }) {
    return (
        <div>
            <div className="mb-1 flex flex-wrap items-center gap-2 text-sm"><Chip className={plan.status === 'approved' ? 'bg-ok-100 text-ok-700' : 'bg-watch-100 text-watch-700'}>{label}</Chip>
                <span className="text-ink-soft">{plan.basis === 'body_weight' ? `% of body weight a day · ${pkr(plan.costPerKg)} per KG of mix` : `${kg(plan.kgPerHead)} per goat per day · ${pkr(plan.costPerHead)}`} · meals {plan.split.morning}/{plan.split.midday}/{plan.split.evening}%</span>{plan.cpMix !== null ? <CpTag cp={plan.cpMix} label="Mix CP" /> : null}</div>
            <ul className="flex flex-wrap gap-1.5">{plan.lines.map((l) => <li key={l.feedItemId} className="rounded-[4px] border border-rule px-2 py-1 text-sm"><Wheat size={13} className="mr-1 inline text-turq-700" aria-hidden />{l.name} <span className="font-semibold">{plan.basis === 'body_weight' ? `${l.share}%` : `${l.kg} KG`}</span>{l.cp !== null ? <CpTag cp={l.cp} className="ml-1" /> : null}</li>)}</ul>
            <CpWarning mix={plan.cpMix} target={target} />
            {plan.approvedBy ? <p className="mt-1 text-xs text-ink-faint">Approved by {plan.approvedBy} on {plan.approvedOn}</p> : null}
            {plan.notes ? <p className="mt-1 text-sm text-ink-soft">{plan.notes}</p> : null}
        </div>
    );
}

function RationForm({ group, items, settings, onDone }: { group: Group; items: Item[]; settings: Props['settings']; onDone: () => void }) {
    const base = group.draft ?? group.current;
    const todayIso = new Date().toISOString().slice(0, 10);
    const form = useForm({
        feeding_group_id: group.id,
        effective_from: group.draft?.fromIso ?? todayIso,
        split_morning: base?.split.morning ?? 40, split_midday: base?.split.midday ?? 20, split_evening: base?.split.evening ?? 40,
        // D-067: fixed KG per goat, or a % of each goat's body weight split over the feeds by share.
        basis: (base?.basis ?? 'body_weight') as 'per_head' | 'body_weight',
        lines: base?.lines.map((l) => ({ feed_item_id: l.feedItemId, kg_per_head: String(l.kg ?? l.share ?? '') })) ?? [{ feed_item_id: items.find((i) => i.active)?.id ?? 0, kg_per_head: '' }],
        notes: group.draft?.notes ?? '',
    });
    const byWeight = form.data.basis === 'body_weight';
    const shareTotal = form.data.lines.reduce((s, l) => s + Number(l.kg_per_head || 0), 0);
    const errors = form.errors as Record<string, string>;
    const total = Number(form.data.split_morning) + Number(form.data.split_midday) + Number(form.data.split_evening);
    const setLine = (i: number, key: 'feed_item_id' | 'kg_per_head', v: string) => form.setData('lines', form.data.lines.map((l, j) => (j === i ? { ...l, [key]: key === 'feed_item_id' ? Number(v) : v } : l)));
    const perHead = form.data.lines.reduce((s, l) => s + Number(l.kg_per_head || 0), 0);
    const cost = form.data.lines.reduce((s, l) => s + Number(l.kg_per_head || 0) * (items.find((i) => i.id === l.feed_item_id)?.costPerKg ?? 0), 0);
    // D-112: CP of the mix from each feed's bag label; shown only when every feed in it has one.
    const cpKnown = perHead > 0 && form.data.lines.every((l) => items.find((i) => i.id === l.feed_item_id)?.cp != null);
    const cpMix = cpKnown ? Math.round(form.data.lines.reduce((s, l) => s + Number(l.kg_per_head || 0) * (items.find((i) => i.id === l.feed_item_id)?.cp ?? 0), 0) / perHead * 10) / 10 : null;
    const cpTarget = [settings.cp.grower !== null ? `growers ${settings.cp.grower}%` : null, settings.cp.adult !== null ? `adults ${settings.cp.adult}%` : null].filter(Boolean).join(', ');

    if (!items.length) return <Note>Add feeds in the Feed store before setting a ration.</Note>;

    return (
        <Panel title={`Ration for ${group.name}`} description="Saved as a draft. It is used once a veterinarian approves it.">
            <form onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({ ...d, lines: d.lines.map((l) => (d.basis === 'body_weight' ? { feed_item_id: l.feed_item_id, share_pct: l.kg_per_head } : { feed_item_id: l.feed_item_id, kg_per_head: l.kg_per_head })) }));
                form.post('/feeding/plans', { preserveScroll: true, onSuccess: onDone });
            }} className="space-y-3">
                <Field label="How much each goat gets">
                    <select className="field" value={form.data.basis} onChange={(e) => form.setData('basis', e.target.value as 'per_head' | 'body_weight')}>
                        <option value="body_weight">% of body weight a day (growers {settings.intake.grower.join('–')}%, adults {settings.intake.adult.join('–')}%{settings.intake.kid ? `, kids ${settings.intake.kid.join('–')}%` : ', kids on milk'})</option>
                        <option value="per_head">Fixed KG per goat a day</option>
                    </select>
                </Field>
                {byWeight ? <p className="text-sm text-ink-soft">Each goat gets its latest weight × the % range for its age (planned at the middle), split over the feeds below by share. A goat needs a weight to be planned. Kids drink milk.</p> : null}
                <div className="space-y-2">
                    {form.data.lines.map((l, i) => (
                        <div key={i} className="grid grid-cols-[minmax(0,1fr)_7rem_auto] items-end gap-2">
                            <Field label={i === 0 ? 'Feed' : ''} error={errors[`lines.${i}.feed_item_id`]}><select className="field" value={l.feed_item_id} onChange={(e) => setLine(i, 'feed_item_id', e.target.value)}>{items.filter((it) => it.active || it.id === l.feed_item_id).map((it) => <option key={it.id} value={it.id}>{it.name}</option>)}</select></Field>
                            <Field label={i === 0 ? (byWeight ? 'Share %' : 'kg / goat / day') : ''} error={errors[`lines.${i}.kg_per_head`] ?? errors[`lines.${i}.share_pct`]}><input className="field" type="number" inputMode="decimal" min={0} step={byWeight ? '1' : '0.001'} value={l.kg_per_head} onChange={(e) => setLine(i, 'kg_per_head', e.target.value)} /></Field>
                            <button type="button" aria-label="Remove feed" className="mb-1 grid size-11 place-items-center rounded-tile text-ink-soft hover:bg-crit-100 hover:text-crit-700" onClick={() => form.setData('lines', form.data.lines.filter((_, j) => j !== i))}><Trash2 size={16} aria-hidden /></button>
                        </div>
                    ))}
                    {errors.lines ? <p className="text-sm text-crit-700">{errors.lines}</p> : null}
                    <Button type="button" variant="quiet" onClick={() => form.setData('lines', [...form.data.lines, { feed_item_id: items.find((it) => it.active && !form.data.lines.some((x) => x.feed_item_id === it.id))?.id ?? items[0].id, kg_per_head: '' }])}><Plus size={15} className="mr-1 inline" aria-hidden />Add feed</Button>
                    {byWeight
                        ? <p className={cx('text-sm', Math.abs(shareTotal - 100) < 0.01 ? 'text-ok-700' : 'text-crit-700')}>Shares add up to {shareTotal}%{Math.abs(shareTotal - 100) < 0.01 ? '' : ' (must be 100%)'} · {pkr(cost / 100)} per KG of the mix. Example: a 30 KG adult gets {kg(30 * settings.intake.adult[0] / 100)}–{kg(30 * settings.intake.adult[1] / 100)} a day (planned at the middle).</p>
                        : <p className="text-sm text-ink-soft">{kg(perHead)} per goat per day · {pkr(cost)} per goat per day · {pkr(cost * group.head)} a day for {group.head} goats</p>}
                    {cpMix !== null ? <p className="text-sm font-semibold text-cobalt-900">Mix CP {cpMix}%{group.cpTarget !== null ? <span className="font-normal text-ink-soft"> · target {group.cpTarget}% for {group.name}</span> : cpTarget ? <span className="font-normal text-ink-soft"> · target {cpTarget}</span> : null}</p> : null}
                    <CpWarning mix={cpMix} target={group.cpTarget} />
                </div>
                <div className="grid grid-cols-3 gap-2">
                    {MEALS.map((m) => (
                        <Field key={m} label={`${m[0].toUpperCase()}${m.slice(1)} %`} error={errors[`split_${m}`]}>
                            <input className="field" type="number" inputMode="numeric" min={0} max={100} value={form.data[`split_${m}` as const]} onChange={(e) => form.setData(`split_${m}` as const, Number(e.target.value))} />
                        </Field>
                    ))}
                </div>
                <p className={cx('text-sm', total === 100 ? 'text-ok-700' : 'text-crit-700')}>Meals add up to {total}%{total === 100 ? '' : ' (must be 100%)'}. Put 0% on a meal the farm does not give.</p>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Starts on" error={errors.effective_from}><input className="field" type="date" value={form.data.effective_from} onChange={(e) => form.setData('effective_from', e.target.value)} /></Field>
                    <Field label="Notes" error={errors.notes}><input className="field" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="e.g. nutritionist advice" /></Field>
                </div>
                <div className="flex gap-2"><Button type="submit" disabled={form.processing || total !== 100 || (byWeight && Math.abs(shareTotal - 100) >= 0.01)}>Save draft</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}

// ---------- Feed store ----------

function Store({ items, movements, categories, movementTypes, can }: Props) {
    const [edit, setEdit] = useState<number | null>(null);

    return (
        <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div className="space-y-4">
                <Panel title="Feeds in store" flush>
                    {items.length ? (
                        <ul className="divide-y divide-rule">
                            {items.map((i) => (
                                <li key={i.id} className={cx('px-4 py-3', !i.active && 'opacity-60')}>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-semibold text-cobalt-900">{i.name}</span>
                                        <span className="font-mono text-xs text-ink-soft">{i.code}</span>
                                        {i.cp !== null ? <CpTag cp={i.cp} /> : null}
                                        <span className="text-sm text-ink-soft">{categories[i.category] ?? i.category}</span>
                                        {!i.active ? <Chip className="bg-rule text-ink-soft">Not used</Chip> : null}
                                        <span className="ml-auto flex items-center gap-2"><span className="font-semibold">{kg(i.stock)}</span><Chip className={STOCK[i.status].className}>{STOCK[i.status].label}</Chip></span>
                                    </div>
                                    <div className="mt-0.5 text-sm text-ink-soft">{pkr(i.costPerKg)}/KG · worth {pkr(i.value)} · reorder at {kg(i.reorder)}{i.daysLeft !== null ? ` · about ${i.daysLeft} days left` : ''}{i.supplier ? ` · ${i.supplier}` : ''}</div>
                                    {can.store ? (edit === i.id ? <ItemForm item={i} categories={categories} onDone={() => setEdit(null)} /> : <button type="button" className="mt-1 text-sm font-semibold text-cobalt-800 hover:underline" onClick={() => setEdit(i.id)}>Edit</button>) : null}
                                </li>
                            ))}
                        </ul>
                    ) : <Empty title="No feeds yet">{can.store ? 'Add the feeds you use, with their cost per KG and opening stock.' : 'The manager or feed store adds feeds.'}</Empty>}
                </Panel>
                <Panel title="Recent stock movements" flush>
                    {movements.length ? <ul className="divide-y divide-rule text-sm">{movements.map((m) => <li key={m.id} className="flex flex-wrap gap-x-2 px-4 py-2"><span className="w-14 text-ink-faint">{m.on}</span><span className="font-medium">{m.item}</span><span className="text-ink-soft">{m.type}</span><span className={cx('ml-auto font-semibold', m.qty < 0 ? 'text-crit-700' : 'text-ok-700')}>{m.qty > 0 ? '+' : ''}{kg(m.qty)}</span>{m.rate !== null ? <span className="text-ink-soft">@ {pkr(m.rate)}/KG</span> : null}<span className="w-full text-ink-faint">{[m.party, m.reference, m.by].filter(Boolean).join(' · ')}</span></li>)}</ul>
                        : <p className="p-4 text-sm text-ink-soft">Purchases and adjustments appear here. Feed given to goats reduces stock automatically.</p>}
                </Panel>
            </div>
            {can.store ? (
                <div className="space-y-4">
                    {items.length ? <StockForm items={items} movementTypes={movementTypes} /> : null}
                    <Panel title="Add a feed"><ItemForm categories={categories} /></Panel>
                </div>
            ) : null}
        </div>
    );
}

function ItemForm({ item, categories, onDone }: { item?: Item; categories: Record<string, string>; onDone?: () => void }) {
    const form = useForm({ code: item?.code ?? '', name: item?.name ?? '', category: item?.category ?? 'concentrate', cost_per_kg: item ? String(item.costPerKg) : '', cp_pct: item?.cp != null ? String(item.cp) : '', reorder_level_kg: item ? String(item.reorder) : '', opening_kg: '', supplier: item?.supplier ?? '', notes: item?.notes ?? '', active: item?.active ?? true });
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(item ? `/feeding/items/${item.id}` : '/feeding/items', { preserveScroll: true, onSuccess: () => { if (item) onDone?.(); else form.reset(); } });
    };

    return (
        <form onSubmit={submit} className={cx('grid gap-3', item ? 'mt-2 sm:grid-cols-3' : '')}>
            {!item ? <Field label="Code" error={form.errors.code} hint="Short, e.g. WS, CONC, BRAN"><input className="field font-mono uppercase" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} maxLength={20} /></Field> : null}
            <Field label="Name" error={form.errors.name}><input className="field" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. Wheat straw (Bhoosa)" /></Field>
            <Field label="Category" error={form.errors.category}><select className="field" value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>{Object.entries(categories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            <Field label="Cost PKR per KG" error={form.errors.cost_per_kg}><input className="field" type="number" inputMode="decimal" min={0} step="0.01" value={form.data.cost_per_kg} onChange={(e) => form.setData('cost_per_kg', e.target.value)} /></Field>
            <Field label="CP % (Bag Label)" error={form.errors.cp_pct} hint="Crude protein printed on the bag, e.g. 32 for CP 32"><input className="field" type="number" inputMode="decimal" min={0.5} max={60} step="0.1" placeholder="Not known" value={form.data.cp_pct} onChange={(e) => form.setData('cp_pct', e.target.value)} /></Field>
            <Field label="Reorder when below (KG)" error={form.errors.reorder_level_kg}><input className="field" type="number" inputMode="decimal" min={0} value={form.data.reorder_level_kg} onChange={(e) => form.setData('reorder_level_kg', e.target.value)} /></Field>
            {!item ? <Field label="Stock now (KG)" error={form.errors.opening_kg} hint="Opening stock. Later receipts go through Stock in / out."><input className="field" type="number" inputMode="decimal" min={0} value={form.data.opening_kg} onChange={(e) => form.setData('opening_kg', e.target.value)} /></Field> : null}
            <Field label="Supplier" error={form.errors.supplier}><input className="field" value={form.data.supplier} onChange={(e) => form.setData('supplier', e.target.value)} /></Field>
            {item ? <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="size-5" checked={form.data.active} onChange={(e) => form.setData('active', e.target.checked)} /> Still used</label> : null}
            <div className={cx('flex gap-2', item ? 'sm:col-span-3' : '')}><Button type="submit" disabled={form.processing}>{item ? 'Save' : 'Add feed'}</Button>{item ? <Button type="button" variant="secondary" onClick={onDone}>Cancel</Button> : null}</div>
        </form>
    );
}

function StockForm({ items, movementTypes }: { items: Item[]; movementTypes: Props['movementTypes'] }) {
    const todayIso = new Date().toISOString().slice(0, 10);
    const form = useForm({ feed_item_id: String(items.find((i) => i.active)?.id ?? items[0].id), type: 'purchase', direction: '', qty_kg: '', rate: '', moved_on: todayIso, party: '', reference: '', notes: '' });
    const type = movementTypes[form.data.type];
    const item = items.find((i) => i.id === Number(form.data.feed_item_id));

    return (
        <Panel title="Stock in / out" description="Purchases also go into Expenses automatically.">
            <form onSubmit={(e) => { e.preventDefault(); form.post('/feeding/stock', { preserveScroll: true, onSuccess: () => form.reset('qty_kg', 'rate', 'party', 'reference', 'notes') }); }} className="space-y-3">
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

// ---------- History ----------

function HistoryTab({ history, meals }: Props) {
    const days = useMemo(() => {
        const map = new Map<string, { kg: number; eaten: number; cost: number }>();
        history.forEach((h) => { const d = map.get(h.on) ?? { kg: 0, eaten: 0, cost: 0 }; d.kg += h.offered; d.eaten += h.consumed; d.cost += h.cost; map.set(h.on, d); });
        return [...map.entries()];
    }, [history]);

    if (!history.length) return <Empty title="Nothing fed yet">Feed recorded in "Feed now" appears here with planned, given, leftover and cost.</Empty>;

    return (
        <div className="space-y-4">
            <div className="flex gap-2 overflow-x-auto pb-1">{days.map(([day, d]) => <div key={day} className="min-w-36 rounded-tile border border-rule bg-tile px-3 py-2 text-sm"><div className="font-semibold text-cobalt-900">{day}</div><div>{kg(d.kg)} given</div><div className="text-ink-soft">{kg(d.eaten)} eaten · {pkr(d.cost)}</div></div>)}</div>
            <Panel flush>
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[56rem] text-sm">
                        <thead className="bg-cobalt-100/50 text-left text-ink-soft"><tr>{['Date', 'Meal', 'Pen', 'Feed', 'Goats', 'Plan', 'Given', 'Leftover', 'Eaten', 'Per goat', 'Cost', 'By'].map((h) => <th key={h} className="px-3 py-2 font-semibold">{h}</th>)}</tr></thead>
                        <tbody className="divide-y divide-rule">
                            {history.map((h) => (
                                <tr key={h.id}>
                                    <td className="px-3 py-2">{h.on}</td><td className="px-3 py-2">{meals[h.meal]}</td><td className="px-3 py-2">{h.pen}</td><td className="px-3 py-2">{h.feed}</td><td className="px-3 py-2">{h.headcount}</td>
                                    <td className="px-3 py-2">{kg(h.planned)}</td>
                                    <td className="px-3 py-2">{kg(h.offered)} {h.off ? <Chip className="bg-watch-100 text-watch-700">off plan</Chip> : null}</td>
                                    <td className="px-3 py-2">{h.refused !== null ? kg(h.refused) : '—'}</td><td className="px-3 py-2">{kg(h.consumed)}</td>
                                    <td className="px-3 py-2">{h.perHead !== null ? kg(h.perHead) : '—'}</td><td className="px-3 py-2">{pkr(h.cost)}</td>
                                    <td className="px-3 py-2 text-ink-soft">{h.by}{h.corrected ? ' (updated)' : ''}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Panel>
        </div>
    );
}

// ---------- Meal times ----------

function MealTimes({ mealTimes, settings, meals }: Props) {
    const form = useForm({ morning: mealTimes.morning, midday: mealTimes.midday, evening: mealTimes.evening, late: settings.late, window: settings.window, tolerance: settings.tolerance,
        kid_min: settings.intake.kid ? String(settings.intake.kid[0]) : '', kid_max: settings.intake.kid ? String(settings.intake.kid[1]) : '',
        grower_min: String(settings.intake.grower[0]), grower_max: String(settings.intake.grower[1]), adult_min: String(settings.intake.adult[0]), adult_max: String(settings.intake.adult[1]),
        tracking: settings.tracking,
        kid_cp: settings.cp.kid === null ? '' : String(settings.cp.kid), grower_cp: settings.cp.grower === null ? '' : String(settings.cp.grower), adult_cp: settings.cp.adult === null ? '' : String(settings.cp.adult) });
    const cpField = (key: 'grower_cp' | 'adult_cp' | 'kid_cp', label: string, hint?: string) => (
        <Field label={label} error={errors[key]} hint={hint}><input className="field" type="number" inputMode="decimal" step="0.1" min={1} max={40} placeholder="Not set" value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} /></Field>
    );
    type RangeKey = 'kid' | 'grower' | 'adult';
    const range = (key: RangeKey, label: string, hint?: string) => (
        <div className="grid grid-cols-[7rem_1fr_1fr] items-end gap-2">
            <div className="pb-2.5 text-sm font-semibold text-cobalt-900">{label}</div>
            <Field label="From % / day" error={errors[`${key}_min`]} hint={hint}><input className="field" type="number" inputMode="decimal" step="0.1" min={0} value={form.data[`${key}_min`]} onChange={(e) => form.setData(`${key}_min`, e.target.value)} /></Field>
            <Field label="To % / day" error={errors[`${key}_max`]}><input className="field" type="number" inputMode="decimal" step="0.1" min={0} value={form.data[`${key}_max`]} onChange={(e) => form.setData(`${key}_max`, e.target.value)} /></Field>
        </div>
    );
    const errors = form.errors as Record<string, string>;

    return (
        <Panel title="Feeding settings" description={`Meal times mark feeding late or missed (${settings.timezone}). Intake % is used by body-weight rations.`} className="max-w-xl">
            <form onSubmit={(e) => { e.preventDefault(); form.post('/feeding/settings', { preserveScroll: true }); }} className="space-y-3">
                {/* D-107 */}
                <label className="flex items-start gap-3 rounded-tile border border-rule bg-glaze px-3 py-3">
                    <input type="checkbox" className="mt-0.5 size-5" checked={form.data.tracking} onChange={(e) => form.setData('tracking', e.target.checked)} />
                    <span><span className="block font-semibold text-cobalt-900">Record feeding and the feed store</span>
                        <span className="block text-sm text-ink-soft">On: meals given, missed feeding, feed store and shortage alerts. Off: only how much feed the goats need (dashboard and Feeding).</span></span>
                </label>
                <div className="grid grid-cols-3 gap-2">{MEALS.map((m) => <Field key={m} label={meals[m]} error={errors[m]}><input className="field" type="time" value={form.data[m]} onChange={(e) => form.setData(m, e.target.value)} /></Field>)}</div>
                <Field label="Late after (minutes)" error={errors.late} hint="Feed given this long after the meal time counts as late."><input className="field" type="number" min={5} max={240} value={form.data.late} onChange={(e) => form.setData('late', Number(e.target.value))} /></Field>
                <Field label="Missed after (minutes)" error={errors.window} hint="With nothing recorded this long after the meal time, the meal is missed."><input className="field" type="number" min={30} max={720} value={form.data.window} onChange={(e) => form.setData('window', Number(e.target.value))} /></Field>
                <Field label="Flag when given differs from plan by more than (%)" error={errors.tolerance}><input className="field" type="number" min={1} max={50} value={form.data.tolerance} onChange={(e) => form.setData('tolerance', Number(e.target.value))} /></Field>
                <p className="pt-2 text-sm font-semibold text-cobalt-900">Feed intake, % of body weight a day</p>
                {range('grower', 'Growers')}
                {range('adult', 'Adults')}
                {range('kid', 'Kids', 'Empty = milk only')}
                {/* D-111 */}
                <p className="pt-2 text-sm font-semibold text-cobalt-900">Crude Protein (CP), % of the Feed</p>
                <p className="-mt-2 text-xs text-ink-soft">How much protein the feed for each age should carry. The dashboard shows the CP needed: feed KG × CP %. Leave empty if not set.</p>
                <div className="grid grid-cols-3 gap-2">{cpField('grower_cp', 'Growers CP %')}{cpField('adult_cp', 'Adults CP %')}{cpField('kid_cp', 'Kids CP %', 'Kids on milk need none')}</div>
                <Button type="submit" disabled={form.processing}>Save</Button>
            </form>
        </Panel>
    );
}

/** D-112: a feed's crude protein, shown like its bag label: "CP 32". */
function CpTag({ cp, label = 'CP', className }: { cp: number; label?: string; className?: string }) {
    return <span className={cx('rounded-[4px] bg-turq-100 px-1.5 py-0.5 font-mono text-xs font-bold text-turq-800', className)} title={`Crude protein ${cp}%`}>{label} {cp}{label === 'CP' ? '' : '%'}</span>;
}

/** D-115: a ration whose mix carries less crude protein than its group needs. */
function CpWarning({ mix, target }: { mix: number | null; target: number | null }) {
    if (mix === null || target === null || mix >= target) return null;
    return (
        <p role="alert" className="mt-2 rounded-tile bg-crit-100 px-3 py-2 text-sm font-semibold text-crit-700">
            Crude protein too low: Mix CP {mix}% is {Math.round((target - mix) * 10) / 10}% below the {target}% target. Add a higher-CP feed or raise its share.
        </p>
    );
}
