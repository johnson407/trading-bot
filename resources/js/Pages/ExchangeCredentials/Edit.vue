<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    credential: { type: Object, required: true },
    exchanges: { type: Array, default: () => ['binance'] },
});

const form = useForm({
    exchange: props.credential.exchange,
    api_key: '',
    api_secret: '',
    passphrase: '',
    testnet: props.credential.testnet,
});

const submit = () => form.put(route('exchange-credentials.update', props.credential.id));
</script>

<template>
    <Head title="Replace exchange credential" />
    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-slate-100">Replace {{ credential.exchange }} credential</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-2xl px-4 sm:px-6 lg:px-8">
                <form class="space-y-4 rounded-lg bg-slate-800 p-6 shadow" @submit.prevent="submit">
                    <p class="text-sm text-slate-300">
                        Current key: <span class="font-mono">{{ credential.masked_key }}</span>. Enter a new key and secret to replace it.
                    </p>
                    <div>
                        <label class="block text-sm font-medium text-slate-200">Exchange</label>
                        <select v-model="form.exchange" class="mt-1 block w-full rounded-md border-slate-500">
                            <option v-for="exchange in exchanges" :key="exchange" :value="exchange">{{ exchange }}</option>
                        </select>
                        <p v-if="form.errors.exchange" class="mt-1 text-sm text-rose-300">{{ form.errors.exchange }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-200">New API key</label>
                        <input v-model="form.api_key" type="password" autocomplete="off" class="mt-1 block w-full rounded-md border-slate-500" />
                        <p v-if="form.errors.api_key" class="mt-1 text-sm text-rose-300">{{ form.errors.api_key }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-200">New API secret</label>
                        <input v-model="form.api_secret" type="password" autocomplete="off" class="mt-1 block w-full rounded-md border-slate-500" />
                        <p v-if="form.errors.api_secret" class="mt-1 text-sm text-rose-300">{{ form.errors.api_secret }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-200">Passphrase (optional)</label>
                        <input v-model="form.passphrase" type="password" autocomplete="off" class="mt-1 block w-full rounded-md border-slate-500" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-200">
                        <input v-model="form.testnet" type="checkbox" class="rounded border-slate-500" />
                        Use testnet
                    </label>
                    <div class="flex items-center gap-3">
                        <button type="submit" :disabled="form.processing"
                            class="rounded-md bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50">
                            Replace credential
                        </button>
                        <Link :href="route('exchange-credentials.index')" class="text-sm text-slate-300">Cancel</Link>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
