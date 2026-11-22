<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { formatMoney, submittedMoney } from '@/Support/money.js';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    limits: { type: Object, required: true },
});

const rawMaxPositionNotional = ref(props.limits.max_position_notional);
const rawDailyLossLimit = ref(props.limits.daily_loss_limit);

const form = useForm({
    max_position_notional: formatMoney(rawMaxPositionNotional.value),
    max_open_orders: props.limits.max_open_orders,
    daily_loss_limit: formatMoney(rawDailyLossLimit.value),
});

function syncLimits(limits) {
    rawMaxPositionNotional.value = limits.max_position_notional;
    rawDailyLossLimit.value = limits.daily_loss_limit;
    form.max_position_notional = formatMoney(limits.max_position_notional);
    form.daily_loss_limit = formatMoney(limits.daily_loss_limit);
    form.defaults();
}

watch(
    () => props.limits,
    (limits) => {
        if (form.processing || form.isDirty) return;

        syncLimits(limits);
    },
);

const submit = () => {
    const submitted = {
        max_position_notional: submittedMoney(
            form.max_position_notional,
            formatMoney(rawMaxPositionNotional.value),
            rawMaxPositionNotional.value,
        ),
        daily_loss_limit: submittedMoney(
            form.daily_loss_limit,
            formatMoney(rawDailyLossLimit.value),
            rawDailyLossLimit.value,
        ),
    };

    form.transform((data) => ({ ...data, ...submitted }))
        .patch(route('risk-limits.update'), {
            onSuccess: () => {
                rawMaxPositionNotional.value = submitted.max_position_notional;
                rawDailyLossLimit.value = submitted.daily_loss_limit;
                form.max_position_notional = formatMoney(submitted.max_position_notional);
                form.daily_loss_limit = formatMoney(submitted.daily_loss_limit);
                form.defaults();
            },
        });
};
</script>

<template>
    <Head title="Risk limits" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-slate-100">
                Risk limits
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-slate-800 shadow-sm sm:rounded-lg">
                    <form class="space-y-6 p-6" @submit.prevent="submit">
                        <p class="text-sm text-slate-300">
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
