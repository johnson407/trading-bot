<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatMoney, submittedMoney } from '@/Support/money.js';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    bot: { type: Object, required: true },
    configuration: { type: Object, default: () => ({}) },
    editable: { type: Boolean, default: false },
});

const rawCapitalAllocation = props.bot.capital_allocation;
const displayCapitalAllocation = formatMoney(rawCapitalAllocation);
const form = useForm({
    name: props.bot.name,
    capital_allocation: displayCapitalAllocation,
    fee_rate: props.bot.fee_rate ?? '0',
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
    volatility_multiplier: props.configuration.volatility_multiplier ?? '1',
    recalculation_interval_seconds: props.configuration.recalculation_interval_seconds ?? 60,
});

const isTrailing = computed(() => props.bot.strategy_key === 'trailing-grid');
const isMartingale = computed(() => props.bot.strategy_key === 'martingale-grid');
const isDynamic = computed(() => props.bot.strategy_key === 'dynamic-grid');
const isEditable = computed(() => props.editable);

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
                    </p>
                    <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                        <div><dt class="text-slate-300">Symbol</dt><dd class="font-mono">{{ bot.symbol }}</dd></div>
                        <div><dt class="text-slate-300">Exchange</dt><dd>{{ bot.exchange }}</dd></div>
                        <div><dt class="text-slate-300">Mode</dt><dd class="capitalize">{{ bot.mode }}</dd></div>
                        <div><dt class="text-slate-300">Strategy</dt><dd>{{ bot.strategy_key }}</dd></div>
                    </dl>
                </div>

                <div v-if="!isEditable" class="rounded-lg bg-amber-950 p-6 text-sm text-amber-200">
                    This bot is {{ bot.status }}. Only idle or stopped bots can be edited. Stop the bot before changing its settings.
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
                            <InputLabel for="center_price" value="Center price" />
                            <TextInput id="center_price" v-model="form.center_price" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.center_price" />
                        </div>

                        <div>
                            <InputLabel for="level_count" value="Levels per side" />
                            <TextInput id="level_count" v-model="form.level_count" type="number" min="1" max="50" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.level_count" />
                        </div>

                        <div>
                            <InputLabel for="spacing_type" value="Spacing type" />
                            <select id="spacing_type" v-model="form.spacing_type" class="mt-1 block w-full rounded-md border-slate-500">
                                <option value="arithmetic">Arithmetic</option>
                                <option value="geometric">Geometric</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.spacing_type" />
                        </div>

                        <div>
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
                                <InputLabel for="volatility_multiplier" value="Volatility multiplier" />
                                <TextInput id="volatility_multiplier" v-model="form.volatility_multiplier" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.volatility_multiplier" />
                            </div>
                            <div>
                                <InputLabel for="recalculation_interval_seconds" value="Recalculation interval (seconds)" />
                                <TextInput id="recalculation_interval_seconds" v-model="form.recalculation_interval_seconds" type="number" min="1" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.recalculation_interval_seconds" />
                            </div>
                        </template>
                    </div>

                    <div class="flex items-center gap-4">
                        <PrimaryButton :disabled="form.processing">Save changes</PrimaryButton>
                        <span v-if="form.recentlySuccessful" class="text-sm text-emerald-300">Saved.</span>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
