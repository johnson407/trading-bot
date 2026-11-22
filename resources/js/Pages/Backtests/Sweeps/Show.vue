<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, watch } from 'vue';

const props = defineProps({
    sweep: { type: Object, required: true },
    runs: { type: Array, default: () => [] },
    compareUrl: { type: String, default: null },
    active: { type: Boolean, default: false },
});

let poller = null;

function startPolling() {
    if (poller) return;

    poller = setInterval(() => {
        router.reload({ only: ['sweep', 'runs', 'compareUrl', 'active'], preserveScroll: true, preserveState: true });
    }, 3000);
}

function stopPolling() {
    if (poller) {
        clearInterval(poller);
        poller = null;
    }
}

onMounted(() => {
    if (props.active) startPolling();
});

watch(() => props.active, (active) => (active ? startPolling() : stopPolling()));

onUnmounted(stopPolling);

function timestamp(value) {
    return value ? new Date(value).toLocaleString() : '—';
}

function number(value, digits = 2) {
    return Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

function signed(value, digits = 2) {
    const parsed = Number(value ?? 0);
    return `${parsed > 0 ? '+' : ''}${number(parsed, digits)}`;
}

function statusClass(status) {
    return {
        completed: 'bg-green-950 text-green-300',
        running: 'bg-blue-950 text-blue-300',
        pending: 'bg-amber-950 text-amber-300',
        failed: 'bg-red-950 text-red-300',
        incomplete: 'bg-slate-900 text-slate-300',
    }[status] ?? 'bg-slate-900 text-slate-300';
}

const endReasonLabels = {
    range_end: 'Range end',
    risk_pause: 'Risk pause',
    strategy_stop: 'Strategy stop',
    stop_loss: 'Stop-loss',
    take_profit: 'Take-profit',
    range_exit: 'Range exit',
};

function endReasonLabel(value) {
    return endReasonLabels[value] ?? (value ? value.replaceAll('_', ' ') : '—');
}
</script>

<template>
    <Head :title="`Sweep · ${sweep.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-slate-100">{{ sweep.name }}</h2>
                    <p class="mt-1 text-sm text-slate-400">
                        {{ sweep.bot_name ?? 'Deleted bot' }} · {{ sweep.symbol }} · {{ sweep.timeframe }}
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <Link v-if="compareUrl" :href="compareUrl" class="rounded-md border border-slate-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-slate-200">Compare completed</Link>
                    <Link :href="route('backtest-sweeps.index')" class="text-sm text-slate-300 underline">Back to sweeps</Link>
                    <Link :href="route('backtests.index')" class="text-sm text-slate-300 underline">Back to backtests</Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="bg-slate-800 p-5 shadow sm:rounded-lg">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Status</p>
                        <p class="mt-2">
                            <span class="rounded-full px-2 py-1 text-sm capitalize" :class="statusClass(sweep.status)">{{ sweep.status }}</span>
                        </p>
                        <p class="mt-2 text-xs text-slate-400">{{ sweep.active_runs_count }} active · {{ sweep.completed_runs_count }} completed · {{ sweep.failed_runs_count }} failed</p>
                    </div>
                    <div class="bg-slate-800 p-5 shadow sm:rounded-lg">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Progress</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-100">{{ sweep.progress }}%</p>
                        <div class="mt-2 h-2 overflow-hidden rounded bg-slate-900">
                            <div class="h-full bg-blue-500" :style="{ width: `${sweep.progress}%` }"></div>
                        </div>
                    </div>
                    <div class="bg-slate-800 p-5 shadow sm:rounded-lg">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Combinations</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-100">{{ sweep.total_runs }}</p>
                        <p class="mt-1 text-sm text-slate-400">{{ sweep.completed_runs_count }} ready to compare</p>
                    </div>
                    <div class="bg-slate-800 p-5 shadow sm:rounded-lg">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Period</p>
                        <p class="mt-2 text-sm text-slate-200">{{ timestamp(sweep.start_at) }}</p>
                        <p class="text-sm text-slate-400">to {{ timestamp(sweep.end_at) }}</p>
                    </div>
                </div>

                <div class="bg-slate-800 p-5 shadow sm:rounded-lg">
                    <h3 class="text-base font-semibold text-slate-100">Swept values</h3>
                    <div class="mt-3 grid gap-4 sm:grid-cols-3">
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-400">Center prices</p>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <span v-for="value in sweep.parameters.center_prices" :key="value" class="rounded-full bg-slate-900 px-2 py-0.5 text-xs text-slate-200">{{ value }}</span>
                            </div>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-400">Spacing values</p>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <span v-for="value in sweep.parameters.spacing_values" :key="value" class="rounded-full bg-slate-900 px-2 py-0.5 text-xs text-slate-200">{{ value }}</span>
                            </div>
                        </div>
                        <div>
                            <p class="text-xs uppercase tracking-wide text-slate-400">Level counts</p>
                            <div class="mt-1 flex flex-wrap gap-1">
                                <span v-for="value in sweep.parameters.level_counts" :key="value" class="rounded-full bg-slate-900 px-2 py-0.5 text-xs text-slate-200">{{ value }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-slate-800 shadow sm:rounded-lg">
                    <div class="border-b border-slate-700 px-5 py-4">
                        <h3 class="text-base font-semibold text-slate-100">Combinations</h3>
                        <p class="mt-1 text-xs text-slate-400">Each row is a normal backtest with its own immutable snapshot.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-700">
                            <thead class="bg-slate-900">
                                <tr>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Run</th>
                                    <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Center</th>
                                    <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Spacing</th>
                                    <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Levels</th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Status</th>
                                    <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Return</th>
                                    <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Max drawdown</th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">End reason</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700">
                                <tr v-for="run in runs" :key="run.id">
                                    <td class="px-5 py-3 text-sm text-slate-100">
                                        <Link :href="route('backtests.show', run.id)" class="font-medium underline">{{ run.name }}</Link>
                                    </td>
                                    <td class="px-5 py-3 text-right text-sm text-slate-200">{{ run.swept.center_price }}</td>
                                    <td class="px-5 py-3 text-right text-sm text-slate-200">{{ run.swept.spacing_value }}</td>
                                    <td class="px-5 py-3 text-right text-sm text-slate-200">{{ run.swept.level_count }}</td>
                                    <td class="px-5 py-3 text-sm">
                                        <span class="rounded-full px-2 py-1 text-xs capitalize" :class="statusClass(run.status)">{{ run.status }}</span>
                                        <p v-if="run.status === 'running'" class="mt-1 text-xs text-slate-400">{{ run.progress }}% · {{ run.candles_processed }} candles</p>
                                        <p v-if="run.error" class="mt-1 max-w-xs text-xs text-red-300">{{ run.error }}</p>
                                    </td>
                                    <td class="px-5 py-3 text-right text-sm" :class="Number(run.metrics?.return_percent ?? 0) < 0 ? 'text-red-300' : 'text-green-300'">
                                        {{ run.metrics ? signed(run.metrics.return_percent) + '%' : '—' }}
                                    </td>
                                    <td class="px-5 py-3 text-right text-sm text-slate-300">
                                        {{ run.metrics ? number(run.metrics.max_drawdown_percent) + '%' : '—' }}
                                    </td>
                                    <td class="px-5 py-3 text-sm text-slate-300">{{ endReasonLabel(run.end_reason) }}</td>
                                </tr>
                                <tr v-if="runs.length === 0">
                                    <td colspan="8" class="px-5 py-8 text-center text-sm text-slate-400">No runs in this sweep.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
