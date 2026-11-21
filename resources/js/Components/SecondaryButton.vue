<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex items-center rounded-md border border-slate-500 bg-slate-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-slate-200 shadow-sm transition duration-150 ease-in-out hover:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-400 focus:ring-offset-2 focus:ring-offset-slate-950 disabled:cursor-not-allowed disabled:opacity-50"
    >
        <slot />
    </button>
</template>
