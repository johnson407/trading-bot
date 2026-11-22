<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import { formatMoney } from '@/Support/money.js';
import { errorMessages, limitUsage, planLevels, planSummary, warningItems } from '@/Support/plan.js';

const props = defineProps({
    plan: { type: Object, default: null },
    limits: { type: Object, default: null },
    market: { type: Object, default: null },
    loading: { type: Boolean, default: false },
});

const levels = computed(() => planLevels(props.plan));
const summary = computed(() => planSummary(props.plan));
const usage = computed(() => limitUsage(props.plan, props.limits));
const errors = computed(() => errorMessages(props.plan?.errors));
const warnings = computed(() => warningItems(props.plan?.warnings));
const limitWarningCodes = ['plan.max_open_orders', 'plan.max_position_notional'];
</script>

<template>
    <section class="rounded-lg border border-slate-700 bg-slate-900 p-4">
        <div class="flex items-center justify-between gap-4">
            <h3 class="font-semibold text-slate-100">Ladder preview</h3>
            <span v-if="loading" class="text-xs text-slate-400">Updating...</span>
        </div>

        <p v-if="!plan" class="mt-2 text-sm text-slate-300">
            Enter the setup values to see the levels this bot would place.
        </p>

        <template v-else>
            <div v-if="errors.length" class="mt-3 rounded border border-rose-800 bg-rose-950 p-3 text-sm text-rose-200">
                <p class="font-semibold">This setup cannot be saved yet:</p>
                <ul class="mt-1 list-disc space-y-1 pl-5">
                    <li v-for="message in errors" :key="message">{{ message }}</li>
                </ul>
            </div>

            <div v-if="warnings.length" class="mt-3 rounded border border-amber-800 bg-amber-950 p-3 text-sm text-amber-200">
                <p class="font-semibold">Advisories (saving is allowed):</p>
                <ul class="mt-1 list-disc space-y-1 pl-5">
                    <li v-for="warning in warnings" :key="warning.message">
                        {{ warning.message }}
                        <Link
                            v-if="limitWarningCodes.includes(warning.code)"
                            :href="route('risk-limits.edit')"
                            class="ml-1 underline"
                        >Adjust limits</Link>
                    </li>
                </ul>
            </div>

            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-slate-400">Orders</dt>
                    <dd class="font-mono text-slate-100">{{ summary.orderCount }}</dd>
                </div>
                <div>
                    <dt class="text-slate-400">Levels per side</dt>
                    <dd class="font-mono text-slate-100">{{ summary.levelsPerSide }}</dd>
                </div>
                <div>
                    <dt class="text-slate-400">Buy notional</dt>
                    <dd class="font-mono text-slate-100">{{ summary.buyNotional }}</dd>
                </div>
                <div>
                    <dt class="text-slate-400">Sell notional</dt>
                    <dd class="font-mono text-slate-100">{{ summary.sellNotional }}</dd>
                </div>
            </dl>

            <p class="mt-3 text-xs text-slate-400">
                Order limit: {{ usage.orders }} &middot; Position limit: {{ usage.position }}
            </p>

            <p v-if="market" class="mt-1 text-xs text-slate-400">
                Market last {{ formatMoney(market.last) }} &middot;
                minimum order {{ market.min_amount }} &middot;
                minimum notional {{ formatMoney(market.min_notional) }} &middot;
                precision {{ market.price_precision }}/{{ market.amount_precision }}
            </p>
            <p v-else class="mt-1 text-xs text-amber-300">
                Market data is unavailable, so exchange minimums were not checked against this ladder.
            </p>

            <div class="mt-4 max-h-64 overflow-y-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="text-xs uppercase text-slate-400">
                        <tr>
                            <th class="py-1 pr-4">#</th>
                            <th class="py-1 pr-4">Side</th>
                            <th class="py-1 pr-4">Price</th>
                            <th class="py-1 pr-4">Quantity</th>
                            <th class="py-1">Notional</th>
                        </tr>
                    </thead>
                    <tbody class="font-mono text-slate-200">
                        <tr v-for="level in levels" :key="level.key" class="border-t border-slate-800">
                            <td class="py-1 pr-4">{{ level.sequence }}</td>
                            <td class="py-1 pr-4 capitalize" :class="level.side === 'buy' ? 'text-emerald-300' : 'text-rose-300'">
                                {{ level.side }}
                            </td>
                            <td class="py-1 pr-4">{{ level.price }}</td>
                            <td class="py-1 pr-4">{{ level.quantity }}</td>
                            <td class="py-1">{{ level.notional }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </section>
</template>
