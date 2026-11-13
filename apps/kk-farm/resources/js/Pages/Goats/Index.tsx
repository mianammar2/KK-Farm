import { canOpen } from '../../lib/apps';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Download, LayoutGrid, List, Plus, Search } from 'lucide-react';
import { useMemo, useState, type CSSProperties } from 'react';
import { inScannerApp } from '../../lib/native';
import GoatPortrait from '../../components/GoatPortrait';
import { RfidAttachSheet, RfidButton } from '../../components/RfidAttach';
import { AgeBadge, BreedBadge, GoatBadges } from '../../components/GoatBadges';
import { Button, cx, Empty, Field, GoatLabel, HealthChip, PageHeader, Panel, ReproChip, SexIcon } from '../../components/ui';
import type { GoatCard } from '../../types';

type Props = { goats: Array<GoatCard & { tags?: string[] }>; breeds: Record<string, string>; pens: Array<{ id: number; name: string; purpose: string }>; showLabels: Record<string, string>; roleNames?: Record<string, string>; teethOptions: Record<string, string>; teethShort?: Record<string, string>; canEdit: boolean; canTag?: boolean };
type SortKey = 'number' | 'name' | 'breed' | 'age' | 'weight' | 'pen';
type Goat = Props['goats'][number];

/**
 * D-089: one colour per status, used for the filter pills, the bar on each row and the card edge.
 * The most urgent status wins: left farm, critical, in treatment, watch, then pregnant, lactating, healthy.
 */
/** D-160: filter chips use the icon library. */
const CHIP_ART: Record<string, string> = { critical: 'ic-sick', treatment: 'ic-under-treatment', watch: 'ic-recovery', pregnant: 'ic-pregnant', lactating: 'ic-lactating', healthy: 'ic-healthy', left: 'ic-sold' };

const TONES = {
    critical: { label: 'Critical', bar: 'bg-crit-500', dot: 'bg-crit-500', pill: 'border-crit-500 bg-crit-100 text-crit-700', row: 'bg-crit-100/40' },
    treatment: { label: 'In treatment', bar: 'bg-treat-400', dot: 'bg-treat-400', pill: 'border-treat-400 bg-treat-100 text-treat-700', row: 'bg-treat-100/30' },
    watch: { label: 'Watch', bar: 'bg-watch-400', dot: 'bg-watch-400', pill: 'border-watch-400 bg-watch-100 text-watch-700', row: '' },
    pregnant: { label: 'Pregnant', bar: 'bg-manganese-600', dot: 'bg-manganese-600', pill: 'border-manganese-600 bg-manganese-100 text-manganese-600', row: '' },
    lactating: { label: 'Lactating', bar: 'bg-turq-600', dot: 'bg-turq-600', pill: 'border-turq-600 bg-turq-100 text-turq-800', row: '' },
    healthy: { label: 'Healthy', bar: 'bg-ok-700', dot: 'bg-ok-700', pill: 'border-ok-700 bg-ok-100 text-ok-700', row: '' },
    left: { label: 'Left farm', bar: 'bg-rule-strong', dot: 'bg-rule-strong', pill: 'border-rule-strong bg-glaze text-ink-soft', row: 'opacity-70' },
} as const;
type Tone = keyof typeof TONES;
const toneOf = (g: Goat): Tone => g.status !== 'active' ? 'left' : g.health !== 'healthy' ? g.health : g.repro === 'pregnant' ? 'pregnant' : g.repro === 'lactating' ? 'lactating' : 'healthy';
const LITE = inScannerApp();
const PAGE = LITE ? 25 : 60; // rows drawn at once; the gun draws fewer (D-089)
/** Rows rise in one after another (first 12 only), on the compositor; the gun gets a single quick fade. */
const rise = (i: number): CSSProperties => (LITE ? {} : { animationDelay: `${Math.min(i, 12) * 22}ms` });

export default function GoatsIndex({ goats, breeds, pens, showLabels, canEdit, canTag = false, roleNames = {} }: Props) {
    const [tagging, setTagging] = useState<Goat | null>(null); // D-144
    const url = usePage().url;
    const params = new URLSearchParams(url.split('?')[1] ?? '');
    const [query, setQuery] = useState(() => params.get('q') ?? '');
    // D-071: opened from a dashboard number or tile, e.g. /goats?show=pregnant or /goats?breed=Teddy
    const [show, setShow] = useState(() => params.get('show') ?? '');
    const [breed, setBreed] = useState(() => params.get('breed') ?? '');
    const [sex, setSex] = useState('');
    const [pen, setPen] = useState(() => params.get('pen') ?? ''); // D-120: opened from a pen on the dashboard
    const [filtersOpen, setFiltersOpen] = useState(false);
    const [health, setHealth] = useState('');
    const [status, setStatus] = useState('active');
    const activeFilters = [breed, sex, pen, health].filter(Boolean).length + (status !== 'active' ? 1 : 0);
    // Phones and the gun open on cards (a wide table needs sideways scrolling there); the choice is remembered.
    const [view, setViewState] = useState<'table' | 'cards'>(() => {
        try { const v = localStorage.getItem('kk.goats.view'); if (v === 'table' || v === 'cards') return v; } catch { /* storage off */ }
        return LITE || window.matchMedia('(max-width: 640px)').matches ? 'cards' : 'table';
    });
    const setView = (v: 'table' | 'cards') => { setViewState(v); try { localStorage.setItem('kk.goats.view', v); } catch { /* storage off */ } };
    const [tone, setTone] = useState<Tone | ''>(() => (params.get('tone') as Tone | null) ?? ''); // D-165: ?tone=healthy from the dashboard
    const [limit, setLimit] = useState(PAGE);
    const [sort, setSort] = useState<{ key: SortKey; up: boolean }>({ key: 'number', up: true });
    const [adding, setAdding] = useState(false);

    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();
        const list = goats.filter((g) =>
            (!q || [g.number, g.name, g.tag, g.breed, g.animalId].some((v) => v?.toLowerCase().includes(q))) &&
            (!show || (g.tags ?? []).includes(show)) && (!breed || g.breed === breed) && (!sex || g.sex === sex) && (!pen || g.pen === pen) && (!health || g.health === health) &&
            (status === 'all' || (status === 'active' ? g.status === 'active' : g.status !== 'active')) && (!tone || toneOf(g) === tone),
        );
        const value = (g: GoatCard): string | number => (sort.key === 'weight' ? g.weight ?? 0 : sort.key === 'age' ? g.ageMonths ?? -1 : (g[sort.key] ?? '') as string);
        return [...list].sort((a, b) => {
            const [x, y] = [value(a), value(b)];
            const order = typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), undefined, { numeric: true });
            return sort.up ? order : -order;
        });
    }, [goats, query, show, breed, sex, pen, health, status, sort, tone]);
    const counts = useMemo(() => goats.reduce((c, g) => { const t = toneOf(g); c[t] = (c[t] ?? 0) + 1; return c; }, {} as Partial<Record<Tone, number>>), [goats]);
    const shown = rows.slice(0, limit);
    const pickTone = (t: Tone | '') => { setTone(t); setLimit(PAGE); if (t === 'left') setStatus('all'); };

    const sortBy = (key: SortKey) => setSort((s) => ({ key, up: s.key === key ? !s.up : true }));
    const Th = ({ id, label }: { id: SortKey; label: string }) => (
        <th aria-sort={sort.key === id ? (sort.up ? 'ascending' : 'descending') : 'none'}>
            <button type="button" onClick={() => sortBy(id)} className="inline-flex items-center gap-1 hover:text-cobalt-900">
                {label}
                {sort.key === id ? (sort.up ? <ArrowUp size={13} aria-hidden /> : <ArrowDown size={13} aria-hidden />) : null}
            </button>
        </th>
    );
    const clear = () => { setQuery(''); setShow(''); setBreed(''); setSex(''); setPen(''); setHealth(''); setStatus('active'); setTone(''); };

    return (
        <>
            <Head title="Goat Registry" />
            <PageHeader
                title="Goat Registry"
                description={`${rows.length} of ${goats.length} goats shown. Goats that left the farm stay on record.`}
                actions={
                    <>
                        <a href="/goats/export" className="max-sm:hidden"><Button variant="secondary"><Download size={16} aria-hidden /> Export CSV</Button></a>
                        {canOpen('/goats/register') ? <Button onClick={() => router.visit('/goats/register')}><Plus size={16} aria-hidden /> Register goat</Button> : null}
                    </>
                }
            />

            {show && showLabels[show] ? (
                <div className="mb-3 flex flex-wrap items-center gap-2 rounded-tile border border-cobalt-200 bg-cobalt-100 px-3 py-2 text-sm">
                    <span className="text-ink-soft">Showing</span>
                    <span className="font-semibold text-cobalt-900">{showLabels[show]}</span>
                    <span className="text-ink-soft">· {rows.length} goat{rows.length === 1 ? '' : 's'}</span>
                    <button type="button" onClick={() => setShow('')} className="ml-auto font-semibold text-cobalt-800 hover:underline">Show all goats</button>
                </div>
            ) : null}

            {/* D-251: goats on Watch by how they are doing, so the ones not improving come first */}
            {['attention', 'not_improving', 'no_recheck', 'improving', 'recovered'].includes(show) ? (
                <div className="-mx-1 mb-3 flex gap-2 overflow-x-auto px-1 pb-1" role="group" aria-label="Goats on Watch by progress">
                    {([['not_improving', 'Not improving', 'border-crit-500 text-crit-700'], ['no_recheck', 'Waiting for a recheck', 'border-watch-400 text-watch-700'], ['improving', 'Improving', 'border-turq-600 text-turq-800'], ['recovered', 'Recovered (14 days)', 'border-ok-700 text-ok-700']] as const).map(([key, label, tone]) => {
                        const n = goats.filter((g) => (g.tags ?? []).includes(key)).length;
                        return (
                            <button key={key} type="button" aria-pressed={show === key} onClick={() => setShow(show === key ? 'attention' : key)}
                                className={cx('min-h-11 shrink-0 rounded-tile border-l-4 bg-tile px-3 py-1.5 text-start shadow-tile', tone, show === key && 'ring-2 ring-cobalt-800')}>
                                <span className="block text-lg font-bold tabular-nums">{n}</span><span className="text-xs font-semibold">{label}</span>
                            </button>
                        );
                    })}
                </div>
            ) : null}

            {adding ? <AddGoat breeds={breeds} pens={pens} onDone={() => setAdding(false)} /> : null}

            {/* D-089: status pills, each with its colour and count; tap to filter. */}
            <div className="-mx-1 mb-3 flex gap-1.5 overflow-x-auto px-1 pb-1" role="group" aria-label="Filter by status">
                <button type="button" aria-pressed={!tone} onClick={() => pickTone('')} className={cx('kk-pill shrink-0 rounded-full border px-3 py-1.5 text-sm font-semibold', !tone ? 'border-cobalt-800 bg-cobalt-800 text-white' : 'border-rule bg-tile text-cobalt-900')}>
                    All <span className="tabular-nums opacity-75">{goats.filter((g) => g.status === 'active').length}</span>
                </button>
                {(Object.keys(TONES) as Tone[]).filter((t) => counts[t]).map((t) => (
                    <button key={t} type="button" aria-pressed={tone === t} onClick={() => pickTone(tone === t ? '' : t)}
                        className={cx('kk-pill flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-semibold', tone === t ? `${TONES[t].pill} ring-2 ring-offset-1 ring-current` : 'border-rule bg-tile text-cobalt-900')}>
                        <img src={`/icons/${CHIP_ART[t]}.png`} alt="" aria-hidden className={cx('size-5 rounded-md bg-white object-contain', t === 'critical' && 'kk-beat')} />{TONES[t].label} <span className="tabular-nums opacity-75">{counts[t]}</span>
                    </button>
                ))}
            </div>

            <Panel flush>
                <div className="flex flex-wrap items-center gap-2 border-b border-rule p-3">
                    <div className="relative min-w-0 flex-1 sm:min-w-52">
                        <Search size={16} aria-hidden className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-faint" />
                        <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Number, name, breed or tag" aria-label="Search the registry" className="field pl-9" />
                    </div>
                    {/* Phones: the filters fold behind one button, so the goats come first. */}
                    <button type="button" onClick={() => setFiltersOpen(!filtersOpen)} aria-expanded={filtersOpen} className="flex min-h-11 items-center gap-1.5 rounded-tile border border-rule-strong px-3 text-sm font-semibold text-cobalt-900 sm:hidden">
                        Filters{activeFilters ? <span className="grid size-5 place-items-center rounded-full bg-cobalt-800 text-[11px] text-white">{activeFilters}</span> : null}
                    </button>
                    <div className={cx('contents', !filtersOpen && 'max-sm:hidden')}>
                    <select aria-label="Breed" value={breed} onChange={(e) => setBreed(e.target.value)} className="field min-w-0 flex-1 basis-[calc(50%-0.25rem)] sm:w-auto sm:flex-none sm:basis-auto">
                        <option value="">All breeds</option>
                        {Object.values(breeds).map((b) => <option key={b}>{b}</option>)}
                    </select>
                    <select aria-label="Gender" value={sex} onChange={(e) => setSex(e.target.value)} className="field min-w-0 flex-1 basis-[calc(50%-0.25rem)] sm:w-auto sm:flex-none sm:basis-auto">
                        <option value="">All genders</option><option value="female">Female</option><option value="male">Male</option>
                    </select>
                    <select aria-label="Pen" value={pen} onChange={(e) => setPen(e.target.value)} className="field min-w-0 flex-1 basis-[calc(50%-0.25rem)] sm:w-auto sm:flex-none sm:basis-auto">
                        <option value="">All pens</option>
                        {pens.map((p) => <option key={p.id} value={p.name}>{p.name}: {p.purpose}</option>)}
                    </select>
                    <select aria-label="Health" value={health} onChange={(e) => setHealth(e.target.value)} className="field min-w-0 flex-1 basis-[calc(50%-0.25rem)] sm:w-auto sm:flex-none sm:basis-auto">
                        <option value="">Any health</option><option value="healthy">Healthy</option><option value="watch">Watch</option><option value="treatment">In treatment</option><option value="critical">Critical</option>
                    </select>
                    <select aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)} className="field min-w-0 flex-1 basis-[calc(50%-0.25rem)] sm:w-auto sm:flex-none sm:basis-auto">
                        <option value="active">On farm</option><option value="left">Left the farm</option><option value="all">All records</option>
                    </select>
                    </div>
                    <div className="flex rounded-tile border border-rule-strong max-sm:hidden" role="group" aria-label="View">
                        <button type="button" aria-pressed={view === 'table'} aria-label="Table view" onClick={() => setView('table')} className={cx('grid size-11 place-items-center rounded-l-[5px]', view === 'table' ? 'bg-cobalt-100 text-cobalt-900' : 'text-ink-soft')}><List size={17} /></button>
                        <button type="button" aria-pressed={view === 'cards'} aria-label="Card view" onClick={() => setView('cards')} className={cx('grid size-11 place-items-center rounded-r-[5px]', view === 'cards' ? 'bg-cobalt-100 text-cobalt-900' : 'text-ink-soft')}><LayoutGrid size={17} /></button>
                    </div>
                </div>

                {!rows.length ? (
                    <Empty title="No goats match these filters"><button type="button" onClick={clear} className="font-semibold text-turq-800 underline">Clear filters</button> to see the whole registry.</Empty>
                ) : view === 'table' ? (
                    <div className="max-h-[70vh] overflow-auto">
                        <table className="data-table min-w-[900px]">
                            <thead><tr><Th id="name" label="Goat" /><Th id="breed" label="Breed" /><th>Gender</th><th>Role</th><Th id="age" label="Age" /><Th id="weight" label="Weight" /><Th id="pen" label="Pen" /><th>Status</th><th>Current Tag</th></tr></thead>
                            <tbody>
                                {shown.map((g, i) => {
                                    const t = toneOf(g);
                                    return (
                                        <tr key={g.id} style={rise(i)} className={cx('kk-rise transition-colors hover:bg-glaze', TONES[t].row)}>
                                            <td className="relative">
                                                <span aria-hidden className={cx('absolute inset-y-1.5 left-0 w-1 rounded-r-full', TONES[t].bar)} />
                                                <div className="flex flex-wrap items-center gap-1.5 pl-1"><GoatLabel goat={g} badges={false} />{canTag && g.status === 'active' ? <RfidButton goat={g} onOpen={() => setTagging(g)} /> : null}{t === 'critical' ? <span className="kk-beat size-2.5 shrink-0 rounded-full bg-crit-500" title="Critical" /> : null}</div>
                                            </td>
                                            <td><BreedBadge breed={g.breed} withName /></td>
                                            <td><SexIcon sex={g.sex} label /></td>
                                            <td>{g.roles?.length ? <div className="flex flex-col gap-0.5">{g.roles.map((r) => <span key={r} className="whitespace-nowrap text-xs"><b className="font-mono text-cobalt-800">{r}</b> <span className="text-ink-soft">{roleNames[r] ?? ''}</span></span>)}</div> : <span className="text-ink-faint">—</span>}</td>
                                            <td className="whitespace-nowrap"><span className="inline-flex items-center gap-1.5"><AgeBadge stage={g.stage} code={g.ageCode} withLabel />{g.ageExact ? g.age : !g.ageCode ? <span className="text-ink-faint">Unknown</span> : null}</span></td>
                                            <td className="whitespace-nowrap">{g.weight ? `${g.weight} KG` : <span className="text-ink-faint">None yet</span>}</td>
                                            <td className="font-mono text-sm">{g.pen ?? '—'}</td>
                                            <td><div className="flex flex-wrap gap-1">{g.status === 'active' ? <HealthChip health={g.health} /> : <span className="text-sm text-ink-soft">{g.status === 'voided' ? 'Removed (wrong entry)' : 'Left farm'}</span>}<ReproChip repro={g.repro} until={g.monitorUntil} /></div></td>
                                            <td className="text-sm text-ink-soft"><span className="flex items-center gap-1.5">{g.tag ? <span className="font-mono">{g.tag}</span> : 'No tag'}{canTag && g.status === 'active' ? <RfidButton goat={g} onOpen={() => setTagging(g)} /> : null}</span></td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div className="grid gap-2 p-2 sm:grid-cols-2 sm:gap-3 sm:p-3 lg:grid-cols-3 2xl:grid-cols-4">
                        {shown.map((g, i) => {
                            const t = toneOf(g);
                            return (
                                <Link key={g.id} href={`/goats/${g.id}`} style={rise(i)}
                                    className={cx('kk-rise kk-lift relative flex items-center gap-3 overflow-hidden rounded-tile border border-rule bg-tile py-2 pr-2.5 pl-3.5', TONES[t].row)}>
                                    <span aria-hidden className={cx('absolute inset-y-0 left-0 w-1.5', TONES[t].bar)} />
                                    {g.photo ? <img src={`/media/${g.photo}`} alt="" loading="lazy" className="size-14 shrink-0 rounded-tile object-cover" /> : <GoatPortrait number={g.number} sex={g.sex} stage={g.stage} repro={g.repro} size={56} />}
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-1.5"><span className="shrink-0 font-mono font-semibold text-cobalt-900">{g.number}</span><GoatBadges breed={g.breed} stage={g.stage} code={g.ageCode} />{canTag && g.status === 'active' ? <RfidButton goat={g} onOpen={() => setTagging(g)} /> : null}<SexIcon sex={g.sex} />{t === 'critical' ? <span className="kk-beat size-2 shrink-0 rounded-full bg-crit-500" /> : null}</div>
                                        {g.name ? <div className="truncate text-xs text-ink-soft">{g.name}</div> : null}
                                        <div className="truncate text-xs text-ink-soft">{g.breed} · {g.ageCode ? `${g.ageCode}${g.ageExact && g.age ? ` · ${g.age}` : ''}` : g.stage ? `${g.stage[0].toUpperCase()}${g.stage.slice(1)}` : 'age unknown'}{g.roles?.length ? ` · ${g.roles.join(' ')}` : ''}{g.weight ? ` · ${g.weight} KG` : ''}</div>
                                        <div className="mt-1 flex flex-wrap items-center gap-1">{g.status === 'active' ? <HealthChip health={g.health} /> : <span className="text-xs text-ink-soft">{g.status === 'voided' ? 'Removed (wrong entry)' : 'Left farm'}</span>}<ReproChip repro={g.repro} until={g.monitorUntil} />{g.pen ? <span className="font-mono text-[11px] text-ink-faint">{g.pen}</span> : null}</div>
                                    </div>
                                </Link>
                            );
                        })}
                    </div>
                )}
                {rows.length > shown.length ? (
                    <button type="button" onClick={() => setLimit((l) => l + PAGE)} className="w-full border-t border-rule px-4 py-3 text-sm font-semibold text-cobalt-800 hover:bg-glaze">
                        Show {Math.min(PAGE, rows.length - shown.length)} more <span className="font-normal text-ink-soft">({rows.length - shown.length} left)</span>
                    </button>
                ) : null}
            </Panel>
            {tagging ? <RfidAttachSheet goat={tagging} onClose={() => setTagging(null)} /> : null}
        </>
    );
}

function AddGoat({ breeds, pens, onDone }: { breeds: Props['breeds']; pens: Props['pens']; onDone: () => void }) {
    const form = useForm({ name: '', sex: 'female', breed_id: Object.keys(breeds)[0] ?? '', pen_id: String(pens[0]?.id ?? ''), birth_date: '', birth_date_is_estimate: false, origin: 'purchased' });

    return (
        <Panel title="Register a goat" description="The system issues the permanent identity. Assign an RFID tag afterwards." className="mb-4">
            <form onSubmit={(e) => { e.preventDefault(); form.post('/goats', { onSuccess: onDone }); }} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Field label="Name (optional)" error={form.errors.name}><input className="field" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field>
                <Field label="Gender" error={form.errors.sex}><select className="field" value={form.data.sex} onChange={(e) => form.setData('sex', e.target.value)}><option value="female">Female</option><option value="male">Male</option></select></Field>
                <Field label="Breed" error={form.errors.breed_id}><select className="field" value={form.data.breed_id} onChange={(e) => form.setData('breed_id', e.target.value)}>{Object.entries(breeds).map(([id, name]) => <option key={id} value={id}>{name}</option>)}</select></Field>
                <Field label="Birth date" error={form.errors.birth_date}><input type="date" className="field" value={form.data.birth_date} onChange={(e) => form.setData('birth_date', e.target.value)} /></Field>
                <Field label="Origin" error={form.errors.origin}><select className="field" value={form.data.origin} onChange={(e) => form.setData('origin', e.target.value)}><option value="purchased">Purchased</option><option value="born_on_farm">Born on farm</option></select></Field>
                <Field label="Pen" error={form.errors.pen_id}><select className="field" value={form.data.pen_id} onChange={(e) => form.setData('pen_id', e.target.value)}>{pens.map((p) => <option key={p.id} value={p.id}>{p.name}: {p.purpose}</option>)}</select></Field>
                <label className="flex items-center gap-2 text-sm sm:col-span-2 lg:col-span-3"><input type="checkbox" className="size-4 accent-turq-700" checked={form.data.birth_date_is_estimate} onChange={(e) => form.setData('birth_date_is_estimate', e.target.checked)} /> Birth date is an estimate</label>
                <div className="flex gap-2 sm:col-span-2 lg:col-span-3"><Button type="submit" disabled={form.processing}>Register goat</Button><Button variant="quiet" onClick={onDone}>Cancel</Button></div>
            </form>
        </Panel>
    );
}
