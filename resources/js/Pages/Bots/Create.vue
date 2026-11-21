<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    strategies: { type: Array, default: () => [] },
    exchanges: { type: Array, default: () => [] },
});

const form = useForm({
    name: '',
    exchange: props.exchanges[0] ?? 'binance',
    symbol: 'BTC/USDT',
    strategy_key: props.strategies[0]?.key ?? 'simple-grid',
    mode: 'paper',
    capital_allocation: '1000',
    fee_rate: '0',
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
    volatility_multiplier: '1',
    recalculation_interval_seconds: 60,
    live_confirmation: '',
});

const isTrailing = computed(() => form.strategy_key === 'trailing-grid');
const isMartingale = computed(() => form.strategy_key === 'martingale-grid');
const isDynamic = computed(() => form.strategy_key === 'dynamic-grid');
const expectedConfirmation = computed(() => `${form.name} on ${form.exchange}`);

const submit = () => form.post(route('bots.store'));
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
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <form class="space-y-6 bg-slate-800 p-6 shadow sm:rounded-lg" @submit.prevent="submit">
                    <div class="grid gap-6 md:grid-cols-2">
                        <div>
                            <InputLabel for="name" value="Name" />
                            <TextInput id="name" v-model="form.name" class="mt-1 block w-full" required />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>

                        <div>
                            <InputLabel for="symbol" value="Symbol" />
                            <TextInput id="symbol" v-model="form.symbol" class="mt-1 block w-full" placeholder="BTC/USDT" required />
                            <InputError class="mt-2" :message="form.errors.symbol" />
                        </div>

                        <div>
                            <InputLabel for="exchange" value="Exchange" />
                            <select id="exchange" v-model="form.exchange" class="mt-1 block w-full rounded-md border-slate-500">
                                <option v-for="exchange in exchanges" :key="exchange" :value="exchange">{{ exchange }}</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.exchange" />
                        </div>

                        <div>
                            <InputLabel for="strategy_key" value="Strategy" />
                            <select id="strategy_key" v-model="form.strategy_key" class="mt-1 block w-full rounded-md border-slate-500">
                                <option v-for="strategy in strategies" :key="strategy.key" :value="strategy.key">{{ strategy.key }}</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.strategy_key" />
                        </div>

                        <div>
                            <InputLabel for="mode" value="Mode" />
                            <select id="mode" v-model="form.mode" class="mt-1 block w-full rounded-md border-slate-500">
                                <option value="paper">Paper (default)</option>
                                <option value="live">Live</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.mode" />
                        </div>

                        <div v-if="form.mode === 'live'" class="md:col-span-2">
                            <InputLabel for="live_confirmation" :value="`Type &quot;${expectedConfirmation}&quot; to confirm live trading`" />
                            <TextInput id="live_confirmation" v-model="form.live_confirmation" class="mt-1 block w-full" :placeholder="expectedConfirmation" />
                            <InputError class="mt-2" :message="form.errors.live_confirmation" />
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
                                <InputLabel for="max_order_quantity" value="Max order quantity" />
                                <TextInput id="max_order_quantity" v-model="form.max_order_quantity" class="mt-1 block w-full" />
                                <InputError class="mt-2" :message="form.errors.max_order_quantity" />
                            </div>
                            <div>
                                <InputLabel for="max_increases" value="Max increases" />
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

                    <div class="flex items-center justify-end gap-4">
                        <Link :href="route('bots.index')" class="text-sm text-slate-300 underline">Cancel</Link>
                        <PrimaryButton :disabled="form.processing">Create bot</PrimaryButton>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
