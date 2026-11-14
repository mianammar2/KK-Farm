import { Head, router, useForm, usePoll } from '@inertiajs/react';
import DeleteButton from '../components/DeleteButton';
import { LIVE_MS } from '../lib/live';
import FeedRequirement, { type Requirement } from '../components/FeedRequirement';
import { Check, Clock, Pencil, Plus, Trash2, Wheat } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import { Button, Chip, cx, Empty, Field, Note, PageHeader, Panel, Stat, StatRow, Tabs } from '../components/ui';
import AddButton from '../components/AddButton';
import Sheet from '../components/Sheet';
import { BreedBadge } from '../components/GoatBadges';
import { useTabParam } from '../lib/useTabParam';
import { TmrPanel, WasteReport, type TmrBoard, type WasteReportData } from '../components/Tmr';

type Meal = 'morning' | 'midday' | 'evening';
type Status = 'done' | 'late' | 'missed' | 'due' | 'upcoming' | 'none';
type Usage = { id: string; offered: number; refused: number | null; wasted?: number | null; by: string; at: string };
type Line = { feedItemId: number; code: string; name: string; planned: number; min: number; max: number; usage: Usage | null; stock: number };
type PenRow = { id: number; name: string; shed: string | null; headcount: number; unplanned: number; noWeight: number; onMilk: number; auto: number; balance: 'over' | 'short' | 'ok' | null; diff: number; minKg: number; maxKg: number; day: { planned: number; given: number; balance: 'over' | 'short' | 'ok' | null; diff: number }; plannedKg: number; offeredKg: number; status: Status; meals: Record<Meal, Status>; lines: Line[] };
type Plan = { id: number; from: string; fromIso: string; status: string; basis: 'per_head' | 'body_weight'; split: Record<Meal, number>; lines: Array<{ feedItemId: number; name: string; code: string; kg: number | null; share: number | null; cp: number | null }>; cpMix: number | null; cpBasis: 'dry_matter' | 'as_fed' | null; kgPerHead: number; costPerHead: number; costPerKg: number; notes: string | null; createdBy: string; approvedBy: string | null; approvedOn: string | null };
type Group = { id: number; name: string; criteria: string | null; head: number; cpTarget: number | null; cpStandard: string | null; current: Plan | null; upcoming: Plan | null; draft: Plan | null };
type Item = { id: number; code: string; name: string; category: string; costPerKg: number; cp: number | null; dm: number | null; reorder: number; supplier: string | null; notes: string | null; active: boolean; stock: number; value: number; status: 'ok' | 'low' | 'out' | 'negative'; daysLeft: number | null };
type Movement = { id: number; on: string; item: string; type: string; qty: number; rate: number | null; party: string | null; reference: string | null; by: string };
type History = { id: string; on: string; meal: Meal; pen: string; feed: string; headcount: number; planned: number; offered: number; refused: number | null; consumed: number; perHead: number | null; cost: number; by: string; corrected: boolean; off: boolean };
type Props = {
    today: string; meal: Meal; meals: Record<Meal, string>; mealTimes: Record<Meal, string>;
    settings: { groupCodes?: Record<string, string>; late: number; window: number; tolerance: number; timezone: string; tracking: boolean; intake: { kid: [number, number] | null; grower: [number, number]; adult: [number, number] }; cp: { kid: number | null; grower: number | null; adult: number | null } };
    board: PenRow[]; groups: Group[]; items: Item[]; movements: Movement[]; history: History[];
    categories: Record<string, string>; movementTypes: Record<string, { label: string; direction: number }>;
    requirement: Requirement; standards: Standard[]; nutritionCategories: Record<string, string>; methods: Record<string, string>; breeds: Array<{ id: number; name: string }>;
    can: { store: boolean; plan: boolean; approve: boolean; settings: boolean };
    tmr: TmrBoard; waste: WasteReportData; // D-177
};
type Standard = {
    id: number; name: string; category: string | null; breedId: number | null; breed: string | null; minKg: number | null; maxKg: number | null; cpMin: number; cpMax: number; range: string; target: number;
    tdnMin: number | null; tdnMax: number | null; method: string | null; meals: number | null; formula: string | null; purpose: string | null; reviewedOn: string | null; reviewedIso: string | null; reviewedBy: string | null;
    active: boolean; goats: number; tags: string[]; pens: Record<string, number>; cpKgDay: number; feedKgDay: number; feedCostDay: number | null;
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
    const [tab, setTab] = useTabParam('now'); // D-170
    const focusPen = typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('pen') ? Number(new URLSearchParams(window.location.search).get('pen')) : null; // D-121
    const fed = board.filter((p) => p.status === 'done' || p.status === 'late').length;
    const expected = board.filter((p) => p.status !== 'none' && p.status !== 'upcoming').length;
    const unplanned = board.reduce((sum, p) => sum + p.unplanned, 0);
    const lowStock = items.filter((i) => i.active && i.status !== 'ok').length;

    const tabs = [
        { id: 'now', label: 'Feed now' },
        { id: 'requirement', label: 'Requirement' },
        { id: 'rations', label: 'Rations', count: groups.filter((g) => g.draft).length || undefined },
        { id: 'store', label: 'Feed store', count: lowStock || undefined },
        { id: 'tmr', label: 'TMR Formulas & Mixing' },
        { id: 'waste', label: 'Offered / Refused / Wasted' },
        { id: 'nutrition', label: 'Nutrition Standards' },
        { id: 'history', label: 'History' },
        ...(can.settings ? [{ id: 'times', label: 'Settings' }] : []),
    ];

    return (
        <>
            <Head title="Feeding & Nutrition" />
            <PageHeader title="Feeding" description={`${today} · ${meals[meal]} meal (${mealTimes[meal]})`} />
            <StatRow>
                <Stat label={`${meals[meal]} meal`} value={`${fed}/${expected}`} note="PENs fed" tone={expected && fed === expected ? 'good' : undefined} />
                <Stat label="Planned / given" value={kg(board.reduce((s, p) => s + p.offeredKg, 0))} note={`of ${kg(board.reduce((s, p) => s + p.plannedKg, 0))} planned`} />
                <Stat label="Feeds low or out" value={lowStock} tone={lowStock ? 'alert' : undefined} note={`${items.filter((i) => i.active).length} feeds in store`} />
                <Stat label="Goats not planned" value={unplanned} tone={unplanned ? 'alert' : undefined} note={`need a weight · ${board.reduce((s, p) => s + p.auto, 0)} planned automatically`} />
            </StatRow>
            <Tabs tabs={tabs} value={tab} onChange={setTab} />
            {tab === 'now' ? <FeedNow {...props} onTab={setTab} /> : null}
            {tab === 'requirement' ? <FeedRequirement req={props.requirement} focusPen={focusPen} /> : null}
            {tab === 'rations' ? <Rations {...props} /> : null}
            {tab === 'store' ? <Store {...props} /> : null}
            {tab === 'tmr' ? <TmrPanel tmr={props.tmr} items={items} canPlan={can.plan} canMix={can.store} /> : null}
            {tab === 'waste' ? <WasteReport data={props.waste} /> : null}
            {tab === 'nutrition' ? <Nutrition {...props} /> : null}
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
            {!board.length ? <Empty title="No goats in any PEN">Goats appear here once they are registered into a PEN.</Empty> : null}
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
    const [lines, setLines] = useState(() => pen.lines.map((l) => ({ ...l, offered: String(l.usage?.offered ?? l.planned), refused: l.usage?.refused != null ? String(l.usage.refused) : '', wasted: l.usage?.wasted != null ? String(l.usage.wasted) : '' })));
    const [editing, setEditing] = useState(!recorded);
    const form = useForm({});
    const errors = form.errors as Record<string, string>;
    const others = items.filter((i) => i.active && !lines.some((l) => l.feedItemId === i.id));

    const save = () => {
        form.transform(() => ({ pen_id: pen.id, meal, lines: lines.map((l) => ({ id: uuid(), feed_item_id: l.feedItemId, offered_kg: Number(l.offered || 0) })) }));
        form.post('/feeding/record', { preserveScroll: true, onSuccess: () => setEditing(false) });
    };
    const saveLeftover = () => {
        form.transform(() => ({ lines: lines.filter((l) => l.usage && (l.refused !== '' || l.wasted !== '')).map((l) => ({ id: uuid(), usage_id: l.usage!.id, refused_kg: l.refused === '' ? null : Number(l.refused), wasted_kg: l.wasted === '' ? null : Number(l.wasted) })) }));
        form.post('/feeding/leftover', { preserveScroll: true, onSuccess: onDone });
    };
    const set = (i: number, key: 'offered' | 'refused' | 'wasted', value: string) => setLines((ls) => ls.map((l, j) => (j === i ? { ...l, [key]: value } : l)));
    const first = pen.lines.find((l) => l.usage)?.usage;
    // D-084: what can be given = what is in store + what this meal already took (a correction only needs the extra).
    const available = (l: (typeof lines)[number]) => Math.round((Math.max(0, items.find((x) => x.id === l.feedItemId)?.stock ?? l.stock ?? 0) + (l.usage?.offered ?? 0)) * 100) / 100;
    const overStock = (l: (typeof lines)[number]) => editing && Number(l.offered || 0) > available(l) + 0.0001;

    return (
        <div className="border-t border-rule px-4 py-3">
            {recorded && first ? <p className="mb-2 flex items-center gap-1.5 text-sm text-ink-soft"><Check size={15} className="text-ok-700" aria-hidden /> Given at {first.at} by {first.by}</p> : null}
            {!lines.length ? <p className="mb-2 text-sm text-ink-soft">No ration planned for this PEN. Add the feed you gave below.</p> : null}
            <div className="space-y-2">
                {lines.map((l, i) => (
                    <div key={l.feedItemId} className="grid grid-cols-2 items-end gap-2 sm:grid-cols-[minmax(0,1fr)_6.5rem_6.5rem_6.5rem]">
                        <div className="min-w-0 pb-2"><div className="truncate font-medium text-cobalt-900">{l.name}</div><div className="text-xs text-ink-soft">Plan {kg(l.planned)}{l.max > l.min ? ` (${kg(l.min)}–${kg(l.max)})` : ''}</div><div className={cx('text-xs', overStock(l) ? 'font-semibold text-crit-700' : 'text-ink-faint')}>In store {kg(available(l))}</div></div>
                        <Field label="Given KG" error={errors[`lines.${i}.offered_kg`] ?? (overStock(l) ? `Only ${kg(available(l))} in store` : undefined)}><input className="field" type="number" inputMode="decimal" min={0} max={available(l)} step="0.1" disabled={!editing} value={l.offered} onChange={(e) => set(i, 'offered', e.target.value)} /></Field>
                        <Field label="Leftover KG" error={errors[`lines.${i}.refused_kg`]}><input className="field" type="number" inputMode="decimal" min={0} step="0.1" disabled={editing || !l.usage} placeholder={l.usage ? '0' : '—'} value={l.refused} onChange={(e) => set(i, 'refused', e.target.value)} /></Field>
                        <Field label="Wasted KG" error={errors[`lines.${i}.wasted_kg`]}><input className="field" type="number" inputMode="decimal" min={0} step="0.1" disabled={editing || !l.usage} placeholder={l.usage ? '0' : '—'} value={l.wasted} onChange={(e) => set(i, 'wasted', e.target.value)} title="Spilled, trampled or spoiled feed" /></Field>
                    </div>
                ))}
            </div>
            {editing && others.length ? (
                <select className="field mt-2" value="" aria-label="Add another feed" onChange={(e) => { const it = items.find((x) => x.id === Number(e.target.value)); if (it) setLines((ls) => [...ls, { feedItemId: it.id, code: it.code, name: it.name, planned: 0, min: 0, max: 0, usage: null, stock: it.stock, offered: '', refused: '', wasted: '' }]); }}>
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
                        <Button type="button" onClick={saveLeftover} disabled={form.processing || !lines.some((l) => l.usage && (l.refused !== '' || l.wasted !== ''))}>Save leftover & waste</Button>
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
                    {g.current ? <PlanView plan={g.current} label="In use" target={g.cpTarget} standard={g.cpStandard} /> : <p className="text-sm text-ink-soft">No approved ration. {g.head ? `${g.head} goats in this group get no planned feed.` : ''}</p>}
                    {g.upcoming ? <div className="mt-3"><PlanView plan={g.upcoming} label={`Starts ${g.upcoming.from}`} target={g.cpTarget} standard={g.cpStandard} /></div> : null}
                    {g.draft ? (
                        <div className="mt-3 rounded-tile border border-dashed border-watch-400 p-3">
                            <PlanView plan={g.draft} label={`Draft by ${g.draft.createdBy}, from ${g.draft.from}`} target={g.cpTarget} standard={g.cpStandard} />
                            {can.approve ? <Button type="button" className="mt-2" onClick={() => approve(g.draft!)}><Check size={16} className="mr-1 inline" aria-hidden />Approve ration</Button> : <p className="mt-2 flex items-center gap-1.5 text-sm text-watch-700"><Clock size={14} aria-hidden /> Waiting for veterinary approval</p>}
                        </div>
                    ) : null}
                </Panel>
            ))}
        </div>
    );
}

function PlanView({ plan, label, target = null, standard = null }: { plan: Plan; label: string; target?: number | null; standard?: string | null }) {
    return (
        <div>
            <div className="mb-1 flex flex-wrap items-center gap-2 text-sm"><Chip className={plan.status === 'approved' ? 'bg-ok-100 text-ok-700' : 'bg-watch-100 text-watch-700'}>{label}</Chip>
                <span className="text-ink-soft">{plan.basis === 'body_weight' ? `% of body weight a day · ${pkr(plan.costPerKg)} per KG of mix` : `${kg(plan.kgPerHead)} per goat per day · ${pkr(plan.costPerHead)}`} · meals {plan.split.morning}/{plan.split.midday}/{plan.split.evening}%</span>{plan.cpMix !== null ? <CpTag cp={plan.cpMix} label={plan.cpBasis === 'dry_matter' ? 'Mix CP (DM)' : 'Mix CP'} /> : null}</div>
            <ul className="flex flex-wrap gap-1.5">{plan.lines.map((l) => <li key={l.feedItemId} className="rounded-[4px] border border-rule px-2 py-1 text-sm"><Wheat size={13} className="mr-1 inline text-turq-700" aria-hidden />{l.name} <span className="font-semibold">{plan.basis === 'body_weight' ? `${l.share}%` : `${l.kg} KG`}</span>{l.cp !== null ? <CpTag cp={l.cp} className="ml-1" /> : null}</li>)}</ul>
            <CpWarning mix={plan.cpMix} target={target} standard={standard} />
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
    const feedOf = (id: number) => items.find((i) => i.id === id);
    const cpKnown = perHead > 0 && form.data.lines.every((l) => feedOf(l.feed_item_id)?.cp != null);
    const dmKnown = cpKnown && form.data.lines.every((l) => feedOf(l.feed_item_id)?.dm != null);
    const cpSum = form.data.lines.reduce((s, l) => s + Number(l.kg_per_head || 0) * (feedOf(l.feed_item_id)?.cp ?? 0), 0);
    const dmSum = form.data.lines.reduce((s, l) => s + Number(l.kg_per_head || 0) * (feedOf(l.feed_item_id)?.dm ?? 0), 0);
    // D-116: on dry matter when every feed has a DM %, else as fed.
    const cpMix = cpKnown ? Math.round((dmKnown && dmSum > 0 ? (cpSum / dmSum) * 100 : cpSum / perHead) * 10) / 10 : null;
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
                    {cpMix !== null ? <p className="text-sm font-semibold text-cobalt-900">Mix CP {cpMix}%{dmKnown ? ' of the dry matter' : ' as fed'}{group.cpTarget !== null ? <span className="font-normal text-ink-soft"> · needs at least {group.cpTarget}%{group.cpStandard ? ` (${group.cpStandard})` : ''}</span> : cpTarget ? <span className="font-normal text-ink-soft"> · target {cpTarget}</span> : null}</p> : null}
                    <CpWarning mix={cpMix} target={group.cpTarget} standard={group.cpStandard} />
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
    const [adding, setAdding] = useState<'' | 'feed' | 'stock'>(''); // D-127

    return (
        <div>
            {adding === 'feed' ? <Sheet onClose={() => setAdding('')}><Panel title="Add a feed"><ItemForm categories={categories} onDone={() => setAdding('')} /></Panel></Sheet> : null}
            {adding === 'stock' && items.length ? <Sheet onClose={() => setAdding('')}><StockForm items={items} movementTypes={movementTypes} onDone={() => setAdding('')} /></Sheet> : null}
            <div className="space-y-4">
                <Panel title="Feeds in store" flush actions={can.store ? <>
                    {items.length ? <AddButton primary={false} label="Stock in / out" onClick={() => setAdding('stock')} /> : null}
                    <AddButton label="Add a feed" onClick={() => setAdding('feed')} />
                </> : null}>
                    {items.length ? (
                        <ul className="divide-y divide-rule">
                            {items.map((i) => (
                                <li key={i.id} className={cx('px-4 py-3', !i.active && 'opacity-60')}>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-semibold text-cobalt-900">{i.name}</span>
                                        <span className="font-mono text-xs text-ink-soft">{i.code}</span>
                                        {i.cp !== null ? <CpTag cp={i.cp} /> : null}
                                        {i.dm !== null ? <span className="rounded-[4px] bg-glaze px-1.5 py-0.5 font-mono text-xs text-ink-soft">DM {i.dm}</span> : null}
                                        <span className="text-sm text-ink-soft">{categories[i.category] ?? i.category}</span>
                                        {!i.active ? <Chip className="bg-rule text-ink-soft">Not used</Chip> : null}
                                        <span className="ml-auto flex items-center gap-2"><span className="font-semibold">{kg(i.stock)}</span><Chip className={STOCK[i.status].className}>{STOCK[i.status].label}</Chip></span>
                                    </div>
                                    <div className="mt-0.5 text-sm text-ink-soft">{pkr(i.costPerKg)}/KG · worth {pkr(i.value)} · reorder at {kg(i.reorder)}{i.daysLeft !== null ? ` · about ${i.daysLeft} days left` : ''}{i.supplier ? ` · ${i.supplier}` : ''}</div>
                                    {can.store ? (edit === i.id ? <ItemForm item={i} categories={categories} onDone={() => setEdit(null)} /> : <button type="button" className="mt-1 text-sm font-semibold text-cobalt-800 hover:underline" onClick={() => setEdit(i.id)}>Edit</button>) : null}{can.store && edit !== i.id ? <DeleteButton type="feed-item" id={i.id} name={i.name} /> : null}
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
        </div>
    );
}

function ItemForm({ item, categories, onDone }: { item?: Item; categories: Record<string, string>; onDone?: () => void }) {
    const form = useForm({ code: item?.code ?? '', name: item?.name ?? '', category: item?.category ?? 'concentrate', cost_per_kg: item ? String(item.costPerKg) : '', cp_pct: item?.cp != null ? String(item.cp) : '', dm_pct: item?.dm != null ? String(item.dm) : '', reorder_level_kg: item ? String(item.reorder) : '', opening_kg: '', supplier: item?.supplier ?? '', notes: item?.notes ?? '', active: item?.active ?? true });
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(item ? `/feeding/items/${item.id}` : '/feeding/items', { preserveScroll: true, onSuccess: () => { if (!item) form.reset(); onDone?.(); } });
    };

    return (
        <form onSubmit={submit} className={cx('grid gap-3', item ? 'mt-2 sm:grid-cols-3' : '')}>
            {!item ? <Field label="Code" error={form.errors.code} hint="Short, e.g. WS, CONC, BRAN"><input className="field font-mono uppercase" value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} maxLength={20} /></Field> : null}
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

function StockForm({ items, movementTypes, onDone }: { items: Item[]; movementTypes: Props['movementTypes']; onDone?: () => void }) {
    const todayIso = new Date().toISOString().slice(0, 10);
    const form = useForm({ feed_item_id: String(items.find((i) => i.active)?.id ?? items[0].id), type: 'purchase', direction: '', qty_kg: '', rate: '', moved_on: todayIso, party: '', reference: '', notes: '' });
    const type = movementTypes[form.data.type];
    const item = items.find((i) => i.id === Number(form.data.feed_item_id));

    return (
        <Panel title="Stock in / out" description="Purchases also go into Expenses automatically.">
            <form onSubmit={(e) => { e.preventDefault(); form.post('/feeding/stock', { preserveScroll: true, onSuccess: () => { form.reset('qty_kg', 'rate', 'party', 'reference', 'notes'); onDone?.(); } }); }} className="space-y-3">
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
                        <thead className="bg-cobalt-100/50 text-left text-ink-soft"><tr>{['Date', 'Meal', 'PEN', 'Feed', 'Goats', 'Plan', 'Given', 'Leftover', 'Eaten', 'Per goat', 'Cost', 'By'].map((h) => <th key={h} className="px-3 py-2 font-semibold">{h}</th>)}</tr></thead>
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
            <div className="pb-2.5 text-sm font-semibold text-cobalt-900">{label}{settings.groupCodes?.[key] ? <span className="block font-mono text-xs font-normal text-ink-soft">{settings.groupCodes[key]}</span> : null}</div>
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
                <p className="pt-2 text-sm font-semibold text-cobalt-900">Fallback Crude Protein (CP) %</p>
                <p className="-mt-2 text-xs text-ink-soft">Used only for goats no Nutrition Standard fits. Each goat's CP target normally comes from Feeding → Nutrition Standards.</p>
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
function CpWarning({ mix, target, standard = null }: { mix: number | null; target: number | null; standard?: string | null }) {
    if (mix === null || target === null || mix >= target) return null;
    return (
        <p role="alert" className="mt-2 rounded-tile bg-crit-100 px-3 py-2 text-sm font-semibold text-crit-700">
            Crude protein too low: Mix CP {mix}% is {Math.round((target - mix) * 10) / 10}% below the {target}% target{standard ? ` for ${standard}` : ''}. Add a higher-CP feed or raise its share.
        </p>
    );
}

// ---------- D-116: Nutrition Standards ----------

const num = (v: number | null) => (v === null ? '' : String(v));

function Nutrition({ standards, nutritionCategories, methods, breeds, can }: Props) {
    const [editing, setEditing] = useState<number | 'new' | null>(null);
    const [showAll, setShowAll] = useState(false);
    const active = standards.filter((s) => s.active);
    const list = showAll ? standards : active;
    const total = active.reduce((a, s) => a + s.goats, 0);

    return (
        <div className="space-y-4">
            <Note>The CP (crude protein) the whole daily diet should carry, on a dry-matter basis, for each nutrition group. Every goat is matched to one by itself from its age, gender, kid stage, pregnancy (late = last {50} days) and purpose; a standard for its breed or weight range is preferred. A goat's CP need = its daily intake (dry matter) × the middle of the range, e.g. 30 KG × 3% = 0.9 KG × 14% = 126 g. Starting values are from the KK Farms CP chart: have them checked by a livestock nutritionist, especially for young kids, late-pregnant and lactating does.</Note>
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm text-ink-soft">{total} goats on {active.filter((s) => s.goats).length} standards</span>
                <label className="ml-auto flex items-center gap-2 text-sm text-ink-soft"><input type="checkbox" className="size-4" checked={showAll} onChange={(e) => setShowAll(e.target.checked)} /> Show inactive</label>
                {can.plan ? <AddButton label="Add Standard" onClick={() => setEditing('new')} /> : null}
            </div>
            {editing === 'new' ? <Sheet onClose={() => setEditing(null)}><Panel title="New Nutrition Standard"><StandardForm stacked nutritionCategories={nutritionCategories} methods={methods} breeds={breeds} onDone={() => setEditing(null)} /></Panel></Sheet> : null}
            <Panel flush title="CP Requirements" description="Feeding & Nutrition → Nutrition Standards">
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[64rem] text-sm">
                        <thead className="text-left text-xs text-ink-soft"><tr><th className="px-4 py-2 font-semibold">Nutrition Group</th><th className="px-3 font-semibold">Breed</th><th className="px-3 font-semibold">Weight Range</th><th className="px-3 font-semibold">Target CP</th><th className="px-3 font-semibold">Target Energy (TDN)</th><th className="px-3 font-semibold">Feeding</th><th className="px-3 font-semibold">Feed Formula</th><th className="px-3 font-semibold">Goats and PENs</th><th className="px-3 font-semibold">CP and Feed a Day</th><th className="px-3 font-semibold">Feed Cost per Day</th><th className="px-3 font-semibold">Last Review</th>{can.plan ? <th /> : null}</tr></thead>
                        <tbody className="divide-y divide-rule">
                            {list.map((s) => (
                                <Fragment key={s.id}>
                                    <tr className={cx(!s.active && 'opacity-60')}>
                                        <td className="px-4 py-2"><span className="font-semibold text-cobalt-900">{s.name}</span><span className="block text-xs text-ink-soft">{s.category ? s.purpose : 'Put on by hand'}{!s.active ? ' · inactive' : ''}</span></td>
                                        <td className="px-3">{s.breed ? <BreedBadge breed={s.breed} withName /> : <span className="text-ink-faint">Any</span>}</td>
                                        <td className="px-3 tabular-nums">{s.minKg === null && s.maxKg === null ? <span className="text-ink-faint">Any</span> : `${s.minKg ?? 0}–${s.maxKg ?? '∞'} KG`}</td>
                                        <td className="px-3"><span className="rounded-[4px] bg-turq-100 px-1.5 py-0.5 font-mono text-xs font-bold text-turq-800">CP {s.range}</span></td>
                                        <td className="px-3 tabular-nums">{s.tdnMin !== null ? `${s.tdnMin}${s.tdnMax !== null && s.tdnMax !== s.tdnMin ? `–${s.tdnMax}` : ''}%` : <span className="text-ink-faint">—</span>}</td>
                                        <td className="px-3 text-xs">{s.method ? methods[s.method] : '—'}{s.meals ? <span className="block text-ink-soft">{s.meals} times daily</span> : null}</td>
                                        <td className="px-3 font-mono text-xs">{s.formula ?? '—'}</td>
                                        <td className="px-3">{s.goats ? <><span className="font-semibold">{s.goats} goats</span><span className="block text-xs text-ink-soft">{Object.entries(s.pens).map(([p, n]) => `${p} (${n})`).join(', ')}</span></> : <span className="text-ink-faint">None now</span>}</td>
                                        <td className="px-3 tabular-nums">{s.goats ? <>{Math.round(s.cpKgDay * 1000).toLocaleString('en-PK')} g CP<span className="block text-xs text-ink-soft">{s.feedKgDay} KG feed</span></> : '—'}</td>
                                        <td className="px-3 tabular-nums">{s.feedCostDay !== null && s.goats ? pkr(s.feedCostDay) : <span className="text-ink-faint">—</span>}</td>
                                        <td className="px-3 text-xs">{s.reviewedOn ? <>{s.reviewedOn}{s.reviewedBy ? <span className="block text-ink-soft">{s.reviewedBy}</span> : null}</> : <span className="text-watch-700">Not reviewed</span>}</td>
                                        {can.plan ? <td className="px-3"><button type="button" className="text-cobalt-800 hover:underline" aria-label={`Edit ${s.name}`} onClick={() => setEditing(editing === s.id ? null : s.id)}><Pencil size={15} /></button><DeleteButton type="nutrition" id={s.id} name={s.name} /></td> : null}
                                    </tr>
                                    {editing === s.id ? <tr><td colSpan={12} className="bg-glaze/60 px-4 py-3"><StandardForm standard={s} nutritionCategories={nutritionCategories} methods={methods} breeds={breeds} onDone={() => setEditing(null)} /></td></tr> : null}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </div>
            </Panel>
            {can.plan ? <AssignForm standards={active} /> : null}
        </div>
    );
}

function StandardForm({ standard, nutritionCategories, methods, breeds, onDone, stacked = false }: { stacked?: boolean; standard?: Standard; nutritionCategories: Record<string, string>; methods: Record<string, string>; breeds: Props['breeds']; onDone: () => void }) {
    const form = useForm({
        name: standard?.name ?? '', category: standard?.category ?? '', breed_id: standard?.breedId ? String(standard.breedId) : '', min_weight_kg: num(standard?.minKg ?? null), max_weight_kg: num(standard?.maxKg ?? null),
        cp_min_pct: num(standard?.cpMin ?? null), cp_max_pct: num(standard?.cpMax ?? null), tdn_min_pct: num(standard?.tdnMin ?? null), tdn_max_pct: num(standard?.tdnMax ?? null),
        feeding_method: standard?.method ?? '', meals_per_day: num(standard?.meals ?? null), feed_formula: standard?.formula ?? '', purpose: standard?.purpose ?? '',
        reviewed_on: standard?.reviewedIso ?? '', active: standard?.active ?? true,
    });
    const e = form.errors as Record<string, string>;
    const input = (key: keyof typeof form.data, props: React.InputHTMLAttributes<HTMLInputElement> = {}) => <input className="field" value={String(form.data[key])} onChange={(ev) => form.setData(key, ev.target.value as never)} {...props} />;
    const submit = (ev: React.FormEvent) => {
        ev.preventDefault();
        form.transform((d) => ({ ...d, category: d.category || null, breed_id: d.breed_id || null, feeding_method: d.feeding_method || null }));
        form.post(standard ? `/feeding/standards/${standard.id}` : '/feeding/standards', { preserveScroll: true, onSuccess: onDone });
    };
    return (
        <form onSubmit={submit} className={stacked ? 'grid gap-3' : 'grid gap-3 sm:grid-cols-2 lg:grid-cols-4'}>
            <Field label="Nutrition Group" error={e.name}>{input('name', { placeholder: 'e.g. Growing Male (Makhi Cheeni)' })}</Field>
            <Field label="Matches Goats" error={e.category} hint="Empty = only goats put on it by hand"><select className="field" value={form.data.category} onChange={(ev) => form.setData('category', ev.target.value)}><option value="">By hand only</option>{Object.entries(nutritionCategories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            <Field label="Breed" error={e.breed_id}><select className="field" value={form.data.breed_id} onChange={(ev) => form.setData('breed_id', ev.target.value)}><option value="">Any breed</option>{breeds.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select></Field>
            <div className="grid grid-cols-2 gap-2"><Field label="From KG" error={e.min_weight_kg}>{input('min_weight_kg', { type: 'number', inputMode: 'decimal', step: '0.5', placeholder: 'Any' })}</Field><Field label="To KG" error={e.max_weight_kg}>{input('max_weight_kg', { type: 'number', inputMode: 'decimal', step: '0.5', placeholder: 'Any' })}</Field></div>
            <div className="grid grid-cols-2 gap-2"><Field label="CP From %" error={e.cp_min_pct}>{input('cp_min_pct', { type: 'number', inputMode: 'decimal', step: '0.5' })}</Field><Field label="CP To %" error={e.cp_max_pct}>{input('cp_max_pct', { type: 'number', inputMode: 'decimal', step: '0.5' })}</Field></div>
            <div className="grid grid-cols-2 gap-2"><Field label="TDN From %" error={e.tdn_min_pct}>{input('tdn_min_pct', { type: 'number', inputMode: 'decimal', step: '0.5', placeholder: 'e.g. 65' })}</Field><Field label="TDN To %" error={e.tdn_max_pct}>{input('tdn_max_pct', { type: 'number', inputMode: 'decimal', step: '0.5', placeholder: 'e.g. 70' })}</Field></div>
            <Field label="Feeding Method" error={e.feeding_method}><select className="field" value={form.data.feeding_method} onChange={(ev) => form.setData('feeding_method', ev.target.value)}><option value="">Not set</option>{Object.entries(methods).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
            <Field label="Feeding Frequency (Times Daily)" error={e.meals_per_day}>{input('meals_per_day', { type: 'number', min: 1, max: 8 })}</Field>
            <Field label="Feed Formula" error={e.feed_formula}>{input('feed_formula', { placeholder: 'e.g. TMR-GR-01', className: 'field font-mono uppercase' })}</Field>
            <Field label="Feeding Purpose" error={e.purpose}>{input('purpose')}</Field>
            <Field label="Last Nutrition Review" error={e.reviewed_on}>{input('reviewed_on', { type: 'date', max: new Date().toLocaleDateString('en-CA') })}</Field>
            <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" className="size-5" checked={form.data.active} onChange={(ev) => form.setData('active', ev.target.checked)} /> Active</label>
            <div className="flex gap-2 sm:col-span-2 lg:col-span-4"><Button type="submit" disabled={form.processing}>{standard ? 'Save Standard' : 'Add Standard'}</Button><Button type="button" variant="secondary" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}

function AssignForm({ standards }: { standards: Standard[] }) {
    const form = useForm({ standard_id: '', tags: '' });
    return (
        <Panel title="Put Goats on a Standard by Hand" description="For example a high-producing lactating doe. Choose 'Automatic' to match them by themselves again.">
            <form onSubmit={(ev) => { ev.preventDefault(); form.transform((d) => ({ ...d, standard_id: d.standard_id || null })); form.post('/feeding/standards/assign', { preserveScroll: true, onSuccess: () => form.reset('tags') }); }} className="grid gap-3 sm:grid-cols-[16rem_minmax(0,1fr)_auto] sm:items-end">
                <Field label="Standard" error={form.errors.standard_id}><select className="field" value={form.data.standard_id} onChange={(ev) => form.setData('standard_id', ev.target.value)}><option value="">Automatic</option>{standards.map((s) => <option key={s.id} value={s.id}>{s.name} (CP {s.range})</option>)}</select></Field>
                <Field label="Tag IDs" error={form.errors.tags}><input className="field font-mono" value={form.data.tags} onChange={(ev) => form.setData('tags', ev.target.value)} placeholder="MC181026-02, MC181026-11" /></Field>
                <Button type="submit" disabled={form.processing || !form.data.tags.trim()}>Save</Button>
            </form>
        </Panel>
    );
}
