<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps<{ token: string; department: string }>();
const form = useForm<{ membership?: string }>({});
</script>

<template>
    <Head title="Приглашение в отдел" />
    <AppLayout>
        <main class="mx-auto w-full max-w-lg space-y-4 p-4 md:p-8">
            <header class="moon-header"><h1 class="text-2xl font-semibold">Приглашение в отдел {{ department }}</h1></header>
            <form class="space-y-2" @submit.prevent="form.post(route('team.accept', { token }))">
                <p v-if="form.errors.membership" role="alert" class="text-sm text-destructive">{{ form.errors.membership }}</p>
                <button :disabled="form.processing" class="rounded-md bg-primary px-3 py-2 text-sm text-primary-foreground">Присоединиться как менеджер</button>
            </form>
        </main>
    </AppLayout>
</template>
