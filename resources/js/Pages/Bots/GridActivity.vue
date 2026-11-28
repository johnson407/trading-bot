<script setup>
import GridActivityChart from '@/Components/GridActivityChart.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatDuration, hasActivity, formatPrice, rangeLabel } from '@/Support/gridActivity.js';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    bot: { type: Object, required: true },
    gridActivity: { type: Object, required: true },
});

const showFills = ref(true);
const showCancels = ref(true);
const showGrid = ref(true);
let timer = null;

const activity = computed(() => props.gridActivity);
const activityPresent = computed(() => hasActivity(activity.value));
const currentGrid = computed(() => activity.value.grids.find((grid) => grid.current) ?? null);
const spacingLabel = computed(() => {
    const type = activity.value.summary.spacing_type;
    const spacing = activity.value.summary.spacing;

    if (!spacing) {
        return type === 'dynamic' ? 'Not computed yet' : '—';
    }

    const value = type === 'geometric'
        ? `${(Number(spacing) * 100).toFixed(2)}%`
        : formatPrice(spacing);

    return `${type === 'geometric' ? 'Geometric' : type === 'dynamic' ? 'Dynamic' : 'Arithmetic'} · ${value}`;
});

function selectRange(range) {
    router.get(route('bots.grid-activity', props.bot.id), { range }, {
        only: ['gridActivity'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

onMounted(() => {
    timer = setInterval(() => {
        if (document.visibilityState !== 'visible') {
            return;
        }

        router.reload({
            only: ['gridActivity', 'bot'],
            preserveState: true,
            preserveScroll: true,
        });
    }, 30000);
});

onUnmounted(() => {
    if (timer) clearInterval(timer);
});
</script>

<template>
    <Head :title="`Grid activity — ${bot.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-slate-100">Grid activity</h2>
                    <p class="text-sm text-slate-300">{{ bot.name }} · {{ bot.symbol }} · {{ bot.exchange }} · <span class="capitalize">{{ bot.status }}</span></p>
                </div>
                <div class="flex items-center gap-4">
                    <Link :href="route('bots.show', bot.id)" class="text-sm text-blue-300 hover:text-blue-200">Back to bot</Link>
                    <Link :href="route('bots.activity', bot.id)" class="text-sm text-blue-300 hover:text-blue-200">Activity log</Link>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-800 p-4 shadow">
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="range in activity.available_ranges"
                            :key="range"
                            type="button"
                            :aria-pressed="range === activity.range"
                            class="rounded px-3 py-1.5 text-sm font-medium"
                            :class="range === activity.range ? 'bg-blue-600 text-white' : 'bg-slate-700 text-slate-200 hover:bg-slate-600'"
                            @click="selectRange(range)"
                        >
                            {{ rangeLabel(range) }}
                        </button>
                    </div>

                    <div class="flex flex-wrap gap-4 text-sm text-slate-300">
                        <label class="flex items-center gap-2"><input v-model="showGrid" type="checkbox" /> Grid levels</label>
                        <label class="flex items-center gap-2"><input v-model="showFills" type="checkbox" /> Fills</label>
                        <label class="flex items-center gap-2"><input v-model="showCancels" type="checkbox" /> Cancels</label>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
                    <div class="rounded-lg bg-slate-800 p-4 shadow">
                        <p class="text-xs uppercase text-slate-300">Fills</p>
                        <p class="mt-1 text-lg font-semibold text-emerald-300">{{ activity.summary.fill_count }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-800 p-4 shadow">
                        <p class="text-xs uppercase text-slate-300">Cancels</p>
                        <p class="mt-1 text-lg font-semibold text-slate-100">{{ activity.summary.cancel_count }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-800 p-4 shadow">
                        <p class="text-xs uppercase text-slate-300">Cancel : fill</p>
                        <p class="mt-1 text-lg font-semibold text-slate-100">{{ activity.summary.cancel_fill_ratio ?? 'No fills' }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-800 p-4 shadow">
                        <p class="text-xs uppercase text-slate-300">Grid revisions</p>
                        <p class="mt-1 text-lg font-semibold text-slate-100">{{ activity.summary.grid_revisions }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-800 p-4 shadow">
                        <p class="text-xs uppercase text-slate-300">Median lifetime</p>
                        <p class="mt-1 text-lg font-semibold text-slate-100">{{ formatDuration(activity.summary.median_lifetime_seconds) }}</p>
                    </div>
                    <div class="rounded-lg bg-slate-800 p-4 shadow">
                        <p class="text-xs uppercase text-slate-300">Current spacing</p>
                        <p class="mt-1 text-sm font-semibold text-slate-100">{{ spacingLabel }}</p>
                        <p v-if="activity.summary.spacing_type === 'dynamic'" class="mt-1 text-xs text-slate-400">Hysteresis {{ activity.summary.spacing_hysteresis_percent }}%</p>
                    </div>
                </div>

                <div class="rounded-lg bg-slate-800 p-4 text-xs text-slate-400">
                    <p>Cancels include grid replacement, re-arm, pause, stop, operator, and kill-switch cancels.</p>
                    <p class="mt-1">A revision is one stored ladder row. Restart re-arms that reuse a ladder do not create a revision.</p>
                    <p class="mt-1">Median lifetime covers stopped ladders replaced inside the window; the current ladder is excluded.</p>
                    <p v-if="activity.summary.spacing_type === 'dynamic'" class="mt-1">Hysteresis applies only to dynamic grids.</p>
                </div>

                <div v-if="currentGrid && !currentGrid.trading" class="rounded-lg border border-amber-700 bg-amber-950/40 p-4 text-sm text-amber-200">
                    Current ladder is shown, but this bot is not running; it is not necessarily trading.
                </div>

                <div v-if="!activity.price.available && activityPresent" class="rounded-lg border border-amber-700 bg-amber-950/40 p-4 text-sm text-amber-200">
                    {{ activity.price.error ?? 'Price history is unavailable.' }} Grid levels and markers are still shown.
                </div>

                <div v-if="activity.truncated.fills || activity.truncated.cancels || activity.truncated.grids" class="rounded-lg border border-slate-700 bg-slate-900/60 p-4 text-sm text-slate-300">
                    <p v-if="activity.truncated.fills">Showing the most recent {{ activity.fills.length }} of {{ activity.summary.fill_count }} fills.</p>
                    <p v-if="activity.truncated.cancels">Showing the most recent {{ activity.cancels.length }} of {{ activity.summary.cancel_count }} cancels.</p>
                    <p v-if="activity.truncated.grids">Showing the most recent {{ activity.grids.length }} of {{ activity.summary.grid_revisions }} grid revisions.</p>
                </div>

                <div v-if="!activityPresent" class="rounded-lg bg-slate-800 p-8 text-center text-sm text-slate-400 shadow">
                    No grids, fills, or cancels in this window.
                </div>
                <div v-else class="rounded-lg bg-slate-800 p-4 shadow">
                    <GridActivityChart :activity="activity" :show-fills="showFills" :show-cancels="showCancels" :show-grid="showGrid" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
