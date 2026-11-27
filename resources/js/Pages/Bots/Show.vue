<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatMoney, formatSignedMoney, submittedMoney } from '@/Support/money.js';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps({
    bot: { type: Object, required: true },
    grid: { type: Object, default: null },
    openOrders: { type: Array, default: () => [] },
    recentFills: { type: Array, default: () => [] },
    inventory: { type: Object, required: true },
    pnl: { type: Object, required: true },
    riskLimits: { type: Array, default: () => [] },
    canArchive: { type: Boolean, default: false },
    backtestComparison: { type: Object, default: null },
});

let timer = null;

onMounted(() => {
    timer = setInterval(() => {
        router.reload({
            only: [
                'bot',
                'grid',
                'openOrders',
                'recentFills',
                'inventory',
                'pnl',
                'canArchive',
                ...(props.backtestComparison ? ['backtestComparison'] : []),
            ],
            preserveScroll: true,
            preserveState: true,
        });
    }, 5000);
});

onUnmounted(() => {
    if (timer) clearInterval(timer);
});

const timestamp = (value) => (value ? new Date(value).toLocaleString() : '—');
const comparisonMoney = (value) => (value === null || value === undefined || value === '' ? '—' : formatMoney(value));
const comparisonSignedMoney = (value) => (value === null || value === undefined || value === '' ? '—' : formatSignedMoney(value));
const comparisonPercent = (value) => (value === null || value === undefined || value === '' ? '—' : `${value}%`);
const comparisonSignedPercent = (value) => {
    if (value === null || value === undefined || value === '') return '—';

    return `${String(value).startsWith('-') ? '' : '+'}${value} pp`;
};
const comparisonCount = (value) => (value === null || value === undefined || value === '' ? '—' : value);

const ordersByGridLevel = computed(() => {
    const orders = {};
    for (const order of props.openOrders) {
        if (order.grid_level_id !== null && order.grid_level_id !== undefined) {
            orders[order.grid_level_id] = order;
        }
    }

    return orders;
});
const orderForLevel = (level) => ordersByGridLevel.value[level.id] ?? null;

const page = usePage();
const confirmation = ref('');
const rangeLowRaw = ref(props.bot.range_low ?? '');
const rangeHighRaw = ref(props.bot.range_high ?? '');
const displayMoney = (value) => (value === null || value === undefined || value === '' ? '' : formatMoney(value));
const exitForm = useForm({
    stop_loss_percent: props.bot.stop_loss_percent ?? '',
    take_profit_percent: props.bot.take_profit_percent ?? '',
    range_low: displayMoney(rangeLowRaw.value),
    range_high: displayMoney(rangeHighRaw.value),
});
const saveExitGuard = () => {
    const submitted = {
        stop_loss_percent: exitForm.stop_loss_percent,
        take_profit_percent: exitForm.take_profit_percent,
        range_low: submittedMoney(exitForm.range_low, displayMoney(rangeLowRaw.value), rangeLowRaw.value),
        range_high: submittedMoney(exitForm.range_high, displayMoney(rangeHighRaw.value), rangeHighRaw.value),
    };

    exitForm.transform(() => submitted).patch(route('bots.exit-guard.update', props.bot.id), {
        preserveScroll: true,
        onSuccess: () => {
            rangeLowRaw.value = submitted.range_low;
            rangeHighRaw.value = submitted.range_high;
            exitForm.range_low = displayMoney(submitted.range_low);
            exitForm.range_high = displayMoney(submitted.range_high);
            exitForm.defaults();
        },
    });
};

watch(
    () => [props.bot.stop_loss_percent, props.bot.take_profit_percent, props.bot.range_low, props.bot.range_high],
    ([stopLoss, takeProfit, rangeLow, rangeHigh]) => {
        if (exitForm.processing || exitForm.isDirty) return;

        rangeLowRaw.value = rangeLow ?? '';
        rangeHighRaw.value = rangeHigh ?? '';

        exitForm.stop_loss_percent = stopLoss ?? '';
        exitForm.take_profit_percent = takeProfit ?? '';
        exitForm.range_low = displayMoney(rangeLow);
        exitForm.range_high = displayMoney(rangeHigh);
        exitForm.defaults();
    },
);

const start = () => router.post(route('bots.start', props.bot.id), { confirmation: confirmation.value }, { preserveScroll: true });
const pause = () => router.post(route('bots.pause', props.bot.id), {}, { preserveScroll: true });
const stop = () => router.post(route('bots.stop', props.bot.id), {}, { preserveScroll: true });
const archive = () => {
    if (confirm(`Archive ${props.bot.name}? It will disappear from the active list. Residual inventory is not sold.`)) {
        router.delete(route('bots.destroy', props.bot.id), { preserveScroll: true });
    }
};
const cancelOrder = (order) => router.post(route('bots.orders.cancel', [props.bot.id, order.id]), {}, { preserveScroll: true });
const levelState = (state) => ({
    idle: 'bg-slate-700 text-slate-300',
    open: 'bg-blue-950 text-blue-200',
    filled: 'bg-emerald-950 text-emerald-300',
    canceled: 'bg-rose-950 text-rose-200',
}[state] ?? 'bg-slate-700 text-slate-300');
</script>

<template>
    <Head :title="`Bot ${bot.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-slate-100">{{ bot.name }}</h2>
                    <p class="text-sm text-slate-300">{{ bot.symbol }} · {{ bot.strategy_key }} · {{ bot.exchange }}</p>
                </div>
                <div class="flex items-center gap-4">
                    <Link v-if="['idle', 'stopped'].includes(bot.status)" :href="route('bots.edit', bot.id)" class="text-sm text-blue-300 hover:text-blue-200">Edit</Link>
                    <Link :href="route('bots.activity', bot.id)" class="text-sm text-blue-300 hover:text-blue-200">Activity log</Link>
                    <Link :href="route('bots.index')" class="text-sm text-blue-300 hover:text-blue-200">Back to bots</Link>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div class="rounded-lg bg-slate-800 p-4 shadow">
                        <p class="text-xs uppercase text-slate-300">Mode</p>
                        <p class="mt-1 text-lg font-semibold capitalize">{{ bot.mode }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-800 p-4 shadow">
                        <p class="text-xs uppercase text-slate-300">Status</p>
                        <p class="mt-1 text-lg font-semibold capitalize">{{ bot.status }}</p>
                        <p class="mt-1 text-xs capitalize text-slate-400">Desired: {{ bot.desired_status }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-800 p-4 shadow">
                        <p class="text-xs uppercase text-slate-300">Realized P&amp;L</p>
                        <p class="mt-1 text-lg font-semibold" :class="Number(pnl.realized) >= 0 ? 'text-emerald-300' : 'text-rose-300'">{{ formatSignedMoney(pnl.realized) }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-800 p-4 shadow">
                        <p class="text-xs uppercase text-slate-300">Unrealized P&amp;L</p>
                        <p class="mt-1 text-lg font-semibold" :class="Number(pnl.unrealized) >= 0 ? 'text-emerald-300' : 'text-rose-300'">{{ formatSignedMoney(pnl.unrealized) }}</p>
                    </div>
                </div>

                <div v-if="backtestComparison" class="rounded-lg bg-slate-800 p-6 shadow">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-slate-100">Backtest comparison</h3>
                            <p class="mt-1 text-sm text-slate-300">
                                <Link v-if="backtestComparison.source.id" :href="route('backtests.show', backtestComparison.source.id)" class="text-blue-300 underline">
                                    {{ backtestComparison.source.name }}
                                </Link>
                                <span v-else>{{ backtestComparison.source.name }}</span>
                                <span v-if="backtestComparison.source.symbol"> · {{ backtestComparison.source.symbol }}</span>
                                <span v-if="backtestComparison.source.timeframe"> · {{ backtestComparison.source.timeframe }}</span>
                            </p>
                            <p class="mt-1 text-xs text-slate-400">
                                {{ timestamp(backtestComparison.source.start_at) }} to {{ timestamp(backtestComparison.source.end_at) }}
                                <span v-if="backtestComparison.source.status"> · {{ backtestComparison.source.status }}</span>
                            </p>
                        </div>
                        <span v-if="backtestComparison.modified" class="rounded bg-amber-900 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-amber-200">
                            Modified since creation
                        </span>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="rounded border border-slate-700 p-4">
                            <h4 class="text-sm font-semibold text-slate-100">Source backtest</h4>
                            <p v-if="!backtestComparison.source.metrics_available" class="mt-1 text-xs text-amber-300">Metrics unavailable for this run.</p>
                            <dl class="mt-3 space-y-2 text-sm">
                                <div class="flex justify-between gap-4"><dt class="text-slate-300">Capital</dt><dd class="font-mono">{{ comparisonMoney(backtestComparison.source.capital_allocation) }}</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-slate-300">Net P&amp;L</dt><dd class="font-mono">{{ comparisonSignedMoney(backtestComparison.source.net_pnl) }}</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-slate-300">Return</dt><dd class="font-mono">{{ comparisonPercent(backtestComparison.source.return_percent) }}</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-slate-300">Fees</dt><dd class="font-mono">{{ comparisonMoney(backtestComparison.source.fees) }}</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-slate-300">Fills</dt><dd class="font-mono">{{ comparisonCount(backtestComparison.source.fill_count) }}</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-slate-300">Max drawdown</dt><dd class="font-mono">{{ comparisonMoney(backtestComparison.source.max_drawdown) }} <span v-if="backtestComparison.source.max_drawdown_percent !== null" class="text-slate-400">({{ comparisonPercent(backtestComparison.source.max_drawdown_percent) }})</span></dd></div>
                            </dl>
                        </div>

                        <div class="rounded border border-slate-700 p-4">
                            <h4 class="text-sm font-semibold text-slate-100">Paper bot</h4>
                            <dl class="mt-3 space-y-2 text-sm">
                                <div class="flex justify-between gap-4"><dt class="text-slate-300">Capital</dt><dd class="font-mono">{{ comparisonMoney(backtestComparison.paper.capital_allocation) }}</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-slate-300">Net P&amp;L</dt><dd class="font-mono">{{ comparisonSignedMoney(backtestComparison.paper.net_pnl) }}</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-slate-300">Return</dt><dd class="font-mono">{{ comparisonPercent(backtestComparison.paper.return_percent) }}</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-slate-300">Fees</dt><dd class="font-mono">{{ comparisonMoney(backtestComparison.paper.fees) }}</dd></div>
                                <div class="flex justify-between gap-4"><dt class="text-slate-300">Fills</dt><dd class="font-mono">{{ comparisonCount(backtestComparison.paper.fill_count) }}</dd></div>
                            </dl>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
                        <div class="rounded bg-slate-900 p-3">
                            <p class="text-xs uppercase text-slate-400">Net P&amp;L delta</p>
                            <p class="mt-1 font-mono">{{ comparisonSignedMoney(backtestComparison.deltas.net_pnl) }}</p>
                        </div>
                        <div class="rounded bg-slate-900 p-3">
                            <p class="text-xs uppercase text-slate-400">Return delta</p>
                            <p class="mt-1 font-mono">{{ comparisonSignedPercent(backtestComparison.deltas.return_percent_points) }}</p>
                        </div>
                        <div class="rounded bg-slate-900 p-3">
                            <p class="text-xs uppercase text-slate-400">Fees delta</p>
                            <p class="mt-1 font-mono">{{ comparisonSignedMoney(backtestComparison.deltas.fees) }}</p>
                        </div>
                        <div class="rounded bg-slate-900 p-3">
                            <p class="text-xs uppercase text-slate-400">Fills delta</p>
                            <p class="mt-1 font-mono">{{ comparisonCount(backtestComparison.deltas.fill_count) }}</p>
                        </div>
                    </div>

                    <p class="mt-4 text-xs text-slate-400">{{ backtestComparison.caveat }}</p>
                </div>

                <div class="rounded-lg bg-slate-800 p-6 shadow">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-semibold text-slate-100">Controls</h3>
                        <div class="flex gap-2">
                            <button v-if="bot.status !== 'running'" type="button" class="rounded bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800" @click="start">Start</button>
                            <button v-if="bot.status === 'running'" type="button" class="rounded bg-amber-700 px-4 py-2 text-sm font-medium text-white hover:bg-amber-800" @click="pause">Pause</button>
                            <button v-if="bot.status !== 'stopped'" type="button" class="rounded bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700" @click="stop">Stop</button>
                            <button v-if="canArchive" type="button" class="rounded border border-rose-400 px-4 py-2 text-sm font-medium text-rose-200 hover:bg-rose-950" @click="archive">Archive</button>
                        </div>
                    </div>
                    <div v-if="bot.mode === 'live' && bot.status !== 'running'" class="mt-4">
                        <label class="block text-sm font-medium text-slate-200" :for="`confirmation-${bot.id}`">Type "{{ bot.name }} on {{ bot.exchange }}" to confirm live trading</label>
                        <input :id="`confirmation-${bot.id}`" v-model="confirmation" class="mt-1 w-full rounded border-slate-500 shadow-sm" :placeholder="`${bot.name} on ${bot.exchange}`" />
                    </div>
                    <p v-if="page.props.errors?.confirmation" class="mt-3 text-sm text-rose-300">{{ page.props.errors.confirmation }}</p>
                    <p v-if="page.props.errors?.action" class="mt-3 text-sm text-rose-300">{{ page.props.errors.action }}</p>
                </div>

                <div class="rounded-lg bg-slate-800 p-6 shadow">
                    <div>
                        <h3 class="font-semibold text-slate-100">Protective exits</h3>
                        <p class="mt-1 text-sm text-slate-300">Optional. A trigger pauses this bot and cancels its open orders; it does not sell inventory. Leave a field blank to disable that rule.</p>
                    </div>
                    <form class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4" @submit.prevent="saveExitGuard">
                        <div>
                            <label class="block text-sm font-medium text-slate-200" :for="`stop-loss-${bot.id}`">Stop-loss percent</label>
                            <input :id="`stop-loss-${bot.id}`" v-model="exitForm.stop_loss_percent" type="number" min="0.0001" max="99.9999" step="0.0001" placeholder="Off" class="mt-1 w-full rounded border-slate-500 shadow-sm" />
                            <p v-if="exitForm.errors.stop_loss_percent" class="mt-1 text-sm text-rose-300">{{ exitForm.errors.stop_loss_percent }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-200" :for="`take-profit-${bot.id}`">Take-profit percent</label>
                            <input :id="`take-profit-${bot.id}`" v-model="exitForm.take_profit_percent" type="number" min="0.0001" max="99.9999" step="0.0001" placeholder="Off" class="mt-1 w-full rounded border-slate-500 shadow-sm" />
                            <p v-if="exitForm.errors.take_profit_percent" class="mt-1 text-sm text-rose-300">{{ exitForm.errors.take_profit_percent }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-200" :for="`range-low-${bot.id}`">Range low</label>
                            <input :id="`range-low-${bot.id}`" v-model="exitForm.range_low" type="number" min="0.00000001" max="99999999.99999999" step="0.00000001" placeholder="Off" class="mt-1 w-full rounded border-slate-500 shadow-sm" />
                            <p v-if="exitForm.errors.range_low" class="mt-1 text-sm text-rose-300">{{ exitForm.errors.range_low }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-200" :for="`range-high-${bot.id}`">Range high</label>
                            <input :id="`range-high-${bot.id}`" v-model="exitForm.range_high" type="number" min="0.00000001" max="99999999.99999999" step="0.00000001" placeholder="Off" class="mt-1 w-full rounded border-slate-500 shadow-sm" />
                            <p v-if="exitForm.errors.range_high" class="mt-1 text-sm text-rose-300">{{ exitForm.errors.range_high }}</p>
                        </div>
                        <div class="md:col-span-2 lg:col-span-4">
                            <button type="submit" :disabled="exitForm.processing" class="rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">Save protective exits</button>
                        </div>
                    </form>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div class="rounded-lg bg-slate-800 p-6 shadow lg:col-span-2">
                        <div class="flex items-center justify-between">
                            <h3 class="font-semibold text-slate-100">Grid ladder</h3>
                            <span class="text-sm text-slate-300">Center {{ formatMoney(grid?.center_price) }}</span>
                        </div>
                        <div v-if="!grid" class="mt-4 text-sm text-slate-300">No active grid yet.</div>
                        <div v-else class="mt-4 space-y-2">
                            <div v-for="level in [...grid.levels].reverse()" :key="level.id" class="flex items-center gap-3 rounded border px-3 py-2">
                                <span class="w-16 text-xs font-medium uppercase" :class="level.side === 'buy' ? 'text-emerald-300' : 'text-rose-300'">{{ level.side }}</span>
                                <span v-if="orderForLevel(level) && orderForLevel(level).side !== level.side" class="rounded px-2 py-0.5 text-xs uppercase" :class="orderForLevel(level).side === 'buy' ? 'bg-emerald-950 text-emerald-300' : 'bg-rose-950 text-rose-300'">order {{ orderForLevel(level).side }}</span>
                                <span class="w-24 font-mono text-sm">{{ formatMoney(level.price) }}</span>
                                <span class="rounded px-2 py-0.5 text-xs" :class="levelState(level.state)">{{ level.state }}</span>
                                <span class="ml-auto text-xs text-slate-300">Level {{ level.sequence }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-lg bg-slate-800 p-6 shadow">
                        <h3 class="font-semibold text-slate-100">Inventory</h3>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div class="flex justify-between"><dt class="text-slate-300">Base</dt><dd class="font-mono">{{ inventory.base }}</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-300">Quote</dt><dd class="font-mono">{{ formatMoney(inventory.quote) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-300">Average entry</dt><dd class="font-mono">{{ formatMoney(inventory.average_entry_price) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-300">Reference price</dt><dd class="font-mono">{{ formatMoney(inventory.reference_price) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-slate-300">Fees paid</dt><dd class="font-mono">{{ formatMoney(pnl.fees_paid) }}</dd></div>
                        </dl>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <div class="overflow-x-auto rounded-lg bg-slate-800 p-6 shadow">
                        <h3 class="font-semibold text-slate-100">Open orders</h3>
                        <div v-if="openOrders.length === 0" class="mt-3 text-sm text-slate-300">No open orders.</div>
                        <table v-else class="mt-3 min-w-full text-sm">
                            <thead class="text-left text-xs uppercase text-slate-300">
                                <tr><th class="py-2">Side</th><th>Price</th><th>Quantity</th><th>Status</th><th><span class="sr-only">Actions</span></th></tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="order in openOrders" :key="order.id">
                                    <td class="py-2 capitalize" :class="order.side === 'buy' ? 'text-emerald-300' : 'text-rose-300'">{{ order.side }}</td>
                                    <td class="font-mono">{{ formatMoney(order.price) }}</td>
                                    <td class="font-mono">{{ order.quantity }}</td>
                                    <td class="capitalize text-slate-300">{{ order.status }}</td>
                                    <td class="py-2 text-right">
                                        <button type="button" class="rounded bg-rose-700 px-3 py-1 text-xs font-semibold text-white hover:bg-rose-600" @click="cancelOrder(order)">Cancel</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="overflow-x-auto rounded-lg bg-slate-800 p-6 shadow">
                        <h3 class="font-semibold text-slate-100">Recent fills</h3>
                        <div v-if="recentFills.length === 0" class="mt-3 text-sm text-slate-300">No fills yet.</div>
                        <table v-else class="mt-3 min-w-full text-sm">
                            <thead class="text-left text-xs uppercase text-slate-300">
                                <tr><th class="py-2">Side</th><th>Price</th><th>Quantity</th><th>Fee</th><th>Time</th></tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="fill in recentFills" :key="fill.id">
                                    <td class="py-2 capitalize" :class="fill.side === 'buy' ? 'text-emerald-300' : 'text-rose-300'">{{ fill.side }}</td>
                                    <td class="font-mono">{{ formatMoney(fill.price) }}</td>
                                    <td class="font-mono">{{ fill.quantity }}</td>
                                    <td class="font-mono">{{ formatMoney(fill.fee) }}</td>
                                    <td class="text-slate-300">{{ timestamp(fill.filled_at) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-lg bg-slate-800 p-6 shadow">
                    <h3 class="font-semibold text-slate-100">Risk limits</h3>
                    <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div v-for="limit in riskLimits" :key="limit.scope" class="rounded border p-3 text-sm">
                            <p class="font-medium capitalize">{{ limit.scope }} scope</p>
                            <p class="text-slate-300">Max position {{ formatMoney(limit.max_position_notional) }} · Max orders {{ limit.max_open_orders }} · Daily loss {{ formatMoney(limit.daily_loss_limit) }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
