import { Head, Link } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { saveGoatWalk } from '../../components/GoatStepper';
import { usePaged } from '../../components/Pager';
import { BreedBadge } from '../../components/GoatBadges';
import { cx, Empty, HealthChip, PageHeader, Panel, SexIcon } from '../../components/ui';
import type { GoatCard } from '../../types';
import { TempCell, WeightCell, type Vitals } from '../Goats/Index';

/**
 * D-348: the Veterinary app's main page. Only goats on the farm and only what the vet works on: cards that count and
 * filter by the vet's reasons (high or low temperature, open cases, doses due, weight loss, critical, Watch), a search
 * and a PEN filter, and one row per goat with its temperature, weight, health status and its cases, doses and reports.
 * A goat opens its page inside the Veterinary app (Previous / Next follow this list).
 */
type Kind = 'critical' | 'temp_high' | 'temp_low' | 'case' | 'dose' | 'weight_loss' | 'weight_low' | 'report' | 'watch' | 'treatment';
type Issue = [tone: 'crit' | 'warn' | 'info', text: string, kind: Kind];
type Goat = GoatCard & Vitals & { issues: Issue[] };

const ATTENTION: Kind[] = ['critical', 'temp_high', 'temp_low', 'case', 'dose', 'weight_loss']; // what puts a goat on the list
const CARDS: Array<{ key: string; label: string; kinds: Kind[]; bar: string }> = [
    { key: 'attention', label: 'Needs attention', kinds: ATTENTION, bar: 'bg-crit-500' },
    { key: 'temp_high', label: 'High temperature', kinds: ['temp_high'], bar: 'bg-crit-500' },
    { key: 'temp_low', label: 'Low temperature', kinds: ['temp_low'], bar: 'bg-treat-400' },
    { key: 'case', label: 'Open cases', kinds: ['case'], bar: 'bg-treat-400' },
    { key: 'dose', label: 'Doses due', kinds: ['dose'], bar: 'bg-watch-400' },
    { key: 'weight_loss', label: 'Lost 2.5% of weight', kinds: ['weight_loss'], bar: 'bg-watch-400' },
    { key: 'watch', label: 'On Watch', kinds: ['watch', 'treatment', 'critical'], bar: 'bg-watch-400' },
    { key: 'all', label: 'All goats', kinds: [], bar: 'bg-cobalt-600' },
];
const TONE = { crit: 'border-crit-500 bg-crit-100 text-crit-700', warn: 'border-treat-400 bg-treat-100 text-treat-700', info: 'border-rule-strong bg-glaze text-ink-soft' } as const;
const rank = (g: Goat) => g.issues.reduce((n, [t]) => n + (t === 'crit' ? 100 : t === 'warn' ? 10 : 1), 0);
const has = (g: Goat, kinds: Kind[]) => !kinds.length || g.issues.some(([, , k]) => kinds.includes(k));
const ROW = { high: 'bg-crit-500/30', low: 'bg-treat-400/40' } as const;

export default function VetGoats({ goats, pens }: { goats: Goat[]; pens: string[] }) {
    const params = typeof window === 'undefined' ? new URLSearchParams() : new URLSearchParams(window.location.search);
    const [card, setCard] = useState(() => params.get('show') ?? 'attention');
    const [query, setQuery] = useState(() => params.get('q') ?? '');
    const [pen, setPen] = useState(() => params.get('pen') ?? '');

    const counts = useMemo(() => Object.fromEntries(CARDS.map((c) => [c.key, goats.filter((g) => has(g, c.kinds)).length])), [goats]);
    const rows = useMemo(() => {
        const kinds = CARDS.find((c) => c.key === card)?.kinds ?? ATTENTION;
        const q = query.trim().toLowerCase();
        return goats.filter((g) => has(g, kinds) && (!pen || g.pen === pen) && (!q || [g.number, g.name, g.breed].some((v) => v?.toLowerCase().includes(q))))
            .sort((a, b) => rank(b) - rank(a) || a.number.localeCompare(b.number, undefined, { numeric: true }));
    }, [goats, card, query, pen]);
    const { rows: shown, pager } = usePaged(rows, { size: 25, id: 'vet-goats' });
    const serial = useMemo(() => new Map(rows.map((g, i) => [g.id, i + 1])), [rows]);
    const label = [CARDS.find((c) => c.key === card)?.label, pen, query.trim() && `“${query.trim()}”`].filter(Boolean).join(' · ');

    useEffect(() => { // the goat page steps through this list
        const qs = new URLSearchParams(Object.entries({ show: card === 'attention' ? '' : card, q: query.trim(), pen }).filter(([, v]) => v) as string[][]).toString();
        saveGoatWalk({ ids: rows.map((g) => g.id), numbers: rows.map((g) => g.number), label, back: `/health/goats${qs ? `?${qs}` : ''}` });
    }, [rows, card, query, pen, label]);

    return (
        <>
            <Head title="Goats to Check" />
            <PageHeader title="Goats to Check" description={`${rows.length} of ${goats.length} goats on the farm · ${label}`} />

            <div className="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-8" role="group" aria-label="Filter by reason">
                {CARDS.map((c) => (
                    <button key={c.key} type="button" aria-pressed={card === c.key} onClick={() => setCard(c.key)}
                        className={cx('relative flex min-h-[4.25rem] flex-col items-start justify-between overflow-hidden rounded-tile border px-3 py-2 text-start shadow-tile',
                            card === c.key ? 'border-cobalt-800 bg-cobalt-800 text-white' : 'border-rule bg-tile text-cobalt-900 hover:border-cobalt-200')}>
                        <span className="text-xl leading-none font-bold tabular-nums">{counts[c.key]}</span>
                        <span className={cx('text-xs font-semibold', card === c.key ? 'text-white/85' : 'text-ink-soft')}>{c.label}</span>
                        {card !== c.key ? <span aria-hidden className={cx('absolute inset-x-0 bottom-0 h-1', c.bar)} /> : null}
                    </button>
                ))}
            </div>

            <Panel flush>
                <div className="flex flex-wrap items-center gap-2 border-b border-rule p-3">
                    <div className="relative min-w-0 flex-1 sm:max-w-sm">
                        <Search size={16} aria-hidden className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-ink-faint" />
                        <input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Tag ID, name or breed" aria-label="Search" className="field pl-9" />
                    </div>
                    <select aria-label="PEN" value={pen} onChange={(e) => setPen(e.target.value)} className="field w-auto min-w-48">
                        <option value="">All PENs</option>
                        {pens.map((p) => <option key={p}>{p}</option>)}
                    </select>
                </div>
                {!rows.length ? (
                    <Empty title="No goats here">Nothing for the vet in this list.</Empty>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="data-table min-w-[900px] [&_td]:px-2.5 [&_td]:py-2 [&_th]:px-2.5">
                            <thead><tr><th className="w-10 text-right">#</th><th>Goat</th><th>Breed</th><th>Age</th><th>Temp</th><th>Weight</th><th>PEN</th><th>Health</th><th>Cases, doses &amp; reports</th></tr></thead>
                            <tbody>
                                {shown.map((g) => (
                                    <tr key={g.id} className={g.tempState === 'high' || g.tempState === 'low' ? ROW[g.tempState] : 'hover:bg-glaze'}>
                                        <td className="text-right font-mono text-sm text-ink-soft tabular-nums">{serial.get(g.id)}</td>
                                        <td className="whitespace-nowrap"><Link href={`/goats/${g.id}`} className="inline-flex items-center gap-1.5 font-mono font-semibold text-cobalt-900 hover:underline">{g.number}<SexIcon sex={g.sex} /></Link></td>
                                        <td><BreedBadge breed={g.breed} withName /></td>
                                        <td className="whitespace-nowrap text-sm">{g.ageCode ?? '—'}</td>
                                        <td><TempCell g={g} /></td>
                                        <td><WeightCell g={g} /></td>
                                        <td className="whitespace-nowrap font-mono text-sm">{g.pen ?? '—'}</td>
                                        <td><HealthChip health={g.health} /></td>
                                        <td>
                                            <div className="flex flex-wrap gap-1">
                                                {g.issues.filter(([, , k]) => !['temp_high', 'temp_low', 'watch', 'treatment', 'critical'].includes(k)).map(([tone, text], k) => (
                                                    <span key={k} className={cx('rounded border px-1.5 py-0.5 text-xs font-semibold whitespace-nowrap', TONE[tone])}>{text}</span>
                                                ))}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                {pager}
            </Panel>
        </>
    );
}
