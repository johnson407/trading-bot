<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

const props = defineProps({
    summary: {
        type: Object,
        required: true,
    },
    recentActivity: {
        type: Array,
        required: true,
    },
});

const money = (value) => Number(value ?? 0).toFixed(2);

let poller = null;

onMounted(() => {
    poller = setInterval(() => {
        router.reload({
            only: ['summary', 'recentActivity'],
            preserveScroll: true,
            preserveState: true,
        });
    }, 5000);
});

onBeforeUnmount(() => {
    if (poller !== null) {
        clearInterval(poller);
    }
});
const signedMoney = (value) => `${Number(value ?? 0) > 0 ? '+' : ''}${Number(value ?? 0).toFixed(2)}`;
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
                            {{ signedMoney(summary.realized_pnl) }}
                        </div>
                    </div>
                    <div class="bg-slate-800 overflow-hidden shadow-sm sm:rounded-lg p-6">
                        <div class="text-sm text-slate-300">Unrealized P&amp;L</div>
                        <div class="mt-1 text-2xl font-semibold" :class="Number(summary.unrealized_pnl) < 0 ? 'text-red-300' : 'text-slate-100'">
                            {{ signedMoney(summary.unrealized_pnl) }}
                        </div>
                    </div>
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
