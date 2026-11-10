import { titleCase } from '../lib/titleCase';

/**
 * D-148: a symbol for every breed and for every age stage, shown wherever a goat appears.
 *
 * Breed symbol: its own shape in the breed's colours with a short code (Beetal = hexagon "BT", Boer = shield "BR" …),
 * so a breed is recognised without reading. Age symbol: signal-style bars, 1 = Kid, 2 = Grower, 3 = Adult.
 */
type Shape = 'hexagon' | 'diamond' | 'shield' | 'circle' | 'square' | 'octagon' | 'triangle' | 'pill';

const SHAPES: Record<Shape, string> = {
    hexagon: 'M12 1.5 21.5 7v10L12 22.5 2.5 17V7Z',
    diamond: 'M12 1 23 12 12 23 1 12Z',
    shield: 'M12 1.5 21 5v6.5c0 5.5-3.8 9.3-9 11-5.2-1.7-9-5.5-9-11V5Z',
    circle: 'M12 1.5a10.5 10.5 0 1 1 0 21 10.5 10.5 0 0 1 0-21Z',
    square: 'M6 1.5h12A4.5 4.5 0 0 1 22.5 6v12a4.5 4.5 0 0 1-4.5 4.5H6A4.5 4.5 0 0 1 1.5 18V6A4.5 4.5 0 0 1 6 1.5Z',
    octagon: 'M8 1.5h8L22.5 8v8L16 22.5H8L1.5 16V8Z',
    triangle: 'M12 1.5 23 21.5H1Z',
    pill: 'M7 4h10a8 8 0 0 1 0 16H7A8 8 0 0 1 7 4Z',
};

export const BREED_SYMBOL: Record<string, { code: string; shape: Shape; bg: string; fg: string; ring: string }> = {
    beetal: { code: 'BT', shape: 'hexagon', bg: '#4a3426', fg: '#f3e6cf', ring: '#c9a77a' },
    kamori: { code: 'KM', shape: 'diamond', bg: '#7a4527', fg: '#ffe8cc', ring: '#d9b38c' },
    'dera din panah': { code: 'DD', shape: 'shield', bg: '#1c1a19', fg: '#ffffff', ring: '#8a8a8a' },
    boer: { code: 'BR', shape: 'circle', bg: '#9b4a2a', fg: '#ffffff', ring: '#f6f2ea' },
    teddy: { code: 'TD', shape: 'square', bg: '#262626', fg: '#ffffff', ring: '#f4f1ea' },
    nachi: { code: 'NC', shape: 'octagon', bg: '#3a2d26', fg: '#f5d98a', ring: '#a4876c' },
    crossbred: { code: 'X', shape: 'triangle', bg: '#b9844f', fg: '#ffffff', ring: '#f4ead8' },
};

const symbolFor = (breed: string) => BREED_SYMBOL[breed.trim().toLowerCase()] ?? {
    code: breed.trim().split(/\s+/).map((w) => w[0]).join('').slice(0, 2).toUpperCase() || '?', shape: 'pill' as Shape, bg: '#4d6356', fg: '#ffffff', ring: '#c9d6cd',
};

export function BreedBadge({ breed, withName = false, size = 24 }: { breed?: string | null; withName?: boolean; size?: number }) {
    if (!breed) return null;
    const s = symbolFor(breed);
    const top = s.shape === 'triangle' ? 15.5 : 12.6;
    return (
        <span className="inline-flex shrink-0 items-center gap-1 align-middle" title={`Breed: ${breed}`}>
            <svg viewBox="0 0 24 24" width={size} height={size} role="img" aria-label={`Breed ${breed}`} className="shrink-0 drop-shadow-[0_1px_1px_rgb(0_0_0/0.2)]">
                <path d={SHAPES[s.shape]} fill={s.bg} stroke={s.ring} strokeWidth="1.4" strokeLinejoin="round" />
                <text x="12" y={top} textAnchor="middle" dominantBaseline="middle" fontSize={s.code.length > 1 ? 9 : 11} fontWeight="800" fill={s.fg} fontFamily="ui-sans-serif, system-ui, sans-serif">{s.code}</text>
            </svg>
            {withName ? <span className="truncate">{breed}</span> : null}
        </span>
    );
}

const STAGE: Record<string, { bars: number; color: string; label: string }> = {
    kid: { bars: 1, color: '#7cc04f', label: 'Kid' },
    grower: { bars: 2, color: '#1f9e95', label: 'Grower' },
    adult: { bars: 3, color: '#1f7a3a', label: 'Adult' },
};

export function AgeBadge({ stage, withLabel = false }: { stage?: string | null; withLabel?: boolean }) {
    const s = stage ? STAGE[stage] : undefined;
    if (!s) return null;
    return (
        <span className="inline-flex shrink-0 items-center gap-1 rounded-full px-1.5 py-0.5 align-middle text-[11px] font-bold" style={{ color: s.color, background: `${s.color}1a` }} title={`Age: ${s.label}`}>
            <svg viewBox="0 0 14 12" width="13" height="11" role="img" aria-label={s.label}>
                {[0, 1, 2].map((i) => <rect key={i} x={i * 5} y={8 - i * 3.5} width="3.6" height={4 + i * 3.5} rx="1" fill={i < s.bars ? s.color : `${s.color}33`} />)}
            </svg>
            {withLabel ? titleCase(s.label) : null}
        </span>
    );
}

/** Both symbols together, as shown next to a goat's tag ID. */
export function GoatBadges({ breed, stage }: { breed?: string | null; stage?: string | null }) {
    if (!breed && !stage) return null;
    return <span className="inline-flex shrink-0 items-center gap-1"><BreedBadge breed={breed} /><AgeBadge stage={stage} /></span>;
}
