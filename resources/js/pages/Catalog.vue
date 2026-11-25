<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

type Item = { id: number; name: string; price: string };
defineProps<{ items: { data: Item[]; prev_page_url: string | null; next_page_url: string | null }; canEdit: boolean }>();
const entry = useForm({ name: '', price: '' });
const money = new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' });

function edit(item: Item) {
    entry.name = item.name;
    entry.price = item.price;
}
</script>

<template>
    <Head title="Каталог услуг" />
    <AppLayout :breadcrumbs="[{ title: 'Каталог услуг', href: '/catalog' }]">
        <main class="mx-auto w-full max-w-6xl space-y-5 p-4 md:p-8">
            <header class="moon-header"><h1 class="text-3xl font-semibold tracking-tight">Каталог услуг</h1></header>
            <p class="text-sm text-muted-foreground">Цены для новых предложений. В ранее созданных версиях сохраняются их исходные суммы.</p>
            <form v-if="canEdit" class="grid items-end gap-3 moon-panel p-4 sm:grid-cols-[2fr_1fr_auto]" @submit.prevent="entry.post(route('catalog.store'), { preserveScroll: true, onSuccess: () => entry.reset() })">
                <label class="grid gap-1 text-sm">Название услуги <input v-model="entry.name" required maxlength="255" class="w-full rounded-md border bg-background px-3 py-2" /></label>
                <label class="grid gap-1 text-sm">Цена, ₽ <input v-model="entry.price" inputmode="decimal" required class="w-full rounded-md border bg-background px-3 py-2" /></label>
                <button :disabled="entry.processing" class="rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground">Сохранить цену</button>
                <p v-for="error in entry.errors" :key="error" class="text-sm text-destructive sm:col-span-3" role="alert">{{ error }}</p>
            </form>
            <p v-if="!items.data.length" class="moon-panel p-5 text-sm">Услуги пока не добавлены</p>
            <ul v-else class="divide-y moon-panel">
                <li v-for="item in items.data" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 p-4">
                    <span class="break-words font-medium">{{ item.name }}</span>
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <span>{{ money.format(Number(item.price)) }}</span>
                        <button v-if="canEdit" class="underline" @click="edit(item)">Изменить цену</button>
                    </div>
                </li>
            </ul>
            <nav v-if="items.prev_page_url || items.next_page_url" aria-label="Страницы каталога" class="flex gap-4 text-sm">
                <Link v-if="items.prev_page_url" :href="items.prev_page_url" class="underline">Предыдущая</Link>
                <Link v-if="items.next_page_url" :href="items.next_page_url" class="underline">Следующая</Link>
            </nav>
        </main>
    </AppLayout>
</template>
