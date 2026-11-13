import { CountUp, LibIcon } from './ui';

export type ReproKpis = { events: number; deliveries: number; abortions: number; stillborn: number; live: number; kiddingRate: number | null; lossRate: number | null; recovery: number | null };

/**
 * D-161: reproduction KPIs for the owner (Breeding page and dashboard): kidding events, successful deliveries, abortion
 * cases, stillborn and live kids, kidding rate (live kids per doe that delivered), pregnancy loss rate and post-kidding
 * recovery. Anything that is zero is hidden.
 */
export default function ReproKpiTiles({ k }: { k: ReproKpis }) {
    const tiles = [
        { art: 'ic-kidding', label: 'Kidding Events', value: k.events },
        { art: 'ic-healthy', label: 'Successful Deliveries', value: k.deliveries },
        { art: 'ic-sick', label: 'Abortion Cases', value: k.abortions, alert: true },
        { art: 'ic-died', label: 'Stillborn Kids', value: k.stillborn, alert: true },
        { art: 'cls-kid', label: 'Total Live Kids', value: k.live },
        { art: 'app-growth', label: 'Kidding Rate', value: k.kiddingRate, suffix: ' kids/doe' },
        { art: 'ic-recovery', label: 'Pregnancy Loss Rate', value: k.lossRate, suffix: '%', alert: true },
        { art: 'ic-lactating', label: 'Post-Kidding Recovery', value: k.recovery, suffix: '%' },
    ].filter((t) => t.value);
    if (!tiles.length) return null;
    return (
        <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-4">
            {tiles.map((t) => (
                <div key={t.label} className="kk-sign kk-rise">
                    <span className="kk-icon-tile" aria-hidden><LibIcon name={t.art} size={36} className="bg-transparent" /></span>
                    <span className="kk-sign-paper min-w-0">
                        <span className="block truncate text-[11px] font-semibold text-[#4d6356] sm:text-xs">{t.label}</span>
                        <span className={`block text-xl font-extrabold tabular-nums sm:text-2xl ${t.alert ? 'text-crit-700' : 'text-[#022a1c]'}`}>
                            {Number.isInteger(t.value) ? <CountUp to={t.value as number} /> : t.value}{t.suffix ?? ''}
                        </span>
                    </span>
                </div>
            ))}
        </div>
    );
}
