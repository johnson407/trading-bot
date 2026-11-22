<script setup>
import { formatMoney } from '@/Support/money.js';
import { computed } from 'vue';
import { Line } from 'vue-chartjs';
import {
    CategoryScale,
    Chart as ChartJS,
    Filler,
    Legend,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend, Filler);

const props = defineProps({
    labels: { type: Array, default: () => [] },
    series: { type: Array, default: () => [] },
    valueLabel: { type: String, default: 'Equity' },
    format: {
        type: String,
        default: 'money',
        validator: (value) => ['money', 'normalized'].includes(value),
    },
});

const colors = ['#60a5fa', '#34d399', '#fbbf24', '#f87171'];

function formatValue(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return props.format === 'money' ? formatMoney(value) : Number(value).toFixed(4);
}

const chartData = computed(() => ({
    labels: props.labels,
    datasets: props.series.map((series, index) => ({
        label: series.label,
        data: series.values.map((value) => (value === null || value === undefined ? null : Number(value))),
        borderColor: colors[index % colors.length],
        backgroundColor: `${colors[index % colors.length]}22`,
        pointRadius: props.labels.length === 1 ? 4 : 0,
        borderWidth: 2,
        tension: 0.15,
        fill: props.series.length === 1,
    })),
}));

const chartOptions = computed(() => ({
    responsive: true,
    maintainAspectRatio: false,
    interaction: { mode: 'index', intersect: false },
    plugins: {
        legend: {
            display: props.series.length > 1,
            labels: { color: '#cbd5e1' },
        },
        tooltip: {
            callbacks: {
                label: (context) => `${context.dataset.label}: ${formatValue(context.parsed.y)}`,
            },
        },
    },
    scales: {
        x: {
            ticks: { color: '#94a3b8', maxTicksLimit: 8 },
            grid: { color: '#334155' },
        },
        y: {
            ticks: { color: '#94a3b8' },
            grid: { color: '#334155' },
            title: { display: true, text: props.valueLabel, color: '#94a3b8' },
        },
    },
}));
</script>

<template>
    <div>
        <div v-if="labels.length" class="h-80">
            <Line :data="chartData" :options="chartOptions" />
        </div>
        <p v-else class="py-8 text-center text-sm text-slate-400">No equity samples yet.</p>

        <details v-if="labels.length" class="mt-4 text-sm text-slate-300">
            <summary class="cursor-pointer text-slate-400">View chart data as a table</summary>
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-700">
                    <thead>
                        <tr>
                            <th class="px-3 py-2 text-left font-medium text-slate-300">Time</th>
                            <th v-for="series in series" :key="series.label" class="px-3 py-2 text-right font-medium text-slate-300">{{ series.label }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                        <tr v-for="(label, index) in labels" :key="label + index">
                            <td class="px-3 py-2">{{ label }}</td>
                            <td v-for="series in series" :key="series.label" class="px-3 py-2 text-right">{{ formatValue(series.values[index]) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </details>
    </div>
</template>
