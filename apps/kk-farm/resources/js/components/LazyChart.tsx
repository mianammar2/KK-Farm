import { lazy, Suspense } from 'react';

const Chart = lazy(() => import('./Chart'));

type Props = { option: Record<string, unknown>; height?: number; label: string; onSelect?: (name: string) => void };

/** D-088: ECharts (~180 KB compressed) loads only when a chart is on screen, never with the page itself. */
export default function LazyChart(props: Props) {
    return (
        <Suspense fallback={<div role="img" aria-label={props.label} className="animate-pulse rounded-tile bg-glaze" style={{ height: props.height ?? 240 }} />}>
            <Chart {...props} />
        </Suspense>
    );
}
