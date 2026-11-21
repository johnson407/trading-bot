<script setup>
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const visible = ref(true);

watch(
    () => page.props.flash?.status,
    () => {
        visible.value = true;
    },
);
</script>

<template>
    <div
        v-if="visible && $page.props.flash?.status"
        class="border-b border-green-200 bg-green-50"
    >
        <div
            class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3 sm:px-6 lg:px-8"
        >
            <p class="text-sm font-medium text-green-800">
                {{ $page.props.flash.status }}
            </p>

            <button
                type="button"
                class="text-sm font-medium text-green-700 hover:text-green-900"
                @click="visible = false"
            >
                Dismiss
            </button>
        </div>
    </div>
</template>
