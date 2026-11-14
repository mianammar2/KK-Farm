import { Link } from '@inertiajs/react';
import { useFive } from '../lib/useFive';
import { AgeBadge, BreedBadge } from './GoatBadges';
import { useMemo, useState, useSyncExternalStore, type ReactNode } from 'react';
import { CountUp, HealthChip, SexIcon } from './ui';
import type { Health } from '../types';
import { COLLAR, type Cls } from '../lib/shedSim';
import Shed3D, { goatClass, type ShedPen } from './Shed3D';

export type SceneFigure = { female: boolean; stage: string | null; code?: string | null; breed: string | null; flag: boolean; id?: number; number?: string; name?: string | null; health?: Health };

/** D-156: the goat under the pointer, shown in the legend area under the sheds. */
export type HoverGoat = { id?: number | string; number?: string; name?: string | null; breed?: string | null; female: boolean; stage?: string | null; code?: string | null; health?: Health; pen?: string };
let hovered: HoverGoat | null = null;
const listeners = new Set<() => void>();
export function hoverGoat(g: HoverGoat | null) { hovered = g; listeners.forEach((l) => l()); }
function useHovered() { return useSyncExternalStore((l) => { listeners.add(l); return () => { listeners.delete(l); }; }, () => hovered, () => null); }
export type ScenePen = { id: number; name: string; code: string; capacity: number | null; goats: number; female: number; male: number; attention: number; figures?: SceneFigure[] };
export type SceneShed = { id: number; name: string; typeName: string | null; goats: number; pens: ScenePen[] };


/**
 * D-133: the dashboard's sheds drawn like the real farm: a goat barn with a tin roof and its name board, a straw floor
 * with each pen as a wooden-fenced enclosure (feed trough and water bucket), and a figure for each goat in it:
 * cream for a female, brown for a male, a red tag for one that needs attention. A pen opens its goats.
 */
export default function FarmScene({ sheds, meals, clock }: { sheds: SceneShed[]; meals?: string[]; clock?: string | null }) {
    const [shown, more] = useFive(sheds);
    return (
        <div className="space-y-5 p-3 sm:p-4">
            {shown.map((s, i) => <Barn key={s.id} shed={s} index={i} meals={meals} clock={clock} />)}
            {more ? <div className="-mx-3 sm:-mx-4">{more}</div> : null}
            <FigureLegend collars breeds={sheds.flatMap((s) => s.pens.flatMap((p) => (p.figures ?? []).map((f) => f.breed)))} />
        </div>
    );
}

/** The goats of a pen: from the server, or made up from the counts (females first, those needing attention first). */
const figuresOf = (pen: ScenePen): SceneFigure[] => (pen.figures?.length ? pen.figures
    : Array.from({ length: pen.goats }, (_, i) => ({ female: i < pen.female, flag: i < pen.attention, stage: null, breed: null })));

function Barn({ shed, index, meals, clock }: { shed: SceneShed; index: number; meals?: string[]; clock?: string | null }) {
    const [pens, more] = useFive(shed.pens);
    // D-244: every goat of every pen walks about in the 3D shed.
    const pens3d = useMemo<ShedPen[]>(() => shed.pens.map((p) => ({
        id: p.id, code: p.code, name: p.name, capacity: p.capacity,
        goats: figuresOf(p).map((f, i) => ({ key: f.id !== undefined ? String(f.id) : `${p.id}-${i}`, cls: goatClass(f.female, f.stage), attention: f.flag, info: f.number ? f : undefined })),
    })), [shed.pens]);
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
                <Shed3D pens={pens3d} meals={meals} clock={clock} label={shed.name} penHref={(p) => `/goats?PEN=${encodeURIComponent(p.name)}`}>
                    {(ok) => ok ? (
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {pens.map((p) => <PenChip key={p.id} pen={p} />)}
                        </div>
                    ) : (
                        <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                            {pens.map((p) => <PenYard key={p.id} pen={p} />)}
                        </div>
                    )}
                </Shed3D>
                {more ? <div className="mt-2 overflow-hidden rounded-md bg-tile/80">{more}</div> : null}
            </div>
            <div className="kk-grass" aria-hidden />
        </section>
    );
}

/** D-244: under the 3D shed, each pen as a link to its goats: code, goats of capacity, how many need attention. */
function PenChip({ pen }: { pen: ScenePen }) {
    const full = pen.capacity !== null && pen.goats > pen.capacity;
    return (
        <Link href={`/goats?pen=${encodeURIComponent(pen.name)}`} className="kk-pen-chip kk-lift" title={`${pen.name}: ${pen.goats} goats`}>
            <span className="kk-pen-sign">{pen.code}</span>
            <span className={`text-sm font-bold tabular-nums ${full ? 'text-crit-700' : 'text-[#022a1c]'}`}>{pen.goats}{pen.capacity !== null ? <span className="text-xs font-semibold opacity-70">/{pen.capacity}</span> : null}</span>
            {pen.attention ? <span className="text-xs font-semibold text-crit-700">{pen.attention} need attention</span> : null}
            <span className="sr-only">{pen.female} female, {pen.male} male</span>
        </Link>
    );
}

function PenYard({ pen }: { pen: ScenePen }) {
    const full = pen.capacity !== null && pen.goats > pen.capacity;
    const all = figuresOf(pen);
    const [shown, more] = useTen(all);
    return (
        <div className="kk-pen kk-lift group">
            <Link href={`/goats?pen=${encodeURIComponent(pen.name)}`} className="block" title={`${pen.name}: ${pen.goats} goats`}>
                <div className="flex items-center justify-between gap-1">
                    <span className="kk-pen-sign">{pen.code}</span>
                    <span className={`text-sm font-bold tabular-nums ${full ? 'text-crit-700' : 'text-[#022a1c]'}`}>{pen.goats}{pen.capacity !== null ? <span className="text-xs font-semibold opacity-70">/{pen.capacity}</span> : null}</span>
                </div>
                <div className="kk-herd" aria-hidden>
                    {shown.map((f, i) => <GoatFigure key={i} female={f.female} flag={f.flag} stage={f.stage} breed={f.breed} delay={(i * 0.37) % 2.4} info={f.number ? { ...f, pen: pen.name } : undefined} />)}
                </div>
            </Link>
            {more}
            <div className="mt-1 flex items-center gap-1.5" aria-hidden>
                <span className="kk-trough" />
                <svg viewBox="0 0 12 12" className="size-3.5 shrink-0"><path d="M2 3h8l-1 8H3z" fill="#5f87a8" /><rect x="1.5" y="2" width="9" height="1.6" rx=".8" fill="#46667f" /></svg>
            </div>
            <span className="sr-only">{pen.female} female, {pen.male} male{pen.attention ? `, ${pen.attention} need attention` : ''}</span>
            {pen.attention ? <span className="mt-1 block text-xs font-semibold text-crit-700">{pen.attention} need attention</span> : null}
        </div>
    );
}

export type Stage = 'kid' | 'grower' | 'adult';

/** D-158: a pen shows 10 goats, then Load More adds 10 at a time. */
export function useTen<T>(items: T[]): [T[], ReactNode] {
    const [n, setN] = useState(10);
    const left = items.length - n;
    const button = left > 0 ? (
        <button type="button" className="kk-load-more" onClick={(e) => { e.preventDefault(); e.stopPropagation(); setN(n + 10); }}>
            Load {Math.min(10, left)} More <span className="font-normal opacity-70">· {left} left</span>
        </button>
    ) : null;
    return [items.slice(0, n), button];
}

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

export function GoatFigure({ female, flag, delay, stage = 'adult', info }: { female: boolean; flag: boolean; delay: number; flip?: boolean; stage?: Stage | string | null; breed?: string | null; info?: HoverGoat }) {
    const on = info ? () => hoverGoat(info) : undefined;
    return (
        <span className={`kk-goat kk-goat-pic ${female ? 'kk-goat-f' : 'kk-goat-m'}`} style={{ animationDelay: `${delay}s` }}
            onMouseEnter={on} onMouseLeave={info ? () => hoverGoat(null) : undefined} title={info?.number}>
            <img src={`/icons/${goatArt(female, stage)}.png`} alt="" decoding="async" loading="lazy" />
            {flag ? <img src="/icons/ic-sick.png" alt="" className="kk-goat-flag" /> : null}
        </span>
    );
}

/** D-155: what the pictures mean (from the icon library) and the symbol of each breed on the farm. */
export function FigureLegend({ breeds = [], collars = false }: { breeds?: Array<string | null | undefined>; collars?: boolean }) {
    // D-244: in the 3D shed the goats are all cream; the class is the colour of the collar and ear tag.
    const item = (art: string, label: string, cls?: Cls) => (
        <span key={art} className="inline-flex items-center gap-1.5"><img src={`/icons/${art}.png`} alt="" className="kk-legend-pic" />{collars && cls ? <span className="kk-collar-dot" style={{ background: COLLAR[cls] }} title="Collar and ear tag colour" /> : null}{label}</span>
    );
    const present = [...new Set(breeds.filter(Boolean).map((b) => b!.trim()))].sort();
    const g = useHovered();
    if (g) {
        return (
            <div className="kk-hover-card flex flex-wrap items-center gap-x-3 gap-y-1.5 px-1 text-sm text-[#3d5446]" aria-live="polite">
                <img src={`/icons/${goatArt(g.female, g.stage)}.png`} alt="" className="size-12 shrink-0 rounded-xl object-cover shadow-sm ring-1 ring-black/5" />
                <span className="min-w-0">
                    <span className="block font-mono text-base font-bold text-[#022a1c]">{g.number ?? 'Goat'}</span>
                    {g.name ? <span className="block text-xs">{g.name}</span> : null}
                </span>
                {g.breed ? <BreedBadge breed={g.breed} withName /> : null}
                <AgeBadge stage={g.stage} code={g.code} withLabel />
                <SexIcon sex={g.female ? 'female' : 'male'} label />
                {g.health ? <HealthChip health={g.health} /> : null}
                {g.pen ? <span className="text-xs font-semibold">PEN {g.pen}</span> : null}
            </div>
        );
    }
    return (
        <div className="space-y-2 px-1 text-xs font-semibold text-[#3d5446]">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                {item('cls-bdf', 'Female (Doe)', 'doe')}
                {item('cls-bkm', 'Male (Buck)', 'buck')}
                {item('cls-grw', 'Grower', 'grower')}
                {item('cls-kid', 'Kid', 'kid')}
                {item('ic-sick', 'Needs Attention')}
                <span className="text-xs font-normal opacity-80">Point at a goat to see its details</span>
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
    // D-163: the Maternity Unit is signed like a hospital: medical kit, "Kidding & Neonatal Care".
    const maternity = typeName === 'Maternity Unit';
    return (
        <div className={`kk-roof ${maternity ? 'kk-roof-maternity' : ''}`} data-shed={id}>
            <span className="kk-roof-icon kk-roof-pic" aria-hidden><img src={`/icons/${maternity ? 'app-veterinary' : 'app-housing'}.png`} alt="" /></span>
            <span className="min-w-0">
                <span className="block truncate font-mono text-base font-bold tracking-wide">{name}</span>
                {typeName ? <span className="block truncate text-xs text-white/80">{typeName}{maternity ? ' · Kidding & Neonatal Care' : ''}</span> : null}
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
