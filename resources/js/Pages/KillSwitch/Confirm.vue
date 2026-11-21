<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    botCount: { type: Number, default: 0 },
    activeBotCount: { type: Number, default: 0 },
    openOrderCount: { type: Number, default: 0 },
});

const form = useForm({ confirmation: '' });

const submit = () => form.post(route('kill-switch.store'), { preserveScroll: true });
</script>

<template>
    <Head title="Kill switch" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Kill switch</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-2xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="rounded-lg border border-rose-200 bg-rose-50 p-5">
                    <h3 class="text-lg font-semibold text-rose-800">Stop everything immediately</h3>
                    <p class="mt-2 text-sm text-rose-700">
                        This pauses all of your bots and asks the exchange to cancel every open order.
                        It does not close positions. Type <strong>KILL SWITCH</strong> to confirm.
                    </p>
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div class="rounded-lg bg-white p-4 shadow">
                        <p class="text-xs uppercase text-gray-500">Bots</p>
                        <p class="mt-1 text-2xl font-semibold">{{ botCount }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow">
                        <p class="text-xs uppercase text-gray-500">Active</p>
                        <p class="mt-1 text-2xl font-semibold">{{ activeBotCount }}</p>
                    </div>
                    <div class="rounded-lg bg-white p-4 shadow">
                        <p class="text-xs uppercase text-gray-500">Open orders</p>
                        <p class="mt-1 text-2xl font-semibold">{{ openOrderCount }}</p>
                    </div>
                </div>

                <form class="space-y-4 rounded-lg bg-white p-6 shadow" @submit.prevent="submit">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="confirmation">Confirmation</label>
                        <input id="confirmation" v-model="form.confirmation" type="text" autocomplete="off"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-rose-500 focus:ring-rose-500"
                            placeholder="KILL SWITCH" />
                        <p v-if="form.errors.confirmation" class="mt-1 text-sm text-rose-600">{{ form.errors.confirmation }}</p>
                    </div>

                    <div class="flex items-center gap-3">
                        <button type="submit" :disabled="form.processing"
                            class="rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-50">
                            Activate kill switch
                        </button>
                        <Link :href="route('dashboard')" class="text-sm text-gray-600 hover:text-gray-800">Cancel</Link>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
