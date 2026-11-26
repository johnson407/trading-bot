<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatSignedMoney } from '@/Support/money.js';
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

defineProps({
    bots: { type: Array, default: () => [] },
    archived: { type: Boolean, default: false },
});

let poller = null;

onMounted(() => {
    poller = setInterval(() => {
        router.reload({ only: ['bots'], preserveScroll: true, preserveState: true });
    }, 5000);
});

onUnmounted(() => {
    if (poller) clearInterval(poller);
});

function timestamp(value) {
    return value ? new Date(value).toLocaleString() : '—';
}

function restore(bot) {
    if (confirm(`Restore ${bot.name}? It will return to the active list, still stopped.`)) {
        router.post(route('bots.restore', bot.id));
    }
}
</script>

<template>
    <Head title="Bots" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-xl font-semibold leading-tight text-slate-100">Bots</h2>
                <div class="flex flex-wrap items-center gap-3">
                    <template v-if="!archived">
                        <Link :href="route('bots.index', { archived: 1 })" class="rounded-md border border-slate-500 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-slate-200 hover:bg-slate-800">Archived</Link>
                        <Link :href="route('kill-switch.create')" class="rounded-md border border-rose-400 bg-rose-950 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-rose-200 hover:bg-rose-900 focus:outline-none focus:ring-2 focus:ring-rose-300">Kill switch</Link>
                        <Link :href="route('bots.create')" class="rounded-md bg-blue-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white">Create bot</Link>
                    </template>
                    <Link v-else :href="route('bots.index')" class="rounded-md bg-blue-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white">Active bots</Link>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-x-auto bg-slate-800 shadow sm:rounded-lg">
                    <table class="min-w-full divide-y divide-slate-700">
                        <thead class="bg-slate-900">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Symbol</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Exchange</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Mode</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Status</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Open orders</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Realized P&amp;L</th>
                                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Unrealized P&amp;L</th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Last tick</th>
                                <th v-if="archived" class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-slate-300">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-700 bg-slate-800">
                            <tr v-for="bot in bots" :key="bot.id">
                                <td class="px-6 py-4 text-sm text-slate-100">
                                    <Link v-if="!archived" :href="route('bots.show', bot.id)" class="font-medium underline">{{ bot.name }}</Link>
                                    <span v-else class="font-medium">{{ bot.name }}</span>
                                    <p v-if="bot.last_error" class="mt-1 text-xs text-red-300">{{ bot.last_error }}</p>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-300">{{ bot.symbol }}</td>
                                <td class="px-6 py-4 text-sm text-slate-300">{{ bot.exchange }}</td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="rounded-full px-2 py-1 text-xs" :class="bot.mode === 'live' ? 'bg-red-950 text-red-300' : 'bg-green-950 text-green-300'">{{ bot.mode }}</span>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-300">{{ bot.status }}</td>
                                <td class="px-6 py-4 text-right text-sm text-slate-300">{{ bot.open_orders_count }}</td>
                                <td class="px-6 py-4 text-right text-sm" :class="Number(bot.realized_pnl) < 0 ? 'text-red-300' : 'text-green-300'">{{ formatSignedMoney(bot.realized_pnl) }}</td>
                                <td class="px-6 py-4 text-right text-sm" :class="Number(bot.unrealized_pnl) < 0 ? 'text-red-300' : 'text-green-300'">{{ formatSignedMoney(bot.unrealized_pnl) }}</td>
                                <td class="px-6 py-4 text-sm text-slate-300">{{ timestamp(bot.last_tick_at) }}</td>
                                <td v-if="archived" class="px-6 py-4 text-right text-sm">
                                    <button type="button" class="rounded-md border border-blue-400 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-blue-200 hover:bg-blue-950" @click="restore(bot)">Restore</button>
                                </td>
                            </tr>
                            <tr v-if="bots.length === 0">
                                <td :colspan="archived ? 10 : 9" class="px-6 py-8 text-center text-sm text-slate-300">{{ archived ? 'No archived bots.' : 'No bots yet.' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
