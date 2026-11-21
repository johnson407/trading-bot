<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    bot: { type: Object, required: true },
    activityLogs: { type: Array, default: () => [] },
    riskEvents: { type: Array, default: () => [] },
});

const timestamp = (value) => (value ? new Date(value).toLocaleString() : '—');
const severity = (value) => ({
    info: 'bg-slate-100 text-slate-700',
    warning: 'bg-amber-100 text-amber-700',
    error: 'bg-rose-100 text-rose-700',
    critical: 'bg-rose-100 text-rose-700',
}[value] ?? 'bg-slate-100 text-slate-700');
</script>

<template>
    <Head :title="`${bot.name} activity`" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ bot.name }} activity</h2>
                    <p class="text-sm text-gray-500">{{ bot.symbol }} · {{ bot.strategy_key }}</p>
                </div>
                <Link :href="route('bots.show', bot.id)" class="text-sm text-indigo-600">Back to bot</Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto grid max-w-7xl grid-cols-1 gap-6 px-4 sm:px-6 lg:grid-cols-2 lg:px-8">
                <section class="rounded-lg bg-white p-5 shadow">
                    <h3 class="text-lg font-semibold text-gray-800">Activity</h3>
                    <ul class="mt-4 divide-y divide-gray-100">
                        <li v-for="log in activityLogs" :key="log.id" class="py-3">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-medium text-gray-800">{{ log.message }}</p>
                                <span class="rounded px-2 py-0.5 text-xs font-semibold" :class="severity(log.severity)">{{ log.severity }}</span>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">{{ log.type }} · {{ timestamp(log.created_at) }}</p>
                            <pre v-if="log.context && Object.keys(log.context).length" class="mt-1 overflow-x-auto rounded bg-gray-50 p-2 text-xs text-gray-600">{{ JSON.stringify(log.context, null, 2) }}</pre>
                        </li>
                        <li v-if="activityLogs.length === 0" class="py-4 text-sm text-gray-500">No activity yet.</li>
                    </ul>
                </section>

                <section class="rounded-lg bg-white p-5 shadow">
                    <h3 class="text-lg font-semibold text-gray-800">Risk events</h3>
                    <ul class="mt-4 divide-y divide-gray-100">
                        <li v-for="event in riskEvents" :key="event.id" class="py-3">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-medium text-gray-800">{{ event.message }}</p>
                                <span class="rounded px-2 py-0.5 text-xs font-semibold" :class="severity(event.severity)">{{ event.severity }}</span>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">{{ event.type }} · {{ timestamp(event.created_at) }}</p>
                            <pre v-if="event.context && Object.keys(event.context).length" class="mt-1 overflow-x-auto rounded bg-gray-50 p-2 text-xs text-gray-600">{{ JSON.stringify(event.context, null, 2) }}</pre>
                        </li>
                        <li v-if="riskEvents.length === 0" class="py-4 text-sm text-gray-500">No risk events.</li>
                    </ul>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
