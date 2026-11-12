import { Link, usePage } from '@inertiajs/react';
import { motion, useReducedMotion } from 'motion/react';
import { useEffect, useId, useRef, useState } from 'react';
import { AlertTriangle, Baby, HeartPulse, Inbox, Mars, Venus } from 'lucide-react';
import type { ButtonHTMLAttributes, ReactNode } from 'react';
import GoatPortrait from './GoatPortrait';
import { titleCase } from '../lib/titleCase';
import type { GoatCard, Health } from '../types';
import { GoatBadges } from './GoatBadges';

export function cx(...parts: Array<string | false | null | undefined>): string {
    return parts.filter(Boolean).join(' ');
}

export const pkr = (value: number): string => `PKR ${Math.round(value).toLocaleString('en-PK')}`;

export const pkrShort = (value: number): string => {
    const abs = Math.abs(value);
    if (abs >= 1e6) {
        return `PKR ${(value / 1e6).toFixed(2)}M`;
    }
    return abs >= 1e3 ? `PKR ${Math.round(value / 1e3)}k` : `PKR ${value}`;
};

export const monthLabel = (ym: string): string => new Date(`${ym}-01`).toLocaleDateString('en-GB', { month: 'short' });

export const HEALTH: Record<Health, { label: string; tile: string; chip: string; art: string }> = {
    healthy: { label: 'Healthy', tile: 'bg-cobalt-700', chip: 'bg-ok-100 text-ok-700', art: 'ic-healthy' },
    watch: { label: 'Watch', tile: 'bg-watch-400', chip: 'bg-watch-100 text-watch-700', art: 'ic-recovery' },
    treatment: { label: 'In treatment', tile: 'bg-treat-400', chip: 'bg-treat-100 text-treat-700', art: 'ic-under-treatment' },
    critical: { label: 'Critical', tile: 'bg-crit-500', chip: 'bg-crit-100 text-crit-700', art: 'ic-sick' },
};

/** D-153: a small picture from the KK Farms icon library (public/icons), on its white tile. */
export function LibIcon({ name, size = 16, className }: { name: string; size?: number; className?: string }) {
    return <img src={`/icons/${name}.png`} alt="" aria-hidden width={size} height={size} decoding="async" className={cx('inline-block shrink-0 rounded-[28%] bg-white object-contain', className)} style={{ width: size, height: size }} />;
}

export function PageHeader({ title, description, actions }: { title: string; description?: string; actions?: ReactNode }) {
    return (
        <header className="mb-4 flex flex-wrap items-end justify-between gap-3 lg:mb-5">
            <div className="min-w-0">
                {/* On phones and the gun the app bar already shows the title. */}
                <h1 className="hidden text-2xl font-bold tracking-tight text-cobalt-900 lg:block">{titleCase(title)}</h1>
                {description ? <p className="line-clamp-2 max-w-[68ch] text-[13px] leading-snug text-ink-soft sm:line-clamp-none sm:text-sm lg:mt-1 lg:text-base">{description}</p> : null}
            </div>
            {actions ? <div className="flex flex-wrap items-center gap-2">{actions}</div> : null}
        </header>
    );
}

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary' | 'quiet' | 'danger' };

const BUTTON: Record<NonNullable<ButtonProps['variant']>, string> = {
    primary: 'bg-cobalt-800 text-white hover:bg-cobalt-900 active:bg-cobalt-900', // D-068: brand green; gold is kept for highlights
    secondary: 'bg-tile text-cobalt-800 border border-rule-strong hover:bg-cobalt-100',
    quiet: 'text-cobalt-700 hover:bg-cobalt-100',
    danger: 'bg-tile text-crit-700 border border-crit-500/40 hover:bg-crit-100',
};

export function Button({ variant = 'primary', className, ...props }: ButtonProps) {
    return (
        <button
            type="button"
            {...props}
            className={cx(
                'inline-flex min-h-11 items-center justify-center gap-2 rounded-tile px-4 py-2 text-sm font-semibold transition-[color,background-color,transform] duration-150 active:scale-[0.97] disabled:cursor-not-allowed disabled:opacity-50 disabled:active:scale-100',
                BUTTON[variant],
                className,
            )}
        />
    );
}

export function Panel({ title, description, actions, children, className, flush, id }: { title?: string; description?: string; actions?: ReactNode; children: ReactNode; className?: string; flush?: boolean; id?: string }) {
    return (
        <section id={id} className={cx('scroll-mt-20 rounded-tile border border-rule bg-tile shadow-tile', className)}>
            {title ? (
                <div className="flex flex-wrap items-center justify-between gap-2 border-b border-rule px-3 py-2.5 sm:px-4 sm:py-3">
                    <div className="min-w-0">
                        <h2 className="text-[15px] font-semibold text-cobalt-900 sm:text-base">{titleCase(title)}</h2>
                        {description ? <p className="text-[13px] leading-snug text-ink-soft sm:text-sm">{description}</p> : null}
                    </div>
                    {actions}
                </div>
            ) : null}
            <div className={flush ? '' : 'p-3 sm:p-4'}>{children}</div>
        </section>
    );
}

export function Stat({ label, value, note, tone }: { label: string; value: ReactNode; note?: string; tone?: 'alert' | 'good' }) {
    return (
        <div className="min-w-0 px-3 py-2.5 sm:px-4 sm:py-3">
            <div className="truncate text-xs font-medium text-ink-soft sm:text-sm sm:font-normal">{titleCase(label)}</div>
            <div className={cx('mt-0.5 text-xl font-bold tracking-tight tabular-nums sm:text-2xl', tone === 'alert' ? 'text-crit-700' : tone === 'good' ? 'text-ok-700' : 'text-cobalt-900')}>{typeof value === 'number' ? <CountUp to={value} /> : value}</div>
            {note ? <div className="line-clamp-2 text-xs leading-snug text-ink-faint sm:truncate sm:text-sm">{note}</div> : null}
        </div>
    );
}

export function StatRow({ children }: { children: ReactNode }) {
    // Phones: a compact two-column grid with hairline gaps (an odd last tile spans both); wider: one row.
    return <div className="mb-4 grid grid-cols-2 gap-px overflow-hidden rounded-tile border border-rule bg-rule shadow-tile sm:flex sm:gap-0 sm:divide-x sm:divide-rule sm:bg-tile [&>*]:bg-tile [&>*]:sm:flex-1 [&>*:last-child:nth-child(odd)]:col-span-2">{children}</div>;
}

export function Chip({ className, children }: { className?: string; children: ReactNode }) {
    return <span className={cx('inline-flex items-center gap-1 whitespace-nowrap rounded-[4px] px-1.5 py-0.5 text-xs font-semibold', className)}>{children}</span>;
}

export function HealthChip({ health }: { health: Health }) {
    return <Chip className={HEALTH[health].chip}><LibIcon name={HEALTH[health].art} size={14} />{HEALTH[health].label}</Chip>;
}

export function ReproChip({ repro, until }: { repro?: string | null; until?: string | null }) {
    if (repro === 'monitoring') {
        // D-162: 3 days of monitoring after a delivery or a pregnancy loss
        return (
            <Chip className="bg-watch-100 text-watch-700">
                <LibIcon name="ic-recovery" size={14} /> Monitoring{until ? ` until ${until}` : ''}
            </Chip>
        );
    }
    if (repro === 'pregnant') {
        return (
            <Chip className="bg-manganese-100 text-manganese-600">
                <LibIcon name="ic-pregnant" size={14} /> Pregnant
            </Chip>
        );
    }
    if (repro === 'lactating') {
        return (
            <Chip className="bg-turq-100 text-turq-800">
                <LibIcon name="ic-lactating" size={14} /> Lactating
            </Chip>
        );
    }
    return null;
}

/** D-106: gender as a bold, coloured badge (♀ pink, ♂ blue) that is easy to see; `label` adds Female / Male. */
export function SexIcon({ sex, label = false }: { sex: string; label?: boolean }) {
    const male = sex === 'male';
    return (
        <span title={male ? 'Male' : 'Female'} aria-label={male ? 'Male' : 'Female'}
            className={`inline-flex shrink-0 items-center gap-1 rounded-full font-bold leading-none ${label ? 'px-2 py-1 text-xs' : 'size-6 justify-center text-base'} ${male ? 'bg-sky-100 text-sky-700 ring-1 ring-sky-300' : 'bg-pink-100 text-pink-700 ring-1 ring-pink-300'}`}>
            <LibIcon name={male ? 'ic-male' : 'ic-female'} size={label ? 15 : 18} className="bg-transparent" />{label ? (male ? 'Male' : 'Female') : null}
        </span>
    );
}

export function GoatLabel({ goat, size = 38, badges = true }: { goat: Pick<GoatCard, 'id' | 'number' | 'name' | 'coat' | 'sex' | 'photo'> & { breed?: string | null; stage?: string | null }; size?: number; badges?: boolean }) {
    return (
        <Link href={`/goats/${goat.id}`} prefetch className="group flex min-w-0 items-center gap-2.5">
            {goat.photo ? <img src={`/media/${goat.photo}`} alt="" width={size} height={size} loading="lazy" className="shrink-0 rounded-tile object-cover" style={{ width: size, height: size }} /> : <GoatPortrait number={goat.number} sex={goat.sex} stage={goat.stage} size={size} />}
            <span className="min-w-0">
                {/* D-096: the Tag ID is the goat's name on the farm; a name, if given, is small underneath. */}
                <span className="flex min-w-0 items-center gap-1.5"><span className="truncate font-mono font-semibold text-cobalt-800 group-hover:underline">{goat.number}</span>{badges ? <GoatBadges breed={goat.breed} stage={goat.stage} /> : null}</span>
                {goat.name ? <span className="block truncate text-sm text-ink-soft">{goat.name}</span> : null}
            </span>
        </Link>
    );
}

export function Field({ label, error, hint, children }: { label: string; error?: string; hint?: string; children: ReactNode }) {
    return (
        <label className="block">
            <span className="mb-1 block text-sm font-semibold text-ink">{titleCase(label)}</span>
            {children}
            {hint && !error ? <span className="mt-1 block text-sm text-ink-faint">{hint}</span> : null}
            {error ? <span className="mt-1 block text-sm font-medium text-crit-700">{error}</span> : null}
        </label>
    );
}

export function Empty({ title, children }: { title: string; children?: ReactNode }) {
    return (
        <div className="flex flex-col items-center gap-1 px-6 py-10 text-center">
            <Inbox size={22} className="text-ink-faint" aria-hidden />
            <p className="font-semibold text-ink">{title}</p>
            {children ? <p className="max-w-[46ch] text-sm text-ink-soft">{children}</p> : null}
        </div>
    );
}

export function Note({ children }: { children: ReactNode }) {
    return (
        <p className="flex items-start gap-2 rounded-tile bg-watch-100 px-3 py-2 text-sm text-watch-700">
            <AlertTriangle size={15} className="mt-0.5 shrink-0" aria-hidden />
            <span>{children}</span>
        </p>
    );
}

export function Tabs({ tabs, value, onChange }: { tabs: Array<{ id: string; label: string; count?: number }>; value: string; onChange: (id: string) => void }) {
    const group = useId();
    const { url } = usePage();
    // D-215: opened from an app menu (?solo=1), a section is its own page with no other tabs (D-218: no master page link).
    const query = url.split('?')[1] ?? '';
    const params = new URLSearchParams(query);
    if (params.get('solo') && tabs.some((t) => t.id === value)) {
        const current = tabs.find((t) => t.id === value)!;
        return (
            <div className="mb-4 flex flex-wrap items-center gap-2 border-b border-rule pb-2">
                <h2 className="text-lg font-bold text-cobalt-900">{titleCase(current.label)}{current.count !== undefined ? <span className="ml-1.5 text-base font-semibold text-ink-faint">{current.count}</span> : null}</h2>
            </div>
        );
    }
    return (
        <div role="tablist" className="-mx-1 mb-4 flex gap-1 overflow-x-auto border-b border-rule px-1">
            {tabs.map((tab) => (
                <button
                    key={tab.id}
                    role="tab"
                    type="button"
                    aria-selected={value === tab.id}
                    onClick={() => onChange(tab.id)}
                    className={cx(
                        'relative -mb-px min-h-11 whitespace-nowrap px-3 text-sm font-semibold transition-colors',
                        value === tab.id ? 'text-cobalt-900' : 'text-ink-soft hover:text-cobalt-800',
                    )}
                >
                    {/* D-126: the underline slides to the chosen tab. */}
                    {value === tab.id ? <motion.span layoutId={`tab-line-${group}`} className="absolute inset-x-1 bottom-0 h-0.5 rounded-full bg-turq-600" transition={{ type: 'spring', stiffness: 520, damping: 40 }} /> : null}
                    {titleCase(tab.label)}
                    {tab.count !== undefined ? <span className="ml-1.5 text-ink-faint">{tab.count}</span> : null}
                </button>
            ))}
        </div>
    );
}

/**
 * D-126: a number that counts up to its value when it first appears (and glides when it changes), like an app.
 * Plain number with reduced motion or on the RFID gun.
 */
export function CountUp({ to, duration = 700 }: { to: number; duration?: number }) {
    const reduced = useReducedMotion();
    const [shown, setShown] = useState(reduced ? to : 0);
    const from = useRef(reduced ? to : 0);
    useEffect(() => {
        if (reduced || (typeof document !== 'undefined' && document.documentElement.classList.contains('lite')) || !Number.isFinite(to)) { setShown(to); return; }
        const start = performance.now();
        const a = from.current;
        let raf = 0;
        const tick = (now: number) => {
            const t = Math.min(1, (now - start) / duration);
            const eased = 1 - Math.pow(1 - t, 3);
            setShown(a + (to - a) * eased);
            if (t < 1) raf = requestAnimationFrame(tick); else from.current = to;
        };
        raf = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(raf);
    }, [to, duration, reduced]);
    const decimals = Number.isInteger(to) ? 0 : (String(to).split('.')[1]?.length ?? 1);
    return <>{shown.toLocaleString('en-PK', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}</>;
}
