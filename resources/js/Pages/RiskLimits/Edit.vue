<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    limits: { type: Object, required: true },
});

const form = useForm({
    max_position_notional: props.limits.max_position_notional,
    max_open_orders: props.limits.max_open_orders,
    daily_loss_limit: props.limits.daily_loss_limit,
});

const submit = () => form.patch(route('risk-limits.update'));
</script>

<template>
    <Head title="Risk limits" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Risk limits
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <form class="space-y-6 p-6" @submit.prevent="submit">
                        <p class="text-sm text-gray-600">
                            These account-wide limits apply to every bot without its own override.
                            Risk checks run before every order, and a breached daily loss limit
                            pauses the affected bots and cancels their open orders.
                        </p>

                        <div>
                            <InputLabel for="max_position_notional" value="Maximum position notional (quote)" />
                            <TextInput
                                id="max_position_notional"
                                v-model="form.max_position_notional"
                                class="mt-1 block w-full"
                                type="text"
                                inputmode="decimal"
                                required
                            />
                            <InputError class="mt-2" :message="form.errors.max_position_notional" />
                        </div>

                        <div>
                            <InputLabel for="max_open_orders" value="Maximum open orders per bot" />
                            <TextInput
                                id="max_open_orders"
                                v-model="form.max_open_orders"
                                class="mt-1 block w-full"
                                type="number"
                                min="1"
                                required
                            />
                            <InputError class="mt-2" :message="form.errors.max_open_orders" />
                        </div>

                        <div>
                            <InputLabel for="daily_loss_limit" value="Daily loss limit (quote)" />
                            <TextInput
                                id="daily_loss_limit"
                                v-model="form.daily_loss_limit"
                                class="mt-1 block w-full"
                                type="text"
                                inputmode="decimal"
                                required
                            />
                            <InputError class="mt-2" :message="form.errors.daily_loss_limit" />
                        </div>

                        <div class="flex items-center gap-4">
                            <PrimaryButton :disabled="form.processing">Save limits</PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
