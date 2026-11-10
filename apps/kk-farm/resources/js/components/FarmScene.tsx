import { Link } from '@inertiajs/react';
import { useFive } from '../lib/useFive';
import { BreedBadge } from './GoatBadges';
import { CountUp } from './ui';

export type SceneFigure = { female: boolean; stage: string | null; breed: string | null; flag: boolean };
export type ScenePen = { id: number; name: string; code: string; capacity: number | null; goats: number; female: number; male: number; attention: number; figures?: SceneFigure[] };
export type SceneShed = { id: number; name: string; typeName: string | null; goats: number; pens: ScenePen[] };

const MAX_FIGURES = 18; // more than this shows "+N" so a big pen stays readable

/**
 * D-133: the dashboard's sheds drawn like the real farm: a goat barn with a tin roof and its name board, a straw floor
 * with each pen as a wooden-fenced enclosure (feed trough and water bucket), and a figure for each goat in it:
 * cream for a female, brown for a male, a red tag for one that needs attention. A pen opens its goats.
 */
export default function FarmScene({ sheds }: { sheds: SceneShed[] }) {
    const [shown, more] = useFive(sheds);
    return (
        <div className="space-y-5 p-3 sm:p-4">
            {shown.map((s, i) => <Barn key={s.id} shed={s} index={i} />)}
            {more ? <div className="-mx-3 sm:-mx-4">{more}</div> : null}
            <FigureLegend breeds={sheds.flatMap((s) => s.pens.flatMap((p) => (p.figures ?? []).map((f) => f.breed)))} />
        </div>
    );
}

function Barn({ shed, index }: { shed: SceneShed; index: number }) {
    const [pens, more] = useFive(shed.pens);
    const capacity = shed.pens.reduce((n, p) => n + (p.capacity ?? 0), 0);
    return (
        <section className="kk-barn kk-rise" style={{ animationDelay: `${index * 70}ms` }} aria-label={`${shed.name}: ${shed.goats} goats`}>
            <BarnRoof id={shed.id} name={shed.name} typeName={shed.typeName} />
            {/* Inside: posts at both ends, straw floor, the pens. */}
            <div className="kk-barn-body">
                <div className="mb-2 flex flex-wrap items-baseline justify-between gap-2 px-1 text-sm">
                    <span />
                    <span className="font-semibold text-[#3d5446]"><CountUp to={shed.goats} /> goats{capacity ? <span className="font-normal opacity-75"> of {capacity}</span> : null}</span>
                </div>
                <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                    {pens.map((p) => <PenYard key={p.id} pen={p} />)}
                </div>
                {more ? <div className="mt-2 overflow-hidden rounded-md bg-tile/80">{more}</div> : null}
            </div>
            <div className="kk-grass" aria-hidden />
        </section>
    );
}

function PenYard({ pen }: { pen: ScenePen }) {
    const full = pen.capacity !== null && pen.goats > pen.capacity;
    const figures = Math.min(pen.goats, MAX_FIGURES);
    // Females first, then males, in the same proportion as the pen when it is cut at MAX_FIGURES.
    const females = pen.goats ? Math.round((pen.female / pen.goats) * figures) : 0;
    const flagged = Math.min(pen.attention, figures);
    return (
        <Link href={`/goats?pen=${encodeURIComponent(pen.name)}`} className="kk-pen kk-lift group" title={`${pen.name}: ${pen.goats} goats`}>
            <div className="flex items-center justify-between gap-1">
                <span className="kk-pen-sign">{pen.code}</span>
                <span className={`text-sm font-bold tabular-nums ${full ? 'text-crit-700' : 'text-[#022a1c]'}`}>{pen.goats}{pen.capacity !== null ? <span className="text-xs font-semibold opacity-70">/{pen.capacity}</span> : null}</span>
            </div>
            <div className="kk-herd" aria-hidden>
                {pen.figures?.length
                    ? pen.figures.map((f, i) => <GoatFigure key={i} female={f.female} flag={f.flag} stage={f.stage} breed={f.breed} delay={(i * 0.37) % 2.4} flip={i % 3 === 1} />)
                    : Array.from({ length: figures }, (_, i) => <GoatFigure key={i} female={i < females} flag={i < flagged} delay={(i * 0.37) % 2.4} flip={i % 3 === 1} />)}
                {pen.goats > figures ? <span className="self-center text-xs font-bold text-[#3d5446]">+{pen.goats - figures}</span> : null}
            </div>
            <div className="mt-1 flex items-center gap-1.5" aria-hidden>
                <span className="kk-trough" />
                <svg viewBox="0 0 12 12" className="size-3.5 shrink-0"><path d="M2 3h8l-1 8H3z" fill="#5f87a8" /><rect x="1.5" y="2" width="9" height="1.6" rx=".8" fill="#46667f" /></svg>
            </div>
            <span className="sr-only">{pen.female} female, {pen.male} male{pen.attention ? `, ${pen.attention} need attention` : ''}</span>
            {pen.attention ? <span className="mt-1 block text-[11px] font-semibold text-crit-700">{pen.attention} need attention</span> : null}
        </Link>
    );
}

export type Stage = 'kid' | 'grower' | 'adult';

/**
 * D-147: what each breed looks like: coat, head, patches and ear length. Names match the breed register,
 * case-insensitively; an unknown breed gets a neutral coat (lighter for a female, darker for a male).
 */
export const BREED_LOOK: Record<string, { coat: string; head?: string; spots?: string; ears: 'long' | 'short'; label: string }> = {
    beetal: { coat: '#4a3426', spots: '#efe2cc', ears: 'long', label: 'Beetal' },
    kamori: { coat: '#7a4527', spots: '#d9b38c', ears: 'long', label: 'Kamori' },
    'dera din panah': { coat: '#1c1a19', ears: 'long', label: 'Dera Din Panah' },
    boer: { coat: '#f6f2ea', head: '#9b4a2a', ears: 'long', label: 'Boer' },
    teddy: { coat: '#262626', spots: '#f4f1ea', ears: 'short', label: 'Teddy' },
    nachi: { coat: '#3a2d26', head: '#1c1a19', ears: 'short', label: 'Nachi' },
    crossbred: { coat: '#b9844f', spots: '#f4ead8', ears: 'short', label: 'Crossbred' },
};
export const breedLook = (breed?: string | null) => (breed ? BREED_LOOK[breed.trim().toLowerCase()] : undefined);

/**
 * D-155: a goat in a pen is the KK Farms icon-library picture for what it is: Breeding Male (BKM) for a buck,
 * Breeding Female (BDF) for a doe, Grower (GRW), Kid (KID). The red Sick sign marks one that needs attention.
 */
export function goatArt(female: boolean, stage?: string | null) {
    return stage === 'kid' ? 'cls-kid' : stage === 'grower' ? 'cls-grw' : female ? 'cls-bdf' : 'cls-bkm';
}

export function GoatFigure({ female, flag, delay, stage = 'adult' }: { female: boolean; flag: boolean; delay: number; flip?: boolean; stage?: Stage | string | null; breed?: string | null }) {
    return (
        <span className={`kk-goat kk-goat-pic ${female ? 'kk-goat-f' : 'kk-goat-m'}`} style={{ animationDelay: `${delay}s` }}>
            <img src={`/icons/${goatArt(female, stage)}.png`} alt="" decoding="async" loading="lazy" />
            {flag ? <img src="/icons/ic-sick.png" alt="" className="kk-goat-flag" /> : null}
        </span>
    );
}

/** D-155: what the pictures mean (from the icon library) and the symbol of each breed on the farm. */
export function FigureLegend({ breeds = [] }: { breeds?: Array<string | null | undefined> }) {
    const item = (art: string, label: string) => (
        <span key={art} className="inline-flex items-center gap-1.5"><img src={`/icons/${art}.png`} alt="" className="kk-legend-pic" />{label}</span>
    );
    const present = [...new Set(breeds.filter(Boolean).map((b) => b!.trim()))].sort();
    return (
        <div className="space-y-2 px-1 text-xs font-semibold text-[#3d5446]">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                {item('cls-bdf', 'Female (Doe)')}
                {item('cls-bkm', 'Male (Buck)')}
                {item('cls-grw', 'Grower')}
                {item('cls-kid', 'Kid')}
                {item('ic-sick', 'Needs Attention')}
            </div>
            {present.length ? (
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <span>Breeds:</span>
                    {present.map((b) => <BreedBadge key={b} breed={b} withName />)}
                </div>
            ) : null}
        </div>
    );
}

/** D-133/D-134: the barn roof (corrugated tin on a pitched frame) with the shed's name board; shared by the farm scenes. */
export function BarnRoof({ id, name, typeName }: { id: number | string; name: string; typeName: string | null }) {
    // D-146: the shed's header bar: a green band with a building icon tile, the shed name and its unit type.
    return (
        <div className="kk-roof" data-shed={id}>
            <span className="kk-roof-icon kk-roof-pic" aria-hidden><img src="/icons/mod-housing.png" alt="" /></span>
            <span className="min-w-0">
                <span className="block truncate font-mono text-base font-bold tracking-wide">{name}</span>
                {typeName ? <span className="block truncate text-xs text-white/80">{typeName}</span> : null}
            </span>
        </div>
    );
}

/** D-138: a yellow plastic ear tag with the goat's tag number, as on the real goats. */
export function EarTag({ number }: { number: string }) {
    return (
        <span className="kk-eartag" title={number}>
            <span className="kk-eartag-hole" aria-hidden />
            <span className="font-mono text-xs font-extrabold tracking-tight">{number}</span>
        </span>
    );
}
