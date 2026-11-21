<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        class="rounded-md bg-slate-900 text-slate-100 placeholder:text-slate-400 border-slate-500 shadow-sm focus:border-blue-400 focus:ring-blue-400"
        v-model="model"
        ref="input"
    />
</template>
