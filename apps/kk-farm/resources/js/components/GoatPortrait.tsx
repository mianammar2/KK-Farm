/**
 * D-160: a goat's picture is its KK Farms icon-library picture for what it is (until a real photo is added):
 * Pregnant Doe (PGD), Lactating Doe (LCT), Kid (KID), Grower (GRW), Breeding Male (BKM), Breeding Female (BDF).
 */
export function portraitArt(sex?: string, stage?: string | null, repro?: string | null) {
    if (repro === 'pregnant' || repro === 'expected_kidding') return 'cls-pgd';
    if (repro === 'lactating' || repro === 'nursing') return 'cls-lct';
    if (stage === 'kid') return 'cls-kid';
    if (stage === 'grower') return 'cls-grw';
    return sex === 'male' ? 'cls-bkm' : 'cls-bdf';
}

export default function GoatPortrait({ number, sex, stage, repro, size = 40, className = '' }: { number: string; coat?: string; sex?: string; stage?: string | null; repro?: string | null; size?: number; className?: string }) {
    return (
        <img src={`/icons/${portraitArt(sex, stage, repro)}.png`} alt="" aria-hidden title={number} width={size} height={size} loading="lazy" decoding="async"
            className={`shrink-0 rounded-tile bg-white object-cover shadow-sm ring-1 ${sex === 'male' ? 'ring-sky-300' : 'ring-pink-200'} ${className}`} style={{ width: size, height: size }} />
    );
}
