import type { LucideIcon } from 'lucide-react';
import { cx } from './ui';

/**
 * D-153: the KK Farms icon library (owner's "Goat Farming ERP – Icon Library & Classification"). Each icon is a small
 * picture on a white rounded tile, the way the library shows them. Files live in /public/icons:
 * mod-* modules, cls-* goat classifications (picture cards), ic-* gender, origin, breed, health, repro and status.
 */
export const NAV_ART: Record<string, string> = {
    // D-247: module pages use the owner's app icons (public/icons/app-*.svg); other pages keep the library pictures
    '/': 'app-dashboard', '/actions': 'ic-sick', '/goats': 'app-livestock', '/breeding': 'app-breeding', '/milking': 'app-dairy',
    '/health': 'app-veterinary', '/weights': 'app-growth', '/feeding': 'app-feeding', '/sheds': 'app-housing', '/movements': 'ic-transferred',
    '/tasks': 'app-operations', '/employees': 'app-employees', '/activity': 'app-reports', '/value': 'app-sales', '/finance': 'app-accounting',
    '/finance/books': 'app-accounting', '/sensors': 'app-maintenance', '/reports': 'app-reports', '/settings': 'app-settings',
};

export function ArtIcon({ name, size = 24, className, label }: { name: string; size?: number; className?: string; label?: string }) {
    return (
        <img src={`/icons/${name}.${name.startsWith('app-') ? 'svg' : 'png'}`} width={size} height={size} alt={label ?? ''} aria-hidden={label ? undefined : true} decoding="async" loading="lazy"
            className={cx('shrink-0 rounded-[28%] bg-white object-contain shadow-sm ring-1 ring-black/5', className)} style={{ width: size, height: size }} />
    );
}

/** A menu icon: the library picture for that page, or the line icon on the same white tile when the library has none. */
export function NavIcon({ href, icon: Icon, size = 24, className }: { href: string; icon: LucideIcon; size?: number; className?: string }) {
    const art = NAV_ART[href];
    if (art) return <ArtIcon name={art} size={size} className={className} />;
    return (
        <span className={cx('grid shrink-0 place-items-center rounded-[28%] bg-white text-cobalt-800 shadow-sm ring-1 ring-black/5', className)} style={{ width: size, height: size }} aria-hidden>
            <Icon size={Math.round(size * 0.62)} />
        </span>
    );
}

/** D-153: goat group → classification picture from the library (BKM, BDF, KID, GRW, RPL, FTN, QRT …). */
export const CLASS_ART: Record<string, string> = {
    kid: 'cls-kid', grower: 'cls-grw', adult: 'cls-bdf', age_unknown: 'cls-wea',
    breeding_male: 'cls-bkm', breeding_female: 'cls-bdf', replacement_male: 'cls-rpl', replacement_female: 'cls-rpl',
    male_kid: 'cls-kid', female_kid: 'cls-kid', growing_male: 'cls-grw', growing_female: 'cls-grw',
    fattening_male: 'cls-ftn', wether: 'cls-ftn', cull: 'cls-qrt', none: 'cls-wea',
    pregnant: 'cls-pgd', lactating: 'cls-lct', kidding: 'cls-mat', mating: 'cls-mtg', quarantine: 'cls-qrt',
};

/** The library's goat picture for a group, as a small rounded card. */
export function ClassPic({ group, size = 44, className }: { group: string; size?: number; className?: string }) {
    const art = CLASS_ART[group.replace(/^class_/, '')] ?? 'cls-wea';
    return <img src={`/icons/${art}.png`} alt="" aria-hidden width={size} height={size} decoding="async" loading="lazy" className={cx('shrink-0 rounded-[22%] object-cover shadow-sm ring-1 ring-black/5', className)} style={{ width: size, height: size }} />;
}
