<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PlanPreview from '@/Components/PlanPreview.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatMoney, submittedMoney } from '@/Support/money.js';
import { strategyMeta } from '@/Support/strategies.js';
import { Head, Link, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    bot: { type: Object, required: true },
    configuration: { type: Object, default: () => ({}) },
    editable: { type: Boolean, default: false },
    runningPaper: { type: Boolean, default: false },
    requiresConfirmation: { type: Boolean, default: false },
    preview: { type: Object, default: null },
    diff: { type: Array, default: () => [] },
});

const rawCapitalAllocation = props.bot.capital_allocation;
const displayCapitalAllocation = formatMoney(rawCapitalAllocation);
const form = useForm({
    name: props.bot.name,
    capital_allocation: displayCapitalAllocation,
    fee_rate: props.bot.fee_rate ?? '0',
    tick_interval_seconds: props.bot.tick_interval_seconds ?? 10,
    center_price: props.configuration.center_price ?? '0',
    level_count: props.configuration.levels_per_side ?? 1,
    spacing_type: props.configuration.spacing_type ?? 'arithmetic',
    spacing_value: props.configuration.spacing_value ?? '0',
    order_quantity: props.configuration.order_quantity ?? '0',
    trailing_distance: props.configuration.trailing_distance ?? '200',
    size_multiplier: props.configuration.size_multiplier ?? '2',
    max_order_quantity: props.configuration.max_order_quantity ?? '0.01',
    max_increases: props.configuration.max_increases ?? 3,
    min_spacing: props.configuration.min_spacing ?? '50',
    max_spacing: props.configuration.max_spacing ?? '500',
    initial_spacing: props.configuration.initial_spacing ?? '',
    volatility_multiplier: props.configuration.volatility_multiplier ?? '1',
    recalculation_interval_seconds: props.configuration.recalculation_interval_seconds ?? 60,
    spacing_hysteresis_percent: props.configuration.spacing_hysteresis_percent ?? '20',
    confirm_running_edit: false,
});

const isTrailing = computed(() => props.bot.strategy_key === 'trailing-grid');
const isMartingale = computed(() => props.bot.strategy_key === 'martingale-grid');
const isDynamic = computed(() => props.bot.strategy_key === 'dynamic-grid');
const isEditable = computed(() => props.editable);
const selectedStrategy = computed(() => strategyMeta(props.bot.strategy_key));

const preview = ref(props.preview);
const previewLoading = ref(false);
const previewError = ref(props.preview?.error ?? '');
const diff = ref(props.diff ?? []);
let previewTimer = null;
let previewRequest = 0;

function previewPayload() {
    return {
        bot_id: props.bot.id,
        exchange: props.bot.exchange,
        symbol: props.bot.symbol,
        strategy_key: props.bot.strategy_key,
        name: form.name,
        fee_rate: form.fee_rate,
        tick_interval_seconds: form.tick_interval_seconds,
        capital_allocation: submittedMoney(form.capital_allocation, displayCapitalAllocation, rawCapitalAllocation),
        center_price: form.center_price,
        level_count: Number(form.level_count),
        spacing_type: form.spacing_type,
        spacing_value: form.spacing_value,
        order_quantity: form.order_quantity,
        trailing_distance: form.trailing_distance,
        size_multiplier: form.size_multiplier,
        max_order_quantity: form.max_order_quantity,
        max_increases: form.max_increases,
        min_spacing: form.min_spacing,
        max_spacing: form.max_spacing,
        initial_spacing: form.initial_spacing,
        volatility_multiplier: form.volatility_multiplier,
        recalculation_interval_seconds: form.recalculation_interval_seconds,
        spacing_hysteresis_percent: form.spacing_hysteresis_percent,
    };
}

async function fetchPreview() {
    if (!props.editable) {
        return;
    }

    const requestId = ++previewRequest;
    previewLoading.value = true;

    try {
        const { data } = await axios.post(route('bots.preview'), previewPayload());

        if (requestId === previewRequest) {
            preview.value = data;
            diff.value = Array.isArray(data.diff) ? data.diff : [];
            previewError.value = '';
        }
    } catch (error) {
        if (requestId === previewRequest) {
            const responseDiff = error?.response?.data?.diff;

            if (Array.isArray(responseDiff)) {
                diff.value = responseDiff;
            }

            previewError.value = error?.response?.data?.message ?? 'Preview is unavailable right now.';
        }
    } finally {
        if (requestId === previewRequest) {
            previewLoading.value = false;
        }
    }
}

function schedulePreview() {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(fetchPreview, 250);
}

watch(
    () => [
        form.name,
        form.capital_allocation,
        form.fee_rate,
        form.tick_interval_seconds,
        form.center_price,
        form.level_count,
        form.spacing_type,
        form.spacing_value,
        form.order_quantity,
        form.trailing_distance,
        form.size_multiplier,
        form.max_order_quantity,
        form.max_increases,
        form.min_spacing,
        form.max_spacing,
        form.initial_spacing,
        form.volatility_multiplier,
        form.recalculation_interval_seconds,
        form.spacing_hysteresis_percent,
    ],
    schedulePreview,
);

onBeforeUnmount(() => clearTimeout(previewTimer));

function diffValue(change, value) {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return change.type === 'money' ? formatMoney(String(value)) : String(value);
}

const submit = () => form
    .transform((data) => ({
        ...data,
        capital_allocation: submittedMoney(form.capital_allocation, displayCapitalAllocation, rawCapitalAllocation),
    }))
    .patch(route('bots.update', props.bot.id));
</script>

<template>
    <Head :title="`Edit ${bot.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-slate-100">Edit {{ bot.name }}</h2>
                <Link :href="route('bots.show', bot.id)" class="text-sm text-slate-300 underline">Back to bot</Link>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <div class="rounded-lg bg-slate-800 p-6 shadow">
                    <h3 class="font-semibold text-slate-100">Identity</h3>
                    <p class="mt-1 text-sm text-slate-300">
                        Symbol, exchange, mode and strategy are fixed for the lifetime of a bot. Create a new bot to change them.
                        Live mode sends real orders only when the engine live gate is enabled; use testnet before a
                        real-money account. Paper bots never promote to live automatically.
                    </p>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-slate-300">Symbol</dt><dd class="font-mono">{{ bot.symbol }}</dd></div>
                        <div><dt class="text-slate-300">Exchange</dt><dd>{{ bot.exchange }}</dd></div>
                        <div><dt class="text-slate-300">Mode</dt><dd class="capitalize">{{ bot.mode }}</dd></div>
                        <div><dt class="text-slate-300">Strategy</dt><dd>{{ selectedStrategy.label }}</dd></div>
                    </dl>
                </div>

                <div v-if="runningPaper" class="rounded-lg border border-amber-700 bg-amber-950 p-6 text-sm text-amber-200">
                    <p class="font-semibold">This bot is running.</p>
                    <p class="mt-1">Saving changes can cancel open orders and rebuild the ladder on the next tick. An interval-only change does not rebuild the ladder, but an overdue bot can still be paused by the stale-tick check.</p>
                </div>

                <div v-if="!isEditable" class="rounded-lg bg-amber-950 p-6 text-sm text-amber-200">
                    This bot is {{ bot.status }}. Live running bots and paused bots cannot be edited. Stop the bot before changing its settings.
                </div>

                <form v-else class="space-y-6 bg-slate-800 p-6 shadow sm:rounded-lg" @submit.prevent="submit">
                    <div class="grid gap-6 md:grid-cols-2">
                        <div>
                            <InputLabel for="name" value="Name" />
                            <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>

                        <div>
                            <InputLabel for="capital_allocation" value="Capital allocation (quote)" />
                            <TextInput id="capital_allocation" v-model="form.capital_allocation" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.capital_allocation" />
                        </div>

                        <div>
                            <InputLabel for="fee_rate" value="Fee rate" />
                            <TextInput id="fee_rate" v-model="form.fee_rate" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.fee_rate" />
                        </div>

                        <div>
                            <InputLabel for="tick_interval_seconds" value="Tick interval (seconds)" />
                            <TextInput id="tick_interval_seconds" v-model="form.tick_interval_seconds" type="number" min="1" max="3600" class="mt-1 block w-full" required />
                            <p class="mt-1 text-xs text-slate-400">How often the engine evaluates this bot. Applies on the next due-check.</p>
                            <InputError class="mt-2" :message="form.errors.tick_interval_seconds" />
                        </div>

                        <div>
                            <InputLabel for="center_price" value="Center price" />
                            <TextInput id="center_price" v-model="form.center_price" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.center_price" />
                        </div>

                        <div>
                            <InputLabel for="level_count" value="Levels per side" />
                            <TextInput id="level_count" v-model="form.level_count" type="number" min="1" max="50" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.level_count" />
                        </div>

                        <div v-if="!isDynamic">
                            <InputLabel for="spacing_type" value="Spacing type" />
                            <select id="spacing_type" v-model="form.spacing_type" class="mt-1 block w-full rounded-md border-slate-500">
                                <option value="arithmetic">Arithmetic</option>
                                <option value="geometric">Geometric</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.spacing_type" />
                        </div>

                        <div v-if="!isDynamic">
                            <InputLabel for="spacing_value" value="Spacing value" />
                            <TextInput id="spacing_value" v-model="form.spacing_value" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.spacing_value" />
                        </div>

                        <div>
                            <InputLabel for="order_quantity" value="Order quantity (base)" />
                            <TextInput id="order_quantity" v-model="form.order_quantity" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.order_quantity" />
                        </div>

                        <div v-if="isTrailing">
                            <InputLabel for="trailing_distance" value="Trailing distance" />
                            <TextInput id="trailing_distance" v-model="form.trailing_distance" class="mt-1 block w-full" />
                            <InputError class="mt-2" :message="form.errors.trailing_distance" />
                        </div>

                        <template v-if="isMartingale">
                            <div>
                                <InputLabel for="size_multiplier" value="Size multiplier" />
                                <TextInput id="size_multiplier" v-model="form.size_multiplier" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.size_multiplier" />
                            </div>
                            <div>
                                <InputLabel for="max_order_quantity" value="Maximum order quantity" />
                                <TextInput id="max_order_quantity" v-model="form.max_order_quantity" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.max_order_quantity" />
                            </div>
                            <div>
                                <InputLabel for="max_increases" value="Maximum increases" />
                                <TextInput id="max_increases" v-model="form.max_increases" type="number" min="0" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.max_increases" />
                            </div>
                        </template>

                        <template v-if="isDynamic">
                            <div>
                                <InputLabel for="min_spacing" value="Minimum spacing" />
                                <TextInput id="min_spacing" v-model="form.min_spacing" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.min_spacing" />
                            </div>
                            <div>
                                <InputLabel for="max_spacing" value="Maximum spacing" />
                                <TextInput id="max_spacing" v-model="form.max_spacing" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.max_spacing" />
                            </div>
                            <div>
                                <InputLabel for="initial_spacing" value="Initial spacing (optional)" />
                                <TextInput id="initial_spacing" v-model="form.initial_spacing" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.initial_spacing" />
                            </div>
                            <div>
                                <InputLabel for="volatility_multiplier" value="Volatility multiplier" />
                                <TextInput id="volatility_multiplier" v-model="form.volatility_multiplier" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.volatility_multiplier" />
                            </div>
                            <div>
                                <InputLabel for="recalculation_interval_seconds" value="Recalculation interval (seconds)" />
                                <TextInput id="recalculation_interval_seconds" v-model="form.recalculation_interval_seconds" type="number" min="1" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.recalculation_interval_seconds" />
                            </div>
                            <div>
                                <InputLabel for="spacing_hysteresis_percent" value="Spacing hysteresis (%)" />
                                <TextInput id="spacing_hysteresis_percent" v-model="form.spacing_hysteresis_percent" type="number" min="0" max="90" step="0.1" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.spacing_hysteresis_percent" />
                            </div>
                        </template>
                    </div>

                    <div v-if="diff.length" class="rounded-md border border-slate-700 bg-slate-900 p-4">
                        <h3 class="text-sm font-semibold text-slate-100">Changes to save</h3>
                        <dl class="mt-3 space-y-2 text-sm">
                            <div v-for="change in diff" :key="change.key" class="grid grid-cols-[minmax(0,1fr)_auto_auto] items-baseline gap-3">
                                <dt class="text-slate-300">{{ change.label }}</dt>
                                <dd class="font-mono text-rose-300 line-through">{{ diffValue(change, change.from) }}</dd>
                                <dd class="font-mono text-emerald-300">{{ diffValue(change, change.to) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <p v-if="previewError" class="rounded-md border border-rose-700 bg-rose-950 p-4 text-sm text-rose-200">
                        {{ previewError }}
                    </p>
                    <PlanPreview
                    :plan="preview?.plan"
                    :limits="preview?.limits"
                    :market="preview?.market"
                    :loading="previewLoading"
                />

                    <div v-if="requiresConfirmation" class="space-y-2">
                        <label class="flex items-start gap-3 text-sm text-slate-200">
                            <input
                                v-model="form.confirm_running_edit"
                                type="checkbox"
                                class="mt-1 rounded border-slate-500 bg-slate-900 text-emerald-600"
                            />
                            <span>I understand saving structural or capital changes will cancel open orders and rebuild the ladder.</span>
                        </label>
                        <InputError :message="form.errors.confirm_running_edit" />
                    </div>

                    <div class="flex items-center gap-4">
                        <PrimaryButton :disabled="form.processing || (requiresConfirmation && !form.confirm_running_edit)">Save changes</PrimaryButton>
                        <span v-if="form.recentlySuccessful" class="text-sm text-emerald-300">Saved.</span>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
