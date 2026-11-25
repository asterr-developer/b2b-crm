<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Building2, CalendarDays, ClipboardCheck, FileText } from 'lucide-vue-next';

const page = usePage<{ auth: { user: unknown } }>();
const steps = [
    { title: 'Приём и квалификация', detail: 'Сотрудник находит клиента, уточняет даты проживания и число гостей.', icon: Building2 },
    { title: 'Предложение и согласование', detail: 'Руководитель проверяет конкретную версию. Менеджер получает PDF и фиксирует отправку.', icon: FileText },
    { title: 'Контроль ответа', detail: 'CRM напоминает о просрочке и связывает показатель со сделкой.', icon: ClipboardCheck },
];
</script>

<template>
    <Head title="Корпоративное размещение" />
    <div class="min-h-screen bg-background text-foreground">
        <header class="border-b">
            <nav aria-label="Основная навигация" class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-5 py-4 md:px-8">
                <div class="flex items-center gap-2 font-semibold">
                    <span class="flex size-8 items-center justify-center rounded-md bg-primary text-primary-foreground"><AppLogoIcon class="size-5" /></span>
                    B2B CRM
                </div>
                <Link v-if="page.props.auth.user" :href="route('dashboard')" class="rounded-md border px-3 py-2 text-sm">Рабочая область</Link>
                <Link v-else :href="route('login')" class="rounded-md border px-3 py-2 text-sm">Войти</Link>
            </nav>
        </header>
        <main class="mx-auto max-w-6xl px-5 py-12 md:px-8 md:py-20">
            <div class="grid items-center gap-8 lg:grid-cols-[0.95fr_1.05fr] lg:gap-12">
                <section class="space-y-6">
                    <h1 class="max-w-2xl text-4xl font-semibold tracking-tight md:text-6xl">CRM корпоративного размещения</h1>
                    <p class="max-w-lg text-base leading-relaxed text-muted-foreground md:text-lg">Ведите гостиничные заявки от первого обращения до закрытой сделки. Клиенты, согласования и контроль ответа в одной рабочей области.</p>
                    <Link :href="page.props.auth.user ? route('group-requests.index') : route('login')" class="inline-flex rounded-md bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground">{{ page.props.auth.user ? 'Открыть заявки' : 'Войти в CRM' }}</Link>
                </section>
                <img src="/hotel-blue-hour.webp" alt="Освещённый вход в гостиницу в сумерках" width="1536" height="1024" fetchpriority="high" class="aspect-[4/3] w-full rounded-2xl border object-cover shadow-lg sm:aspect-[3/2]" />
            </div>
                <section class="moon-panel mt-10 p-5 md:p-7">
                    <div class="flex items-center gap-2 text-sm text-muted-foreground"><CalendarDays class="size-4" aria-hidden="true" /> Демонстрационный процесс</div>
                    <h2 class="mt-2 text-xl font-semibold">Размещение корпоративной группы</h2>
                    <ol class="mt-5 grid gap-3 md:grid-cols-3">
                        <li v-for="step in steps" :key="step.title" class="flex gap-3 rounded-xl border bg-background/60 p-4">
                            <component :is="step.icon" class="mt-0.5 size-5 shrink-0 text-indigo-700 dark:text-indigo-300" aria-hidden="true" />
                            <div><p class="font-medium">{{ step.title }}</p><p class="mt-1 text-sm text-muted-foreground">{{ step.detail }}</p></div>
                        </li>
                    </ol>
                </section>
        </main>
    </div>
</template>
