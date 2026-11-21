<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EquityChart from '@/Components/EquityChart.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    runs: { type: Array, default: () => [] },
});

const timestamps = computed(() => {
    const values = new Set();

    props.runs.forEach((run) => {
        (run.normalized_curve ?? []).forEach((point) => values.add(point.at));
    });

    return [...values].sort((a, b) => new Date(a) - new Date(b));
});

const labels = computed(() => timestamps.value.map((value) => new Date(value).toLocaleString()));

const series = computed(() => props.runs.map((run) => {
    const points = new Map((run.normalized_curve ?? []).map((point) => [point.at, point.value]));

    return {
        label: run.name,
        values: timestamps.value.map((timestamp) => points.get(timestamp) ?? null),
    };
}));

function number(value, digits = 2) {
    return Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

function signed(value, digits = 2) {
    const parsed = Number(value ?? 0);
    return `${parsed > 0 ? '+' : ''}${number(parsed, digits)}`;
}
</script>

<template>
    <Head title="Compare backtests" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-slate-100">Compare backtests</h2>
                    <p class="mt-1 text-sm text-slate-400">Normalized equity · same symbol, timeframe, and range</p>
                </div>
                <Link :href="route('backtests.index')" class="text-sm text-slate-300 underline">Back to backtests</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="bg-slate-800 p-5 shadow sm:rounded-lg">
                    <h3 class="text-base font-semibold text-slate-100">Normalized equity</h3>
                    <p class="mb-4 mt-1 text-xs text-slate-400">Each curve is divided by its starting capital, so all runs begin at 1.0.</p>
                    <EquityChart :labels="labels" :series="series" value-label="Equity / capital" />
                </div>

                <div class="overflow-x-auto bg-slate-800 shadow sm:rounded-lg">
                    <table class="min-w-full divide-y divide-slate-700">
                        <thead class="bg-slate-900">
                            <tr>
                                <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Run</th>
                                <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Strategy</th>
                                <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Outcome</th>
                                <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Return</th>
                                <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Net P&amp;L</th>
                                <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Max drawdown</th>
                                <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Fees</th>
                                <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Fills</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-700">
                            <tr v-for="run in runs" :key="run.id">
                                <td class="px-5 py-4 text-sm text-slate-100">
                                    <Link :href="route('backtests.show', run.id)" class="underline">{{ run.name }}</Link>
                                    <p class="mt-1 text-xs text-slate-400">{{ run.symbol }} · {{ run.timeframe }}</p>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-300">{{ run.strategy_key }}</td>
                                <td class="px-5 py-4 text-sm text-slate-300">
                                    {{ run.end_reason ?? '—' }}
                                    <span class="block text-xs text-slate-400">{{ run.candles_processed.toLocaleString() }} candles</span>
                                </td>
                                <td class="px-5 py-4 text-right text-sm" :class="Number(run.metrics?.return_percent ?? 0) < 0 ? 'text-red-300' : 'text-green-300'">
                                    {{ run.metrics ? signed(run.metrics.return_percent) + '%' : '—' }}
                                </td>
                                <td class="px-5 py-4 text-right text-sm" :class="Number(run.metrics?.net_pnl ?? 0) < 0 ? 'text-red-300' : 'text-green-300'">
                                    {{ run.metrics ? signed(run.metrics.net_pnl) : '—' }}
                                </td>
                                <td class="px-5 py-4 text-right text-sm text-slate-300">{{ run.metrics ? number(run.metrics.max_drawdown_percent) + '%' : '—' }}</td>
                                <td class="px-5 py-4 text-right text-sm text-slate-300">{{ run.metrics ? number(run.metrics.fees, 4) : '—' }}</td>
                                <td class="px-5 py-4 text-right text-sm text-slate-300">{{ run.metrics?.fill_count ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="text-xs text-slate-400">
                    Backtests use close-only fills and start flat. Results are directional, not predictive; compare runs that use the same timeframe and range.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
