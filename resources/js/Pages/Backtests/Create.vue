<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    bots: { type: Array, default: () => [] },
    timeframes: { type: Array, default: () => [] },
    defaults: { type: Object, default: () => ({}) },
    presets: { type: Array, default: () => [] },
    maxCandles: { type: Number, default: 200000 },
});

const initialBot = props.bots.find((bot) => bot.has_configuration) ?? props.bots[0];

const form = useForm({
    bot_id: initialBot?.id ?? null,
    timeframe: props.defaults.timeframe ?? '15m',
    start_at: props.defaults.start_at ?? '',
    end_at: props.defaults.end_at ?? '',
    name: '',
});

const seconds = {
    '1m': 60,
    '5m': 300,
    '15m': 900,
    '1h': 3600,
    '4h': 14400,
    '1d': 86400,
};

const selectedBot = computed(() => props.bots.find((bot) => bot.id === form.bot_id));
const botReady = computed(() => Boolean(selectedBot.value?.has_configuration));

const estimate = computed(() => {
    if (!form.start_at || !form.end_at) return null;

    const start = new Date(`${form.start_at}T00:00:00Z`).getTime();
    const end = new Date(`${form.end_at}T00:00:00Z`).getTime();

    if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) return null;

    return Math.floor((end - start) / 1000 / (seconds[form.timeframe] ?? 900));
});

const tooLarge = computed(() => estimate.value !== null && estimate.value > props.maxCandles);

function applyPreset(days) {
    const end = new Date();
    const start = new Date(end.getTime() - days * 86400000);
    form.start_at = start.toISOString().slice(0, 10);
    form.end_at = end.toISOString().slice(0, 10);
}

const submit = () => form.post(route('backtests.store'));
</script>

<template>
    <Head title="New backtest" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-slate-100">New backtest</h2>
                <Link :href="route('backtests.index')" class="text-sm text-slate-300 underline">Back to backtests</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <form class="space-y-6 bg-slate-800 p-6 shadow sm:rounded-lg" @submit.prevent="submit">
                    <div>
                        <InputLabel for="bot_id" value="Bot" />
                        <select id="bot_id" v-model="form.bot_id" class="mt-1 block w-full rounded-md border-slate-500" required>
                            <option :value="null" disabled>Select one of your bots</option>
                            <option v-for="bot in bots" :key="bot.id" :value="bot.id" :disabled="!bot.has_configuration">
                                {{ bot.name }} · {{ bot.symbol }} · {{ bot.strategy_key }}{{ bot.has_configuration ? '' : ' (configure it first)' }}
                            </option>
                        </select>
                        <p v-if="selectedBot" class="mt-1 text-xs text-slate-400">
                            {{ selectedBot.exchange }} · {{ selectedBot.symbol }} · capital {{ selectedBot.capital_allocation }} · fee {{ selectedBot.fee_rate }}
                        </p>
                        <p v-if="selectedBot && !botReady" class="mt-2 text-sm text-amber-300">
                            This bot has no saved strategy configuration yet. Open the bot and save a configuration before running a backtest.
                        </p>
                        <p v-if="bots.length === 0" class="mt-2 text-sm text-amber-300">
                            Create and configure a bot before running a backtest.
                        </p>
                        <InputError class="mt-2" :message="form.errors.bot_id" />
                    </div>

                    <div>
                        <InputLabel for="name" value="Run name (optional)" />
                        <TextInput id="name" v-model="form.name" class="mt-1 block w-full" placeholder="Defaults to the bot name" />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div class="grid gap-6 md:grid-cols-3">
                        <div>
                            <InputLabel for="timeframe" value="Timeframe" />
                            <select id="timeframe" v-model="form.timeframe" class="mt-1 block w-full rounded-md border-slate-500">
                                <option v-for="timeframe in timeframes" :key="timeframe" :value="timeframe">{{ timeframe }}</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.timeframe" />
                        </div>

                        <div>
                            <InputLabel for="start_at" value="Start date (UTC)" />
                            <TextInput id="start_at" v-model="form.start_at" type="date" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.start_at" />
                        </div>

                        <div>
                            <InputLabel for="end_at" value="End date (UTC)" />
                            <TextInput id="end_at" v-model="form.end_at" type="date" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.end_at" />
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-xs font-medium uppercase tracking-wide text-slate-400">Range presets</span>
                        <button
                            v-for="preset in presets"
                            :key="preset.days"
                            type="button"
                            class="rounded-md border border-slate-600 px-3 py-1 text-xs text-slate-200 hover:bg-slate-700"
                            @click="applyPreset(preset.days)"
                        >
                            Last {{ preset.label }}
                        </button>
                    </div>

                    <div class="rounded-md border border-slate-700 bg-slate-900 p-4 text-sm text-slate-300">
                        <p v-if="estimate !== null">
                            Estimated candles: <span class="font-semibold text-slate-100">{{ estimate.toLocaleString() }}</span>
                            <span class="text-slate-400"> (limit {{ maxCandles.toLocaleString() }})</span>
                        </p>
                        <p v-else>Choose a timeframe and a valid date range to see the estimate.</p>
                        <p v-if="tooLarge" class="mt-2 text-amber-300">This range is too large. Choose a shorter range or a larger timeframe.</p>
                    </div>

                    <div class="rounded-md border border-slate-700 bg-slate-900 p-4 text-sm text-slate-300">
                        <p class="font-medium text-slate-100">What the replay does</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">
                            <li>Starts flat with the bot's capital allocation and no inventory.</li>
                            <li>Fills limit orders at the candle close, at the limit price, in full.</li>
                            <li>Uses the bot's strategy configuration and effective risk limits at the time you click Run.</li>
                            <li>Results are directional, not predictive.</li>
                        </ul>
                    </div>

                    <div class="flex items-center gap-4">
                        <PrimaryButton :disabled="form.processing || bots.length === 0 || !botReady || tooLarge">Queue backtest</PrimaryButton>
                        <span v-if="form.processing" class="text-sm text-slate-400">Queuing…</span>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
