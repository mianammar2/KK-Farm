import { Link } from '@inertiajs/react';
import { useFive } from '../lib/useFive';
import { CountUp } from './ui';

export type ScenePen = { id: number; name: string; code: string; capacity: number | null; goats: number; female: number; male: number; attention: number };
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
            <Legend />
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
                    <span className="font-semibold text-[#4a3a22]">{shed.typeName ?? ''}</span>
                    <span className="font-semibold text-[#4a3a22]"><CountUp to={shed.goats} /> goats{capacity ? <span className="font-normal opacity-75"> of {capacity}</span> : null}</span>
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
                <span className={`text-sm font-bold tabular-nums ${full ? 'text-crit-700' : 'text-[#3b2e1a]'}`}>{pen.goats}{pen.capacity !== null ? <span className="text-xs font-semibold opacity-70">/{pen.capacity}</span> : null}</span>
            </div>
            <div className="kk-herd" aria-hidden>
                {Array.from({ length: figures }, (_, i) => (
                    <GoatFigure key={i} female={i < females} flag={i < flagged} delay={(i * 0.37) % 2.4} flip={i % 3 === 1} />
                ))}
                {pen.goats > figures ? <span className="self-center text-xs font-bold text-[#4a3a22]">+{pen.goats - figures}</span> : null}
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

/** A small side-on goat: body, neck and head, horns, ear, beard, four legs and a tail. */
function GoatFigure({ female, flag, delay, flip }: { female: boolean; flag: boolean; delay: number; flip: boolean }) {
    const coat = female ? '#fffdf7' : '#8b5a33';
    const shade = female ? '#6f5a3a' : '#4a2c16';
    return (
        <svg viewBox="0 -1 32 27" className="kk-goat" style={{ animationDelay: `${delay}s`, transform: flip ? 'scaleX(-1)' : undefined }}>
            <g stroke="#3b2e1a" strokeWidth="1.3" strokeLinecap="round">
                <line x1="9" y1="17" x2="9" y2="24" /><line x1="12" y1="17" x2="12" y2="24" />
                <line x1="19" y1="17" x2="19" y2="24" /><line x1="22" y1="17" x2="22" y2="24" />
                <path d="M6 12 L3 9.5" fill="none" />
                <path d="M25.5 5.5 Q24.5 2 22.5 1.2 M27.5 5.6 Q27.6 2.2 26 1" fill="none" />
            </g>
            <ellipse cx="15.5" cy="13.5" rx="10" ry="5.6" fill={coat} stroke={shade} strokeWidth="1.2" />
            {female ? <ellipse cx="13" cy="15.6" rx="5" ry="1.6" fill="#e9dcc0" /> : <ellipse cx="13" cy="11.2" rx="5" ry="1.4" fill="#a8744a" />}
            <path d="M21.5 11 L24.5 5 Q27.5 3.8 29.6 6.4 L30 9.2 Q28.6 10.6 26.8 10 L24.6 14 Z" fill={coat} stroke={shade} strokeWidth="1.2" />
            <path d="M24.6 6.6 L21.6 8.6" stroke={shade} strokeWidth="1.6" strokeLinecap="round" />
            <path d="M29 9.6 L28.6 12.4" stroke="#3b2e1a" strokeWidth="0.8" strokeLinecap="round" />
            <circle cx="27.4" cy="6.8" r="0.75" fill="#2a2116" />
            {flag ? <g><circle cx="14" cy="4.6" r="4.6" fill="#c8312a" stroke="#fff" strokeWidth="1.1" /><path d="M14 2.2v4.8M11.6 4.6h4.8" stroke="#fff" strokeWidth="1.5" /></g> : null}
        </svg>
    );
}

function Legend() {
    const item = (label: string, female: boolean, flag = false) => (
        <span className="inline-flex items-center gap-1.5"><span className="inline-block size-6"><GoatFigure female={female} flag={flag} delay={0} flip={false} /></span>{label}</span>
    );
    return (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 px-1 text-xs text-ink-soft">
            {item('Female', true)}{item('Male', false)}{item('Needs Attention', true, true)}
            <span>Tap a pen to see its goats.</span>
        </div>
    );
}

/** D-133/D-134: the barn roof (corrugated tin on a pitched frame) with the shed's name board; shared by the farm scenes. */
export function BarnRoof({ id, name, typeName }: { id: number | string; name: string; typeName: string | null }) {
    const shed = { id, name, typeName };
    return (
        <>
                        <div className="relative">
                <svg viewBox="0 0 400 54" preserveAspectRatio="none" className="block h-12 w-full sm:h-14" aria-hidden>
                    <defs>
                        <pattern id={`tin-${shed.id}`} width="9" height="54" patternUnits="userSpaceOnUse">
                            <rect width="9" height="54" fill="#8a9a8c" />
                            <rect width="4" height="54" fill="#a3b2a4" />
                            <rect x="4" width="1" height="54" fill="#6f7f72" />
                        </pattern>
                    </defs>
                    <polygon points="0,54 30,6 370,6 400,54" fill={`url(#tin-${shed.id})`} />
                    <polygon points="30,6 370,6 365,0 35,0" fill="#5d6b5f" />
                    <rect x="0" y="50" width="400" height="4" fill="#4b5a4d" />
                </svg>
                <div className="absolute inset-x-0 top-1 flex justify-center sm:top-1.5">
                    <div className="kk-board">
                        <span className="font-mono text-sm font-bold tracking-wide sm:text-base">{shed.name}</span>
                        <span className="hidden text-[11px] font-semibold opacity-85 sm:inline">{shed.typeName}</span>
                    </div>
                </div>
            </div>
        </>
    );
}
