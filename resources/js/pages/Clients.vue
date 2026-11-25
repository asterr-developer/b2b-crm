<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

type Result = { total: number; valid: number; companies: number; contacts: number; errors: string[] };
const props = defineProps<{
    companies: { data: { id: number; name: string; contacts_count: number }[]; prev_page_url: string | null; next_page_url: string | null };
    search: string;
    canImport: boolean;
    preview: Result | null;
    imported: Result | null;
}>();

const lookup = useForm({ search: props.search });
const importing = useForm<{ file: File | null; mode: 'preview' | 'apply' }>({ file: null, mode: 'preview' });

function pickFile(event: Event) {
    importing.file = (event.target as HTMLInputElement).files?.item(0) ?? null;
    importing.mode = 'preview';
    importing.clearErrors();
}

function submit(mode: 'preview' | 'apply') {
    importing.mode = mode;
    importing.post(route('clients.import'), { preserveScroll: true, forceFormData: true });
}
</script>

<template>
    <Head title="Клиенты" />
    <AppLayout :breadcrumbs="[{ title: 'Клиенты', href: '/clients' }]">
        <main class="mx-auto w-full max-w-6xl space-y-5 p-4 md:p-8">
            <header class="moon-header flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-3xl font-semibold tracking-tight">Клиенты</h1>
                <Link :href="route('group-requests.index')" class="text-sm underline">Заявки на размещение</Link>
            </header>
            <form class="moon-toolbar flex flex-wrap items-end gap-2 text-sm" @submit.prevent="lookup.get(route('clients.index'))">
                <label class="grid gap-1">Компания <input v-model="lookup.search" type="search" maxlength="100" class="rounded-md border bg-background px-3 py-2" /></label>
                <button class="rounded-md border px-3 py-2" :disabled="lookup.processing">Найти</button>
                <Link v-if="search" :href="route('clients.index')" class="py-2 underline">Сбросить</Link>
                <p v-if="lookup.errors.search" class="text-destructive" role="alert">{{ lookup.errors.search }}</p>
            </form>

            <details v-if="canImport" :open="Boolean(preview || imported || importing.errors.file)" class="rounded-xl border bg-card p-4">
                <summary class="cursor-pointer font-medium">Импорт клиентов из CSV</summary>
                <form class="mt-4 space-y-3 text-sm" @submit.prevent="submit('preview')">
                    <label class="grid gap-1">
                        CSV в UTF-8 с заголовками company,contact,email
                        <input type="file" accept=".csv,text/csv" required class="w-full" @change="pickFile" />
                    </label>
                    <p v-if="importing.errors.file" class="text-destructive" role="alert">{{ importing.errors.file }}</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="submit" :disabled="importing.processing" class="rounded-md border px-3 py-2">Проверить файл</button>
                        <button v-if="preview?.valid && importing.file" type="button" :disabled="importing.processing" class="rounded-md bg-primary px-3 py-2 text-primary-foreground" @click="submit('apply')">Импортировать корректные строки</button>
                    </div>
                </form>
                <p v-if="preview" class="mt-3 text-sm" role="status">Готово к импорту {{ preview.valid }} из {{ preview.total }} строк.</p>
                <p v-if="imported" class="mt-3 text-sm" role="status">Создано компаний — {{ imported.companies }}, контактов — {{ imported.contacts }}.</p>
                <ul v-if="preview?.errors.length || imported?.errors.length" class="mt-2 list-inside list-disc text-sm text-destructive">
                    <li v-for="error in (preview ?? imported)?.errors" :key="error">{{ error }}</li>
                </ul>
            </details>

            <p v-if="!companies.data.length" class="moon-empty">Компании не найдены.</p>
            <div v-else class="md:moon-panel">
            <div aria-hidden="true" class="hidden grid-cols-[minmax(0,1fr)_8rem] rounded-t-2xl border-b bg-muted/50 px-5 py-3 text-xs font-semibold text-muted-foreground md:grid">
                <span>Компания</span><span class="text-right">Контакты</span>
            </div>
            <ul class="grid gap-2 md:block md:divide-y">
                <li v-for="company in companies.data" :key="company.id" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-3 rounded-xl border bg-card px-4 py-4 md:grid-cols-[minmax(0,1fr)_8rem] md:rounded-none md:border-x-0 md:border-b-0 md:bg-transparent md:px-5 md:first:border-t-0">
                    <Link :href="route('clients.show', { company: company.id })" class="break-words font-medium hover:text-primary hover:underline">{{ company.name }}</Link>
                    <p class="text-right text-sm tabular-nums text-muted-foreground"><span class="md:sr-only">Контактов · </span>{{ company.contacts_count }}</p>
                </li>
            </ul>
            </div>
            <nav v-if="companies.prev_page_url || companies.next_page_url" aria-label="Страницы компаний" class="flex gap-4 text-sm">
                <Link v-if="companies.prev_page_url" :href="companies.prev_page_url" class="underline">Предыдущая</Link>
                <Link v-if="companies.next_page_url" :href="companies.next_page_url" class="underline">Следующая</Link>
            </nav>
        </main>
    </AppLayout>
</template>
