import { useMemo } from 'react';

const COATS: Record<string, { base: string; mark: string; ear: string }> = {
    black: { base: '#2a2c35', mark: '#4b4e5c', ear: '#1d1f27' },
    brown: { base: '#8a5a36', mark: '#c79a6c', ear: '#6b4326' },
    tan: { base: '#c79a62', mark: '#ecd2a6', ear: '#a97f4c' },
    white: { base: '#efeae0', mark: '#d7cfbf', ear: '#ddd4c4' },
    spotted: { base: '#ece4d6', mark: '#54392a', ear: '#54392a' },
    boer: { base: '#f1ece2', mark: '#8d4a2b', ear: '#8d4a2b' },
};

function hash(text: string): number {
    let h = 2166136261;
    for (let i = 0; i < text.length; i++) {
        h = Math.imul(h ^ text.charCodeAt(i), 16777619);
    }
    return h >>> 0;
}

/** A goat's generated portrait: drawn from its identity, so no two animals look alike. A placeholder until real photographs exist. */
export default function GoatPortrait({ number, coat, sex, size = 40, className = '' }: { number: string; coat: string; sex?: string; size?: number; className?: string }) {
    const art = useMemo(() => {
        const h = hash(number);
        const c = COATS[coat] ?? COATS.brown;
        return {
            c,
            blazeWidth: 5 + (h % 7),
            spotX: 34 + ((h >>> 3) % 30),
            spotY: 30 + ((h >>> 7) % 24),
            spotR: 6 + ((h >>> 11) % 8),
            spot2X: 36 + ((h >>> 15) % 28),
            spot2Y: 52 + ((h >>> 19) % 14),
            earDrop: (h >>> 5) % 8,
            hornCurve: sex === 'male' ? 16 : 8,
            hasBlaze: h % 3 !== 0,
            hasBeard: sex === 'male' || h % 5 === 0,
            ground: ['#e3ecd9', '#f1ead2', '#e6eedf', '#f4ecdc'][(h >>> 23) % 4],
        };
    }, [number, coat, sex]);
    const { c } = art;

    return (
        <svg viewBox="0 0 100 100" width={size} height={size} className={`shrink-0 rounded-[6px] ${className}`} role="img" aria-label={`Generated portrait for ${number}`}>
            <rect width="100" height="100" fill={art.ground} />
            <path d={`M38 26 C ${34 - art.hornCurve} 16, ${30 - art.hornCurve} 8, ${26 - art.hornCurve / 2} 6 C 34 8, 42 14, 46 24 Z`} fill="#9b8a72" />
            <path d={`M62 26 C ${66 + art.hornCurve} 16, ${70 + art.hornCurve} 8, ${74 + art.hornCurve / 2} 6 C 66 8, 58 14, 54 24 Z`} fill="#9b8a72" />
            <path d={`M30 36 C 18 ${36 + art.earDrop}, 8 ${46 + art.earDrop}, 6 ${60 + art.earDrop} C 18 ${60 + art.earDrop}, 30 ${52 + art.earDrop}, 36 44 Z`} fill={c.ear} />
            <path d={`M70 36 C 82 ${36 + art.earDrop}, 92 ${46 + art.earDrop}, 94 ${60 + art.earDrop} C 82 ${60 + art.earDrop}, 70 ${52 + art.earDrop}, 64 44 Z`} fill={c.ear} />
            <path d="M50 22 C 66 22, 72 34, 69 50 C 67 62, 62 76, 57 84 C 54 88, 46 88, 43 84 C 38 76, 33 62, 31 50 C 28 34, 34 22, 50 22 Z" fill={c.base} />
            {coat === 'boer' ? <path d="M50 22 C 66 22, 72 34, 69 50 L 31 50 C 28 34, 34 22, 50 22 Z" fill={c.mark} /> : null}
            {coat === 'spotted' || coat === 'black' || coat === 'brown' ? (
                <>
                    <ellipse cx={art.spotX} cy={art.spotY} rx={art.spotR} ry={art.spotR * 0.8} fill={c.mark} opacity="0.92" />
                    <ellipse cx={art.spot2X} cy={art.spot2Y} rx={art.spotR * 0.6} ry={art.spotR * 0.5} fill={c.mark} opacity="0.85" />
                </>
            ) : null}
            {art.hasBlaze ? <path d={`M${50 - art.blazeWidth / 2} 26 L${50 + art.blazeWidth / 2} 26 L${50 + art.blazeWidth} 68 L${50 - art.blazeWidth} 68 Z`} fill={coat === 'black' ? '#e8e2d6' : c.mark} opacity={coat === 'white' ? 0.5 : 0.75} /> : null}
            <circle cx="41" cy="45" r="3" fill="#191b22" />
            <circle cx="59" cy="45" r="3" fill="#191b22" />
            <circle cx="40" cy="44" r="0.9" fill="#fff" />
            <circle cx="58" cy="44" r="0.9" fill="#fff" />
            <path d="M45 78 Q50 82 55 78" stroke="#3a2d28" strokeWidth="1.6" fill="none" strokeLinecap="round" />
            <ellipse cx="46" cy="73" rx="1.5" ry="1" fill="#3a2d28" />
            <ellipse cx="54" cy="73" rx="1.5" ry="1" fill="#3a2d28" />
            {art.hasBeard ? <path d="M46 86 Q50 99 54 86 Z" fill={c.ear} /> : null}
        </svg>
    );
}
