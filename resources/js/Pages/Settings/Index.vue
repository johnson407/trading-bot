<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Settings" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-slate-100">Settings</h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <p class="mb-6 text-sm text-slate-300">
                    Manage the account configuration used for live trading and risk checks.
                </p>

                <div class="grid gap-6 md:grid-cols-2">
                    <Link :href="route('exchange-credentials.index')"
                        class="group rounded-lg border border-slate-700 bg-slate-800 p-6 shadow-sm transition hover:border-blue-500 hover:bg-slate-700">
                        <h3 class="text-lg font-semibold text-slate-100">Exchange credentials</h3>
                        <p class="mt-2 text-sm text-slate-300">
                            Add, replace, or remove the API keys used for live trading.
                        </p>
                        <span class="mt-4 inline-block text-sm font-semibold text-blue-300 group-hover:text-blue-200">
                            Manage credentials &rarr;
                        </span>
                    </Link>

                    <Link :href="route('risk-limits.edit')"
                        class="group rounded-lg border border-slate-700 bg-slate-800 p-6 shadow-sm transition hover:border-blue-500 hover:bg-slate-700">
                        <h3 class="text-lg font-semibold text-slate-100">Risk limits</h3>
                        <p class="mt-2 text-sm text-slate-300">
                            Set account-wide position, open-order, and daily-loss limits.
                        </p>
                        <span class="mt-4 inline-block text-sm font-semibold text-blue-300 group-hover:text-blue-200">
                            Edit limits &rarr;
                        </span>
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
