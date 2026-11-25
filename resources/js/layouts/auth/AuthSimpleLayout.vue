<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <main class="auth-sky relative isolate grid min-h-svh place-items-center overflow-hidden px-4 py-9 sm:px-8 sm:py-12">
        <div class="auth-orbit pointer-events-none absolute -z-10" aria-hidden="true"></div>
        <div class="relative w-full max-w-[26rem] space-y-6">
            <Link :href="route('home')" class="mx-auto flex w-fit items-center gap-3 rounded-lg px-2 py-1" aria-label="B2B CRM — на главную">
                <span class="moon-mark grid size-11 shrink-0 place-items-center rounded-full" aria-hidden="true"><span class="moon-crescent"></span></span>
                <span class="text-lg font-semibold tracking-tight">B2B CRM</span>
            </Link>
            <section class="auth-card flex flex-col gap-7 rounded-2xl border p-5 sm:p-8" aria-labelledby="auth-heading">
                <header class="space-y-2">
                    <h1 id="auth-heading" class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ title }}</h1>
                    <p v-if="description" class="text-sm leading-relaxed text-muted-foreground">{{ description }}</p>
                </header>
                <slot />
            </section>
        </div>
    </main>
</template>

<style scoped>
.auth-sky {
    background:
        radial-gradient(ellipse at 50% 17%, hsl(var(--primary) / .13), transparent 59%),
        hsl(var(--background));
}
.auth-orbit {
    top: 46%;
    left: 50%;
    width: min(120vw, 66rem);
    aspect-ratio: 1;
    transform: translate(-50%, -50%);
    border: 1px solid hsl(var(--primary) / .12);
    border-radius: 50%;
    box-shadow: 0 0 0 3rem hsl(var(--primary) / .035), 0 0 0 8rem hsl(var(--primary) / .025);
}
.auth-card {
    background: hsl(var(--card));
    box-shadow: 0 24px 70px hsl(var(--foreground) / .08), 0 1px hsl(var(--primary) / .1) inset;
}
.dark .auth-card {
    box-shadow: 0 24px 70px rgb(2 5 17 / 42%), 0 1px hsl(var(--primary) / .1) inset;
}
.moon-mark {
    border: 1px solid hsl(var(--primary) / .62);
    background: hsl(var(--accent));
    box-shadow: 0 0 22px hsl(var(--primary) / .18);
}
.moon-crescent {
    width: 1.3rem;
    aspect-ratio: 1;
    border-radius: 50%;
    box-shadow: inset .38rem -.23rem hsl(var(--primary));
    transform: rotate(-20deg);
}
</style>
