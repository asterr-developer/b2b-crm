<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Check, ClipboardList } from 'lucide-vue-next';

type Filter = 'open' | 'overdue' | 'completed';
type Task = {
    id: number;
    body: string;
    due_at: string | null;
    completed_at: string | null;
    automation_key: string | null;
    deal: { id: number; title: string; assignee: { name: string } | null };
};

const props = defineProps<{
    filter: Filter;
    now: string;
    tasks: { data: Task[]; total: number; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null };
}>();

const tabs: { key: Filter; label: string; empty: string }[] = [
    { key: 'open', label: 'Открытые', empty: 'Открытых задач нет' },
    { key: 'overdue', label: 'Просроченные', empty: 'Просроченных задач нет' },
    { key: 'completed', label: 'Выполненные', empty: 'Выполненных задач пока нет' },
];
const form = useForm({});
const dateTime = new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium', timeStyle: 'short' });

function complete(task: Task) {
    form.patch(route('deals.complete', { deal: task.deal.id, activity: task.id, task_filter: props.filter }), { preserveScroll: true });
}
</script>

<template>
    <Head title="Задачи" />
    <AppLayout :breadcrumbs="[{ title: 'Задачи', href: '/tasks' }]">
        <main class="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-8">
            <header class="moon-header">
                <h1 class="text-3xl font-semibold tracking-tight">Задачи</h1>
                <p class="mt-1 text-sm text-muted-foreground">Действия по сделкам и напоминания о коммерческих предложениях</p>
            </header>
            <nav aria-label="Фильтр задач" class="flex flex-wrap gap-2">
                <Link
                    v-for="tab in tabs" :key="tab.key"
                    :href="route('tasks', { filter: tab.key })" :aria-current="filter === tab.key ? 'page' : undefined"
                    class="rounded-md border px-3 py-2 text-sm"
                    :class="filter === tab.key ? 'bg-primary text-primary-foreground' : 'bg-card hover:bg-muted'"
                >{{ tab.label }}</Link>
            </nav>
            <div v-if="!tasks.total" class="space-y-2 moon-panel p-6">
                <ClipboardList class="size-5 text-muted-foreground" aria-hidden="true" />
                <p class="font-medium">{{ tabs.find((tab) => tab.key === filter)?.empty }}</p>
                <Link :href="route('dashboard')" class="text-sm underline">Открыть воронку продаж</Link>
            </div>
            <ul v-else class="divide-y moon-panel">
                <li v-for="task in tasks.data" :key="task.id" class="flex flex-wrap items-start justify-between gap-4 p-4">
                    <div class="min-w-0 space-y-1">
                        <p class="whitespace-pre-wrap break-words font-medium">{{ task.body }}</p>
                        <p class="text-sm text-muted-foreground">
                            <Link :href="route('deals.show', { deal: task.deal.id })" class="underline">{{ task.deal.title }}</Link>
                            <span v-if="task.deal.assignee"> · {{ task.deal.assignee.name }}</span>
                            <span v-if="task.automation_key"> · Напоминание</span>
                        </p>
                        <p v-if="task.completed_at" class="text-xs text-muted-foreground">Выполнено {{ dateTime.format(new Date(task.completed_at)) }}</p>
                        <p v-else-if="task.due_at" class="text-xs" :class="Date.parse(task.due_at) < Date.parse(now) ? 'text-destructive' : 'text-muted-foreground'">
                            {{ Date.parse(task.due_at) < Date.parse(now) ? 'Просрочено с' : 'Срок' }} {{ dateTime.format(new Date(task.due_at)) }}
                        </p>
                        <p v-else class="text-xs text-muted-foreground">Без срока</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                    <a v-if="task.due_at" :href="route('tasks.calendar', { task: task.id })" class="rounded-md border px-3 py-2 text-sm hover:bg-muted">Скачать в календарь</a>
                    <button
                        v-if="!task.completed_at" type="button" :disabled="form.processing"
                        class="inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm hover:bg-muted disabled:opacity-50"
                        @click="complete(task)"
                    ><Check class="size-4" aria-hidden="true" /> Отметить выполненной</button>
                    </div>
                </li>
            </ul>
            <nav v-if="tasks.last_page > 1" aria-label="Страницы задач" class="flex flex-wrap items-center gap-4 text-sm">
                <Link v-if="tasks.prev_page_url" :href="tasks.prev_page_url" class="underline">Предыдущая</Link>
                <span>Страница {{ tasks.current_page }} из {{ tasks.last_page }}</span>
                <Link v-if="tasks.next_page_url" :href="tasks.next_page_url" class="underline">Следующая</Link>
            </nav>
        </main>
    </AppLayout>
</template>
