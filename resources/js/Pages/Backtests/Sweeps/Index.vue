<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, watch } from 'vue';

const props = defineProps({
    sweeps: { type: Array, default: () => [] },
    active: { type: Boolean, default: false },
});

let poller = null;

function startPolling() {
    if (poller) return;

    poller = setInterval(() => {
        router.reload({ only: ['sweeps', 'active'], preserveScroll: true, preserveState: true });
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

function statusClass(status) {
    return {
        completed: 'bg-green-950 text-green-300',
        running: 'bg-blue-950 text-blue-300',
        pending: 'bg-amber-950 text-amber-300',
        failed: 'bg-red-950 text-red-300',
        incomplete: 'bg-slate-900 text-slate-300',
    }[status] ?? 'bg-slate-900 text-slate-300';
}
</script>

<template>
    <Head title="Backtest sweeps" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-slate-100">Parameter sweeps</h2>
                    <p class="mt-1 text-sm text-slate-400">Compare center price, spacing, and level count combinations.</p>
                </div>
                <div class="flex items-center gap-3">
                    <Link :href="route('backtests.index')" class="text-sm text-slate-300 underline">Back to backtests</Link>
                    <Link :href="route('backtest-sweeps.create')" class="rounded-md bg-blue-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white">New sweep</Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-x-auto bg-slate-800 shadow sm:rounded-lg">
                    <table class="min-w-full divide-y divide-slate-700">
                        <thead class="bg-slate-900">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Sweep</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Bot / symbol</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Period</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Combinations</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Progress</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Status</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-700 bg-slate-800">
                            <tr v-for="sweep in sweeps" :key="sweep.id">
                                <td class="px-6 py-4 text-sm text-slate-100">
                                    <Link :href="route('backtest-sweeps.show', sweep.id)" class="font-medium underline">{{ sweep.name }}</Link>
                                    <p class="mt-1 text-xs text-slate-400">{{ sweep.timeframe }} · {{ sweep.symbol }}</p>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-300">
                                    {{ sweep.bot_name ?? 'Deleted bot' }}
                                    <p class="mt-1 text-xs text-slate-400">{{ sweep.symbol }}</p>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-300">
                                    {{ timestamp(sweep.start_at) }}<br />
                                    <span class="text-xs text-slate-400">to {{ timestamp(sweep.end_at) }}</span>
                                </td>
                                <td class="px-6 py-4 text-right text-sm text-slate-200">{{ sweep.total_runs }}</td>
                                <td class="px-6 py-4 text-sm text-slate-300">
                                    <div class="h-2 w-32 overflow-hidden rounded bg-slate-900">
                                        <div class="h-full bg-blue-500" :style="{ width: `${sweep.progress}%` }"></div>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-400">
                                        {{ sweep.progress }}% · {{ sweep.completed_runs_count }} completed · {{ sweep.failed_runs_count }} failed
                                    </p>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="rounded-full px-2 py-1 text-xs capitalize" :class="statusClass(sweep.status)">{{ sweep.status }}</span>
                                    <p v-if="sweep.active_runs_count > 0" class="mt-1 text-xs text-slate-400">{{ sweep.active_runs_count }} active</p>
                                </td>
                                <td class="px-6 py-4 text-right text-sm">
                                    <Link :href="route('backtest-sweeps.show', sweep.id)" class="text-slate-200 underline">View</Link>
                                </td>
                            </tr>
                            <tr v-if="sweeps.length === 0">
                                <td colspan="7" class="px-6 py-8 text-center text-sm text-slate-300">
                                    No parameter sweeps yet.
                                    <Link :href="route('backtest-sweeps.create')" class="underline">Create one from a grid bot.</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
