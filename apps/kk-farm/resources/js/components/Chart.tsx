import { BarChart, LineChart, PieChart } from 'echarts/charts';
import { GridComponent, LegendComponent, MarkLineComponent, TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { SVGRenderer } from 'echarts/renderers';
import { useEffect, useRef } from 'react';
import { SERIES } from '../lib/chartTheme';

echarts.use([BarChart, LineChart, PieChart, GridComponent, LegendComponent, MarkLineComponent, TooltipComponent, SVGRenderer]);

const BASE = {
    color: SERIES,
    textStyle: { fontFamily: 'Hanken Grotesk Variable, sans-serif', color: '#4d5a4f' },
    tooltip: { backgroundColor: '#163d22', borderWidth: 0, textStyle: { color: '#fff', fontSize: 13 }, confine: true },
    grid: { left: 8, right: 12, top: 18, bottom: 6, containLabel: true },
};

/** ECharts with the SVG renderer: crisp on phones and light on memory. */
export default function Chart({ option, height = 240, label, onSelect }: { option: Record<string, unknown>; height?: number; label: string; onSelect?: (name: string) => void }) {
    const select = useRef(onSelect);
    select.current = onSelect;
    const element = useRef<HTMLDivElement>(null);
    const chart = useRef<echarts.ECharts | null>(null);

    useEffect(() => {
        if (!element.current) {
            return;
        }
        const instance = echarts.init(element.current, undefined, { renderer: 'svg' });
        chart.current = instance;
        // D-071: a click on a bar or point opens the matching list (when the page passes onSelect).
        instance.on('click', (params: { name?: string }) => { if (select.current && params.name) select.current(params.name); });
        const observer = new ResizeObserver(() => instance.resize());
        observer.observe(element.current);
        return () => {
            observer.disconnect();
            instance.dispose();
        };
    }, []);

    useEffect(() => {
        const animation = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        chart.current?.setOption({ ...BASE, animation, animationDuration: 400, ...option }, true);
    }, [option]);

    return <div ref={element} role="img" aria-label={label} style={{ height, width: '100%', cursor: onSelect ? 'pointer' : undefined }} />;
}
