<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    alerts: { type: Object, required: true },
    bots: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ bot_id: null }) },
});

function timestamp(value) {
    return value ? new Date(value).toLocaleString() : '—';
}

function severityClass(severity) {
    return {
        critical: 'bg-red-950 text-red-300',
        warning: 'bg-amber-950 text-amber-300',
        info: 'bg-blue-950 text-blue-300',
    }[severity] ?? 'bg-slate-900 text-slate-300';
}

function applyBotFilter(event) {
    const botId = event.target.value;

    router.get(
        route('alerts.index'),
        botId ? { bot_id: botId } : {},
        { preserveScroll: true, preserveState: true },
    );
}

function markRead(alert) {
    router.post(route('alerts.read', alert.id), {}, { preserveScroll: true });
}

function markAllRead() {
    router.post(
        route('alerts.read-all'),
        { bot_id: props.filters.bot_id ?? null },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Alerts" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-slate-100">
                        Alerts
                    </h2>
                    <p class="mt-1 text-sm text-slate-400">
                        Operational incidents that need your attention.
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <select
                        :value="filters.bot_id ?? ''"
                        class="rounded-md border border-slate-600 bg-slate-900 px-3 py-2 text-sm text-slate-100"
                        @change="applyBotFilter"
                    >
                        <option value="">All bots</option>
                        <option
                            v-for="bot in bots"
                            :key="bot.id"
                            :value="bot.id"
                        >
                            {{ bot.name }}
                        </option>
                    </select>

                    <button
                        type="button"
                        class="rounded-md bg-slate-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-slate-100 hover:bg-slate-600"
                        @click="markAllRead"
                    >
                        Mark all read
                    </button>
                </div>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-slate-800 shadow sm:rounded-lg">
                    <ul class="divide-y divide-slate-700">
                        <li
                            v-for="alert in alerts.data"
                            :key="alert.id"
                            class="px-6 py-5 transition"
                            :class="alert.read_at ? 'opacity-60' : 'bg-slate-800'"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span
                                            v-if="!alert.read_at"
                                            class="h-2 w-2 rounded-full bg-blue-400"
                                            aria-label="Unread"
                                        />
                                        <span
                                            class="rounded-full px-2 py-1 text-xs font-semibold uppercase tracking-wide"
                                            :class="severityClass(alert.severity)"
                                        >
                                            {{ alert.severity }}
                                        </span>
                                        <span class="text-xs text-slate-400">
                                            {{ alert.type }}
                                        </span>
                                    </div>

                                    <h3 class="mt-2 text-base font-semibold text-slate-100">
                                        {{ alert.title }}
                                    </h3>

                                    <p class="mt-1 text-sm leading-6 text-slate-300">
                                        {{ alert.message }}
                                    </p>

                                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400">
                                        <span v-if="alert.bot">
                                            Bot:
                                            <Link
                                                v-if="!alert.bot.archived"
                                                :href="route('bots.show', alert.bot.id)"
                                                class="text-slate-200 underline"
                                            >
                                                {{ alert.bot.name }}
                                            </Link>
                                            <span v-else class="text-slate-200">{{ alert.bot.name }}</span>
                                        </span>
                                        <span v-else>No bot</span>
                                        <span>{{ timestamp(alert.created_at) }}</span>
                                    </div>
                                </div>

                                <button
                                    v-if="!alert.read_at"
                                    type="button"
                                    class="shrink-0 rounded-md border border-slate-600 px-3 py-1.5 text-xs font-semibold text-slate-200 hover:bg-slate-700"
                                    @click="markRead(alert)"
                                >
                                    Mark read
                                </button>
                            </div>
                        </li>

                        <li
                            v-if="alerts.data.length === 0"
                            class="px-6 py-12 text-center text-sm text-slate-300"
                        >
                            No alerts yet. Pauses, breaches, and kill-switch
                            events will appear here.
                        </li>
                    </ul>
                </div>

                <div
                    v-if="alerts.links.length > 3"
                    class="mt-6 flex flex-wrap items-center justify-center gap-2"
                >
                    <template v-for="link in alerts.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            preserve-scroll
                            class="rounded-md px-3 py-1.5 text-sm"
                            :class="
                                link.active
                                    ? 'bg-slate-100 font-semibold text-slate-900'
                                    : 'bg-slate-800 text-slate-300 hover:bg-slate-700'
                            "
                            v-html="link.label"
                        />
                        <span
                            v-else
                            class="rounded-md bg-slate-900 px-3 py-1.5 text-sm text-slate-600"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
