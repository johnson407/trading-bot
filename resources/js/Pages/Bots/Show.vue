<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    bot: { type: Object, required: true },
    grid: { type: Object, default: null },
    openOrders: { type: Array, default: () => [] },
    recentFills: { type: Array, default: () => [] },
    inventory: { type: Object, required: true },
    pnl: { type: Object, required: true },
    riskLimits: { type: Array, default: () => [] },
});

let timer = null;

onMounted(() => {
    timer = setInterval(() => {
        router.reload({
            only: ['bot', 'grid', 'openOrders', 'recentFills', 'inventory', 'pnl'],
            preserveScroll: true,
            preserveState: true,
        });
    }, 5000);
});

onUnmounted(() => {
    if (timer) clearInterval(timer);
});

const money = (value) => Number(value ?? 0).toFixed(2);
const timestamp = (value) => (value ? new Date(value).toLocaleString() : '—');

const page = usePage();
const confirmation = ref('');

const start = () => router.post(route('bots.start', props.bot.id), { confirmation: confirmation.value }, { preserveScroll: true });
const pause = () => router.post(route('bots.pause', props.bot.id), {}, { preserveScroll: true });
const stop = () => router.post(route('bots.stop', props.bot.id), {}, { preserveScroll: true });
const levelState = (state) => ({
    idle: 'bg-slate-100 text-slate-600',
    open: 'bg-blue-100 text-blue-700',
    filled: 'bg-emerald-100 text-emerald-700',
    canceled: 'bg-rose-100 text-rose-700',
}[state] ?? 'bg-slate-100 text-slate-600');
</script>

<template>
    <Head :title="`Bot ${bot.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ bot.name }}</h2>
                    <p class="text-sm text-gray-500">{{ bot.symbol }} · {{ bot.strategy_key }} · {{ bot.exchange }}</p>
                </div>
                <div class="flex items-center gap-4">
                    <Link v-if="['idle', 'stopped'].includes(bot.status)" :href="route('bots.edit', bot.id)" class="text-sm text-indigo-600 hover:text-indigo-800">Edit</Link>
                    <Link :href="route('bots.activity', bot.id)" class="text-sm text-indigo-600 hover:text-indigo-800">Activity log</Link>
                    <Link :href="route('bots.index')" class="text-sm text-indigo-600 hover:text-indigo-800">Back to bots</Link>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div class="rounded-lg bg-white p-4 shadow">
                        <p class="text-xs uppercase text-gray-500">Mode</p>
                        <p class="mt-1 text-lg font-semibold capitalize">{{ bot.mode }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow">
                        <p class="text-xs uppercase text-gray-500">Status</p>
                        <p class="mt-1 text-lg font-semibold capitalize">{{ bot.status }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow">
                        <p class="text-xs uppercase text-gray-500">Realized P&amp;L</p>
                        <p class="mt-1 text-lg font-semibold" :class="Number(pnl.realized) >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ money(pnl.realized) }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow">
                        <p class="text-xs uppercase text-gray-500">Unrealized P&amp;L</p>
                        <p class="mt-1 text-lg font-semibold" :class="Number(pnl.unrealized) >= 0 ? 'text-emerald-600' : 'text-rose-600'">{{ money(pnl.unrealized) }}</p>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-6 shadow">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="font-semibold text-gray-800">Controls</h3>
                        <div class="flex gap-2">
                            <button v-if="bot.status !== 'running'" type="button" class="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700" @click="start">Start</button>
                            <button v-if="bot.status === 'running'" type="button" class="rounded bg-amber-500 px-4 py-2 text-sm font-medium text-white hover:bg-amber-600" @click="pause">Pause</button>
                            <button v-if="bot.status !== 'stopped'" type="button" class="rounded bg-rose-600 px-4 py-2 text-sm font-medium text-white hover:bg-rose-700" @click="stop">Stop</button>
                        </div>
                    </div>
                    <div v-if="bot.mode === 'live' && bot.status !== 'running'" class="mt-4">
                        <label class="block text-sm font-medium text-gray-700" :for="`confirmation-${bot.id}`">Type "{{ bot.name }} on {{ bot.exchange }}" to confirm live trading</label>
                        <input :id="`confirmation-${bot.id}`" v-model="confirmation" class="mt-1 w-full rounded border-gray-300 shadow-sm" :placeholder="`${bot.name} on ${bot.exchange}`" />
                    </div>
                    <p v-if="page.props.errors?.confirmation" class="mt-3 text-sm text-rose-600">{{ page.props.errors.confirmation }}</p>
                    <p v-if="page.props.errors?.action" class="mt-3 text-sm text-rose-600">{{ page.props.errors.action }}</p>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div class="rounded-lg bg-white p-6 shadow lg:col-span-2">
                        <div class="flex items-center justify-between">
                            <h3 class="font-semibold text-gray-800">Grid ladder</h3>
                            <span class="text-sm text-gray-500">Center {{ money(grid?.center_price) }}</span>
                        </div>
                        <div v-if="!grid" class="mt-4 text-sm text-gray-500">No active grid yet.</div>
                        <div v-else class="mt-4 space-y-2">
                            <div v-for="level in [...grid.levels].reverse()" :key="level.id" class="flex items-center gap-3 rounded border px-3 py-2">
                                <span class="w-16 text-xs font-medium uppercase" :class="level.side === 'buy' ? 'text-emerald-600' : 'text-rose-600'">{{ level.side }}</span>
                                <span class="w-24 font-mono text-sm">{{ money(level.price) }}</span>
                                <span class="rounded px-2 py-0.5 text-xs" :class="levelState(level.state)">{{ level.state }}</span>
                                <span class="ml-auto text-xs text-gray-400">Level {{ level.sequence }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-lg bg-white p-6 shadow">
                        <h3 class="font-semibold text-gray-800">Inventory</h3>
                        <dl class="mt-4 space-y-3 text-sm">
                            <div class="flex justify-between"><dt class="text-gray-500">Base</dt><dd class="font-mono">{{ inventory.base }}</dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500">Quote</dt><dd class="font-mono">{{ inventory.quote }}</dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500">Average entry</dt><dd class="font-mono">{{ inventory.average_entry_price }}</dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500">Reference price</dt><dd class="font-mono">{{ money(inventory.reference_price) }}</dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500">Fees paid</dt><dd class="font-mono">{{ money(pnl.fees_paid) }}</dd></div>
                        </dl>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <div class="rounded-lg bg-white p-6 shadow">
                        <h3 class="font-semibold text-gray-800">Open orders</h3>
                        <div v-if="openOrders.length === 0" class="mt-3 text-sm text-gray-500">No open orders.</div>
                        <table v-else class="mt-3 min-w-full text-sm">
                            <thead class="text-left text-xs uppercase text-gray-500">
                                <tr><th class="py-2">Side</th><th>Price</th><th>Quantity</th><th>Status</th></tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="order in openOrders" :key="order.id">
                                    <td class="py-2 capitalize" :class="order.side === 'buy' ? 'text-emerald-600' : 'text-rose-600'">{{ order.side }}</td>
                                    <td class="font-mono">{{ money(order.price) }}</td>
                                    <td class="font-mono">{{ order.quantity }}</td>
                                    <td class="capitalize text-gray-600">{{ order.status }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="rounded-lg bg-white p-6 shadow">
                        <h3 class="font-semibold text-gray-800">Recent fills</h3>
                        <div v-if="recentFills.length === 0" class="mt-3 text-sm text-gray-500">No fills yet.</div>
                        <table v-else class="mt-3 min-w-full text-sm">
                            <thead class="text-left text-xs uppercase text-gray-500">
                                <tr><th class="py-2">Side</th><th>Price</th><th>Quantity</th><th>Fee</th><th>Time</th></tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="fill in recentFills" :key="fill.id">
                                    <td class="py-2 capitalize" :class="fill.side === 'buy' ? 'text-emerald-600' : 'text-rose-600'">{{ fill.side }}</td>
                                    <td class="font-mono">{{ money(fill.price) }}</td>
                                    <td class="font-mono">{{ fill.quantity }}</td>
                                    <td class="font-mono">{{ money(fill.fee) }}</td>
                                    <td class="text-gray-500">{{ timestamp(fill.filled_at) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-lg bg-white p-6 shadow">
                    <h3 class="font-semibold text-gray-800">Risk limits</h3>
                    <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div v-for="limit in riskLimits" :key="limit.scope" class="rounded border p-3 text-sm">
                            <p class="font-medium capitalize">{{ limit.scope }} scope</p>
                            <p class="text-gray-500">Max position {{ money(limit.max_position_notional) }} · Max orders {{ limit.max_open_orders }} · Daily loss {{ money(limit.daily_loss_limit) }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
