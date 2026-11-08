import { BarChart, LineChart, PieChart } from 'echarts/charts';
import { GridComponent, LegendComponent, MarkLineComponent, TooltipComponent } from 'echarts/components';
import * as echarts from 'echarts/core';
import { SVGRenderer } from 'echarts/renderers';
import { useEffect, useRef } from 'react';

echarts.use([BarChart, LineChart, PieChart, GridComponent, LegendComponent, MarkLineComponent, TooltipComponent, SVGRenderer]);

// D-068: brand green and gold first, then distinct colours.
export const SERIES = ['#1d5c2e', '#b8892b', '#5b8c3a', '#7a4a2b', '#2f7d74', '#c9a23f', '#8aa36b'];

const BASE = {
    color: SERIES,
    textStyle: { fontFamily: 'Hanken Grotesk Variable, sans-serif', color: '#4d5a4f' },
    tooltip: { backgroundColor: '#163d22', borderWidth: 0, textStyle: { color: '#fff', fontSize: 13 }, confine: true },
    grid: { left: 8, right: 12, top: 18, bottom: 6, containLabel: true },
};

/** ECharts with the SVG renderer: crisp on phones and light on memory. */
export default function Chart({ option, height = 240, label }: { option: Record<string, unknown>; height?: number; label: string }) {
    const element = useRef<HTMLDivElement>(null);
    const chart = useRef<echarts.ECharts | null>(null);

    useEffect(() => {
        if (!element.current) {
            return;
        }
        const instance = echarts.init(element.current, undefined, { renderer: 'svg' });
        chart.current = instance;
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

    return <div ref={element} role="img" aria-label={label} style={{ height, width: '100%' }} />;
}

export const axis = {
    category: (data: string[]) => ({ type: 'category', data, axisLine: { lineStyle: { color: '#cfd3c2' } }, axisTick: { show: false }, axisLabel: { color: '#5e6a5f' } }),
    value: (format?: (v: number) => string) => ({ type: 'value', splitLine: { lineStyle: { color: '#ebece2' } }, axisLabel: { color: '#5e6a5f', formatter: format } }),
};
