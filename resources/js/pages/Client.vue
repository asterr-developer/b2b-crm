<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    company: { id: number; name: string };
    contacts: { data: { id: number; name: string; email: string }[]; prev_page_url: string | null; next_page_url: string | null };
    deals: { id: number; title: string; stage: string }[];
    stages: Record<string, string>;
    aliases: string[];
    canMerge: boolean;
    mergeSearch: string;
    mergeCandidates: { id: number; name: string }[];
}>();
const lookup = useForm({ merge_search: props.mergeSearch });
const merge = useForm({ target: '', confirmed: false });
</script>

<template>
    <Head :title="company.name" />
    <AppLayout :breadcrumbs="[{ title: 'Клиенты', href: '/clients' }, { title: company.name, href: route('clients.show', { company: company.id }) }]">
        <main class="mx-auto w-full max-w-6xl space-y-5 p-4 md:p-8">
            <Link :href="route('clients.index')" class="text-sm underline">Все клиенты</Link>
            <header class="moon-header"><h1 class="break-words text-3xl font-semibold">{{ company.name }}</h1></header>
            <p v-if="aliases.length" class="break-words text-sm text-muted-foreground">Объединённые записи — {{ aliases.join(', ') }}</p>
            <div class="grid items-start gap-4 lg:grid-cols-2">
                <section class="space-y-2 moon-panel p-4">
                    <h2 class="text-lg font-semibold">Контакты</h2>
                    <p v-if="!contacts.data.length" class="text-sm text-muted-foreground">Контактов пока нет</p>
                    <ul class="divide-y text-sm">
                        <li v-for="contact in contacts.data" :key="contact.id" class="break-words py-2">{{ contact.name }} · {{ contact.email }}</li>
                    </ul>
                    <nav v-if="contacts.prev_page_url || contacts.next_page_url" aria-label="Страницы контактов" class="flex gap-4 text-sm">
                        <Link v-if="contacts.prev_page_url" :href="contacts.prev_page_url" class="underline">Предыдущая</Link>
                        <Link v-if="contacts.next_page_url" :href="contacts.next_page_url" class="underline">Следующая</Link>
                    </nav>
                </section>
                <section class="space-y-2 moon-panel p-4">
                    <h2 class="text-lg font-semibold">Последние доступные сделки</h2>
                    <p v-if="!deals.length" class="text-sm text-muted-foreground">Сделок пока нет</p>
                    <ul class="divide-y text-sm">
                        <li v-for="deal in deals" :key="deal.id" class="flex flex-wrap justify-between gap-2 py-2">
                            <Link :href="route('deals.show', { deal: deal.id })" class="break-words underline">{{ deal.title }}</Link>
                            <span class="text-muted-foreground">{{ stages[deal.stage] }}</span>
                        </li>
                    </ul>
                </section>
            </div>
            <details v-if="canMerge" class="space-y-3 moon-panel p-4">
                <summary class="cursor-pointer font-medium">Объединить с другой компанией</summary>
                <form class="flex flex-wrap items-end gap-2 text-sm" @submit.prevent="lookup.get(route('clients.show', { company: company.id }), { preserveState: true, preserveScroll: true })">
                    <label class="grid gap-1">Найти основную компанию
                        <input v-model="lookup.merge_search" maxlength="100" class="rounded-md border bg-background px-2 py-2" />
                    </label>
                    <button :disabled="lookup.processing" class="rounded-md border px-3 py-2">Найти</button>
                </form>
                <form class="grid gap-3 text-sm" @submit.prevent="merge.post(route('clients.merge', { company: company.id }))">
                    <label class="grid max-w-md gap-1">Компания, которую оставить
                        <select v-model="merge.target" required class="w-full rounded-md border bg-background px-2 py-2" @change="merge.confirmed = false">
                            <option value="" disabled>Выберите компанию</option>
                            <option v-for="candidate in mergeCandidates" :key="candidate.id" :value="String(candidate.id)">{{ candidate.name }} · #{{ candidate.id }}</option>
                        </select>
                    </label>
                    <label class="flex items-start gap-2"><input v-model="merge.confirmed" type="checkbox" required /> Архивировать {{ company.name }} и перенести её заявки, сделки и контакты к выбранной компании.</label>
                    <p v-for="error in merge.errors" :key="error" role="alert" class="text-destructive">{{ error }}</p>
                    <button :disabled="merge.processing || !merge.target || !merge.confirmed" class="justify-self-start rounded-md border px-3 py-2 disabled:opacity-50">Подтвердить объединение</button>
                </form>
            </details>
        </main>
    </AppLayout>
</template>
