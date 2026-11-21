<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

defineProps({
    bots: { type: Array, default: () => [] },
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

function money(value) {
    return Number(value ?? 0).toFixed(2);
}

function timestamp(value) {
    return value ? new Date(value).toLocaleString() : '—';
}
const signedMoney = (value) => `${Number(value ?? 0) > 0 ? '+' : ''}${Number(value ?? 0).toFixed(2)}`;
</script>

<template>
    <Head title="Bots" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-slate-100">Bots</h2>
                <Link :href="route('bots.create')" class="rounded-md bg-blue-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white">Create bot</Link>
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
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-700 bg-slate-800">
                            <tr v-for="bot in bots" :key="bot.id">
                                <td class="px-6 py-4 text-sm text-slate-100">
                                    <Link :href="route('bots.show', bot.id)" class="font-medium underline">{{ bot.name }}</Link>
                                    <p v-if="bot.last_error" class="mt-1 text-xs text-red-300">{{ bot.last_error }}</p>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-300">{{ bot.symbol }}</td>
                                <td class="px-6 py-4 text-sm text-slate-300">{{ bot.exchange }}</td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="rounded-full px-2 py-1 text-xs" :class="bot.mode === 'live' ? 'bg-red-950 text-red-300' : 'bg-green-950 text-green-300'">{{ bot.mode }}</span>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-300">{{ bot.status }}</td>
                                <td class="px-6 py-4 text-right text-sm text-slate-300">{{ bot.open_orders_count }}</td>
                                <td class="px-6 py-4 text-right text-sm" :class="Number(bot.realized_pnl) < 0 ? 'text-red-300' : 'text-green-300'">{{ signedMoney(bot.realized_pnl) }}</td>
                                <td class="px-6 py-4 text-right text-sm" :class="Number(bot.unrealized_pnl) < 0 ? 'text-red-300' : 'text-green-300'">{{ signedMoney(bot.unrealized_pnl) }}</td>
                                <td class="px-6 py-4 text-sm text-slate-300">{{ timestamp(bot.last_tick_at) }}</td>
                            </tr>
                            <tr v-if="bots.length === 0">
                                <td colspan="9" class="px-6 py-8 text-center text-sm text-slate-300">No bots yet.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
