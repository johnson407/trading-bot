<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EquityChart from '@/Components/EquityChart.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, watch } from 'vue';

const props = defineProps({
    run: { type: Object, required: true },
    fills: { type: Array, default: () => [] },
    compareIds: { type: Array, default: () => [] },
    assumptions: { type: Array, default: () => [] },
});

let poller = null;

function startPolling() {
    if (poller) return;

    poller = setInterval(() => {
        router.reload({ only: ['run', 'fills', 'compareIds'], preserveScroll: true, preserveState: true });
    }, 3000);
}

function stopPolling() {
    if (poller) {
        clearInterval(poller);
        poller = null;
    }
}

onMounted(() => {
    if (['pending', 'running'].includes(props.run.status)) startPolling();
});

watch(
    () => props.run.status,
    (status) => (['pending', 'running'].includes(status) ? startPolling() : stopPolling()),
);

onUnmounted(stopPolling);

const metrics = computed(() => props.run.metrics ?? null);
const curve = computed(() => props.run.equity_curve ?? []);
const chartLabels = computed(() => curve.value.map((point) => new Date(point.at).toLocaleString()));
const chartSeries = computed(() => [{ label: 'Equity', values: curve.value.map((point) => point.equity) }]);
const compareUrl = computed(() => {
    const query = new URLSearchParams();
    props.compareIds.forEach((id) => query.append('ids[]', id));
    return `${route('backtests.compare')}?${query.toString()}`;
});

const statusClass = computed(() => ({
    completed: 'bg-green-950 text-green-300',
    running: 'bg-blue-950 text-blue-300',
    pending: 'bg-amber-950 text-amber-300',
    failed: 'bg-red-950 text-red-300',
}[props.run.status] ?? 'bg-slate-900 text-slate-300'));

const endReasonLabels = {
    range_end: 'Range end',
    risk_pause: 'Risk pause',
    strategy_stop: 'Strategy stop',
    stop_loss: 'Stop-loss',
    take_profit: 'Take-profit',
    range_exit: 'Range exit',
};

function endReasonLabel(value) {
    return endReasonLabels[value] ?? (value ? value.replaceAll('_', ' ') : '');
}

function number(value, digits = 2) {
    return Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

function signed(value, digits = 2) {
    const parsed = Number(value ?? 0);
    return `${parsed > 0 ? '+' : ''}${number(parsed, digits)}`;
}

function timestamp(value) {
    return value ? new Date(value).toLocaleString() : '—';
}

function destroy() {
    if (confirm(`Delete backtest "${props.run.name}"?`)) {
        router.delete(route('backtests.destroy', props.run.id));
    }
}
</script>

<template>
    <Head :title="`Backtest · ${run.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-slate-100">{{ run.name }}</h2>
                    <p class="mt-1 text-sm text-slate-400">
                        {{ run.symbol }} · {{ run.timeframe }} · {{ run.strategy_key }} · {{ run.exchange }}
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <Link v-if="compareIds.length > 1" :href="compareUrl" class="rounded-md border border-slate-600 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-slate-200">Compare</Link>
                    <Link :href="route('backtests.index')" class="text-sm text-slate-300 underline">Back to backtests</Link>
                    <button type="button" class="text-sm text-red-300 underline" @click="destroy">Delete</button>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="bg-slate-800 p-5 shadow sm:rounded-lg">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Status</p>
                        <p class="mt-2">
                            <span class="rounded-full px-2 py-1 text-sm capitalize" :class="statusClass">{{ run.status }}</span>
                        </p>
                        <p v-if="run.status === 'running'" class="mt-2 text-xs text-slate-400">{{ run.progress }}% · {{ run.candles_processed }} candles</p>
                        <p v-if="run.end_reason" class="mt-2 text-xs text-slate-400">Ended: {{ endReasonLabel(run.end_reason) }}</p>
                        <p v-if="run.error" class="mt-2 text-xs text-red-300">{{ run.error }}</p>
                    </div>
                    <div class="bg-slate-800 p-5 shadow sm:rounded-lg">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Final equity</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-100">{{ metrics ? number(metrics.final_equity) : '—' }}</p>
                        <p v-if="metrics" class="mt-1 text-sm" :class="Number(metrics.net_pnl) < 0 ? 'text-red-300' : 'text-green-300'">{{ signed(metrics.net_pnl) }} net</p>
                    </div>
                    <div class="bg-slate-800 p-5 shadow sm:rounded-lg">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Return</p>
                        <p class="mt-2 text-2xl font-semibold" :class="Number(metrics?.return_percent ?? 0) < 0 ? 'text-red-300' : 'text-green-300'">
                            {{ metrics ? signed(metrics.return_percent) + '%' : '—' }}
                        </p>
                        <p v-if="metrics" class="mt-1 text-sm text-slate-400">Max drawdown {{ number(metrics.max_drawdown_percent) }}%</p>
                    </div>
                    <div class="bg-slate-800 p-5 shadow sm:rounded-lg">
                        <p class="text-xs uppercase tracking-wide text-slate-400">Activity</p>
                        <p class="mt-2 text-2xl font-semibold text-slate-100">{{ metrics?.fill_count ?? 0 }} fills</p>
                        <p v-if="metrics" class="mt-1 text-sm text-slate-400">Fees {{ number(metrics.fees, 4) }} · {{ metrics.candles_processed }} candles</p>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-3">
                    <div class="bg-slate-800 p-5 shadow sm:rounded-lg lg:col-span-2">
                        <h3 class="text-base font-semibold text-slate-100">Equity curve</h3>
                        <p class="mb-4 mt-1 text-xs text-slate-400">Sampled at candle close, capped at 1,000 points.</p>
                        <EquityChart :labels="chartLabels" :series="chartSeries" />
                    </div>

                    <div class="bg-slate-800 p-5 shadow sm:rounded-lg">
                        <h3 class="text-base font-semibold text-slate-100">Range and configuration</h3>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div class="flex justify-between gap-4"><dt class="text-slate-400">Start</dt><dd class="text-right text-slate-200">{{ timestamp(run.start_at) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-slate-400">End</dt><dd class="text-right text-slate-200">{{ timestamp(run.end_at) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-slate-400">Capital</dt><dd class="text-right text-slate-200">{{ number(run.capital_allocation) }}</dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-slate-400">Fee rate</dt><dd class="text-right text-slate-200">{{ run.fee_rate }}</dd></div>
                            <div v-if="metrics" class="flex justify-between gap-4"><dt class="text-slate-400">Realized</dt><dd class="text-right text-slate-200">{{ signed(metrics.realized_pnl) }}</dd></div>
                            <div v-if="metrics" class="flex justify-between gap-4"><dt class="text-slate-400">Unrealized</dt><dd class="text-right text-slate-200">{{ signed(metrics.unrealized_pnl) }}</dd></div>
                            <div v-if="metrics" class="flex justify-between gap-4"><dt class="text-slate-400">Final base</dt><dd class="text-right text-slate-200">{{ number(metrics.final_base_inventory, 6) }}</dd></div>
                            <div v-if="metrics" class="flex justify-between gap-4"><dt class="text-slate-400">Final quote</dt><dd class="text-right text-slate-200">{{ number(metrics.final_quote_inventory) }}</dd></div>
                        </dl>
                    </div>
                </div>

                <div class="bg-slate-800 p-5 shadow sm:rounded-lg">
                    <h3 class="text-base font-semibold text-slate-100">Assumptions</h3>
                    <ul class="mt-3 list-disc space-y-2 pl-5 text-sm text-slate-300">
                        <li v-for="assumption in assumptions" :key="assumption">{{ assumption }}</li>
                    </ul>
                </div>

                <div class="bg-slate-800 shadow sm:rounded-lg">
                    <div class="border-b border-slate-700 px-5 py-4">
                        <h3 class="text-base font-semibold text-slate-100">Simulated fills</h3>
                        <p v-if="metrics && metrics.fill_count > fills.length" class="mt-1 text-xs text-slate-400">
                            Showing the first {{ fills.length.toLocaleString() }} of {{ metrics.fill_count.toLocaleString() }} fills.
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-700">
                            <thead class="bg-slate-900">
                                <tr>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">#</th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Filled at</th>
                                    <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Side</th>
                                    <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Price</th>
                                    <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Quantity</th>
                                    <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Fee</th>
                                    <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Grid level</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-700">
                                <tr v-for="fill in fills" :key="fill.id">
                                    <td class="px-5 py-3 text-sm text-slate-300">{{ fill.sequence }}</td>
                                    <td class="px-5 py-3 text-sm text-slate-300">{{ timestamp(fill.filled_at) }}</td>
                                    <td class="px-5 py-3 text-sm capitalize" :class="fill.side === 'buy' ? 'text-green-300' : 'text-red-300'">{{ fill.side }}</td>
                                    <td class="px-5 py-3 text-right text-sm text-slate-200">{{ number(fill.price, 6) }}</td>
                                    <td class="px-5 py-3 text-right text-sm text-slate-200">{{ number(fill.quantity, 8) }}</td>
                                    <td class="px-5 py-3 text-right text-sm text-slate-300">{{ number(fill.fee, 6) }} {{ fill.fee_currency }}</td>
                                    <td class="px-5 py-3 text-right text-sm text-slate-300">{{ fill.grid_level_sequence ?? '—' }}</td>
                                </tr>
                                <tr v-if="fills.length === 0">
                                    <td colspan="7" class="px-5 py-8 text-center text-sm text-slate-400">No fills yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-slate-800 p-5 shadow sm:rounded-lg">
                    <h3 class="text-base font-semibold text-slate-100">Snapshot</h3>
                    <p class="mt-1 text-xs text-slate-400">This run is immutable, even if the source bot is edited or deleted.</p>
                    <pre class="mt-3 overflow-x-auto rounded-md bg-slate-950 p-4 text-xs text-slate-300">{{ JSON.stringify(run.config_snapshot, null, 2) }}</pre>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
