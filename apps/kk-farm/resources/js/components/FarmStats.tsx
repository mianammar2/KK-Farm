import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { titleCase } from '../lib/titleCase';
import { CountUp } from './ui';

export type FarmIcon = 'herd' | 'healthy' | 'attention' | 'pregnant' | 'meals' | 'tasks';

/** D-136/D-146: the dashboard's top numbers as modern KPI tiles: a line icon in a rounded tile and the number. */
export function FarmStatRow({ children }: { children: ReactNode }) {
    return <div className="kk-signs">{children}</div>;
}

// D-153: pictures from the KK Farms icon library
const ART: Record<FarmIcon, string> = { herd: 'mod-livestock', healthy: 'ic-healthy', attention: 'mod-health', pregnant: 'ic-pregnant', meals: 'mod-feeding', tasks: 'ic-active' };
const ICON = Object.fromEntries(Object.entries(ART).map(([k, n]) => [k, <img key={k} src={`/icons/${n}.png`} alt="" className="size-[86%] object-contain" />])) as Record<FarmIcon, ReactNode>;

export function FarmStat({ href, icon, label, value, note, tone }: { href: string; icon: FarmIcon; label: string; value: number | string; note?: string; tone?: 'alert' | 'good' }) {
    return (
        <Link href={href} className="kk-sign kk-lift" aria-label={`${titleCase(label)}: ${value}${note ? `, ${note}` : ''}`}>
            <span className={`kk-icon-tile ${tone === 'alert' ? 'kk-icon-tile-alert' : ''}`} aria-hidden>{ICON[icon]}</span>
            <span className="kk-sign-paper">
                <span className="block truncate text-[11px] font-semibold text-[#4d6356] sm:text-xs">{titleCase(label)}</span>
                <span className={`block text-xl font-extrabold leading-tight tabular-nums sm:text-2xl ${tone === 'alert' ? 'text-crit-700' : tone === 'good' ? 'text-ok-700' : 'text-[#022a1c]'}`}>{typeof value === 'number' ? <CountUp to={value} /> : value}</span>
                {note ? <span className="block truncate text-[11px] text-[#4d6356]/85 sm:text-xs">{note}</span> : null}
            </span>
        </Link>
    );
}
