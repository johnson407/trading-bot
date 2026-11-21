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
        ? 'inline-flex items-center px-3 py-2 rounded-md border border-rose-400 text-sm font-semibold text-rose-200 bg-rose-950 hover:bg-rose-900 focus:outline-none focus:ring-2 focus:ring-rose-300 transition duration-150 ease-in-out'
        : props.active
        ? 'inline-flex items-center px-1 pt-1 border-b-2 border-blue-400 text-sm font-medium leading-5 text-slate-100 focus:outline-none focus:border-blue-400 transition duration-150 ease-in-out'
        : 'inline-flex items-center px-1 pt-1 border-b-2 border-transparent text-sm font-medium leading-5 text-slate-300 hover:text-slate-200 hover:border-slate-500 focus:outline-none focus:text-slate-200 focus:border-slate-500 transition duration-150 ease-in-out',
);
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
