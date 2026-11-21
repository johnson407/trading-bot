<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps({
    credentials: { type: Array, default: () => [] },
});

const destroy = (credential) => {
    if (confirm(`Delete the ${credential.exchange} credential?`)) {
        router.delete(route('exchange-credentials.destroy', credential.id));
    }
};

const timestamp = (value) => (value ? new Date(value).toLocaleString() : '—');
</script>

<template>
    <Head title="Exchange credentials" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-slate-100">Exchange credentials</h2>
                <Link :href="route('exchange-credentials.create')"
                    class="rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800">
                    Add credential
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8">
                <div class="overflow-x-auto bg-slate-800 shadow sm:rounded-lg">
                    <table class="min-w-full divide-y divide-slate-700 text-sm">
                        <thead class="bg-slate-900 text-left text-xs uppercase text-slate-300">
                            <tr>
                                <th class="px-6 py-3">Exchange</th>
                                <th class="px-6 py-3">API key</th>
                                <th class="px-6 py-3">Testnet</th>
                                <th class="px-6 py-3">Updated</th>
                                <th class="px-6 py-3"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-700">
                            <tr v-for="credential in credentials" :key="credential.id">
                                <td class="px-6 py-4 font-medium capitalize">{{ credential.exchange }}</td>
                                <td class="px-6 py-4 font-mono text-slate-300">{{ credential.masked_key }}</td>
                                <td class="px-6 py-4">{{ credential.testnet ? 'Yes' : 'No' }}</td>
                                <td class="px-6 py-4 text-slate-300">{{ timestamp(credential.updated_at) }}</td>
                                <td class="px-6 py-4 text-right">
                                    <Link :href="route('exchange-credentials.edit', credential.id)"
                                        class="text-blue-300 hover:text-blue-200">Replace</Link>
                                    <button type="button" class="ml-4 text-rose-300 hover:text-rose-200" @click="destroy(credential)">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="credentials.length === 0">
                                <td colspan="5" class="px-6 py-8 text-center text-slate-300">No credentials stored.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs text-slate-300">Secrets are encrypted at rest and never displayed after saving.</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
