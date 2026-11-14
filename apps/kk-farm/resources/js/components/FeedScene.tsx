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

/** D-146: a feed total as a modern tile: an icon, the period and a digital readout. */
function FeedSack({ sack, index }: { sack: Sack; index: number }) {
    const green = sack.tone === 'green';
    return (
        <div className="kk-sack kk-rise" style={{ animationDelay: `${index * 60}ms` }}>
            <div className="flex items-center gap-2">
                <span className="kk-icon-tile size-10!" aria-hidden><img src={`/icons/${green ? 'app-growth' : ['app-feeding', 'app-inventory', 'app-reports'][index] ?? 'app-reports'}.png`} alt="" className="size-[86%] object-contain" /></span>
                <span className="kk-sack-tag truncate">{sack.label}</span>
            </div>
            <span className="kk-lcd self-start">{sack.value}</span>
            {sack.note ? <span className="text-xs leading-tight text-[#4d6356]">{sack.note}</span> : null}
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
                <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2 px-1 text-sm text-[#3d5446]">
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

/** D-146: a pen's feeder: its sign, goats, a fill bar sized by its share of the shed's feed, and a digital KG/day readout. */
function Trough({ pen, share, tracking }: { pen: FeedPen; share: number; tracking: boolean }) {
    const fill = Math.round(Math.max(0.06, Math.min(1, share)) * 100);
    const code = pen.name.split('-').pop() ?? pen.name;
    return (
        <Link href={pen.id ? `/feeding?tab=requirement&pen=${pen.id}` : '/feeding?tab=requirement'} className="kk-pen kk-lift" title={`${pen.name}: ${pen.kgDay} KG a day`}>
            <div className="flex items-center justify-between gap-1">
                <span className="kk-pen-sign">{code}</span>
                <span className="text-xs font-semibold text-[#3d5446]">{plural(pen.count, 'goat', 'goats')}</span>
            </div>
            <div className="mt-2 flex items-center gap-2">
                <span className="kk-icon-tile size-10!" aria-hidden><img src="/icons/mod-feeding.png" alt="" className="size-[86%] object-contain" /></span>
                <span className="kk-kg-sign"><CountUp to={pen.kgDay} /> KG/day</span>
            </div>
            <div className="mt-2 h-2 overflow-hidden rounded-full bg-[#e9efeb]" role="img" aria-label={`${fill}% of the shed's biggest PEN`}>
                <div className="kk-grow h-full rounded-full bg-gradient-to-r from-[#12663f] to-[#6fe39a]" style={{ width: `${fill}%` }} />
            </div>
            <span className="mt-1 block text-xs text-[#3d5446]">
                {[pen.cpDay ? `CP ${Math.round(pen.cpDay * 1000).toLocaleString('en-PK')} g` : null, pen.unplanned ? `${pen.unplanned} not planned` : null].filter(Boolean).join(' · ')}
            </span>
            {tracking ? <BalanceTag balance={pen.balance} diff={pen.diff} className="mt-1" /> : null}
        </Link>
    );
}
