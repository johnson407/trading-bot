<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
});

const primaryCta =
    'inline-flex items-center justify-center gap-2 rounded-lg bg-blue-500 px-5 py-3 text-sm font-semibold text-slate-950 shadow-lg shadow-blue-950/40 transition hover:bg-blue-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-300';

const secondaryCta =
    'inline-flex items-center justify-center gap-2 rounded-lg border border-slate-700 bg-slate-900/60 px-5 py-3 text-sm font-semibold text-slate-100 transition hover:border-slate-500 hover:bg-slate-800/80';

const currentYear = new Date().getFullYear();
</script>

<template>
    <Head title="Grid Trading Bot — Paper-first crypto grid bots">
        <meta
            name="description"
            content="Build, backtest, and run crypto grid strategies in paper mode first. Live trading stays gated until you deliberately enable it."
        />
    </Head>

    <div class="relative min-h-screen overflow-x-hidden bg-slate-950 text-slate-100">
        <a
            href="#main"
            class="sr-only rounded-md bg-blue-500 px-4 py-2 font-semibold text-slate-950 focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50"
        >
            Skip to content
        </a>

        <div
            class="pointer-events-none absolute inset-x-0 top-0 z-0 h-[36rem] bg-[radial-gradient(ellipse_at_top,_rgba(37,99,235,0.18),_transparent_65%)]"
        ></div>

        <header class="relative z-20 border-b border-slate-800/80 bg-slate-950/80 backdrop-blur">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-4 sm:px-8">
                <Link href="/" class="flex items-center gap-3 rounded-md">
                    <span
                        class="grid h-9 w-9 grid-cols-3 gap-0.5 rounded-lg border border-blue-400/30 bg-blue-400/10 p-1.5 text-blue-300"
                        aria-hidden="true"
                    >
                        <span class="rounded-[1px] bg-current"></span>
                        <span class="rounded-[1px] bg-current opacity-40"></span>
                        <span class="rounded-[1px] bg-current"></span>
                        <span class="rounded-[1px] bg-current opacity-40"></span>
                        <span class="rounded-[1px] bg-current"></span>
                        <span class="rounded-[1px] bg-current opacity-40"></span>
                        <span class="rounded-[1px] bg-current"></span>
                        <span class="rounded-[1px] bg-current opacity-40"></span>
                        <span class="rounded-[1px] bg-current"></span>
                    </span>
                    <span class="text-sm font-semibold tracking-tight text-slate-100 sm:text-base">
                        Grid Trading Bot
                    </span>
                </Link>

                <nav class="ml-auto flex items-center gap-2 text-sm" aria-label="Account">
                    <Link
                        v-if="canLogin"
                        :href="route('login')"
                        class="rounded-md px-3 py-2 font-medium text-slate-300 transition hover:text-white"
                    >
                        Log in
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="rounded-md bg-slate-800 px-3 py-2 font-semibold text-slate-100 transition hover:bg-slate-700"
                    >
                        Create free account
                    </Link>
                </nav>
            </div>
        </header>

        <main id="main" class="relative z-10">
            <section
                class="mx-auto grid max-w-6xl items-center gap-14 px-6 pb-20 pt-16 sm:px-8 lg:grid-cols-[1.05fr_0.95fr] lg:pb-28 lg:pt-24"
                aria-labelledby="hero-heading"
            >
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-300">
                        Paper-first crypto grid trading
                    </p>
                    <h1
                        id="hero-heading"
                        class="mt-5 max-w-xl text-4xl font-semibold leading-[1.08] tracking-tight text-white sm:text-5xl lg:text-6xl"
                    >
                        Run a grid strategy without learning the hard way.
                    </h1>
                    <p class="mt-6 max-w-xl text-base leading-7 text-slate-300 sm:text-lg">
                        Grid Trading Bot places and manages the grid for you. Build a ladder,
                        preview it, backtest it, and run it in paper mode — then enable live
                        trading only when you decide the setup is ready.
                    </p>

                    <div class="mt-8 flex flex-wrap items-center gap-3">
                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            :class="primaryCta"
                        >
                            Create free account
                            <svg
                                class="h-4 w-4"
                                viewBox="0 0 20 20"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.8"
                                aria-hidden="true"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 10h11m0 0-4-4m4 4-4 4" />
                            </svg>
                        </Link>
                        <Link
                            v-else-if="canLogin"
                            :href="route('login')"
                            :class="primaryCta"
                        >
                            Log in
                        </Link>
                        <Link
                            v-if="canLogin && canRegister"
                            :href="route('login')"
                            :class="secondaryCta"
                        >
                            Log in
                        </Link>
                    </div>

                    <p class="mt-5 max-w-lg text-sm leading-6 text-slate-400">
                        Free to start. We email a verification link — the dashboard opens once
                        your address is verified.
                    </p>
                </div>

                <div class="relative mx-auto w-full max-w-lg lg:max-w-none" aria-hidden="true">
                    <div class="absolute -inset-8 rounded-[2.5rem] bg-blue-500/10 blur-3xl"></div>
                    <div
                        class="relative overflow-hidden rounded-2xl border border-slate-700/80 bg-slate-900/95 shadow-2xl shadow-blue-950/50"
                    >
                        <div class="flex items-center justify-between gap-3 border-b border-slate-800 px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="relative flex h-2 w-2">
                                    <span class="pulse-dot absolute inline-flex h-full w-full rounded-full bg-emerald-300"></span>
                                    <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-300"></span>
                                </span>
                                <span class="text-xs font-medium text-slate-200">BTC / USDT</span>
                                <span
                                    class="rounded-full border border-blue-400/30 bg-blue-400/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-blue-200"
                                >
                                    Paper
                                </span>
                            </div>
                            <span class="text-[10px] text-slate-500">Illustrative preview</span>
                        </div>

                        <div class="space-y-4 p-4">
                            <div class="rounded-xl border border-slate-800 bg-slate-950/70 p-3">
                                <div class="flex items-center justify-between text-[10px] uppercase tracking-wider text-slate-500">
                                    <span>Grid ladder</span>
                                    <span>Arithmetic · 6 levels</span>
                                </div>
                                <svg
                                    class="mt-2 h-36 w-full"
                                    viewBox="0 0 360 144"
                                    fill="none"
                                    role="presentation"
                                >
                                    <defs>
                                        <linearGradient id="hero-area" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="0%" stop-color="#60a5fa" stop-opacity="0.28" />
                                            <stop offset="100%" stop-color="#60a5fa" stop-opacity="0" />
                                        </linearGradient>
                                    </defs>
                                    <g stroke="#334155" stroke-width="1" stroke-dasharray="3 5">
                                        <line x1="0" y1="24" x2="360" y2="24" />
                                        <line x1="0" y1="54" x2="360" y2="54" />
                                        <line x1="0" y1="84" x2="360" y2="84" />
                                        <line x1="0" y1="114" x2="360" y2="114" />
                                    </g>
                                    <path
                                        d="M0 104 C22 96 34 108 52 98 S82 68 104 78 S132 92 154 72 S186 44 206 58 S236 84 258 66 S292 34 316 44 S344 34 360 30 V144 H0 Z"
                                        fill="url(#hero-area)"
                                    />
                                    <path
                                        class="price-trace"
                                        d="M0 104 C22 96 34 108 52 98 S82 68 104 78 S132 92 154 72 S186 44 206 58 S236 84 258 66 S292 34 316 44 S344 34 360 30"
                                        stroke="#60a5fa"
                                        stroke-width="2.5"
                                        stroke-linecap="round"
                                    />
                                    <circle cx="360" cy="30" r="3.5" fill="#93c5fd" />
                                </svg>
                            </div>

                            <div class="grid gap-2 sm:grid-cols-2">
                                <div class="rounded-lg border border-rose-400/20 bg-rose-400/5 px-3 py-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[10px] font-semibold uppercase tracking-wider text-rose-200">
                                            Sell
                                        </span>
                                        <span class="text-[10px] text-slate-500">Level 4</span>
                                    </div>
                                    <p class="mt-1 font-mono text-sm text-slate-100">69,400.00</p>
                                </div>
                                <div class="rounded-lg border border-emerald-400/20 bg-emerald-400/5 px-3 py-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[10px] font-semibold uppercase tracking-wider text-emerald-200">
                                            Buy
                                        </span>
                                        <span class="text-[10px] text-slate-500">Level 1</span>
                                    </div>
                                    <p class="mt-1 font-mono text-sm text-slate-100">64,600.00</p>
                                </div>
                                <div class="rounded-lg border border-slate-800 bg-slate-950/60 px-3 py-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[10px] font-semibold uppercase tracking-wider text-rose-200">
                                            Sell
                                        </span>
                                        <span class="text-[10px] text-slate-500">Level 3</span>
                                    </div>
                                    <p class="mt-1 font-mono text-sm text-slate-100">67,800.00</p>
                                </div>
                                <div class="rounded-lg border border-slate-800 bg-slate-950/60 px-3 py-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-[10px] font-semibold uppercase tracking-wider text-emerald-200">
                                            Buy
                                        </span>
                                        <span class="text-[10px] text-slate-500">Level 2</span>
                                    </div>
                                    <p class="mt-1 font-mono text-sm text-slate-100">66,200.00</p>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-px border-t border-slate-800 bg-slate-800 text-center">
                            <div class="bg-slate-900 px-3 py-3">
                                <p class="text-[10px] uppercase tracking-wider text-slate-500">Grid levels</p>
                                <p class="mt-1 text-sm font-semibold text-slate-100">6</p>
                            </div>
                            <div class="bg-slate-900 px-3 py-3">
                                <p class="text-[10px] uppercase tracking-wider text-slate-500">Spacing</p>
                                <p class="mt-1 text-sm font-semibold text-slate-100">1.2%</p>
                            </div>
                            <div class="bg-slate-900 px-3 py-3">
                                <p class="text-[10px] uppercase tracking-wider text-slate-500">Mode</p>
                                <p class="mt-1 text-sm font-semibold text-blue-300">Paper</p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section aria-label="At a glance" class="border-y border-slate-800/80 bg-slate-900/30">
                <ul class="mx-auto grid max-w-6xl gap-6 px-6 py-8 sm:grid-cols-3 sm:px-8">
                    <li class="flex items-start gap-3">
                        <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-700 bg-slate-900 text-blue-300">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" d="M3 5h14M3 10h14M3 15h14" />
                                <path stroke-linecap="round" d="M6 3v4M13 8v4M8 13v4" />
                            </svg>
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-slate-100">Four built-in strategies</p>
                            <p class="mt-1 text-sm leading-6 text-slate-400">Simple, trailing, martingale, and dynamic grids.</p>
                        </div>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-700 bg-slate-900 text-emerald-300">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10 2.5 4 5v4.5c0 3.7 2.5 6.4 6 8 3.5-1.6 6-4.3 6-8V5l-6-2.5Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="m7.5 10 1.7 1.7 3.3-3.4" />
                            </svg>
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-slate-100">Paper mode by default</p>
                            <p class="mt-1 text-sm leading-6 text-slate-400">No private exchange orders until you enable live.</p>
                        </div>
                    </li>
                    <li class="flex items-start gap-3">
                        <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-slate-700 bg-slate-900 text-amber-200">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10 2.5 3 6v4c0 4 3 7 7 7.5 4-.5 7-3.5 7-7.5V6l-7-3.5Z" />
                                <path stroke-linecap="round" d="M10 6.5v4" />
                                <circle cx="10" cy="13.5" r=".6" fill="currentColor" />
                            </svg>
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-slate-100">Risk checks in the engine</p>
                            <p class="mt-1 text-sm leading-6 text-slate-400">Limits, daily-loss pauses, and exits are enforced before every order.</p>
                        </div>
                    </li>
                </ul>
            </section>

            <section
                class="mx-auto max-w-6xl px-6 py-20 sm:px-8 lg:py-24"
                aria-labelledby="strategies-heading"
            >
                <div class="max-w-2xl">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-300">
                        Strategies
                    </p>
                    <h2
                        id="strategies-heading"
                        class="mt-3 text-3xl font-semibold tracking-tight text-white sm:text-4xl"
                    >
                        Four strategies. One risk-aware engine.
                    </h2>
                    <p class="mt-4 text-base leading-7 text-slate-300">
                        A strategy decides what grid to build and which orders to request. The engine
                        owns persistence, exchange calls, risk checks, and order bookkeeping — so the
                        ladder you preview is the ladder the engine runs.
                    </p>
                </div>

                <div class="mt-10 grid gap-4 sm:grid-cols-2">
                    <article class="rounded-2xl border border-slate-800 bg-slate-900/50 p-6">
                        <div class="flex items-center justify-between gap-4">
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg border border-blue-400/25 bg-blue-400/10 text-blue-300">
                                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                    <path stroke-linecap="round" d="M3 5h14M3 10h14M3 15h14" />
                                </svg>
                            </span>
                            <code class="rounded-full border border-slate-700 bg-slate-950/60 px-2.5 py-1 text-[10px] font-medium text-slate-400">
                                simple-grid
                            </code>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold text-slate-100">Simple grid</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-400">
                            A static arithmetic or geometric ladder around a centre price. Set the
                            levels per side, spacing, and order quantity.
                        </p>
                    </article>

                    <article class="rounded-2xl border border-slate-800 bg-slate-900/50 p-6">
                        <div class="flex items-center justify-between gap-4">
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg border border-emerald-400/25 bg-emerald-400/10 text-emerald-300">
                                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 15h4V9h4V4h6" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m14 7 3-3-3-3" />
                                </svg>
                            </span>
                            <code class="rounded-full border border-slate-700 bg-slate-950/60 px-2.5 py-1 text-[10px] font-medium text-slate-400">
                                trailing-grid
                            </code>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold text-slate-100">Trailing grid</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-400">
                            The simple ladder plus a trailing distance. When price moves beyond that
                            distance, the grid recenters around the new price.
                        </p>
                    </article>

                    <article class="rounded-2xl border border-slate-800 bg-slate-900/50 p-6">
                        <div class="flex items-center justify-between gap-4">
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg border border-rose-400/25 bg-rose-400/10 text-rose-300">
                                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                    <path stroke-linecap="round" d="M3 16h2v-3H3v3Zm4 0h2V9H7v7Zm4 0h2V5h-2v11Zm4 0h2V2h-2v14Z" />
                                </svg>
                            </span>
                            <code class="rounded-full border border-slate-700 bg-slate-950/60 px-2.5 py-1 text-[10px] font-medium text-slate-400">
                                martingale-grid
                            </code>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold text-slate-100">Martingale grid</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-400">
                            Increases order size after fills, with hard caps on the maximum order
                            quantity and the number of increases.
                        </p>
                    </article>

                    <article class="rounded-2xl border border-slate-800 bg-slate-900/50 p-6">
                        <div class="flex items-center justify-between gap-4">
                            <span class="flex h-10 w-10 items-center justify-center rounded-lg border border-amber-300/25 bg-amber-300/10 text-amber-200">
                                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2 12c2.5 0 2.5-5 5-5s2.5 6 5 6 2.5-8 6-8" />
                                </svg>
                            </span>
                            <code class="rounded-full border border-slate-700 bg-slate-950/60 px-2.5 py-1 text-[10px] font-medium text-slate-400">
                                dynamic-grid
                            </code>
                        </div>
                        <h3 class="mt-5 text-lg font-semibold text-slate-100">Dynamic grid</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-400">
                            Sets spacing from recent volatility between your minimum and maximum,
                            recalculating on the interval you choose.
                        </p>
                    </article>
                </div>

                <p class="mt-6 text-sm leading-6 text-slate-400">
                    Preview, save validation, and runtime ticks all use the same strategy code. If
                    the engine is unreachable, preview and save fail with a clear error and persist
                    nothing.
                </p>
            </section>

            <section
                class="border-y border-slate-800/80 bg-slate-900/30 py-20 lg:py-24"
                aria-labelledby="safety-heading"
            >
                <div class="mx-auto grid max-w-6xl gap-12 px-6 sm:px-8 lg:grid-cols-[0.85fr_1.15fr]">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-emerald-300">
                            Paper-first safety
                        </p>
                        <h2
                            id="safety-heading"
                            class="mt-3 text-3xl font-semibold tracking-tight text-white sm:text-4xl"
                        >
                            Paper first. Live only by deliberate choice.
                        </h2>
                        <p class="mt-4 text-base leading-7 text-slate-300">
                            Risk checks are enforced by the trading engine, not just the dashboard.
                            Strategies return order intents; only the engine may turn an intent into
                            an order, and every intent passes the risk guard.
                        </p>
                        <p class="mt-5 rounded-xl border border-emerald-400/20 bg-emerald-400/5 px-4 py-3 text-sm leading-6 text-emerald-100">
                            Paper mode never sends private exchange orders. There is no silent
                            fallback from live to paper.
                        </p>
                    </div>

                    <ul class="grid gap-4 sm:grid-cols-2">
                        <li class="rounded-xl border border-slate-800 bg-slate-950/50 p-5">
                            <div class="flex items-center gap-2 text-slate-100">
                                <svg class="h-4 w-4 text-blue-300" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 9V6a4 4 0 1 1 8 0v3" />
                                    <rect x="4" y="9" width="12" height="8" rx="2" />
                                </svg>
                                <h3 class="text-sm font-semibold">Deliberate live gate</h3>
                            </div>
                            <p class="mt-2 text-sm leading-6 text-slate-400">
                                Live requires an environment enable flag, a stored exchange
                                credential, a covered quote balance, and the exact
                                <code class="rounded bg-slate-800 px-1 py-0.5 text-[11px] text-slate-200">&lt;bot name&gt; on &lt;exchange&gt;</code>
                                confirmation at start.
                            </p>
                        </li>

                        <li class="rounded-xl border border-slate-800 bg-slate-950/50 p-5">
                            <div class="flex items-center gap-2 text-slate-100">
                                <svg class="h-4 w-4 text-rose-300" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" d="M10 3v5" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.5 5.2a6 6 0 1 0 7 0" />
                                </svg>
                                <h3 class="text-sm font-semibold">Kill switch</h3>
                            </div>
                            <p class="mt-2 text-sm leading-6 text-slate-400">
                                One control pauses every non-stopped bot you own and cancels its
                                orders. If the engine heartbeat is unavailable, live orders are
                                cancelled directly.
                            </p>
                        </li>

                        <li class="rounded-xl border border-slate-800 bg-slate-950/50 p-5">
                            <div class="flex items-center gap-2 text-slate-100">
                                <svg class="h-4 w-4 text-amber-200" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 15h14M5 12V8m5 4V5m5 7V7" />
                                </svg>
                                <h3 class="text-sm font-semibold">Position and order caps</h3>
                            </div>
                            <p class="mt-2 text-sm leading-6 text-slate-400">
                                The engine blocks an order that would exceed your maximum position
                                notional or maximum open-order count. Limits can be set per account
                                or overridden per bot.
                            </p>
                        </li>

                        <li class="rounded-xl border border-slate-800 bg-slate-950/50 p-5">
                            <div class="flex items-center gap-2 text-slate-100">
                                <svg class="h-4 w-4 text-emerald-300" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <circle cx="10" cy="10" r="7" />
                                    <path stroke-linecap="round" d="M10 6v4l2.5 2" />
                                </svg>
                                <h3 class="text-sm font-semibold">Daily-loss pause</h3>
                            </div>
                            <p class="mt-2 text-sm leading-6 text-slate-400">
                                Per-bot and account-wide loss baselines reset at midnight UTC. A
                                breach pauses the affected bots and cancels their orders — nothing
                                resumes automatically.
                            </p>
                        </li>

                        <li class="rounded-xl border border-slate-800 bg-slate-950/50 p-5">
                            <div class="flex items-center gap-2 text-slate-100">
                                <svg class="h-4 w-4 text-blue-300" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 10h12M12 6l4 4-4 4" />
                                </svg>
                                <h3 class="text-sm font-semibold">Protective exits</h3>
                            </div>
                            <p class="mt-2 text-sm leading-6 text-slate-400">
                                Optional stop-loss, take-profit, and range exits per bot. A trigger
                                pauses the bot and cancels orders; it never places a market order or
                                liquidates inventory.
                            </p>
                        </li>

                        <li class="rounded-xl border border-slate-800 bg-slate-950/50 p-5">
                            <div class="flex items-center gap-2 text-slate-100">
                                <svg class="h-4 w-4 text-slate-300" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 3 3 6v4c0 4 3 7 7 7.5 4-.5 7-3.5 7-7.5V6l-7-3Z" />
                                    <path stroke-linecap="round" d="M7.5 10h5" />
                                </svg>
                                <h3 class="text-sm font-semibold">No silent fallback</h3>
                            </div>
                            <p class="mt-2 text-sm leading-6 text-slate-400">
                                A live failure pauses the bot and records the error. The engine also
                                rechecks the gate, status, exposure, and exchange precision before
                                every live order.
                            </p>
                        </li>
                    </ul>
                </div>
            </section>

            <section
                class="mx-auto max-w-6xl px-6 py-20 sm:px-8 lg:py-24"
                aria-labelledby="backtesting-heading"
            >
                <div class="grid items-center gap-12 lg:grid-cols-2">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-300">
                            Backtesting
                        </p>
                        <h2
                            id="backtesting-heading"
                            class="mt-3 text-3xl font-semibold tracking-tight text-white sm:text-4xl"
                        >
                            Test the ladder before you go live.
                        </h2>
                        <p class="mt-4 text-base leading-7 text-slate-300">
                            Replay an existing bot's configuration against cached historical
                            candles, then keep the run to inspect its metrics and equity curve.
                            Compare saved runs side by side before you commit capital.
                        </p>

                        <ul class="mt-8 space-y-4">
                            <li class="flex gap-3">
                                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-400/15 text-blue-300">
                                    <svg class="h-3 w-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4 10 4 4 8-8" />
                                    </svg>
                                </span>
                                <div>
                                    <h3 class="text-sm font-semibold text-slate-100">Cached candles, immutable snapshots</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-400">
                                        Runs read public historical data and snapshot the bot's
                                        strategy, fees, limits, and exits at creation. Later bot edits
                                        never rewrite an existing run.
                                    </p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-400/15 text-blue-300">
                                    <svg class="h-3 w-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4 10 4 4 8-8" />
                                    </svg>
                                </span>
                                <div>
                                    <h3 class="text-sm font-semibold text-slate-100">Metrics and equity curve</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-400">
                                        Review final equity, net P&amp;L, return, maximum drawdown, fill
                                        counts, fees, and the sampled equity curve for every completed
                                        run.
                                    </p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-400/15 text-blue-300">
                                    <svg class="h-3 w-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4 10 4 4 8-8" />
                                    </svg>
                                </span>
                                <div>
                                    <h3 class="text-sm font-semibold text-slate-100">Side-by-side comparison</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-400">
                                        Compare two to four completed runs with the same symbol,
                                        timeframe, and range, using a metrics table and overlaid equity
                                        curves normalised by starting capital.
                                    </p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-blue-400/15 text-blue-300">
                                    <svg class="h-3 w-3" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m4 10 4 4 8-8" />
                                    </svg>
                                </span>
                                <div>
                                    <h3 class="text-sm font-semibold text-slate-100">Parameter sweeps</h3>
                                    <p class="mt-1 text-sm leading-6 text-slate-400">
                                        Test combinations of centre price, spacing, and level count in
                                        one sweep, then compare the completed runs without editing the
                                        source bot.
                                    </p>
                                </div>
                            </li>
                        </ul>

                        <p class="mt-6 text-xs leading-5 text-slate-400">
                            Backtests are directional, not predictive. Fills are close-only, at the
                            limit, in full, without slippage; exchange precision and minimum rules
                            are omitted.
                        </p>
                    </div>

                    <div class="relative" aria-hidden="true">
                        <div class="absolute -inset-6 rounded-[2rem] bg-blue-500/10 blur-2xl"></div>
                        <div class="relative overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80">
                            <div class="flex items-center justify-between border-b border-slate-800 px-5 py-4">
                                <div>
                                    <p class="text-sm font-semibold text-slate-100">Run comparison</p>
                                    <p class="mt-0.5 text-[11px] text-slate-500">Same symbol · timeframe · range</p>
                                </div>
                                <span class="rounded-full border border-blue-400/25 bg-blue-400/10 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wider text-blue-200">
                                    2–4 runs
                                </span>
                            </div>
                            <div class="p-5">
                                <svg class="h-48 w-full" viewBox="0 0 400 190" fill="none" role="presentation">
                                    <g stroke="#334155" stroke-width="1" stroke-dasharray="3 6">
                                        <line x1="0" y1="30" x2="400" y2="30" />
                                        <line x1="0" y1="75" x2="400" y2="75" />
                                        <line x1="0" y1="120" x2="400" y2="120" />
                                        <line x1="0" y1="165" x2="400" y2="165" />
                                    </g>
                                    <path
                                        d="M0 150 C40 144 56 130 90 134 S140 104 172 112 S220 90 250 96 S300 70 332 78 S372 58 400 62"
                                        stroke="#60a5fa"
                                        stroke-width="2.5"
                                        stroke-linecap="round"
                                    />
                                    <path
                                        d="M0 150 C40 150 60 142 92 146 S140 126 172 132 S220 112 250 120 S300 96 332 104 S372 86 400 92"
                                        stroke="#6ee7b7"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-opacity="0.85"
                                    />
                                    <path
                                        d="M0 150 C40 146 58 152 90 142 S140 136 172 120 S220 128 250 106 S300 112 332 90 S372 96 400 74"
                                        stroke="#fda4af"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-opacity="0.8"
                                    />
                                </svg>
                                <div class="mt-4 grid grid-cols-3 gap-3 text-center text-[10px] uppercase tracking-wider text-slate-500">
                                    <div class="flex items-center justify-center gap-2">
                                        <span class="h-2 w-2 rounded-full bg-blue-400"></span>
                                        Run A
                                    </div>
                                    <div class="flex items-center justify-center gap-2">
                                        <span class="h-2 w-2 rounded-full bg-emerald-300"></span>
                                        Run B
                                    </div>
                                    <div class="flex items-center justify-center gap-2">
                                        <span class="h-2 w-2 rounded-full bg-rose-300"></span>
                                        Run C
                                    </div>
                                </div>
                                <p class="mt-4 text-center text-[10px] text-slate-500">
                                    Equity normalised by starting capital
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                class="border-y border-slate-800/80 bg-slate-900/30 py-20 lg:py-24"
                aria-labelledby="how-it-works-heading"
            >
                <div class="mx-auto max-w-6xl px-6 sm:px-8">
                    <div class="max-w-2xl">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-blue-300">
                            How it works
                        </p>
                        <h2
                            id="how-it-works-heading"
                            class="mt-3 text-3xl font-semibold tracking-tight text-white sm:text-4xl"
                        >
                            From signup to a running grid in three steps.
                        </h2>
                    </div>

                    <ol class="mt-10 grid gap-4 md:grid-cols-3">
                        <li class="rounded-2xl border border-slate-800 bg-slate-950/50 p-6">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full border border-slate-700 bg-slate-900 font-mono text-xs font-semibold text-blue-300">
                                01
                            </span>
                            <h3 class="mt-5 text-lg font-semibold text-slate-100">Create your account</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-400">
                                Register with your email address and verify it. The dashboard opens
                                after verification.
                            </p>
                        </li>
                        <li class="rounded-2xl border border-slate-800 bg-slate-950/50 p-6">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full border border-slate-700 bg-slate-900 font-mono text-xs font-semibold text-blue-300">
                                02
                            </span>
                            <h3 class="mt-5 text-lg font-semibold text-slate-100">Build and preview a ladder</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-400">
                                Choose a strategy and set the centre price, spacing, levels, and
                                order quantity. Preview the exact ladder the engine will run.
                            </p>
                        </li>
                        <li class="rounded-2xl border border-slate-800 bg-slate-950/50 p-6">
                            <span class="flex h-9 w-9 items-center justify-center rounded-full border border-slate-700 bg-slate-900 font-mono text-xs font-semibold text-blue-300">
                                03
                            </span>
                            <h3 class="mt-5 text-lg font-semibold text-slate-100">Run paper, then decide</h3>
                            <p class="mt-2 text-sm leading-6 text-slate-400">
                                Watch orders, fills, and risk events in paper mode. Enable live only
                                after the gate, credential, balance, and confirmation checks pass.
                            </p>
                        </li>
                    </ol>
                </div>
            </section>

            <section class="mx-auto max-w-6xl px-6 py-20 sm:px-8 lg:py-24" aria-labelledby="closing-cta-heading">
                <div
                    class="relative overflow-hidden rounded-3xl border border-blue-400/20 bg-gradient-to-br from-blue-500/15 via-slate-900 to-slate-900 px-6 py-12 text-center sm:px-12 lg:py-16"
                >
                    <div class="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-blue-400/10 blur-3xl"></div>
                    <div class="relative">
                        <h2
                            id="closing-cta-heading"
                            class="mx-auto max-w-2xl text-3xl font-semibold tracking-tight text-white sm:text-4xl"
                        >
                            Start in paper. Keep the decision to go live.
                        </h2>
                        <p class="mx-auto mt-4 max-w-xl text-base leading-7 text-slate-300">
                            Create an account, build a grid, and see how the strategy behaves before
                            any real order is possible.
                        </p>
                        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                            <Link
                                v-if="canRegister"
                                :href="route('register')"
                                :class="primaryCta"
                            >
                                Create free account
                                <svg
                                    class="h-4 w-4"
                                    viewBox="0 0 20 20"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1.8"
                                    aria-hidden="true"
                                >
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 10h11m0 0-4-4m4 4-4 4" />
                                </svg>
                            </Link>
                            <Link
                                v-else-if="canLogin"
                                :href="route('login')"
                                :class="primaryCta"
                            >
                                Log in
                            </Link>
                            <Link
                                v-if="canLogin && canRegister"
                                :href="route('login')"
                                :class="secondaryCta"
                            >
                                Log in
                            </Link>
                        </div>
                        <p class="mt-5 text-sm text-slate-400">
                            Email verification is required before the dashboard opens.
                        </p>
                    </div>
                </div>
            </section>
        </main>

        <footer class="border-t border-slate-800/80 bg-slate-950">
            <div class="mx-auto max-w-6xl px-6 py-10 sm:px-8">
                <div class="flex flex-col gap-6 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-3">
                        <span
                            class="grid h-8 w-8 grid-cols-3 gap-0.5 rounded-lg border border-blue-400/30 bg-blue-400/10 p-1.5 text-blue-300"
                            aria-hidden="true"
                        >
                            <span class="rounded-[1px] bg-current"></span>
                            <span class="rounded-[1px] bg-current opacity-40"></span>
                            <span class="rounded-[1px] bg-current"></span>
                            <span class="rounded-[1px] bg-current opacity-40"></span>
                            <span class="rounded-[1px] bg-current"></span>
                            <span class="rounded-[1px] bg-current opacity-40"></span>
                            <span class="rounded-[1px] bg-current"></span>
                            <span class="rounded-[1px] bg-current opacity-40"></span>
                            <span class="rounded-[1px] bg-current"></span>
                        </span>
                        <div>
                            <p class="text-sm font-semibold text-slate-100">Grid Trading Bot</p>
                            <p class="text-xs text-slate-400">Paper-first crypto grid strategies</p>
                        </div>
                    </div>

                    <nav class="flex items-center gap-4 text-sm" aria-label="Footer">
                        <Link
                            v-if="canLogin"
                            :href="route('login')"
                            class="rounded-md text-slate-400 transition hover:text-white"
                        >
                            Sign in
                        </Link>
                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="rounded-md text-slate-400 transition hover:text-white"
                        >
                            Create account
                        </Link>
                    </nav>
                </div>

                <div class="mt-8 flex flex-col gap-2 border-t border-slate-800/80 pt-6 text-xs leading-5 text-slate-400 sm:flex-row sm:items-center sm:justify-between">
                    <p>Paper mode by default. Live trading is gated and off unless deliberately enabled.</p>
                    <p>© {{ currentYear }} Grid Trading Bot. Trading involves risk. Not financial advice.</p>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
@media (prefers-reduced-motion: no-preference) {
    .price-trace {
        stroke-dasharray: 620;
        stroke-dashoffset: 620;
        animation: draw-trace 2.4s ease-out 0.2s forwards;
    }

    .pulse-dot {
        animation: pulse-dot 2.8s ease-in-out infinite;
    }
}

@keyframes draw-trace {
    to {
        stroke-dashoffset: 0;
    }
}

@keyframes pulse-dot {
    0%,
    100% {
        opacity: 0.25;
        transform: scale(0.85);
    }

    50% {
        opacity: 1;
        transform: scale(1.25);
    }
}
</style>
