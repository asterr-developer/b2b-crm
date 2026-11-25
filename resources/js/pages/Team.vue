<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Member = { id: number; name: string; email: string; sales_role: string };
defineProps<{ department: string; members: { data: Member[]; prev_page_url: string | null; next_page_url: string | null }; invite: string | null }>();
const invitation = useForm({ email: '' });
const removal = useForm<{ member?: string }>({});
const selected = ref<Member>();
</script>

<template>
    <Head title="Отдел продаж" />
    <AppLayout :breadcrumbs="[{ title: 'Отдел продаж', href: '/team' }]">
        <main class="mx-auto w-full max-w-6xl space-y-5 p-4 md:p-8">
            <header class="moon-header"><h1 class="text-3xl font-semibold tracking-tight">{{ department }}</h1></header>
            <section class="space-y-3 moon-panel p-4">
                <h2 class="text-lg font-semibold">Пригласить менеджера</h2>
                <form class="flex flex-wrap items-end gap-2 text-sm" @submit.prevent="invitation.post(route('team.invite'), { onSuccess: () => invitation.reset() })">
                    <label class="grid gap-1">Рабочая почта <input v-model="invitation.email" type="email" required maxlength="255" class="max-w-full rounded-md border bg-background px-3 py-2" /></label>
                    <button :disabled="invitation.processing" class="rounded-md bg-primary px-3 py-2 text-primary-foreground">Создать приглашение</button>
                    <p v-if="invitation.errors.email" role="alert" class="w-full text-destructive">{{ invitation.errors.email }}</p>
                </form>
                <label v-if="invite" class="grid gap-2 text-sm">
                    Передайте эту ссылку сотруднику. Она действует сутки и подходит только для указанной почты.
                    <input :value="invite" readonly class="w-full rounded-md border bg-background px-3 py-2" @focus="($event.target as HTMLInputElement).select()" />
                </label>
            </section>
            <ul class="divide-y moon-panel">
                <li v-for="person in members.data" :key="person.id" class="flex flex-wrap justify-between gap-3 p-4 text-sm">
                    <div class="min-w-0">
                        <p class="break-words font-medium">{{ person.name }}</p>
                        <p class="break-words text-muted-foreground">{{ person.email }} · {{ person.sales_role === 'lead' ? 'Руководитель' : 'Менеджер' }}</p>
                    </div>
                    <button v-if="person.sales_role === 'manager'" type="button" class="underline" @click="selected = person; removal.clearErrors()">Исключить из отдела</button>
                </li>
            </ul>
            <form v-if="selected" class="space-y-2 moon-panel p-4 text-sm" @submit.prevent="removal.delete(route('team.remove', { member: selected.id }), { onSuccess: () => (selected = undefined) })">
                <p>Исключить {{ selected.name }} из отдела?</p>
                <p v-if="removal.errors.member" role="alert" class="text-destructive">{{ removal.errors.member }}</p>
                <div class="flex gap-3">
                    <button class="rounded-md border px-3 py-2" :disabled="removal.processing">Подтвердить</button>
                    <button type="button" class="underline" @click="selected = undefined">Отмена</button>
                </div>
            </form>
            <nav v-if="members.prev_page_url || members.next_page_url" aria-label="Страницы сотрудников" class="flex gap-4 text-sm">
                <Link v-if="members.prev_page_url" :href="members.prev_page_url" class="underline">Предыдущая</Link>
                <Link v-if="members.next_page_url" :href="members.next_page_url" class="underline">Следующая</Link>
            </nav>
        </main>
    </AppLayout>
</template>
