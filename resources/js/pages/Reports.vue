<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    conversion: { stage: string; count: number; percent: number | null }[];
    losses: { loss_reason: string | null; count: number }[];
    forecast: Record<string, number>;
    forecastHistory: { days: string[]; active: string | null; totals: Record<string, number> };
    actual: { month: string; amount: string | number; count: number }[];
    period: { from?: string; to?: string; capture?: string; metric?: string };
    bottlenecks: Record<string, { label:string; count:number; minutes:number|null }>;
    bottleneckDetails: { data:{id:number;title:string;started_at?:string;ended_at?:string;description?:string}[];prev_page_url:string|null;next_page_url:string|null }|null;
    overdueCount: number;
    overdueTasks: { id: number; body: string; deal: { id: number; title: string } }[];
}>();
const filters = useForm({ from: props.period.from ?? '', to: props.period.to ?? '', capture: props.period.capture ?? '' });
const history = useForm({ capture: props.forecastHistory.active ?? '', from: props.period.from ?? '', to: props.period.to ?? '' });
const money = new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' });
const forecastTotal = computed(() => Object.values(props.forecast).reduce((sum, amount) => sum + amount, 0));
const actualTotal = computed(() => props.actual.reduce((sum, row) => sum + Number(row.amount), 0));
const forecastPeak = computed(() => Math.max(1, ...Object.values(props.forecast)));
const metricLabels:Record<string,string> = { response:'До первой отправки КП', client_reply:'До первого письма клиента', approval:'До первого согласования КП', overdue:'Просроченные задачи', lost:'Проигранные сделки' };
const decimal = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 });
const reportDate = new Intl.DateTimeFormat('ru-RU', { dateStyle:'short',timeStyle:'short' });
</script>

<template>
    <Head title="Отчёты по продажам" />
    <AppLayout :breadcrumbs="[{ title: 'Отчёты по продажам', href: '/reports' }]">
        <main class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
            <header class="moon-header space-y-2">
                <Link :href="route('dashboard')" class="text-sm text-muted-foreground underline">Воронка продаж</Link>
                <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">Отчёты по продажам</h1>
            </header>
            <form class="moon-toolbar flex flex-wrap items-end gap-3 text-sm" @submit.prevent="filters.get(route('reports'), { preserveScroll: true })">
                <label class="grid gap-1">Дата создания с <input v-model="filters.from" type="date" class="rounded-md border bg-background px-2 py-1" /></label>
                <label class="grid gap-1">Дата создания по <input v-model="filters.to" type="date" class="rounded-md border bg-background px-2 py-1" /></label>
                <button class="rounded-md border px-3 py-1">Применить</button>
                <Link :href="route('reports')" class="px-1 py-1 underline">Сбросить</Link>
                <p v-for="error in filters.errors" :key="error" role="alert" class="w-full text-destructive">{{ error }}</p>
            </form>
            <dl class="grid gap-3 sm:grid-cols-3">
                <div class="moon-panel p-4"><dt class="text-sm text-muted-foreground">Закрытые продажи</dt><dd class="mt-2 text-2xl font-semibold tabular-nums">{{ money.format(actualTotal) }}</dd></div>
                <div class="moon-panel p-4"><dt class="text-sm text-muted-foreground">Взвешенный прогноз</dt><dd class="mt-2 text-2xl font-semibold tabular-nums">{{ money.format(forecastTotal / 100) }}</dd></div>
                <div class="moon-panel p-4"><dt class="text-sm text-muted-foreground">Просроченные задачи</dt><dd class="mt-2 text-2xl font-semibold tabular-nums"><a href="#overdue" class="underline decoration-primary/50 underline-offset-4">{{ overdueCount }}</a></dd></div>
            </dl>
            <section id="bottlenecks" class="moon-panel scroll-mt-4 space-y-4 p-5">
                <h2 class="text-lg font-semibold">Узкие места процесса</h2>
                <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                    <Link v-for="(metric,key) in bottlenecks" :key="key" :href="route('reports',{...period,metric:key})+'#bottlenecks'" class="space-y-1 rounded-lg border p-3 hover:bg-muted" :aria-current="period.metric === key ? 'page' : undefined">
                        <p class="text-sm">{{ metric.label }}</p>
                        <p class="font-semibold">{{ metric.minutes === null ? 'Нет данных' : decimal.format(metric.minutes) + ' мин в среднем' }}</p>
                        <p class="text-xs text-muted-foreground">Сделок в расчёте: {{ metric.count }}</p>
                    </Link>
                    <Link :href="route('reports',{...period,metric:'overdue'})+'#bottlenecks'" class="space-y-1 rounded-lg border p-3 hover:bg-muted" :aria-current="period.metric === 'overdue' ? 'page' : undefined">
                        <p class="text-sm">Просроченные задачи</p><p class="font-semibold">{{ overdueCount }}</p>
                    </Link>
                    <Link :href="route('reports',{...period,metric:'lost'})+'#bottlenecks'" class="space-y-1 rounded-lg border p-3 hover:bg-muted" :aria-current="period.metric === 'lost' ? 'page' : undefined">
                        <p class="text-sm">Проигранные сделки</p><p class="font-semibold">{{ losses.reduce((sum,row) => sum + Number(row.count),0) }}</p>
                    </Link>
                </div>
                <div v-if="bottleneckDetails && period.metric" class="space-y-2 border-t pt-3">
                    <h3 class="font-medium">{{ metricLabels[period.metric] }}</h3>
                    <p v-if="!bottleneckDetails.data.length" class="text-sm text-muted-foreground">В выбранном периоде нет записей.</p>
                    <ul v-else class="divide-y">
                        <li v-for="(row,index) in bottleneckDetails.data" :key="index" class="flex flex-wrap justify-between gap-2 py-2 text-sm">
                            <Link :href="route('deals.show',{deal:row.id})" class="underline">{{ row.title }}</Link>
                            <span v-if="row.description" class="break-words text-muted-foreground">{{ row.description }}</span>
                            <span v-else-if="period.metric === 'lost'" class="text-muted-foreground">Причина не указана</span>
                            <span class="text-muted-foreground"><template v-if="row.started_at">{{ reportDate.format(new Date(row.started_at.replace(' ','T'))) }}</template><template v-if="row.started_at && row.ended_at"> — </template><template v-if="row.ended_at">{{ reportDate.format(new Date(row.ended_at.replace(' ','T'))) }}</template></span>
                        </li>
                    </ul>
                    <nav v-if="bottleneckDetails.prev_page_url || bottleneckDetails.next_page_url" aria-label="Страницы отчёта" class="flex gap-4 text-sm">
                        <Link v-if="bottleneckDetails.prev_page_url" :href="bottleneckDetails.prev_page_url+'#bottlenecks'" class="underline">Назад</Link>
                        <Link v-if="bottleneckDetails.next_page_url" :href="bottleneckDetails.next_page_url+'#bottlenecks'" class="underline">Далее</Link>
                    </nav>
                </div>
            </section>
            <section id="overdue" class="moon-panel scroll-mt-4 space-y-3 p-5">
                <h2 class="text-lg font-semibold">Просроченные задачи · {{ overdueCount }}</h2>
                <p v-if="!overdueCount" class="text-sm text-muted-foreground">Просроченных задач нет</p>
                <ul v-else class="divide-y">
                    <li v-for="task in overdueTasks" :key="task.id" class="flex flex-wrap justify-between gap-2 py-2 text-sm">
                        <span class="break-words">{{ task.body }}</span>
                        <Link :href="route('deals.show', { deal: task.deal.id })" class="underline">{{ task.deal.title }}</Link>
                    </li>
                </ul>
                <Link v-if="overdueCount > overdueTasks.length && !period.from && !period.to" :href="route('tasks', { filter: 'overdue' })" class="text-sm underline">Все просрочки</Link>
                <p v-else-if="overdueCount > overdueTasks.length" class="text-sm text-muted-foreground">Показаны 10 самых ранних просрочек</p>
            </section>
            <div class="grid items-start gap-4 lg:grid-cols-3">
                <section class="space-y-3 rounded-xl border bg-card p-5">
                    <h2 class="text-lg font-semibold">Конверсия между этапами</h2>
                    <div v-for="row in conversion" :key="row.stage" class="space-y-1 text-sm">
                        <p class="flex justify-between gap-2"><span>{{ row.stage }}</span><span>{{ row.count }} · {{ row.percent === null ? '—' : row.percent + '%' }}</span></p>
                        <progress v-if="row.percent !== null" class="moon-progress" :aria-label="row.stage" :value="row.percent" max="100" />
                    </div>
                </section>
                <section class="space-y-3 rounded-xl border bg-card p-5">
                    <h2 class="text-lg font-semibold">Причины проигрыша</h2>
                    <p v-if="!losses.length" class="text-sm text-muted-foreground">Нет проигранных сделок</p>
                    <p v-for="row in losses" :key="row.loss_reason ?? ''" class="flex justify-between gap-2 text-sm"><span>{{ row.loss_reason ?? 'Не указана' }}</span><span>{{ row.count }}</span></p>
                </section>
                <section class="space-y-3 rounded-xl border bg-card p-5">
                    <h2 class="text-lg font-semibold">Прогноз выручки</h2>
                    <p class="text-xs text-muted-foreground">Вес открытой сделки — 10%, 35% или 70% в зависимости от этапа.</p>
                    <p v-if="!Object.keys(forecast).length" class="text-sm text-muted-foreground">Нет открытых сделок с плановой датой</p>
                    <div v-for="(amount, month) in forecast" :key="month" class="space-y-1 text-sm">
                        <p class="flex flex-wrap justify-between gap-2"><span>{{ month }}</span><span class="tabular-nums">{{ money.format(amount / 100) }}</span></p>
                        <progress class="moon-progress" :aria-label="'Прогноз за ' + month" :value="amount" :max="forecastPeak" />
                    </div>
                </section>
                <section class="space-y-3 rounded-xl border bg-card p-5">
                    <h2 class="text-lg font-semibold">Закрытые продажи</h2>
                    <p class="text-xs text-muted-foreground">Сумма выигранных сделок по фактическому месяцу закрытия.</p>
                    <p v-if="!actual.length" class="text-sm text-muted-foreground">Нет выигранных сделок с датой закрытия</p>
                    <p v-for="row in actual" :key="row.month" class="flex justify-between gap-2 text-sm">
                        <span>{{ row.month }} · {{ row.count }}</span><span>{{ money.format(Number(row.amount)) }}</span>
                    </p>
                </section>
                <section class="space-y-3 rounded-xl border bg-card p-5">
                    <h2 class="text-lg font-semibold">История прогноза</h2>
                    <p class="text-xs text-muted-foreground">Срезы открытых сделок на момент сохранения. Фильтр по дате создания к ним не применяется.</p>
                    <form v-if="forecastHistory.days.length" class="flex flex-wrap items-end gap-2 text-sm" @submit.prevent="history.get(route('reports'), { preserveScroll: true })">
                        <label class="grid gap-1">Дата среза
                            <select v-model="history.capture" class="rounded-md border bg-background px-2 py-1">
                                <option v-for="day in forecastHistory.days" :key="day" :value="day">{{ day }}</option>
                            </select>
                        </label>
                        <button :disabled="history.processing" class="rounded-md border px-3 py-1">Показать</button>
                    </form>
                    <p v-if="!Object.keys(forecastHistory.totals).length" class="text-sm text-muted-foreground">За выбранную дату нет доступного прогноза</p>
                    <p v-for="(amount, month) in forecastHistory.totals" :key="month" class="flex justify-between gap-2 text-sm"><span>{{ month }}</span><span>{{ money.format(amount / 100) }}</span></p>
                </section>
            </div>
        </main>
    </AppLayout>
</template>
