// D-068: brand green and gold first, then distinct colours. Kept apart from the chart component so a page can build
// its chart options without loading ECharts (D-088).
export const SERIES = ['#1d5c2e', '#b8892b', '#5b8c3a', '#7a4a2b', '#2f7d74', '#c9a23f', '#8aa36b'];

export const axis = {
    category: (data: string[]) => ({ type: 'category', data, axisLine: { lineStyle: { color: '#cfd3c2' } }, axisTick: { show: false }, axisLabel: { color: '#5e6a5f' } }),
    value: (format?: (v: number) => string) => ({ type: 'value', splitLine: { lineStyle: { color: '#ebece2' } }, axisLabel: { color: '#5e6a5f', formatter: format } }),
};

/** D-139: a goat silhouette for ECharts symbols (pictorial bars), facing right, in a 32 × 26 box. */
export const GOAT_PATH = 'path://M6 12L3 9.5L4 9L7 11Q9 8 15 8Q20 8 22 10L24.5 5Q27.5 3.8 29.6 6.4L30 9.2Q28.6 10.6 26.8 10L25 13.5Q25.5 16 23 18L23 24L21 24L21 19Q17 20 12 19L12 24L10 24L10 18Q6.5 16 6 12ZM24.6 5.2Q23.8 2 22.2 1L22.8 0.6Q25.4 1.8 25.8 5Z';

/** D-139: farm colours for charts: grass, earth, wheat. */
/** D-146: KK digital palette for charts. */
export const FARM = { grass: '#2e9e4f', grassLight: 'rgba(46,158,79,0.25)', earth: '#1f7a3a', wheat: '#e0a33a', ink: '#3d5446', rule: '#dbe6de' };
