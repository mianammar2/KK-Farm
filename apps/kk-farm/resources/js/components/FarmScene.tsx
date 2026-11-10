import { Link } from '@inertiajs/react';
import { Warehouse } from 'lucide-react';
import type { ReactNode } from 'react';
import { useFive } from '../lib/useFive';
import { AgeBadge, BreedBadge } from './GoatBadges';
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
                <span className={`text-sm font-bold tabular-nums ${full ? 'text-crit-700' : 'text-[#16301f]'}`}>{pen.goats}{pen.capacity !== null ? <span className="text-xs font-semibold opacity-70">/{pen.capacity}</span> : null}</span>
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
 * D-147: a goat in the KK digital style. Male (buck): big swept-back horns, a beard and a thick neck. Female (doe):
 * small horns and an udder. Kid: small, no horns, short legs. Coat, head colour, patches and ears follow the breed.
 * Every goat stands on a glowing pad: pink for female, blue for male. A red cross tag marks one that needs attention.
 */
export function GoatFigure({ female, flag, delay, flip, stage = 'adult', breed = null }: { female: boolean; flag: boolean; delay: number; flip: boolean; stage?: Stage | string | null; breed?: string | null }) {
    const look = breedLook(breed);
    const kid = stage === 'kid';
    const coat = look?.coat ?? (female ? '#f3efe6' : '#8b5a33');
    const head = look?.head ?? coat;
    const dark = ['#1c1a19', '#262626', '#3a2d26', '#4a3426'].includes(coat);
    const line = dark ? '#000000' : '#2b3b30';
    const pad = female ? '#ec6c97' : '#3b82c4';
    // A kid is the same goat drawn smaller, standing on the same ground line.
    const body = kid ? 'translate(6.4 9.6) scale(0.62)' : stage === 'grower' ? 'translate(2.2 3.3) scale(0.87)' : undefined;
    return (
        <svg viewBox="0 -2 34 30" className="kk-goat" style={{ animationDelay: `${delay}s`, transform: flip ? 'scaleX(-1)' : undefined }}>
            <ellipse cx="16" cy="26.6" rx={kid ? 7 : 11} ry="1.7" fill={pad} opacity="0.75" />
            <ellipse cx="16" cy="26.6" rx={kid ? 4 : 6.5} ry="0.8" fill="#ffffff" opacity="0.55" />
            <g transform={body}>
                <g stroke={line} strokeWidth="1.3" strokeLinecap="round">
                    <line x1="9" y1="17" x2="9" y2={kid ? 22.5 : 25} /><line x1="12" y1="17" x2="12" y2={kid ? 22.5 : 25} />
                    <line x1="19" y1="17" x2="19" y2={kid ? 22.5 : 25} /><line x1="22" y1="17" x2="22" y2={kid ? 22.5 : 25} />
                    <path d="M6 12 L3.2 9" fill="none" />
                </g>
                <ellipse cx="15.5" cy="13.5" rx="10" ry={female ? 5.4 : 6} fill={coat} stroke={line} strokeWidth="1.1" />
                {look?.spots ? <g fill={look.spots}><ellipse cx="11" cy="12" rx="3" ry="2" /><ellipse cx="18.5" cy="15" rx="2.6" ry="1.7" /><ellipse cx="15" cy="10.5" rx="1.6" ry="1.1" /></g> : null}
                {female && !kid ? <ellipse cx="17.5" cy="18.6" rx="2.4" ry="1.5" fill="#f2a5bd" stroke={line} strokeWidth="0.7" /> : null}
                {/* neck and head; a buck's neck is thicker */}
                <path d={female || kid ? 'M21.5 11 L24.5 5 Q27.5 3.8 29.6 6.4 L30 9.2 Q28.6 10.6 26.8 10 L24.6 14 Z' : 'M20.5 10.5 L24 4.6 Q27.6 3.4 29.8 6.2 L30.2 9.4 Q28.6 10.9 26.6 10.2 L24.4 15 Z'} fill={head} stroke={line} strokeWidth="1.1" />
                {look?.ears === 'long' ? <path d="M24.4 6.4 Q21.6 8.5 22.2 12.6 Q23.6 11.6 25.2 7.6 Z" fill={head} stroke={line} strokeWidth="0.8" /> : <path d="M24.6 6.6 L21.6 8.4" stroke={line} strokeWidth="1.6" strokeLinecap="round" />}
                {kid ? null : female
                    ? <path d="M25.6 5.2 Q25 2.6 23.6 1.8" stroke={line} strokeWidth="1.1" fill="none" strokeLinecap="round" />
                    : <path d="M25.2 5.4 Q24.4 0.6 20.6 0.2 Q18.6 0.4 18.2 2.6 Q20.8 1.6 22.4 3.2 Q23.6 4.6 23.8 6" fill="#c9b79a" stroke={line} strokeWidth="0.9" strokeLinejoin="round" />}
                {!female && !kid ? <path d="M28.8 9.8 L28.2 13.6 L29.8 10.4" fill={line} stroke={line} strokeWidth="0.6" strokeLinejoin="round" /> : null}
                <circle cx="27.6" cy="6.8" r="0.8" fill={dark ? '#f5e6a8' : '#1b1b1b'} />
            </g>
            {flag ? <g><circle cx="14" cy="3.4" r="4.4" fill="#d92d20" stroke="#fff" strokeWidth="1.1" /><path d="M14 1.1v4.6M11.7 3.4h4.6" stroke="#fff" strokeWidth="1.5" /></g> : null}
        </svg>
    );
}

/** D-147: what the figures mean: female, male, kid, needs attention, and the look of each breed that is on the farm. */
export function FigureLegend({ breeds = [] }: { breeds?: Array<string | null | undefined> }) {
    const item = (key: string, label: string, figure: ReactNode) => (
        <span key={key} className="inline-flex items-center gap-1.5"><span className="kk-legend-goat">{figure}</span>{label}</span>
    );
    const present = [...new Set(breeds.filter(Boolean).map((b) => b!.trim()))].filter((b) => breedLook(b)).sort();
    return (
        <div className="space-y-1.5 px-1 text-xs text-[#3d5446]">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                {item('f', 'Female (Doe)', <GoatFigure female flag={false} delay={0} flip={false} />)}
                {item('m', 'Male (Buck)', <GoatFigure female={false} flag={false} delay={0} flip={false} />)}
                {item('k', 'Kid', <GoatFigure female flag={false} delay={0} flip={false} stage="kid" />)}
                <span className="inline-flex items-center gap-1.5">Age: <AgeBadge stage="kid" withLabel /><AgeBadge stage="grower" withLabel /><AgeBadge stage="adult" withLabel /></span>
                {item('a', 'Needs Attention', <GoatFigure female flag delay={0} flip={false} />)}
            </div>
            {present.length ? (
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                    <span className="font-semibold">Breeds:</span>
                    {present.map((b) => <span key={b} className="inline-flex items-center gap-1"><span className="kk-legend-goat"><GoatFigure female flag={false} delay={0} flip={false} breed={b} /></span><BreedBadge breed={b} withName /></span>)}
                </div>
            ) : null}
            <div>Pink pad: female · blue pad: male · tap a pen to see its goats.</div>
        </div>
    );
}

/** D-133/D-134: the barn roof (corrugated tin on a pitched frame) with the shed's name board; shared by the farm scenes. */
export function BarnRoof({ id, name, typeName }: { id: number | string; name: string; typeName: string | null }) {
    // D-146: the shed's header bar: a green band with a building icon tile, the shed name and its unit type.
    return (
        <div className="kk-roof" data-shed={id}>
            <span className="kk-roof-icon" aria-hidden><Warehouse size={18} /></span>
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
