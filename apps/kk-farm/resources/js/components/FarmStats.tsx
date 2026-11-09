import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { titleCase } from '../lib/titleCase';
import { GoatFigure } from './FarmScene';
import { CountUp } from './ui';

export type FarmIcon = 'herd' | 'attention' | 'pregnant' | 'meals' | 'tasks';

/** D-136: the dashboard's top numbers as wooden signs on a plank wall; each opens what it counts. */
export function FarmStatRow({ children }: { children: ReactNode }) {
    return <div className="kk-signs">{children}</div>;
}

export function FarmStat({ href, icon, label, value, note, tone }: { href: string; icon: FarmIcon; label: string; value: number | string; note?: string; tone?: 'alert' | 'good' }) {
    return (
        <Link href={href} className="kk-sign kk-lift" aria-label={`${titleCase(label)}: ${value}${note ? `, ${note}` : ''}`}>
            <span className="kk-sign-nail left-1.5" aria-hidden /><span className="kk-sign-nail right-1.5" aria-hidden />
            <span className="kk-sign-icon" aria-hidden><Icon kind={icon} /></span>
            <span className="kk-sign-paper">
                <span className="block truncate text-[11px] font-semibold text-[#5a4628] sm:text-xs">{titleCase(label)}</span>
                <span className={`block text-xl font-extrabold leading-tight tabular-nums sm:text-2xl ${tone === 'alert' ? 'text-crit-700' : tone === 'good' ? 'text-ok-700' : 'text-[#2f5a2a]'}`}>{typeof value === 'number' ? <CountUp to={value} /> : value}</span>
                {note ? <span className="block truncate text-[11px] text-[#5a4628]/85 sm:text-xs">{note}</span> : null}
            </span>
        </Link>
    );
}

function Icon({ kind }: { kind: FarmIcon }) {
    if (kind === 'herd') return <span className="kk-herd-icon"><span><GoatFigure female={false} flag={false} delay={0} flip /></span><span><GoatFigure female flag={false} delay={0} flip={false} /></span></span>;
    if (kind === 'attention') return <GoatFigure female flag delay={0} flip={false} />;
    if (kind === 'pregnant') return (
        <span className="relative block size-full"><GoatFigure female flag={false} delay={0} flip={false} />
            <svg viewBox="0 0 12 11" className="kk-heart absolute -top-1 left-0"><path d="M6 10.5 1.4 6A2.9 2.9 0 1 1 6 2.4 2.9 2.9 0 1 1 10.6 6Z" fill="#c2185b" stroke="#fff" strokeWidth="1" /></svg>
        </span>
    );
    if (kind === 'meals') return (
        <svg viewBox="0 0 60 44">
            <path d="M8 22 Q30 6 52 22 Z" fill="#8fb157" /><path d="M12 22 Q30 13 48 22 Z" fill="#d6b46a" />
            <path d="M4 21 H56 L51 33 H9 Z" fill="#8d6238" stroke="#5a3b1e" strokeWidth="2" strokeLinejoin="round" />
            <rect x="12" y="32" width="5" height="9" rx="1" fill="#5a3b1e" /><rect x="43" y="32" width="5" height="9" rx="1" fill="#5a3b1e" />
        </svg>
    );
    return (
        <svg viewBox="0 0 44 50">
            <rect x="5" y="6" width="34" height="42" rx="3" fill="#a87a48" stroke="#5a3b1e" strokeWidth="2" />
            <rect x="9" y="11" width="26" height="33" rx="1.5" fill="#fff8e6" />
            <rect x="15" y="2" width="14" height="8" rx="2" fill="#9aa3a0" stroke="#5f6866" strokeWidth="1.5" />
            <path d="M13 20l2.5 2.5L20 18M13 29l2.5 2.5L20 27" stroke="#2f5a2a" strokeWidth="2.2" fill="none" strokeLinecap="round" strokeLinejoin="round" />
            <path d="M23 20.5h8M23 29.5h8M13 38h18" stroke="#8a6a3a" strokeWidth="2" strokeLinecap="round" />
        </svg>
    );
}
