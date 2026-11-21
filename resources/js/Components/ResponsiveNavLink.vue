<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
    danger: {
        type: Boolean,
        default: false,
    },
});

const classes = computed(() =>
    props.danger
        ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-rose-400 text-start text-base font-semibold text-rose-200 bg-rose-950 hover:bg-rose-900 focus:outline-none focus:ring-2 focus:ring-rose-300 transition duration-150 ease-in-out'
        : props.active
        ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-blue-400 text-start text-base font-medium text-blue-200 bg-blue-950 focus:outline-none focus:text-blue-200 focus:bg-blue-900 focus:border-blue-400 transition duration-150 ease-in-out'
        : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-slate-300 hover:text-slate-100 hover:bg-slate-900 hover:border-slate-500 focus:outline-none focus:text-slate-100 focus:bg-slate-900 focus:border-slate-500 transition duration-150 ease-in-out',
);
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
