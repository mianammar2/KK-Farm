import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Download, LayoutGrid, List, Plus, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import GoatPortrait from '../../components/GoatPortrait';
import { Button, cx, Empty, Field, GoatLabel, HealthChip, PageHeader, Panel, ReproChip, SexIcon } from '../../components/ui';
import type { GoatCard } from '../../types';

type Props = { goats: Array<GoatCard & { tags?: string[] }>; breeds: Record<string, string>; pens: Array<{ id: number; name: string; purpose: string }>; showLabels: Record<string, string> };
type SortKey = 'number' | 'name' | 'breed' | 'age' | 'weight' | 'pen';

export default function GoatsIndex({ goats, breeds, pens, showLabels }: Props) {
    const url = usePage().url;
    const params = new URLSearchParams(url.split('?')[1] ?? '');
    const [query, setQuery] = useState(() => params.get('q') ?? '');
    // D-071: opened from a dashboard number or tile, e.g. /goats?show=pregnant or /goats?breed=Teddy
    const [show, setShow] = useState(() => params.get('show') ?? '');
    const [breed, setBreed] = useState(() => params.get('breed') ?? '');
    const [sex, setSex] = useState('');
    const [pen, setPen] = useState('');
    const [health, setHealth] = useState('');
    const [status, setStatus] = useState('active');
    const [view, setView] = useState<'table' | 'cards'>('table');
    const [sort, setSort] = useState<{ key: SortKey; up: boolean }>({ key: 'number', up: true });
    const [adding, setAdding] = useState(false);

    const rows = useMemo(() => {
        const q = query.trim().toLowerCase();
        const list = goats.filter((g) =>
            (!q || [g.number, g.name, g.tag, g.breed].some((v) => v?.toLowerCase().includes(q))) &&
            (!show || (g.tags ?? []).includes(show)) && (!breed || g.breed === breed) && (!sex || g.sex === sex) && (!pen || g.pen === pen) && (!health || g.health === health) &&
            (status === 'all' || (status === 'active' ? g.status === 'active' : g.status !== 'active')),
        );
        const value = (g: GoatCard): string | number => (sort.key === 'weight' ? g.weight ?? 0 : (g[sort.key] ?? '') as string);
        return [...list].sort((a, b) => {
            const [x, y] = [value(a), value(b)];
            const order = typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), undefined, { numeric: true });
            return sort.up ? order : -order;
        });
    }, [goats, query, show, breed, sex, pen, health, status, sort]);

    const sortBy = (key: SortKey) => setSort((s) => ({ key, up: s.key === key ? !s.up : true }));
    const Th = ({ id, label }: { id: SortKey; label: string }) => (
        <th aria-sort={sort.key === id ? (sort.up ? 'ascending' : 'descending') : 'none'}>
            <button type="button" onClick={() => sortBy(id)} className="inline-flex items-center gap-1 hover:text-cobalt-900">
                {label}
                {sort.key === id ? (sort.up ? <ArrowUp size={13} aria-hidden /> : <ArrowDown size={13} aria-hidden />) : null}
            </button>
        </th>
    );
    const clear = () => { setQuery(''); setShow(''); setBreed(''); setSex(''); setPen(''); setHealth(''); setStatus('active'); };

    return (
        <>
            <Head title="Goat Registry" />
            <PageHeader
                title="Goat Registry"
                description={`${rows.length} of ${goats.length} goats shown. Goats that left the farm stay on record.`}
                actions={
                    <>
                        <a href="/goats/export"><Button variant="secondary"><Download size={16} aria-hidden /> Export CSV</Button></a>
                        <Button onClick={() => router.visit('/goats/register')}><Plus size={16} aria-hidden /> Register goat</Button>
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

            {adding ? <AddGoat breeds={breeds} pens={pens} onDone={() => setAdding(false)} /> : null}

            <Panel flush>
                <div className="flex flex-wrap items-center gap-2 border-b border-rule p-3">
                    <div className="relative min-w-52 flex-1">
                        <Search size={16} aria-hidden className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-faint" />
                        <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Number, name, breed or tag" aria-label="Search the registry" className="field pl-9" />
                    </div>
                    <select aria-label="Breed" value={breed} onChange={(e) => setBreed(e.target.value)} className="field w-auto">
                        <option value="">All breeds</option>
                        {Object.values(breeds).map((b) => <option key={b}>{b}</option>)}
                    </select>
                    <select aria-label="Sex" value={sex} onChange={(e) => setSex(e.target.value)} className="field w-auto">
                        <option value="">Both sexes</option><option value="female">Female</option><option value="male">Male</option>
                    </select>
                    <select aria-label="Pen" value={pen} onChange={(e) => setPen(e.target.value)} className="field w-auto">
                        <option value="">All pens</option>
                        {pens.map((p) => <option key={p.id} value={p.name}>{p.name}: {p.purpose}</option>)}
                    </select>
                    <select aria-label="Health" value={health} onChange={(e) => setHealth(e.target.value)} className="field w-auto">
                        <option value="">Any health</option><option value="healthy">Healthy</option><option value="watch">Watch</option><option value="treatment">In treatment</option><option value="critical">Critical</option>
                    </select>
                    <select aria-label="Status" value={status} onChange={(e) => setStatus(e.target.value)} className="field w-auto">
                        <option value="active">On farm</option><option value="left">Left the farm</option><option value="all">All records</option>
                    </select>
                    <div className="flex rounded-tile border border-rule-strong" role="group" aria-label="View">
                        <button type="button" aria-pressed={view === 'table'} aria-label="Table view" onClick={() => setView('table')} className={cx('grid size-11 place-items-center rounded-l-[5px]', view === 'table' ? 'bg-cobalt-100 text-cobalt-900' : 'text-ink-soft')}><List size={17} /></button>
                        <button type="button" aria-pressed={view === 'cards'} aria-label="Card view" onClick={() => setView('cards')} className={cx('grid size-11 place-items-center rounded-r-[5px]', view === 'cards' ? 'bg-cobalt-100 text-cobalt-900' : 'text-ink-soft')}><LayoutGrid size={17} /></button>
                    </div>
                </div>

                {!rows.length ? (
                    <Empty title="No goats match these filters"><button type="button" onClick={clear} className="font-semibold text-turq-800 underline">Clear filters</button> to see the whole registry.</Empty>
                ) : view === 'table' ? (
                    <div className="max-h-[70vh] overflow-auto">
                        <table className="data-table min-w-[820px]">
                            <thead><tr><Th id="name" label="Goat" /><Th id="breed" label="Breed" /><th>Sex</th><Th id="age" label="Age" /><Th id="weight" label="Weight" /><Th id="pen" label="Pen" /><th>Status</th><th>Current tag</th></tr></thead>
                            <tbody>
                                {rows.map((g) => (
                                    <tr key={g.id}>
                                        <td><GoatLabel goat={g} /></td>
                                        <td>{g.breed}</td>
                                        <td><SexIcon sex={g.sex} /></td>
                                        <td className="whitespace-nowrap">{g.age}</td>
                                        <td className="whitespace-nowrap">{g.weight ? `${g.weight} KG` : 'None yet'}</td>
                                        <td>{g.pen ?? '—'}</td>
                                        <td><div className="flex flex-wrap gap-1">{g.status === 'active' ? <HealthChip health={g.health} /> : <span className="text-sm text-ink-soft">Left farm</span>}<ReproChip repro={g.repro} /></div></td>
                                        <td className="text-sm text-ink-soft">{g.tag ?? 'No open tag'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <div className="grid gap-3 p-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                        {rows.map((g) => (
                            <Link key={g.id} href={`/goats/${g.id}`} className="flex gap-3 rounded-tile border border-rule p-2.5 transition-colors hover:border-turq-600">
                                {g.photo ? <img src={`/media/${g.photo}`} alt="" loading="lazy" className="size-20 rounded-tile object-cover" /> : <GoatPortrait number={g.number} coat={g.coat} sex={g.sex} size={80} />}
                                <div className="min-w-0">
                                    <div className="truncate font-semibold text-cobalt-900">{g.name ?? 'Unnamed'}</div>
                                    <div className="truncate text-sm text-ink-soft">{g.number} · {g.breed}</div>
                                    <div className="truncate text-sm text-ink-soft">{g.age}{g.weight ? ` · ${g.weight} KG` : ''}</div>
                                    <div className="mt-1 flex flex-wrap gap-1"><HealthChip health={g.health} /><ReproChip repro={g.repro} /></div>
                                </div>
                            </Link>
                        ))}
                    </div>
                )}
            </Panel>
        </>
    );
}

function AddGoat({ breeds, pens, onDone }: { breeds: Props['breeds']; pens: Props['pens']; onDone: () => void }) {
    const form = useForm({ name: '', sex: 'female', breed_id: Object.keys(breeds)[0] ?? '', pen_id: String(pens[0]?.id ?? ''), birth_date: '', birth_date_is_estimate: false, origin: 'purchased' });

    return (
        <Panel title="Register a goat" description="The system issues the permanent identity. Assign an RFID tag afterwards." className="mb-4">
            <form onSubmit={(e) => { e.preventDefault(); form.post('/goats', { onSuccess: onDone }); }} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Field label="Name (optional)" error={form.errors.name}><input className="field" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field>
                <Field label="Sex" error={form.errors.sex}><select className="field" value={form.data.sex} onChange={(e) => form.setData('sex', e.target.value)}><option value="female">Female</option><option value="male">Male</option></select></Field>
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
