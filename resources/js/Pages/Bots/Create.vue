<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PlanPreview from '@/Components/PlanPreview.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { hiddenFields, marketNotice, mergeDerivedValues, previewPayload } from '@/Support/botSetup.js';
import { strategyOptions } from '@/Support/strategies.js';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    strategies: { type: Array, default: () => [] },
    exchanges: { type: Array, default: () => [] },
    presets: { type: Object, default: () => ({}) },
    feeRate: { type: String, default: '0' },
    sourceRun: { type: Object, default: null },
    initial: { type: Object, default: null },
});

const presetChoices = computed(() => Object.entries(props.presets).map(([key, preset]) => ({ key, ...preset })));
const sourceMode = computed(() => props.sourceRun !== null);
const setupMode = ref(props.sourceRun ? 'advanced' : 'quick');
const presetKey = ref(presetChoices.value[0]?.key ?? '');
const preview = ref(null);
const previewLoading = ref(false);
const previewError = ref('');
const marketLoading = ref(false);
const marketAvailable = ref(true);
const applyingValues = ref(false);
const initial = props.initial ?? {};

const form = useForm({
    name: '',
    exchange: props.exchanges[0] ?? 'binance',
    symbol: 'BTC/USDT',
    strategy_key: props.strategies[0]?.key ?? 'simple-grid',
    mode: 'paper',
    capital_allocation: '1000',
    fee_rate: props.feeRate ?? '0',
    center_price: '50000',
    level_count: 6,
    spacing_type: 'arithmetic',
    spacing_value: '100',
    order_quantity: '0.001',
    trailing_distance: '200',
    size_multiplier: '2',
    max_order_quantity: '0.01',
    max_increases: 3,
    min_spacing: '50',
    max_spacing: '500',
    initial_spacing: '',
    volatility_multiplier: '1',
    recalculation_interval_seconds: 60,
    spacing_hysteresis_percent: '20',
    ...initial,
    source_backtest_run_id: initial.source_backtest_run_id ?? null,
    stop_loss_percent: initial.stop_loss_percent ?? '',
    take_profit_percent: initial.take_profit_percent ?? '',
    range_low: initial.range_low ?? '',
    range_high: initial.range_high ?? '',
    live_confirmation: '',
});

const strategyChoices = computed(() => strategyOptions(props.strategies.map((strategy) => strategy.key)));
const selectedPreset = computed(() => props.presets[presetKey.value] ?? null);
const selectedStrategy = computed(() => strategyChoices.value.find((strategy) => strategy.key === form.strategy_key));
const isTrailing = computed(() => form.strategy_key === 'trailing-grid');
const isMartingale = computed(() => form.strategy_key === 'martingale-grid');
const isDynamic = computed(() => form.strategy_key === 'dynamic-grid');
const hideSharedSpacing = computed(() => hiddenFields(form.strategy_key).length > 0);
const expectedConfirmation = computed(() => `${form.name} on ${form.exchange}`);
const unavailableNotice = computed(() => marketNotice(marketAvailable.value));

let previewTimer = null;
let previewRequest = 0;

function schedulePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(fetchPreview, 250);
}

async function fetchPreview() {
    const requestId = ++previewRequest;
    previewLoading.value = true;

    try {
        const { data } = await axios.post(
            route('bots.preview'),
            previewPayload({ setupMode: setupMode.value, form, presetKey: presetKey.value }),
        );

        if (requestId !== previewRequest) {
            return;
        }

        preview.value = data;
        previewError.value = '';
        marketAvailable.value = data.market != null;

        if (setupMode.value === 'quick' && data.values) {
            applyValues(data.values);
        }
    } catch (error) {
        if (requestId !== previewRequest) {
            return;
        }

        previewError.value = error?.response?.data?.message ?? 'Preview is unavailable right now.';
    } finally {
        if (requestId === previewRequest) {
            previewLoading.value = false;
        }
    }
}

function applyValues(values) {
    const merged = mergeDerivedValues({
        strategy_key: form.strategy_key,
        level_count: form.level_count,
        spacing_type: form.spacing_type,
        spacing_value: form.spacing_value,
        order_quantity: form.order_quantity,
        trailing_distance: form.trailing_distance,
    }, values);

    applyingValues.value = true;
    Object.assign(form, merged);
    nextTick(() => {
        applyingValues.value = false;
    });
}

async function useMarketPrice() {
    marketLoading.value = true;

    try {
        const { data } = await axios.get(route('bots.market-data'), {
            params: { exchange: form.exchange, symbol: form.symbol },
        });

        marketAvailable.value = data.available;

        if (data.available) {
            form.center_price = data.market.last;
        }
    } catch {
        marketAvailable.value = false;
    } finally {
        marketLoading.value = false;
    }
}

watch(
    () => [
        setupMode.value,
        presetKey.value,
        form.exchange,
        form.symbol,
        form.capital_allocation,
        form.center_price,
        form.strategy_key,
        form.level_count,
        form.spacing_type,
        form.spacing_value,
        form.order_quantity,
        form.trailing_distance,
        form.max_order_quantity,
        form.min_spacing,
        form.max_spacing,
        form.initial_spacing,
        form.spacing_hysteresis_percent,
    ],
    () => {
        if (!applyingValues.value) {
            schedulePreview();
        }
    },
    { immediate: true, flush: 'sync' },
);

onBeforeUnmount(() => clearTimeout(previewTimer));

const submit = () => {
    if (setupMode.value === 'quick') {
        // The server re-derives from the preset, so a slow preview cannot make
        // the stored grid disagree with what the user selected.
        form.transform((data) => ({ ...data, preset_key: presetKey.value })).post(route('bots.store'));

        return;
    }

    form.post(route('bots.store'));
};
</script>

<template>
    <Head title="Create bot" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-slate-100">Create bot</h2>
                <Link :href="route('bots.index')" class="text-sm text-slate-300 underline">Back to bots</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <div v-if="!sourceMode" class="flex items-center gap-2">
                    <button
                        type="button"
                        class="rounded-md px-4 py-2 text-sm font-medium"
                        :class="setupMode === 'quick' ? 'bg-emerald-600 text-white' : 'bg-slate-800 text-slate-300'"
                        @click="setupMode = 'quick'"
                    >
                        Quick setup
                    </button>
                    <button
                        type="button"
                        class="rounded-md px-4 py-2 text-sm font-medium"
                        :class="setupMode === 'advanced' ? 'bg-emerald-600 text-white' : 'bg-slate-800 text-slate-300'"
                        @click="setupMode = 'advanced'"
                    >
                        Advanced
                    </button>
                    <span class="ml-2 text-xs text-slate-400">
                        Quick setup picks a preset and derives the order size for you.
                    </span>
                </div>

                <div
                    v-if="sourceMode"
                    class="rounded-md border border-emerald-700 bg-emerald-950 p-4 text-sm text-emerald-100"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded bg-emerald-700 px-2 py-1 text-xs font-semibold uppercase tracking-wide text-white">
                            Paper only
                        </span>
                        <span class="font-semibold">Creating from {{ sourceRun.name }}</span>
                    </div>
                    <p class="mt-2 text-emerald-200">
                        {{ sourceRun.symbol }} · {{ sourceRun.strategy_key }} · {{ sourceRun.timeframe }}
                        <span v-if="sourceRun.start_at">
                            · {{ sourceRun.start_at.slice(0, 10) }} to {{ sourceRun.end_at?.slice(0, 10) }}
                        </span>
                    </p>
                    <p class="mt-2 text-emerald-200">
                        Settings are copied from the backtest. This bot is created idle with your current risk limits;
                        it will not start automatically. Live trading is not available from a backtest.
                    </p>
                    <dl
                        v-if="form.stop_loss_percent || form.take_profit_percent || form.range_low || form.range_high"
                        class="mt-3 grid grid-cols-2 gap-2 text-xs text-emerald-200 sm:grid-cols-4"
                    >
                        <div v-if="form.stop_loss_percent">
                            <dt class="uppercase tracking-wide text-emerald-300">Stop loss</dt>
                            <dd class="font-mono">{{ form.stop_loss_percent }}%</dd>
                        </div>
                        <div v-if="form.take_profit_percent">
                            <dt class="uppercase tracking-wide text-emerald-300">Take profit</dt>
                            <dd class="font-mono">{{ form.take_profit_percent }}%</dd>
                        </div>
                        <div v-if="form.range_low">
                            <dt class="uppercase tracking-wide text-emerald-300">Range low</dt>
                            <dd class="font-mono">{{ form.range_low }}</dd>
                        </div>
                        <div v-if="form.range_high">
                            <dt class="uppercase tracking-wide text-emerald-300">Range high</dt>
                            <dd class="font-mono">{{ form.range_high }}</dd>
                        </div>
                    </dl>
                </div>

                <form class="space-y-6 bg-slate-800 p-6 shadow sm:rounded-lg" @submit.prevent="submit">
                    <input type="hidden" name="source_backtest_run_id" :value="form.source_backtest_run_id ?? ''" />
                    <div class="grid gap-6 md:grid-cols-2">
                        <div>
                            <InputLabel for="name" value="Name" />
                            <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>

                        <div>
                            <InputLabel for="symbol" value="Symbol" />
                            <TextInput id="symbol" v-model="form.symbol" class="mt-1 block w-full" placeholder="BTC/USDT" required :readonly="sourceMode" />
                            <InputError class="mt-2" :message="form.errors.symbol" />
                        </div>

                        <div>
                            <InputLabel for="exchange" value="Exchange" />
                            <div
                                v-if="sourceMode"
                                id="exchange"
                                class="mt-1 rounded-md border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-200"
                                aria-readonly="true"
                            >
                                {{ form.exchange }}
                            </div>
                            <select v-else id="exchange" v-model="form.exchange" class="mt-1 block w-full rounded-md border-slate-500">
                                <option v-for="exchange in exchanges" :key="exchange" :value="exchange">{{ exchange }}</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.exchange" />
                        </div>

                        <div v-if="sourceMode">
                            <InputLabel value="Mode" />
                            <p class="mt-1 rounded-md border border-emerald-700 bg-emerald-950 px-3 py-2 text-sm text-emerald-200">
                                Paper only
                            </p>
                        </div>

                        <div v-if="!sourceMode">
                            <InputLabel for="mode" value="Mode" />
                            <select id="mode" v-model="form.mode" class="mt-1 block w-full rounded-md border-slate-500">
                                <option value="paper">Paper (default)</option>
                                <option value="live">Live</option>
                            </select>
                            <p class="mt-2 text-xs text-slate-400">
                                Live can send real orders only when the engine live gate is enabled. Use testnet first
                                (<code>TRADING_EXCHANGE_SANDBOX=true</code> or a testnet credential); testnet and
                                real-money live are different. Paper bots never promote to live automatically.
                            </p>
                            <InputError class="mt-2" :message="form.errors.mode" />
                        </div>

                        <div v-if="form.mode === 'live'" class="md:col-span-2">
                            <InputLabel for="live_confirmation" :value="`Type &quot;${expectedConfirmation}&quot; to confirm live trading`" />
                            <TextInput id="live_confirmation" v-model="form.live_confirmation" class="mt-1 block w-full" :placeholder="expectedConfirmation" />
                            <InputError class="mt-2" :message="form.errors.live_confirmation" />
                        </div>
                    </div>

                    <div
                        v-if="Object.keys(form.errors).length"
                        class="rounded-md border border-rose-700 bg-rose-950 p-4 text-sm text-rose-200"
                    >
                        <p class="font-semibold">This setup was not saved:</p>
                        <ul class="mt-2 list-disc space-y-1 pl-5">
                            <li v-for="(message, field) in form.errors" :key="field">
                                {{ field.replaceAll('_', ' ') }}: {{ message }}
                            </li>
                        </ul>
                    </div>

                    <template v-if="setupMode === 'quick'">
                        <div class="space-y-4 border-t border-slate-700 pt-6">
                            <div>
                                <InputLabel for="preset_key" value="Setup preset" />
                                <select id="preset_key" v-model="presetKey" class="mt-1 block w-full rounded-md border-slate-500">
                                    <option v-for="preset in presetChoices" :key="preset.key" :value="preset.key">
                                        {{ preset.label }}
                                    </option>
                                </select>
                                <p v-if="selectedPreset" class="mt-2 text-sm text-slate-300">
                                    {{ selectedPreset.description }}
                                </p>
                                <InputError class="mt-2" :message="form.errors.strategy_key" />
                            </div>

                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel for="capital_allocation" value="Capital allocation (quote)" />
                                    <TextInput id="capital_allocation" v-model="form.capital_allocation" class="mt-1 block w-full" required :readonly="sourceMode" />
                                    <p class="mt-1 text-xs text-slate-400">
                                        Seeded into the bot's quote balance and spread across the ladder.
                                    </p>
                                    <InputError class="mt-2" :message="form.errors.capital_allocation" />
                                </div>

                                <div>
                                    <InputLabel for="center_price" value="Center price" />
                                    <div class="mt-1 flex gap-2">
                                        <TextInput id="center_price" v-model="form.center_price" class="block w-full" required :readonly="sourceMode" />
                                        <button
                                            v-if="!sourceMode"
                                            type="button"
                                            class="whitespace-nowrap rounded-md bg-slate-700 px-3 py-2 text-sm text-slate-100"
                                            :disabled="marketLoading"
                                            @click="useMarketPrice"
                                        >
                                            {{ marketLoading ? 'Checking...' : 'Use current price' }}
                                        </button>
                                    </div>
                                    <p v-if="unavailableNotice && !sourceMode" class="mt-2 text-sm text-amber-300">{{ unavailableNotice }}</p>
                                    <InputError class="mt-2" :message="form.errors.center_price" />
                                </div>
                            </div>
                        </div>
                    </template>

                    <template v-else>
                        <div class="space-y-4 border-t border-slate-700 pt-6">
                            <div>
                                <InputLabel for="strategy_key" value="Strategy" />
                                <div
                                    v-if="sourceMode"
                                    id="strategy_key"
                                    class="mt-1 rounded-md border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-200"
                                    aria-readonly="true"
                                >
                                    {{ selectedStrategy?.label ?? form.strategy_key }}
                                </div>
                                <select v-else id="strategy_key" v-model="form.strategy_key" class="mt-1 block w-full rounded-md border-slate-500">
                                    <option v-for="strategy in strategyChoices" :key="strategy.key" :value="strategy.key">
                                        {{ strategy.label }}
                                    </option>
                                </select>
                                <p v-if="selectedStrategy" class="mt-2 text-sm text-slate-300">
                                    {{ selectedStrategy.description }}
                                </p>
                                <InputError class="mt-2" :message="form.errors.strategy_key" />
                            </div>

                            <div class="grid gap-6 md:grid-cols-2">
                                <div>
                                    <InputLabel for="capital_allocation" value="Capital allocation (quote)" />
                                    <TextInput id="capital_allocation" v-model="form.capital_allocation" class="mt-1 block w-full" required :readonly="sourceMode" />
                                    <InputError class="mt-2" :message="form.errors.capital_allocation" />
                                </div>

                                <div>
                                    <InputLabel for="fee_rate" value="Fee rate" />
                                    <TextInput id="fee_rate" v-model="form.fee_rate" class="mt-1 block w-full" :readonly="sourceMode" />
                                    <InputError class="mt-2" :message="form.errors.fee_rate" />
                                </div>

                                <div>
                                    <InputLabel for="center_price" value="Center price" />
                                    <div class="mt-1 flex gap-2">
                                        <TextInput id="center_price" v-model="form.center_price" class="block w-full" required :readonly="sourceMode" />
                                        <button
                                            v-if="!sourceMode"
                                            type="button"
                                            class="whitespace-nowrap rounded-md bg-slate-700 px-3 py-2 text-sm text-slate-100"
                                            :disabled="marketLoading"
                                            @click="useMarketPrice"
                                        >
                                            {{ marketLoading ? 'Checking...' : 'Use current price' }}
                                        </button>
                                    </div>
                                    <p v-if="unavailableNotice && !sourceMode" class="mt-2 text-sm text-amber-300">{{ unavailableNotice }}</p>
                                    <InputError class="mt-2" :message="form.errors.center_price" />
                                </div>

                                <div>
                                    <InputLabel for="level_count" value="Levels per side" />
                                    <TextInput id="level_count" v-model="form.level_count" type="number" min="1" max="50" class="mt-1 block w-full" required :readonly="sourceMode" />
                                    <p class="mt-1 text-xs text-slate-400">Each side places one order per level.</p>
                                    <InputError class="mt-2" :message="form.errors.level_count" />
                                </div>

                                <template v-if="!hideSharedSpacing">
                                    <div>
                                        <InputLabel for="spacing_type" value="Spacing type" />
                                        <div
                                            v-if="sourceMode"
                                            id="spacing_type"
                                            class="mt-1 rounded-md border border-slate-700 bg-slate-900 px-3 py-2 text-sm capitalize text-slate-200"
                                            aria-readonly="true"
                                        >
                                            {{ form.spacing_type }}
                                        </div>
                                        <select v-else id="spacing_type" v-model="form.spacing_type" class="mt-1 block w-full rounded-md border-slate-500">
                                            <option value="arithmetic">Arithmetic</option>
                                            <option value="geometric">Geometric</option>
                                        </select>
                                        <p class="mt-1 text-xs text-slate-400">
                                            Arithmetic is a fixed price gap. Geometric is a fixed percentage gap below 1.
                                        </p>
                                        <InputError class="mt-2" :message="form.errors.spacing_type" />
                                    </div>

                                    <div>
                                        <InputLabel for="spacing_value" value="Spacing value" />
                                        <TextInput id="spacing_value" v-model="form.spacing_value" class="mt-1 block w-full" required :readonly="sourceMode" />
                                        <InputError class="mt-2" :message="form.errors.spacing_value" />
                                    </div>
                                </template>

                                <div>
                                    <InputLabel for="order_quantity" value="Order quantity (base)" />
                                    <TextInput id="order_quantity" v-model="form.order_quantity" class="mt-1 block w-full" required :readonly="sourceMode" />
                                    <p class="mt-1 text-xs text-slate-400">
                                        Base-asset amount for each level. The preview shows the resulting notional.
                                    </p>
                                    <InputError class="mt-2" :message="form.errors.order_quantity" />
                                </div>

                                <div v-if="isTrailing">
                                    <InputLabel for="trailing_distance" value="Trailing distance" />
                                    <TextInput id="trailing_distance" v-model="form.trailing_distance" class="mt-1 block w-full" :readonly="sourceMode" />
                                    <InputError class="mt-2" :message="form.errors.trailing_distance" />
                                </div>

                                <template v-if="isMartingale">
                                    <div>
                                        <InputLabel for="size_multiplier" value="Size multiplier" />
                                        <TextInput id="size_multiplier" v-model="form.size_multiplier" class="mt-1 block w-full" :readonly="sourceMode" />
                                        <InputError class="mt-2" :message="form.errors.size_multiplier" />
                                    </div>
                                    <div>
                                        <InputLabel for="max_order_quantity" value="Max order quantity" />
                                        <TextInput id="max_order_quantity" v-model="form.max_order_quantity" class="mt-1 block w-full" :readonly="sourceMode" />
                                        <InputError class="mt-2" :message="form.errors.max_order_quantity" />
                                    </div>
                                    <div>
                                        <InputLabel for="max_increases" value="Max increases" />
                                        <TextInput id="max_increases" v-model="form.max_increases" type="number" min="0" class="mt-1 block w-full" :readonly="sourceMode" />
                                        <InputError class="mt-2" :message="form.errors.max_increases" />
                                    </div>
                                </template>

                                <template v-if="isDynamic">
                                    <div>
                                        <InputLabel for="min_spacing" value="Minimum spacing" />
                                        <TextInput id="min_spacing" v-model="form.min_spacing" class="mt-1 block w-full" :readonly="sourceMode" />
                                        <InputError class="mt-2" :message="form.errors.min_spacing" />
                                    </div>
                                    <div>
                                        <InputLabel for="max_spacing" value="Maximum spacing" />
                                        <TextInput id="max_spacing" v-model="form.max_spacing" class="mt-1 block w-full" :readonly="sourceMode" />
                                        <InputError class="mt-2" :message="form.errors.max_spacing" />
                                    </div>
                                    <div>
                                        <InputLabel for="initial_spacing" value="Initial spacing (optional)" />
                                        <TextInput id="initial_spacing" v-model="form.initial_spacing" class="mt-1 block w-full" :readonly="sourceMode" />
                                        <InputError class="mt-2" :message="form.errors.initial_spacing" />
                                    </div>
                                    <div>
                                        <InputLabel for="volatility_multiplier" value="Volatility multiplier" />
                                        <TextInput id="volatility_multiplier" v-model="form.volatility_multiplier" class="mt-1 block w-full" :readonly="sourceMode" />
                                        <InputError class="mt-2" :message="form.errors.volatility_multiplier" />
                                    </div>
                                    <div>
                                        <InputLabel for="recalculation_interval_seconds" value="Recalculation interval (seconds)" />
                                        <TextInput id="recalculation_interval_seconds" v-model="form.recalculation_interval_seconds" type="number" min="1" class="mt-1 block w-full" :readonly="sourceMode" />
                                        <InputError class="mt-2" :message="form.errors.recalculation_interval_seconds" />
                                    </div>
                                    <div>
                                        <InputLabel for="spacing_hysteresis_percent" value="Spacing hysteresis (%)" />
                                        <TextInput id="spacing_hysteresis_percent" v-model="form.spacing_hysteresis_percent" type="number" min="0" max="90" step="0.1" class="mt-1 block w-full" />
                                        <InputError class="mt-2" :message="form.errors.spacing_hysteresis_percent" />
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>

                    <div v-if="previewError" class="rounded border border-rose-800 bg-rose-950 p-3 text-sm text-rose-200">
                        {{ previewError }}
                    </div>

                    <PlanPreview
                        :plan="preview?.plan"
                        :limits="preview?.limits"
                        :market="preview?.market"
                        :loading="previewLoading"
                    />

                    <p class="text-xs text-slate-400">
                        Not sure about a value? See
                        <a
                            class="underline"
                            href="https://github.com/johnson407/trading-bot/blob/main/docs/bot-parameters.md"
                            target="_blank"
                            rel="noreferrer"
                        >the bot parameter guide</a>.
                    </p>

                    <div class="flex items-center justify-end gap-4">
                        <Link :href="route('bots.index')" class="text-sm text-slate-300 underline">Cancel</Link>
                        <PrimaryButton :disabled="form.processing">Create bot</PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
