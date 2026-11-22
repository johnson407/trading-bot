<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatMoney } from '@/Support/money.js';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    bots: { type: Array, default: () => [] },
    timeframes: { type: Array, default: () => [] },
    defaults: { type: Object, default: () => ({}) },
    presets: { type: Array, default: () => [] },
    maxCombinations: { type: Number, default: 24 },
    maxCandles: { type: Number, default: 200000 },
});

const initialBot = props.bots[0];

const form = useForm({
    bot_id: initialBot?.id ?? null,
    timeframe: props.defaults.timeframe ?? '15m',
    start_at: props.defaults.start_at ?? '',
    end_at: props.defaults.end_at ?? '',
    name: '',
    center_prices: '',
    spacing_values: '',
    level_counts: '',
});

const selectedBot = computed(() => props.bots.find((bot) => bot.id === form.bot_id));

function splitValues(value) {
    return String(value ?? '')
        .split(/[,\s]+/)
        .map((item) => item.trim())
        .filter((item) => item !== '');
}

const centers = computed(() => splitValues(form.center_prices));
const spacings = computed(() => splitValues(form.spacing_values));
const levels = computed(() => splitValues(form.level_counts));

const combinationCount = computed(() => centers.value.length * spacings.value.length * levels.value.length);
const overCap = computed(() => combinationCount.value > props.maxCombinations);
const underMinimum = computed(() => combinationCount.value > 0 && combinationCount.value < 2);
const canSubmit = computed(() => props.bots.length > 0
    && combinationCount.value >= 2
    && !overCap.value
    && !tooLarge.value);

const seconds = {
    '1m': 60,
    '5m': 300,
    '15m': 900,
    '1h': 3600,
    '4h': 14400,
    '1d': 86400,
};

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

const submit = () => {
    form
        .transform((data) => ({
            ...data,
            center_prices: centers.value,
            spacing_values: spacings.value,
            level_counts: levels.value,
        }))
        .post(route('backtest-sweeps.store'));
};
</script>

<template>
    <Head title="New parameter sweep" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-slate-100">New parameter sweep</h2>
                <Link :href="route('backtest-sweeps.index')" class="text-sm text-slate-300 underline">Back to sweeps</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <form class="space-y-6 bg-slate-800 p-6 shadow sm:rounded-lg" @submit.prevent="submit">
                    <div>
                        <InputLabel for="bot_id" value="Bot" />
                        <select id="bot_id" v-model="form.bot_id" class="mt-1 block w-full rounded-md border-slate-500" required>
                            <option :value="null" disabled>Select one of your configured grid bots</option>
                            <option v-for="bot in bots" :key="bot.id" :value="bot.id">
                                {{ bot.name }} · {{ bot.symbol }} · {{ bot.strategy_key }}
                            </option>
                        </select>
                        <p v-if="selectedBot" class="mt-1 text-xs text-slate-400">
                            {{ selectedBot.exchange }} · {{ selectedBot.symbol }} · capital {{ formatMoney(selectedBot.capital_allocation) }} · fee {{ selectedBot.fee_rate }}
                        </p>
                        <p v-if="bots.length === 0" class="mt-2 text-sm text-amber-300">
                            Create and configure a grid bot before running a sweep.
                        </p>
                        <InputError class="mt-2" :message="form.errors.bot_id" />
                    </div>

                    <div>
                        <InputLabel for="name" value="Sweep name (optional)" />
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

                    <div class="grid gap-6 md:grid-cols-3">
                        <div>
                            <InputLabel for="center_prices" value="Center prices" />
                            <TextInput id="center_prices" v-model="form.center_prices" class="mt-1 block w-full" placeholder="50000, 51000" />
                            <p class="mt-1 text-xs text-slate-400">Comma-separated positive decimals.</p>
                            <div class="mt-2 flex flex-wrap gap-1">
                                <span v-for="value in centers" :key="value" class="rounded-full bg-slate-900 px-2 py-0.5 text-xs text-slate-200">{{ value }}</span>
                            </div>
                            <InputError class="mt-2" :message="form.errors.center_prices" />
                        </div>

                        <div>
                            <InputLabel for="spacing_values" value="Spacing values" />
                            <TextInput id="spacing_values" v-model="form.spacing_values" class="mt-1 block w-full" placeholder="100, 200" />
                            <p class="mt-1 text-xs text-slate-400">Comma-separated positive decimals.</p>
                            <div class="mt-2 flex flex-wrap gap-1">
                                <span v-for="value in spacings" :key="value" class="rounded-full bg-slate-900 px-2 py-0.5 text-xs text-slate-200">{{ value }}</span>
                            </div>
                            <InputError class="mt-2" :message="form.errors.spacing_values" />
                        </div>

                        <div>
                            <InputLabel for="level_counts" value="Levels per side" />
                            <TextInput id="level_counts" v-model="form.level_counts" class="mt-1 block w-full" placeholder="4, 5, 6" />
                            <p class="mt-1 text-xs text-slate-400">Whole numbers from 1 to 50. Each value sets the strategy&apos;s levels_per_side.</p>
                            <div class="mt-2 flex flex-wrap gap-1">
                                <span v-for="value in levels" :key="value" class="rounded-full bg-slate-900 px-2 py-0.5 text-xs text-slate-200">{{ value }}</span>
                            </div>
                            <InputError class="mt-2" :message="form.errors.level_counts" />
                        </div>
                    </div>

                    <div class="rounded-md border border-slate-700 bg-slate-900 p-4 text-sm text-slate-300">
                        <p>
                            Combinations: <span class="font-semibold text-slate-100">{{ combinationCount }}</span>
                            <span class="text-slate-400"> (maximum {{ maxCombinations }})</span>
                        </p>
                        <p v-if="combinationCount === 0" class="mt-2 text-slate-400">
                            Enter at least one value for each dimension to see the run count.
                        </p>
                        <p v-if="underMinimum" class="mt-2 text-amber-300">
                            A sweep needs at least two combinations.
                        </p>
                        <p v-if="overCap" class="mt-2 text-amber-300">
                            This sweep produces {{ combinationCount }} runs, above the maximum of {{ maxCombinations }}. Remove values before submitting.
                        </p>
                        <p v-if="estimate !== null" class="mt-2 text-xs text-slate-400">
                            Estimated candles per run: {{ estimate.toLocaleString() }} (limit {{ maxCandles.toLocaleString() }})
                        </p>
                        <p v-if="tooLarge" class="mt-2 text-amber-300">This range is too large. Choose a shorter range or a larger timeframe.</p>
                    </div>

                    <div class="rounded-md border border-slate-700 bg-slate-900 p-4 text-sm text-slate-300">
                        <p class="font-medium text-slate-100">What the sweep overrides</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5 text-xs">
                            <li>Only center price, spacing value, and level count change per run.</li>
                            <li>Capital, fees, risk limits, exits, and every other strategy setting come from the bot.</li>
                            <li>All runs use the same grid bot, timeframe, and date range.</li>
                            <li>Each run keeps its own immutable snapshot and can be opened like any backtest.</li>
                        </ul>
                    </div>

                    <div class="flex items-center gap-4">
                        <PrimaryButton :disabled="form.processing || !canSubmit">Run sweep</PrimaryButton>
                        <span v-if="form.processing" class="text-sm text-slate-400">Queuing combinations…</span>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
