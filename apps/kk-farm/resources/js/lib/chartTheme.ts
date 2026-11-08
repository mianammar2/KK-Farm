// D-068: brand green and gold first, then distinct colours. Kept apart from the chart component so a page can build
// its chart options without loading ECharts (D-088).
export const SERIES = ['#1d5c2e', '#b8892b', '#5b8c3a', '#7a4a2b', '#2f7d74', '#c9a23f', '#8aa36b'];

export const axis = {
    category: (data: string[]) => ({ type: 'category', data, axisLine: { lineStyle: { color: '#cfd3c2' } }, axisTick: { show: false }, axisLabel: { color: '#5e6a5f' } }),
    value: (format?: (v: number) => string) => ({ type: 'value', splitLine: { lineStyle: { color: '#ebece2' } }, axisLabel: { color: '#5e6a5f', formatter: format } }),
};
