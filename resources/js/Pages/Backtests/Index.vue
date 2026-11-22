<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, watch } from 'vue';

const props = defineProps({
    runs: { type: Array, default: () => [] },
    active: { type: Boolean, default: false },
});

let poller = null;

function startPolling() {
    if (poller) return;

    poller = setInterval(() => {
        router.reload({ only: ['runs', 'active'], preserveScroll: true, preserveState: true });
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

function percent(value) {
    const number = Number(value ?? 0);
    return `${number > 0 ? '+' : ''}${number.toFixed(2)}%`;
}

function statusClass(status) {
    return {
        completed: 'bg-green-950 text-green-300',
        running: 'bg-blue-950 text-blue-300',
        pending: 'bg-amber-950 text-amber-300',
        failed: 'bg-red-950 text-red-300',
    }[status] ?? 'bg-slate-900 text-slate-300';
}

function destroy(run) {
    if (confirm(`Delete backtest "${run.name}"?`)) {
        router.delete(route('backtests.destroy', run.id), { preserveScroll: true });
    }
}
</script>

<template>
    <Head title="Backtests" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-slate-100">Backtests</h2>
                <div class="flex items-center gap-3">
                    <Link :href="route('backtest-sweeps.index')" class="rounded-md border border-slate-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-slate-200">Parameter sweeps</Link>
                    <Link :href="route('backtests.create')" class="rounded-md bg-blue-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white">New backtest</Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-x-auto bg-slate-800 shadow sm:rounded-lg">
                    <table class="min-w-full divide-y divide-slate-700">
                        <thead class="bg-slate-900">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Run</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Symbol</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Range</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Status</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Return</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Max drawdown</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Fills</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-700 bg-slate-800">
                            <tr v-for="run in runs" :key="run.id">
                                <td class="px-6 py-4 text-sm text-slate-100">
                                    <Link :href="route('backtests.show', run.id)" class="font-medium underline">{{ run.name }}</Link>
                                    <p class="mt-1 text-xs text-slate-400">{{ run.strategy_key }} · {{ run.timeframe }}</p>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-300">{{ run.symbol }}</td>
                                <td class="px-6 py-4 text-sm text-slate-300">
                                    {{ timestamp(run.start_at) }}<br />
                                    <span class="text-xs text-slate-400">to {{ timestamp(run.end_at) }}</span>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="rounded-full px-2 py-1 text-xs capitalize" :class="statusClass(run.status)">{{ run.status }}</span>
                                    <p v-if="run.status === 'running'" class="mt-1 text-xs text-slate-400">{{ run.progress }}% · {{ run.candles_processed }} candles</p>
                                    <p v-if="run.error" class="mt-1 max-w-xs text-xs text-red-300">{{ run.error }}</p>
                                </td>
                                <td class="px-6 py-4 text-right text-sm" :class="Number(run.metrics?.return_percent ?? 0) < 0 ? 'text-red-300' : 'text-green-300'">
                                    {{ run.metrics ? percent(run.metrics.return_percent) : '—' }}
                                </td>
                                <td class="px-6 py-4 text-right text-sm text-slate-300">
                                    {{ run.metrics ? percent(run.metrics.max_drawdown_percent) : '—' }}
                                </td>
                                <td class="px-6 py-4 text-right text-sm text-slate-300">{{ run.metrics?.fill_count ?? '—' }}</td>
                                <td class="px-6 py-4 text-right text-sm">
                                    <button type="button" class="text-red-300 underline" @click="destroy(run)">Delete</button>
                                </td>
                            </tr>
                            <tr v-if="runs.length === 0">
                                <td colspan="8" class="px-6 py-8 text-center text-sm text-slate-300">
                                    No backtests yet.
                                    <Link :href="route('backtests.create')" class="underline">Run one from a bot.</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
