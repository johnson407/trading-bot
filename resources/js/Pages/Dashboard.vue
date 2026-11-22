<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EquityChart from '@/Components/EquityChart.vue';
import { formatMoney, formatSignedMoney } from '@/Support/money.js';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';

const props = defineProps({
    summary: {
        type: Object,
        required: true,
    },
    recentActivity: {
        type: Array,
        required: true,
    },
    equityHistory: {
        type: Object,
        required: true,
    },
});

const percent = (value) => `${Number(value ?? 0).toFixed(2)}%`;
const signedPercent = (value) => `${Number(value ?? 0) > 0 ? '+' : ''}${Number(value ?? 0).toFixed(2)}%`;

const equityLabels = computed(() =>
    props.equityHistory.points.map((point) => new Date(point.at).toLocaleString()),
);
const equitySeries = computed(() => [
    {
        label: 'Account equity',
        values: props.equityHistory.points.map((point) => point.equity),
    },
]);

const selectRange = (range) => {
    router.get(
        route('dashboard'),
        { range },
        {
            only: ['equityHistory'],
            preserveScroll: true,
            preserveState: true,
        },
    );
};

let poller = null;
let equityPoller = null;

onMounted(() => {
    poller = setInterval(() => {
        router.reload({
            only: ['summary', 'recentActivity'],
            preserveScroll: true,
            preserveState: true,
        });
    }, 5000);

    equityPoller = setInterval(() => {
        router.reload({
            only: ['equityHistory'],
            preserveScroll: true,
            preserveState: true,
        });
    }, 60_000);
});

onBeforeUnmount(() => {
    if (poller !== null) {
        clearInterval(poller);
    }

    if (equityPoller !== null) {
        clearInterval(equityPoller);
    }
});
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-slate-100 leading-tight">Dashboard</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="bg-slate-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm text-slate-300">Bots</div>
                        <div class="mt-1 text-2xl font-semibold text-slate-100">{{ summary.total }}</div>
                    </div>
                    <div class="bg-slate-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm text-slate-300">Running</div>
                        <div class="mt-1 text-2xl font-semibold text-green-300">{{ summary.running }}</div>
                    </div>
                    <div class="bg-slate-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm text-slate-300">Paused</div>
                        <div class="mt-1 text-2xl font-semibold text-yellow-200">{{ summary.paused }}</div>
                    </div>
                    <div class="bg-slate-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm text-slate-300">Idle</div>
                        <div class="mt-1 text-2xl font-semibold text-slate-200">{{ summary.idle }}</div>
                    </div>
                    <div class="bg-slate-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm text-slate-300">Stopped</div>
                        <div class="mt-1 text-2xl font-semibold text-slate-200">{{ summary.stopped }}</div>
                    </div>
                    <div class="bg-slate-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm text-slate-300">Realized P&amp;L</div>
                        <div class="mt-1 text-2xl font-semibold" :class="Number(summary.realized_pnl) < 0 ? 'text-red-300' : 'text-slate-100'">
                            {{ formatSignedMoney(summary.realized_pnl) }}
                        </div>
                    </div>
                    <div class="bg-slate-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm text-slate-300">Unrealized P&amp;L</div>
                        <div class="mt-1 text-2xl font-semibold" :class="Number(summary.unrealized_pnl) < 0 ? 'text-red-300' : 'text-slate-100'">
                            {{ formatSignedMoney(summary.unrealized_pnl) }}
                        </div>
                    </div>
                </div>

                <div class="bg-slate-800 overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="flex flex-wrap items-center justify-between gap-3 p-6 border-b border-slate-700">
                        <div>
                            <h3 class="text-lg font-medium text-slate-100">Equity history</h3>
                            <p class="mt-1 text-sm text-slate-400">Captured on a schedule from inventory and mark prices.</p>
                        </div>
                        <div class="flex gap-2">
                            <button
                                v-for="range in equityHistory.available_ranges"
                                :key="range"
                                type="button"
                                class="rounded border px-3 py-1 text-sm"
                                :class="range === equityHistory.range_days ? 'border-blue-400 bg-blue-500/10 text-blue-200' : 'border-slate-600 text-slate-300 hover:border-slate-500'"
                                @click="selectRange(range)"
                            >
                                {{ range }} days
                            </button>
                        </div>
                    </div>

                    <div v-if="!equityHistory.has_history" class="p-6 text-sm text-slate-300">
                        No equity history yet. Snapshots are collected on a schedule; check back after a few capture intervals.
                    </div>

                    <template v-else>
                        <p v-if="equityHistory.collecting" class="px-6 pt-4 text-sm text-yellow-200">
                            Collecting history - at least two snapshots are needed for period metrics.
                        </p>

                        <div v-if="equityHistory.metrics" class="grid gap-4 p-6 sm:grid-cols-3">
                            <div class="rounded-lg border border-slate-700 p-4">
                                <div class="text-sm text-slate-300">Period return</div>
                                <div
                                    class="mt-1 text-2xl font-semibold"
                                    :class="equityHistory.metrics.period_return === null ? 'text-slate-400' : Number(equityHistory.metrics.period_return) < 0 ? 'text-red-300' : 'text-green-300'"
                                >
                                    {{ equityHistory.metrics.period_return === null ? '—' : signedPercent(equityHistory.metrics.period_return) }}
                                </div>
                            </div>
                            <div class="rounded-lg border border-slate-700 p-4">
                                <div class="text-sm text-slate-300">Peak equity</div>
                                <div class="mt-1 text-2xl font-semibold text-slate-100">
                                    {{ formatMoney(equityHistory.metrics.peak_equity) }}
                                </div>
                            </div>
                            <div class="rounded-lg border border-slate-700 p-4">
                                <div class="text-sm text-slate-300">Maximum drawdown</div>
                                <div
                                    class="mt-1 text-2xl font-semibold"
                                    :class="Number(equityHistory.metrics.max_drawdown) > 0 ? 'text-red-300' : 'text-slate-100'"
                                >
                                    {{ percent(equityHistory.metrics.max_drawdown) }}
                                </div>
                            </div>
                        </div>

                        <div class="p-6 pt-0">
                            <EquityChart :labels="equityLabels" :series="equitySeries" value-label="Account equity" format="money" />
                        </div>
                    </template>
                </div>

                <div class="bg-slate-800 overflow-x-auto shadow-sm sm:rounded-lg">
                    <div class="flex items-center justify-between p-6 border-b border-slate-700">
                        <h3 class="text-lg font-medium text-slate-100">Recent activity</h3>
                        <Link :href="route('bots.index')" class="text-sm text-blue-300 hover:text-blue-200">Manage bots</Link>
                    </div>

                    <div v-if="recentActivity.length === 0" class="p-6 text-sm text-slate-300">
                        No activity yet. Create a bot to get started.
                    </div>

                    <table v-else class="min-w-full divide-y divide-slate-700">
                        <thead class="bg-slate-900">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-300 uppercase tracking-wider">Time</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-300 uppercase tracking-wider">Bot</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-300 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-slate-300 uppercase tracking-wider">Message</th>
                            </tr>
                        </thead>
                        <tbody class="bg-slate-800 divide-y divide-slate-700">
                            <tr v-for="entry in recentActivity" :key="entry.id">
                                <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-300">{{ entry.created_at ? new Date(entry.created_at).toLocaleString() : '' }}</td>
                                <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-100">
                                    <Link v-if="entry.bot_id" :href="route('bots.show', entry.bot_id)" class="text-blue-300 hover:text-blue-200">
                                        {{ entry.bot }}
                                    </Link>
                                    <span v-else>Account</span>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-300">{{ entry.type }}</td>
                                <td class="px-6 py-3 text-sm" :class="entry.severity === 'error' ? 'text-red-300' : 'text-slate-200'">
                                    {{ entry.message }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap gap-4 text-sm">
                    <Link :href="route('bots.create')" class="text-blue-300 hover:text-blue-200">Create a bot</Link>
                    <Link :href="route('exchange-credentials.index')" class="text-blue-300 hover:text-blue-200">Exchange credentials</Link>
                    <Link :href="route('kill-switch.create')" class="text-blue-300 hover:text-blue-200">Kill switch</Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
