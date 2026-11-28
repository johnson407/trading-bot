<script setup>
import { buildChartData, buildTableRows, formatDateTime, formatPrice, formatTime } from '@/Support/gridActivity.js';
import { computed } from 'vue';
import { Line } from 'vue-chartjs';
import {
    Chart as ChartJS,
    Filler,
    Legend,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';

ChartJS.register(LinearScale, PointElement, LineElement, Tooltip, Legend, Filler);

const props = defineProps({
    activity: { type: Object, required: true },
    showFills: { type: Boolean, default: true },
    showCancels: { type: Boolean, default: true },
    showGrid: { type: Boolean, default: true },
});

const chartData = computed(() => buildChartData(props.activity));

const gridSegments = computed(() => {
    if (!props.showGrid) {
        return [];
    }

    return (props.activity.grids ?? []).flatMap((grid) =>
        (grid.levels ?? []).map((level) => ({
            price: Number(level.price),
            start: Date.parse(grid.started_at),
            end: Date.parse(grid.ended_at),
            current: grid.current,
            trading: grid.trading,
            side: level.side,
        })),
    );
});

const datasets = computed(() => {
    const data = chartData.value;
    const series = [];

    if (props.activity.price?.available && data.price.length > 0) {
        series.push({
            label: 'Price',
            data: data.price,
            borderColor: '#60a5fa',
            backgroundColor: 'rgba(96, 165, 250, 0.12)',
            borderWidth: 2,
            pointRadius: 0,
            tension: 0.15,
            fill: true,
        });
    }

    if (props.showFills) {
        series.push({
            label: 'Fills',
            data: data.fills,
            showLine: false,
            pointStyle: 'circle',
            pointRadius: 5,
            pointHoverRadius: 7,
            backgroundColor: '#34d399',
            borderColor: '#34d399',
        });
    }

    if (props.showCancels) {
        series.push({
            label: 'Cancels',
            data: data.cancels,
            showLine: false,
            pointStyle: 'crossRot',
            pointRadius: 5,
            pointHoverRadius: 7,
            backgroundColor: '#94a3b8',
            borderColor: '#94a3b8',
        });
    }

    return series;
});

const chartOptions = computed(() => {
    const data = chartData.value;
    const padding = data.yPadding;
    const dateTicks = props.activity.range === '7d';

    return {
        responsive: true,
        maintainAspectRatio: false,
        parsing: false,
        interaction: { mode: 'nearest', intersect: false },
        plugins: {
            legend: {
                display: true,
                labels: { color: '#cbd5e1', usePointStyle: true },
            },
            tooltip: {
                callbacks: {
                    title: (items) => (items.length ? formatDateTime(items[0].parsed.x) : ''),
                    label: (context) => {
                        const value = formatPrice(context.parsed.y);

                        return context.dataset.label === 'Price'
                            ? `Price: ${value}`
                            : `${context.dataset.label}: ${context.raw.side ?? ''} ${value}`.trim();
                    },
                },
            },
            gridLevels: { segments: gridSegments.value },
        },
        scales: {
            x: {
                type: 'linear',
                min: Date.parse(props.activity.window_start),
                max: Date.parse(props.activity.window_end),
                ticks: {
                    color: '#94a3b8',
                    maxTicksLimit: 8,
                    callback: (value) => (dateTicks ? formatDateTime(value) : formatTime(value)),
                },
                grid: { color: '#334155' },
            },
            y: {
                suggestedMin: data.yMin === null ? undefined : data.yMin - padding,
                suggestedMax: data.yMax === null ? undefined : data.yMax + padding,
                ticks: { color: '#94a3b8', callback: (value) => formatPrice(value) },
                grid: { color: '#334155' },
            },
        },
    };
});

const tableRows = computed(() => buildTableRows(props.activity, {
    showFills: props.showFills,
    showCancels: props.showCancels,
}));

const gridLevelsPlugin = {
    id: 'gridLevels',
    beforeDatasetsDraw(chart) {
        const segments = chart.options.plugins.gridLevels?.segments ?? [];
        const { ctx, chartArea, scales } = chart;

        if (segments.length === 0 || !chartArea) {
            return;
        }

        ctx.save();
        ctx.beginPath();
        ctx.rect(chartArea.left, chartArea.top, chartArea.width, chartArea.height);
        ctx.clip();

        segments.forEach((segment) => {
            const start = scales.x.getPixelForValue(segment.start);
            const end = scales.x.getPixelForValue(segment.end);
            const left = Math.max(Math.min(start, end), chartArea.left);
            const right = Math.min(Math.max(start, end), chartArea.right);
            const y = scales.y.getPixelForValue(segment.price);

            if (right <= left || !Number.isFinite(y) || y < chartArea.top || y > chartArea.bottom) {
                return;
            }

            ctx.strokeStyle = segment.current
                ? segment.trading
                    ? 'rgba(96, 165, 250, 0.9)'
                    : 'rgba(251, 191, 36, 0.8)'
                : 'rgba(148, 163, 184, 0.28)';
            ctx.lineWidth = segment.current ? 1.5 : 1;
            ctx.setLineDash(segment.current && !segment.trading ? [6, 4] : []);
            ctx.beginPath();
            ctx.moveTo(left, y);
            ctx.lineTo(right, y);
            ctx.stroke();
        });

        ctx.restore();
    },
};
</script>

<template>
    <div>
        <div v-if="datasets.length || gridSegments.length" class="h-96">
            <Line :data="{ datasets }" :options="chartOptions" :plugins="[gridLevelsPlugin]" />
        </div>
        <p v-else class="py-8 text-center text-sm text-slate-400">No chart data in this window.</p>

        <details v-if="tableRows.length" class="mt-4 text-sm text-slate-300">
            <summary class="cursor-pointer text-slate-400">View chart data as a table</summary>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-700">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-slate-300">Time</th>
                            <th class="px-3 py-2 text-left font-medium text-slate-300">Type</th>
                            <th class="px-3 py-2 text-left font-medium text-slate-300">Side</th>
                            <th class="px-3 py-2 text-right font-medium text-slate-300">Price</th>
                            <th class="px-3 py-2 text-right font-medium text-slate-300">Quantity</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                        <tr v-for="row in tableRows" :key="row.key">
                            <td class="whitespace-nowrap px-3 py-2">{{ formatDateTime(row.time) }}</td>
                            <td class="px-3 py-2">{{ row.kind }}</td>
                            <td class="px-3 py-2">{{ row.side }}</td>
                            <td class="px-3 py-2 text-right">{{ formatPrice(row.price) }}</td>
                            <td class="px-3 py-2 text-right">{{ row.detail }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
    </div>
</template>
