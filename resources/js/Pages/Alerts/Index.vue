<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    mode: { type: String, default: 'grouped' },
    alerts: { type: Object, default: null },
    groups: { type: Object, default: null },
    recentOccurrences: { type: Object, default: () => ({}) },
    bots: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ bot_id: null }) },
});

const expandedGroups = ref([]);

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

function queryFor(mode, botId = props.filters.bot_id) {
    const query = { mode };

    if (botId) {
        query.bot_id = botId;
    }

    return query;
}

function switchMode(mode) {
    router.get(route('alerts.index'), queryFor(mode), {
        preserveScroll: true,
        preserveState: true,
    });
}

function applyBotFilter(event) {
    const botId = event.target.value || null;

    router.get(route('alerts.index'), queryFor(props.mode, botId), {
        preserveScroll: true,
        preserveState: true,
    });
}

function markRead(alert) {
    router.post(route('alerts.read', alert.id), {}, { preserveScroll: true });
}

function markAllRead() {
    router.post(route('alerts.read-all'), { bot_id: props.filters.bot_id ?? null }, { preserveScroll: true });
}

function markGroupRead(group) {
    router.post(route('alerts.groups.read'), {
        type: group.type,
        bot_id: group.bot_id ?? null,
        through: group.through,
    }, { preserveScroll: true });
}

function toggleGroup(key) {
    expandedGroups.value = expandedGroups.value.includes(key)
        ? expandedGroups.value.filter((item) => item !== key)
        : [...expandedGroups.value, key];
}

function isExpanded(key) {
    return expandedGroups.value.includes(key);
}

function groupOccurrences(key) {
    return props.recentOccurrences?.[key] ?? [];
}

function viewAll(group) {
    router.get(route('alerts.index'), queryFor('all', group.bot_id ?? props.filters.bot_id ?? null), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Alerts" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-slate-100">Alerts</h2>
                    <p class="mt-1 text-sm text-slate-400">Operational incidents that need your attention.</p>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex rounded-md border border-slate-600 bg-slate-900 p-1">
                        <button
                            type="button"
                            class="rounded px-3 py-1.5 text-xs font-semibold uppercase tracking-widest"
                            :class="mode === 'grouped' ? 'bg-slate-100 text-slate-900' : 'text-slate-300 hover:bg-slate-800'"
                            @click="switchMode('grouped')"
                        >
                            Grouped
                        </button>
                        <button
                            type="button"
                            class="rounded px-3 py-1.5 text-xs font-semibold uppercase tracking-widest"
                            :class="mode === 'all' ? 'bg-slate-100 text-slate-900' : 'text-slate-300 hover:bg-slate-800'"
                            @click="switchMode('all')"
                        >
                            All alerts
                        </button>
                    </div>

                    <select
                        class="rounded-md border border-slate-600 bg-slate-900 px-3 py-2 text-sm text-slate-100"
                        :value="filters.bot_id ?? ''"
                        @change="applyBotFilter"
                    >
                        <option value="">All bots</option>
                        <option v-for="bot in bots" :key="bot.id" :value="bot.id">{{ bot.name }}</option>
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
                <div v-if="mode === 'grouped'" class="overflow-hidden bg-slate-800 shadow sm:rounded-lg">
                    <ul v-if="groups && groups.data.length > 0" class="divide-y divide-slate-700">
                        <li v-for="group in groups.data" :key="group.key" class="px-6 py-5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span
                                            class="rounded-full px-2 py-1 text-xs font-semibold uppercase tracking-wide"
                                            :class="severityClass(group.highest_severity)"
                                        >
                                            {{ group.highest_severity }}
                                        </span>
                                        <span class="text-xs text-slate-400">{{ group.type }}</span>

                                        <Link
                                            v-if="group.bot && !group.bot.archived"
                                            :href="route('bots.show', group.bot.id)"
                                            class="text-sm font-medium text-slate-200 underline"
                                        >
                                            {{ group.bot.name }}
                                        </Link>
                                        <span v-else-if="group.bot" class="text-sm text-slate-400">
                                            {{ group.bot.name }} (archived)
                                        </span>
                                        <span v-else class="text-sm text-slate-400">No bot</span>

                                        <span class="text-xs text-slate-400">
                                            {{ group.total_count }} total · {{ group.unread_count }} unread
                                        </span>
                                    </div>

                                    <p class="mt-2 text-sm leading-6 text-slate-300">{{ group.latest_message }}</p>
                                    <p class="mt-1 text-xs text-slate-500">{{ timestamp(group.latest_at) }}</p>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        v-if="group.unread_count > 0"
                                        type="button"
                                        class="rounded-md border border-slate-600 px-3 py-1.5 text-xs font-semibold text-slate-200 hover:bg-slate-700"
                                        @click="markGroupRead(group)"
                                    >
                                        Mark group read
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded-md border border-slate-600 px-3 py-1.5 text-xs font-semibold text-slate-200 hover:bg-slate-700"
                                        :aria-expanded="isExpanded(group.key)"
                                        @click="toggleGroup(group.key)"
                                    >
                                        {{ isExpanded(group.key) ? 'Hide recent' : 'Show recent' }}
                                    </button>
                                </div>
                            </div>

                            <div v-if="isExpanded(group.key)" class="mt-4 rounded-md border border-slate-700 bg-slate-900/60">
                                <ul class="divide-y divide-slate-700">
                                    <li
                                        v-for="occurrence in groupOccurrences(group.key)"
                                        :key="occurrence.id"
                                        class="px-4 py-3 text-sm"
                                    >
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span
                                                class="rounded-full px-2 py-1 text-xs font-semibold uppercase tracking-wide"
                                                :class="severityClass(occurrence.severity)"
                                            >
                                                {{ occurrence.severity }}
                                            </span>
                                            <span class="text-slate-300">{{ occurrence.message }}</span>
                                            <span class="text-xs text-slate-500">{{ timestamp(occurrence.created_at) }}</span>
                                            <span v-if="!occurrence.read_at" class="ml-auto text-xs text-blue-300">Unread</span>
                                        </div>
                                    </li>
                                    <li v-if="groupOccurrences(group.key).length === 0" class="px-4 py-3 text-sm text-slate-400">
                                        No recent occurrences.
                                    </li>
                                </ul>
                                <div v-if="!group.bot?.archived" class="border-t border-slate-700 px-4 py-3 text-right">
                                    <button type="button" class="text-xs font-semibold text-slate-200 underline" @click="viewAll(group)">
                                        View all
                                    </button>
                                </div>
                            </div>
                        </li>
                    </ul>

                    <div v-else class="px-6 py-12 text-center text-sm text-slate-300">
                        No grouped alerts {{ filters.bot_id ? 'for this bot ' : '' }}in the last 30 days.
                    </div>

                    <div v-if="groups && groups.links.length > 3" class="flex flex-wrap items-center justify-center gap-2 px-6 pb-6">
                        <template v-for="link in groups.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                preserve-scroll
                                class="rounded-md px-3 py-2 text-xs font-semibold"
                                :class="link.active ? 'bg-slate-100 text-slate-900' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                                v-html="link.label"
                            />
                            <span v-else class="rounded-md bg-slate-900 px-3 py-2 text-xs font-semibold text-slate-600" v-html="link.label" />
                        </template>
                    </div>
                </div>

                <div v-else class="overflow-hidden bg-slate-800 shadow sm:rounded-lg">
                    <ul v-if="alerts && alerts.data.length > 0" class="divide-y divide-slate-700">
                        <li
                            v-for="alert in alerts.data"
                            :key="alert.id"
                            class="px-6 py-5 transition"
                            :class="alert.read_at ? 'bg-slate-800 opacity-60' : 'bg-slate-800'"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span v-if="!alert.read_at" class="h-2 w-2 rounded-full bg-blue-400" />
                                        <span
                                            class="rounded-full px-2 py-1 text-xs font-semibold uppercase tracking-wide"
                                            :class="severityClass(alert.severity)"
                                        >
                                            {{ alert.severity }}
                                        </span>
                                        <span class="text-xs text-slate-400">{{ alert.type }}</span>
                                    </div>

                                    <h3 class="mt-2 text-base font-semibold text-slate-100">{{ alert.title }}</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-300">{{ alert.message }}</p>

                                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-400">
                                        <Link
                                            v-if="alert.bot && !alert.bot.archived"
                                            :href="route('bots.show', alert.bot.id)"
                                            class="text-slate-200 underline"
                                        >
                                            {{ alert.bot.name }}
                                        </Link>
                                        <span v-else-if="alert.bot">{{ alert.bot.name }} (archived)</span>
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
                    </ul>

                    <div v-else class="px-6 py-12 text-center text-sm text-slate-300">
                        No alerts yet. Pauses, breaches, and kill-switch events will appear here.
                    </div>

                    <div v-if="alerts && alerts.links.length > 3" class="flex flex-wrap items-center justify-center gap-2 px-6 pb-6">
                        <template v-for="link in alerts.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                preserve-scroll
                                class="rounded-md px-3 py-2 text-xs font-semibold"
                                :class="link.active ? 'bg-slate-100 text-slate-900' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                                v-html="link.label"
                            />
                            <span v-else class="rounded-md bg-slate-900 px-3 py-2 text-xs font-semibold text-slate-600" v-html="link.label" />
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
