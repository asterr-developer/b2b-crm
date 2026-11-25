<script setup lang="ts">
import PipelineEditor from '@/components/PipelineEditor.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{ pipelines: { data: { id: number; name: string }[]; prev_page_url: string | null; next_page_url: string | null }; canManage: boolean }>();
</script>

<template>
    <Head title="Воронки отдела" />
    <AppLayout :breadcrumbs="[{ title: 'Воронки отдела', href: '/pipelines' }]">
        <main class="mx-auto w-full max-w-6xl space-y-5 p-4 md:p-8">
            <header class="moon-header"><h1 class="text-3xl font-semibold tracking-tight">Воронки отдела</h1></header>
            <ul class="divide-y moon-panel">
                <li class="p-4"><Link :href="route('dashboard')" class="font-medium underline">Основная воронка</Link></li>
                <li v-for="pipeline in pipelines.data" :key="pipeline.id" class="p-4"><Link :href="route('pipelines.show', { pipeline: pipeline.id })" class="break-words font-medium underline">{{ pipeline.name }}</Link></li>
            </ul>
            <nav v-if="pipelines.prev_page_url || pipelines.next_page_url" aria-label="Страницы воронок" class="flex gap-4 text-sm">
                <Link v-if="pipelines.prev_page_url" :href="pipelines.prev_page_url" class="underline">Предыдущая</Link>
                <Link v-if="pipelines.next_page_url" :href="pipelines.next_page_url" class="underline">Следующая</Link>
            </nav>
            <details v-if="canManage" class="space-y-3 moon-panel p-4">
                <summary class="cursor-pointer font-medium">Создать воронку</summary>
                <PipelineEditor />
            </details>
        </main>
    </AppLayout>
</template>
