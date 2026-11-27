<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { createEngineStatusPoller, describeEngineStatus } from '@/Support/engineStatus.js';

const heartbeat = ref({});
const announcement = ref('');
const status = computed(() => describeEngineStatus(heartbeat.value));

let poller = null;

const dotClass = (tone) =>
    ({
        ok: 'bg-emerald-400',
        warn: 'bg-amber-400',
        danger: 'bg-red-500',
        muted: 'bg-slate-500',
    })[tone] ?? 'bg-slate-500';

const textClass = (tone) =>
    ({
        ok: 'text-emerald-200',
        warn: 'text-amber-200',
        danger: 'text-red-200',
        muted: 'text-slate-400',
    })[tone] ?? 'text-slate-400';

watch(
    () => [status.value.engine.state, status.value.feed.state, status.value.live.state].join(':'),
    (next, previous) => {
        if (previous) {
            announcement.value = `${status.value.engine.label}. ${status.value.feed.label}. ${status.value.live.label}.`;
        }
    },
);

onMounted(() => {
    poller = createEngineStatusPoller({
        fetcher: () =>
            fetch(route('health.engine'), {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            }),
        intervalMs: 10_000,
        onStatus: (value) => {
            heartbeat.value = value ?? {};
        },
    });
});

onBeforeUnmount(() => {
    poller?.stop();
});
</script>

<template>
    <div class="border-b border-slate-700 bg-slate-900">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div
                class="flex flex-wrap items-center gap-x-5 gap-y-1 py-2 text-xs"
                role="group"
                aria-label="Engine status"
            >
                <span class="inline-flex items-center gap-1.5" :title="status.engine.detail">
                    <span class="h-2 w-2 rounded-full" :class="dotClass(status.engine.tone)" />
                    <span class="font-medium" :class="textClass(status.engine.tone)">{{ status.engine.label }}</span>
                    <span class="text-slate-400">{{ status.engine.detail }}</span>
                </span>

                <span class="inline-flex items-center gap-1.5" :title="status.feed.detail">
                    <span class="h-2 w-2 rounded-full" :class="dotClass(status.feed.tone)" />
                    <span class="font-medium" :class="textClass(status.feed.tone)">{{ status.feed.label }}</span>
                    <span class="text-slate-400">{{ status.feed.detail }}</span>
                </span>

                <span class="inline-flex items-center gap-1.5" :title="status.live.detail">
                    <span class="h-2 w-2 rounded-full" :class="dotClass(status.live.tone)" />
                    <span class="font-medium" :class="textClass(status.live.tone)">{{ status.live.label }}</span>
                    <span class="text-slate-400">{{ status.live.detail }}</span>
                </span>
            </div>

            <span class="sr-only" role="status" aria-live="polite">{{ announcement }}</span>
        </div>
    </div>
</template>
