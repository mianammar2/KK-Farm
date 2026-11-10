import { titleCase } from '../lib/titleCase';

/**
 * D-148: a symbol for every breed and for every age stage, shown wherever a goat appears.
 *
 * Breed symbol: a goat head in the breed's colours (long drooping or short ears, face blaze) with a short code
 * (Beetal "BT", Teddy "TD" …), so a breed is recognised without reading. Age symbol: signal-style bars, 1 = Kid, 2 = Grower, 3 = Adult.
 */
type Ears = 'long' | 'short';

/** D-149: the breed symbol is a goat head (not a shape), coloured like the breed, with its code beside it. */
export const BREED_SYMBOL: Record<string, { code: string; ears: Ears; bg: string; fg: string; ring: string; blaze?: string }> = {
    beetal: { code: 'BT', ears: 'long', bg: '#4a3426', fg: '#f3e6cf', ring: '#c9a77a' },
    kamori: { code: 'KM', ears: 'long', bg: '#7a4527', fg: '#ffe8cc', ring: '#d9b38c', blaze: '#f1e2cc' },
    'dera din panah': { code: 'DD', ears: 'long', bg: '#1c1a19', fg: '#ffffff', ring: '#8a8a8a' },
    boer: { code: 'BR', ears: 'long', bg: '#9b4a2a', fg: '#ffffff', ring: '#d9c7b8', blaze: '#ffffff' },
    teddy: { code: 'TD', ears: 'short', bg: '#262626', fg: '#ffffff', ring: '#bdb8ae', blaze: '#f4f1ea' },
    nachi: { code: 'NC', ears: 'long', bg: '#3a2d26', fg: '#f5d98a', ring: '#a4876c' },
    crossbred: { code: 'X', ears: 'long', bg: '#b9844f', fg: '#ffffff', ring: '#8a6038', blaze: '#f4ead8' },
    'makhi cheeni': { code: 'MC', ears: 'long', bg: '#c8913f', fg: '#ffffff', ring: '#8a5a1e', blaze: '#f6e3b8' },
    gulabi: { code: 'GB', ears: 'long', bg: '#e3a99a', fg: '#4a2219', ring: '#a86d5f' },
    barbari: { code: 'BB', ears: 'short', bg: '#efe6d6', fg: '#4a3020', ring: '#9c7d5c', blaze: '#b07a4a' },
};

/** "Makhi Cheeni (Beetal Variety)" → "makhi cheeni", "Dera Din Panah (DDP)" → "dera din panah", "Crossbreed" → "crossbred". */
const ALIAS: Record<string, string> = { crossbreed: 'crossbred', cross: 'crossbred', ddp: 'dera din panah', 'makhi chini': 'makhi cheeni' };
const breedKey = (breed: string) => { const k = breed.toLowerCase().replace(/\(.*?\)/g, '').replace(/\s+/g, ' ').trim(); return ALIAS[k] ?? k; };

export const symbolFor = (breed: string) => BREED_SYMBOL[breedKey(breed)] ?? {
    code: breed.replace(/\(.*?\)/g, '').trim().split(/\s+/).map((w) => w[0]).join('').slice(0, 2).toUpperCase() || '?', ears: 'long' as Ears, bg: '#4d6356', fg: '#ffffff', ring: '#9fb3a6', blaze: undefined,
};

/** A front-facing goat head: horns, ears (long and drooping or short), face, blaze, eyes and beard. */
function GoatHead({ s, size }: { s: ReturnType<typeof symbolFor>; size: number }) {
    const ears = s.ears === 'long'
        ? <><path d="M8.2 9.2C5.6 9 2.6 11 1.6 14.6c2.6.4 5.1-.8 6.9-3.2Z" /><path d="M15.8 9.2c2.6-.2 5.6 1.8 6.6 5.4-2.6.4-5.1-.8-6.9-3.2Z" /></>
        : <><path d="M8.3 8.8C6.3 7.9 3.8 8.3 2.6 9.8c1.4 1.4 3.8 1.9 5.9 1.2Z" /><path d="M15.7 8.8c2-.9 4.5-.5 5.7 1-1.4 1.4-3.8 1.9-5.9 1.2Z" /></>;
    return (
        <svg viewBox="0 0 24 24" width={size} height={size} role="img" aria-hidden="true" className="shrink-0 drop-shadow-[0_1px_1px_rgb(0_0_0/0.25)]">
            <circle cx="12" cy="12" r="11.6" fill="#fbf8f1" stroke={s.ring} strokeWidth=".8" />
            <g fill="none" stroke="#8c7a63" strokeWidth="1.7" strokeLinecap="round">
                <path d="M9.6 6.6C8.6 4.4 7 2.6 5 1.8" /><path d="M14.4 6.6c1-2.2 2.6-4 4.6-4.8" />
            </g>
            <g fill={s.bg} stroke={s.ring} strokeWidth="0.6" strokeLinejoin="round">
                {ears}
                <path d="M12 5.6c3.2 0 4.6 2.2 4.4 5.4-.2 3.8-1.8 7.6-4.4 8.6-2.6-1-4.2-4.8-4.4-8.6C7.4 7.8 8.8 5.6 12 5.6Z" />
            </g>
            {s.blaze ? <path d="M12 8.2c.9 0 1.3 2.2 1.3 4.8 0 2.6-.5 5-1.3 5.8-.8-.8-1.3-3.2-1.3-5.8 0-2.6.4-4.8 1.3-4.8Z" fill={s.blaze} /> : null}
            <circle cx="10" cy="10.6" r=".9" fill={s.fg} />
            <circle cx="14" cy="10.6" r=".9" fill={s.fg} />
            <path d="M11 19.2 12 23l1-3.8" fill={s.bg} />
        </svg>
    );
}

export function BreedBadge({ breed, withName = false, size = 24 }: { breed?: string | null; withName?: boolean; size?: number }) {
    if (!breed) return null;
    const s = symbolFor(breed);
    return (
        <span className="inline-flex shrink-0 items-center gap-1 align-middle" title={`Breed: ${breed}`} aria-label={`Breed ${breed}`}>
            <span className="inline-flex items-center gap-0.5 rounded-full p-px pr-1.5 text-[10px] font-extrabold leading-none tracking-wide" style={{ background: s.bg, color: s.fg, boxShadow: `inset 0 0 0 1px ${s.ring}` }}>
                <GoatHead s={s} size={size} />
                {s.code}
            </span>
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
