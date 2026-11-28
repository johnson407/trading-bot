<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import EquityChart from '@/Components/EquityChart.vue';
import { formatMoney, formatSignedMoney } from '@/Support/money.js';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

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
    botHealth: {
        type: Array,
        required: true,
    },
    engine: {
        type: Object,
        required: true,
    },
});

const page = usePage();
const resuming = ref([]);
const unconfirmed = ref([]);

const percent = (value) => `${Number(value ?? 0).toFixed(2)}%`;
const signedPercent = (value) => `${Number(value ?? 0) > 0 ? '+' : ''}${Number(value ?? 0).toFixed(2)}%`;
const timestamp = (value) => (value ? new Date(value).toLocaleString() : 'Never');
const issueLabel = (issue) =>
    ({
        paused_by_engine: 'Paused by engine',
        stale: 'Stale',
        error: 'Error',
    })[issue] ?? issue;
const issueClass = (issue) =>
    issue === 'paused_by_engine'
        ? 'border-amber-500/40 bg-amber-950/40 text-amber-100'
        : 'border-red-500/40 bg-red-950/40 text-red-100';
const pnlClass = (value) => {
    const formatted = formatSignedMoney(value);

    if (formatted.startsWith('-')) {
        return 'text-rose-300';
    }

    return formatted === '0.00' ? 'text-slate-100' : 'text-emerald-300';
};

const isResuming = (id) => resuming.value.includes(id);
const isUnconfirmed = (id) => unconfirmed.value.includes(id);

const resume = (bot) => {
    if (isResuming(bot.id)) {
        return;
    }

    unconfirmed.value = unconfirmed.value.filter((id) => id !== bot.id);
    resuming.value = [...resuming.value, bot.id];

    router.post(route('bots.start', bot.id), {}, {
        preserveScroll: true,
        onError: () => {
            resuming.value = resuming.value.filter((id) => id !== bot.id);
        },
    });
};

watch(
    () => props.botHealth,
    (rows) => {
        const pausedIds = new Set(
            rows
                .filter((bot) => bot.status === 'paused' && bot.desired_status === 'running')
                .map((bot) => bot.id),
        );

        unconfirmed.value = unconfirmed.value.filter((id) => pausedIds.has(id));

        if (resuming.value.length === 0) {
            return;
        }

        const stillPaused = resuming.value.filter((id) => pausedIds.has(id));
        unconfirmed.value = [...new Set([...unconfirmed.value, ...stillPaused])];
        resuming.value = [];
    },
    { deep: true },
);

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
            only: ['summary', 'recentActivity', 'botHealth', 'engine'],
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
        <div class="mx-auto max-w-7xl px-4 pb-10 pt-5 sm:px-6 sm:pt-6 lg:px-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-xl font-semibold leading-tight text-slate-100">Dashboard</h1>
                <div class="flex flex-wrap items-center gap-3">
                    <Link
                        :href="route('kill-switch.create')"
                        class="rounded-md border border-rose-400 bg-rose-950 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-rose-200 hover:bg-rose-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-300"
                    >
                        Kill switch
                    </Link>
                    <Link
                        :href="route('bots.create')"
                        class="rounded-md bg-blue-700 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-blue-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-300"
                    >
                        Create bot
                    </Link>
                </div>
            </div>

            <div v-if="!engine.available" class="mt-4 rounded-lg border border-amber-500/40 bg-amber-950/40 p-4 text-sm text-amber-100">
                <span class="font-semibold">Trading engine heartbeat is unavailable.</span>
                Bots will not be paused automatically. Check the engine container and Redis before relying on bot control.
                <span v-if="engine.last_seen_at" class="text-amber-200"> Last seen {{ new Date(engine.last_seen_at).toLocaleString() }}.</span>
            </div>
            <div v-if="page.props.errors?.action || page.props.errors?.confirmation" class="mt-4 rounded-lg border border-red-500/40 bg-red-950/40 p-4 text-sm text-red-100">
                <p v-if="page.props.errors?.action">{{ page.props.errors.action }}</p>
                <p v-if="page.props.errors?.confirmation">{{ page.props.errors.confirmation }}</p>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-12 lg:gap-5">
                <section class="lg:col-span-8" aria-labelledby="equity-history-heading">
                    <div class="rounded-lg border border-slate-700 bg-slate-800">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-700 px-4 py-3 sm:px-5">
                            <h2 id="equity-history-heading" class="text-sm font-semibold text-slate-100">Equity history</h2>
                            <div class="flex gap-2" role="group" aria-label="Equity history range">
                                <button
                                    v-for="range in equityHistory.available_ranges"
                                    :key="range"
                                    type="button"
                                    class="rounded-md border px-2.5 py-1 text-xs font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300"
                                    :class="range === equityHistory.range_days ? 'border-blue-400 bg-blue-500/10 text-blue-200' : 'border-slate-500 text-slate-300 hover:border-slate-400'"
                                    :aria-pressed="range === equityHistory.range_days"
                                    @click="selectRange(range)"
                                >
                                    {{ range }} days
                                </button>
                            </div>
                        </div>

                        <div class="px-4 pb-4 pt-4 sm:px-5 sm:pb-5">
                            <div class="flex flex-wrap items-end justify-between gap-4">
                                <div>
                                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Account equity</p>
                                    <p class="mt-1 text-3xl font-semibold tabular-nums text-slate-100 sm:text-4xl">
                                        {{ equityHistory.latest ? formatMoney(equityHistory.latest.equity) : '—' }}
                                    </p>
                                    <p class="mt-1 text-xs text-slate-400">
                                        <template v-if="equityHistory.latest">Snapshot {{ timestamp(equityHistory.latest.at) }}</template>
                                        <template v-else>No snapshot yet</template>
                                    </p>
                                </div>
                                <dl class="grid grid-cols-2 gap-4 sm:gap-6">
                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Realized P&amp;L</dt>
                                        <dd
                                            class="mt-1 text-lg font-semibold tabular-nums"
                                            :class="pnlClass(summary.realized_pnl)"
                                        >
                                            {{ formatSignedMoney(summary.realized_pnl) }}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-medium uppercase tracking-wide text-slate-400">Unrealized P&amp;L</dt>
                                        <dd
                                            class="mt-1 text-lg font-semibold tabular-nums"
                                            :class="pnlClass(summary.unrealized_pnl)"
                                        >
                                            {{ formatSignedMoney(summary.unrealized_pnl) }}
                                        </dd>
                                    </div>
                                </dl>
                            </div>

                            <p v-if="equityHistory.collecting && equityHistory.has_history" class="mt-4 text-sm text-amber-200">
                                Collecting history - at least two snapshots are needed for period metrics.
                            </p>

                            <div v-if="!equityHistory.has_history" class="mt-4 rounded-md border border-dashed border-slate-700 px-4 py-6 text-center text-sm text-slate-300">
                                <template v-if="equityHistory.latest">No equity samples in this range. Choose a wider range or check back after the next capture.</template>
                                <template v-else>No equity history yet. Snapshots are collected on a schedule; check back after a few capture intervals.</template>
                            </div>
                            <div v-else class="mt-4">
                                <EquityChart :labels="equityLabels" :series="equitySeries" value-label="Account equity" format="money" height-class="h-56 sm:h-72 lg:h-80" />
                            </div>
                        </div>

                        <div v-if="equityHistory.metrics" class="grid grid-cols-3 gap-3 border-t border-slate-700 px-4 py-3 sm:px-5">
                            <div>
                                <div class="text-xs text-slate-400">Period return</div>
                                <div
                                    class="mt-1 text-base font-semibold tabular-nums sm:text-lg"
                                    :class="equityHistory.metrics.period_return === null ? 'text-slate-400' : Number(equityHistory.metrics.period_return) < 0 ? 'text-rose-300' : 'text-emerald-300'"
                                >
                                    {{ equityHistory.metrics.period_return === null ? '—' : signedPercent(equityHistory.metrics.period_return) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-slate-400">Peak equity</div>
                                <div class="mt-1 text-base font-semibold tabular-nums text-slate-100 sm:text-lg">
                                    {{ formatMoney(equityHistory.metrics.peak_equity) }}
                                </div>
                            </div>
                            <div>
                                <div class="text-xs text-slate-400">Maximum drawdown</div>
                                <div
                                    class="mt-1 text-base font-semibold tabular-nums sm:text-lg"
                                    :class="Number(equityHistory.metrics.max_drawdown) > 0 ? 'text-rose-300' : 'text-slate-100'"
                                >
                                    {{ percent(equityHistory.metrics.max_drawdown) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <aside class="space-y-4 lg:col-span-4" aria-label="Operations">
                    <div class="rounded-lg border border-slate-700 bg-slate-800">
                        <div class="flex items-center justify-between gap-3 border-b border-slate-700 px-4 py-3 sm:px-5">
                            <h2 class="text-sm font-semibold text-slate-100">Needs attention</h2>
                            <span
                                class="rounded-full bg-slate-700 px-2 py-0.5 text-xs font-semibold tabular-nums text-slate-200"
                                :aria-label="`${botHealth.length} ${botHealth.length === 1 ? 'bot needs' : 'bots need'} attention`"
                            >{{ botHealth.length }}</span>
                        </div>

                        <div v-if="botHealth.length === 0" class="flex items-center gap-2 px-4 py-3 text-sm text-slate-300 sm:px-5">
                            <span class="h-2 w-2 rounded-full bg-emerald-400" aria-hidden="true"></span>
                            All bots healthy
                        </div>

                        <ul
                            v-else
                            class="max-h-72 divide-y divide-slate-700 overflow-y-auto focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-300"
                            tabindex="0"
                            aria-label="Bots needing attention"
                        >
                            <li v-for="bot in botHealth" :key="bot.id" class="px-4 py-2.5 sm:px-5">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <Link
                                            :href="route('bots.show', bot.id)"
                                            :title="bot.name"
                                            class="truncate text-sm font-medium text-blue-300 hover:text-blue-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300"
                                        >
                                            {{ bot.name }}
                                        </Link>
                                        <span class="text-xs uppercase tracking-wide text-slate-400">{{ bot.mode }}</span>
                                    </div>
                                    <div class="flex flex-wrap gap-1">
                                        <span
                                            v-for="issue in bot.issues"
                                            :key="issue"
                                            class="rounded border px-1.5 py-0.5 text-xs font-medium"
                                            :class="issueClass(issue)"
                                        >
                                            {{ issueLabel(issue) }}
                                        </span>
                                    </div>
                                </div>

                                <p class="mt-1 text-sm text-slate-300">{{ bot.reason }}</p>
                                <p class="mt-1 text-xs text-slate-400">
                                    Last tick {{ timestamp(bot.last_tick_at) }}
                                    <span class="mx-1 text-slate-500" aria-hidden="true">·</span>
                                    Last success {{ timestamp(bot.last_successful_tick_at) }}
                                </p>

                                <div v-if="bot.can_resume" class="mt-2 flex flex-wrap items-center gap-2">
                                    <button
                                        v-if="bot.mode === 'paper'"
                                        type="button"
                                        class="rounded bg-emerald-700 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300 disabled:cursor-not-allowed disabled:opacity-60"
                                        :disabled="isResuming(bot.id)"
                                        @click="resume(bot)"
                                    >
                                        {{ isResuming(bot.id) ? 'Resuming...' : 'Resume' }}
                                    </button>
                                    <Link
                                        v-else
                                        :href="route('bots.show', bot.id)"
                                        class="rounded border border-emerald-500/50 px-2.5 py-1 text-xs font-semibold text-emerald-200 hover:border-emerald-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300"
                                    >
                                        Resume with live confirmation
                                    </Link>
                                    <p v-if="isUnconfirmed(bot.id)" class="w-full text-xs text-amber-200">
                                        Resume requested; the engine has not confirmed yet.
                                    </p>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <div class="rounded-lg border border-slate-700 bg-slate-800 p-4 sm:p-5">
                        <h2 class="text-sm font-semibold text-slate-100">Fleet</h2>
                        <div v-if="summary.total === 0" class="mt-2 text-sm text-slate-300">
                            No bots yet.
                            <Link :href="route('bots.create')" class="text-blue-300 hover:text-blue-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300">Create a bot</Link>
                        </div>
                        <dl v-else class="mt-3 grid grid-cols-5 divide-x divide-slate-700 text-center">
                            <div class="px-1">
                                <dt class="text-xs text-slate-400">Bots</dt>
                                <dd class="mt-0.5 text-lg font-semibold tabular-nums text-slate-100">{{ summary.total }}</dd>
                            </div>
                            <div class="px-1">
                                <dt class="text-xs text-slate-400">Running</dt>
                                <dd class="mt-0.5 text-lg font-semibold tabular-nums text-emerald-300">{{ summary.running }}</dd>
                            </div>
                            <div class="px-1">
                                <dt class="text-xs text-slate-400">Paused</dt>
                                <dd class="mt-0.5 text-lg font-semibold tabular-nums text-amber-200">{{ summary.paused }}</dd>
                            </div>
                            <div class="px-1">
                                <dt class="text-xs text-slate-400">Idle</dt>
                                <dd class="mt-0.5 text-lg font-semibold tabular-nums text-slate-200">{{ summary.idle }}</dd>
                            </div>
                            <div class="px-1">
                                <dt class="text-xs text-slate-400">Stopped</dt>
                                <dd class="mt-0.5 text-lg font-semibold tabular-nums text-slate-200">{{ summary.stopped }}</dd>
                            </div>
                        </dl>
                    </div>
                </aside>
            </div>

            <div class="mt-4 overflow-x-auto rounded-lg border border-slate-700 bg-slate-800 lg:mt-5">
                <div class="flex items-center justify-between gap-3 border-b border-slate-700 px-4 py-3 sm:px-5">
                    <h2 class="text-sm font-semibold text-slate-100">Recent activity</h2>
                    <Link :href="route('bots.index')" class="text-xs text-blue-300 hover:text-blue-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300">Manage bots</Link>
                </div>

                <div v-if="recentActivity.length === 0" class="px-4 py-6 text-sm text-slate-300 sm:px-5">
                    No activity yet. Create a bot to get started.
                </div>

                <table v-else class="min-w-full divide-y divide-slate-700">
                    <thead class="bg-slate-900">
                        <tr>
                            <th scope="col" class="px-4 py-2 sm:px-5 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Time</th>
                            <th scope="col" class="px-4 py-2 sm:px-5 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Bot</th>
                            <th scope="col" class="hidden sm:table-cell px-4 py-2 sm:px-5 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Type</th>
                            <th scope="col" class="px-4 py-2 sm:px-5 text-left text-xs font-medium uppercase tracking-wider text-slate-300">Message</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-700">
                        <tr v-for="entry in recentActivity" :key="entry.id">
                            <td class="whitespace-nowrap px-4 py-2 sm:px-5 text-xs text-slate-400">{{ entry.created_at ? new Date(entry.created_at).toLocaleString() : '' }}</td>
                            <td class="whitespace-nowrap px-4 py-2 sm:px-5 text-sm text-slate-100">
                                <Link v-if="entry.bot_id && !entry.archived" :href="route('bots.show', entry.bot_id)" class="text-blue-300 hover:text-blue-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-300">
                                    {{ entry.bot }}
                                </Link>
                                <span v-else-if="entry.bot_id">{{ entry.bot }}</span>
                                <span v-else>Account</span>
                            </td>
                            <td class="hidden sm:table-cell whitespace-nowrap px-4 py-2 sm:px-5 text-sm text-slate-300">{{ entry.type }}</td>
                            <td class="px-4 py-2 sm:px-5 text-sm" :class="entry.severity === 'error' ? 'text-red-300' : 'text-slate-200'">
                                {{ entry.message }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
