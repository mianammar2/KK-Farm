import { Link } from '@inertiajs/react';
import { useFive } from '../lib/useFive';
import { BarnRoof } from './FarmScene';
import { BalanceTag, type Balance } from './FeedRequirement';
import { CountUp } from './ui';

type FeedPen = { id: number | null; name: string; count: number; kgDay: number; cpDay: number; unplanned: number; balance: Balance; diff: number };
type FeedShed = { id: number | null; name: string; typeName: string | null; kgDay: number; cpDay: number; count: number; pens: FeedPen[] };
export type Sack = { label: string; value: string; note?: string; tone?: 'grain' | 'green' };

const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

/**
 * D-134: the dashboard's feed requirement drawn like the farm's feed store: the totals are feed sacks, each shed is the
 * barn again, and each pen is a wooden trough heaped with fodder as large as its share of the shed's feed, with a sign
 * giving its KG a day. A pen opens its goats on Feeding → Requirement.
 */
export default function FeedScene({ sacks, sheds, tracking }: { sacks: Sack[]; sheds: FeedShed[]; tracking: boolean }) {
    const [shown, more] = useFive(sheds);
    return (
        <div className="space-y-5 p-3 sm:p-4">
            <div className="kk-store">
                {sacks.map((s, i) => <FeedSack key={s.label} sack={s} index={i} />)}
            </div>
            {shown.map((sh, i) => <FeedBarn key={sh.id ?? 'none'} shed={sh} tracking={tracking} index={i} />)}
            {more ? <div className="-mx-3 sm:-mx-4">{more}</div> : null}
        </div>
    );
}

/** A tied burlap sack with a paper tag: what the farm needs for that period. */
function FeedSack({ sack, index }: { sack: Sack; index: number }) {
    const green = sack.tone === 'green';
    return (
        <div className="kk-sack kk-rise" style={{ animationDelay: `${index * 60}ms` }}>
            <svg viewBox="0 0 120 130" className="absolute inset-0 size-full" preserveAspectRatio="none" aria-hidden>
                <defs>
                    <pattern id={`burlap-${index}`} width="6" height="6" patternUnits="userSpaceOnUse">
                        <rect width="6" height="6" fill={green ? '#b9c48a' : '#cfae78'} />
                        <path d="M0 3h6M3 0v6" stroke={green ? '#9eab6c' : '#b8935c'} strokeWidth="1" />
                    </pattern>
                </defs>
                {/* body bulging at the bottom, gathered at the neck, the tied tuft on top */}
                <path d="M30 22 Q18 40 10 80 Q6 118 26 126 Q60 132 94 126 Q114 118 110 80 Q102 40 90 22 Z" fill={`url(#burlap-${index})`} stroke={green ? '#6f7d43' : '#8a6a3a'} strokeWidth="2" />
                <path d="M34 14 Q60 4 86 14 L90 22 Q60 30 30 22 Z" fill={green ? '#a7b476' : '#c19d66'} stroke={green ? '#6f7d43' : '#8a6a3a'} strokeWidth="2" />
                <rect x="28" y="19" width="64" height="5" rx="2.5" fill="#7b5530" />
                <path d="M22 60 Q60 66 98 60" stroke={green ? '#6f7d43' : '#8a6a3a'} strokeWidth="1.5" strokeDasharray="4 3" fill="none" />
            </svg>
            <div className="relative flex h-full flex-col items-center justify-center px-2 pt-5 text-center">
                <span className="kk-sack-tag">{sack.label}</span>
                <span className="mt-0.5 text-base font-bold tabular-nums text-[#3b2e1a]">{sack.value}</span>
                {sack.note ? <span className="max-w-[78%] text-[11px] font-semibold leading-tight text-[#4a3a22]/80">{sack.note}</span> : null}
            </div>
        </div>
    );
}

function FeedBarn({ shed, tracking, index }: { shed: FeedShed; tracking: boolean; index: number }) {
    const [pens, more] = useFive(shed.pens);
    const biggest = Math.max(...shed.pens.map((p) => p.kgDay), 0.001);
    return (
        <section className="kk-barn kk-rise" style={{ animationDelay: `${index * 70}ms` }} aria-label={`${shed.name}: ${shed.kgDay} KG a day`}>
            <BarnRoof id={`feed-${shed.id ?? 'none'}`} name={shed.name} typeName={shed.typeName} />
            <div className="kk-barn-body">
                <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2 px-1 text-sm text-[#4a3a22]">
                    <span className="font-semibold">{plural(shed.count, 'goat', 'goats')}</span>
                    <span className="font-semibold"><CountUp to={shed.kgDay} /> KG/day{shed.cpDay ? <span className="font-normal opacity-75"> · CP {shed.cpDay} KG</span> : null}</span>
                </div>
                <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                    {pens.map((p) => <Trough key={p.id ?? 'none'} pen={p} share={p.kgDay / biggest} tracking={tracking} />)}
                </div>
                {more ? <div className="mt-2 overflow-hidden rounded-md bg-tile/80">{more}</div> : null}
            </div>
            <div className="kk-grass" aria-hidden />
        </section>
    );
}

/** A wooden trough on legs; the heap of fodder grows with the pen's share of the shed's feed. */
function Trough({ pen, share, tracking }: { pen: FeedPen; share: number; tracking: boolean }) {
    const heap = 6 + Math.round(Math.max(0, Math.min(1, share)) * 16); // 6..22 px of fodder above the rim
    const code = pen.name.split('-').pop() ?? pen.name;
    return (
        <Link href={pen.id ? `/feeding?tab=requirement&pen=${pen.id}` : '/feeding?tab=requirement'} className="kk-pen kk-lift" title={`${pen.name}: ${pen.kgDay} KG a day`}>
            <div className="flex items-center justify-between gap-1">
                <span className="kk-pen-sign">{code}</span>
                <span className="text-xs font-semibold text-[#4a3a22]">{plural(pen.count, 'goat', 'goats')}</span>
            </div>
            <svg viewBox="0 0 120 52" className="mt-1.5 block h-12 w-full" aria-hidden>
                {/* fodder heap: green chopped fodder over grain */}
                <path d={`M14 ${30 - heap} Q60 ${22 - heap * 1.4} 106 ${30 - heap} L106 30 L14 30 Z`} fill="#8fb157" />
                <path d={`M20 ${30 - heap * 0.55} Q60 ${26 - heap * 0.9} 100 ${30 - heap * 0.55} L100 30 L20 30 Z`} fill="#d6b46a" />
                {Array.from({ length: 9 }, (_, i) => <line key={i} x1={22 + i * 9} y1={30 - heap * 0.7} x2={26 + i * 9} y2={30 - heap * 0.9 - (i % 3)} stroke="#6f923f" strokeWidth="1.6" strokeLinecap="round" />)}
                {/* trough box, planks and legs */}
                <path d="M8 28 H112 L104 44 H16 Z" fill="#8d6238" stroke="#5a3b1e" strokeWidth="2" strokeLinejoin="round" />
                <line x1="12" y1="34" x2="108" y2="34" stroke="#6e4624" strokeWidth="1.5" />
                <line x1="14" y1="39" x2="106" y2="39" stroke="#6e4624" strokeWidth="1.5" />
                <rect x="20" y="43" width="6" height="8" rx="1" fill="#5a3b1e" />
                <rect x="94" y="43" width="6" height="8" rx="1" fill="#5a3b1e" />
            </svg>
            <div className="mt-1 text-center">
                <span className="kk-kg-sign"><CountUp to={pen.kgDay} /> KG/day</span>
            </div>
            <span className="mt-1 block text-center text-[11px] text-[#4a3a22]">
                {[pen.cpDay ? `CP ${Math.round(pen.cpDay * 1000).toLocaleString('en-PK')} g` : null, pen.unplanned ? `${pen.unplanned} not planned` : null].filter(Boolean).join(' · ')}
            </span>
            {tracking ? <div className="text-center"><BalanceTag balance={pen.balance} diff={pen.diff} className="mt-1" /></div> : null}
        </Link>
    );
}
