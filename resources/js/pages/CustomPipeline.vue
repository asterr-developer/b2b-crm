<script setup lang="ts">
import PipelineEditor from '@/components/PipelineEditor.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

type Stage = { name: string; kind: string; requires_date: boolean };
type Deal = { id: number; title: string; revision: number; amount: string; assignee: { name: string } | null };
const props = defineProps<{
    pipeline: { id: number; name: string; stages: Stage[] };
    canManage: boolean;
    structureLocked: boolean;
    deals: Deal[][];
    counts: number[];
    available: { id: number; title: string; revision: number }[];
}>();
const transfer = useForm({ deal: '', revision: 0 });
const movement = useForm<{ step: number; revision: number; stage?: string }>({ step: 0, revision: 0 });
const money = new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' });

function attach() {
    const selected = props.available.find((deal) => String(deal.id) === transfer.deal);
    if (!selected) return;
    transfer.revision = selected.revision;
    transfer.post(route('pipelines.attach', { pipeline: props.pipeline.id }), { onSuccess: () => transfer.reset() });
}

function move(deal: Deal, step: number) {
    movement.step = step;
    movement.revision = deal.revision;
    movement.patch(route('pipelines.move', { pipeline: props.pipeline.id, deal: deal.id }), { preserveScroll: true });
}
</script>

<template>
    <Head :title="pipeline.name" />
    <AppLayout :breadcrumbs="[{ title: 'Воронки отдела', href: '/pipelines' }, { title: pipeline.name, href: route('pipelines.show', { pipeline: pipeline.id }) }]">
        <main class="w-full space-y-5 p-4 md:p-8">
            <header class="moon-header"><h1 class="break-words text-3xl font-semibold tracking-tight">{{ pipeline.name }}</h1></header>
            <details v-if="canManage" class="space-y-3 moon-panel p-4">
                <summary class="cursor-pointer font-medium">Настроить этапы</summary>
                <PipelineEditor :pipeline="pipeline" :locked="structureLocked" />
            </details>
            <form v-if="canManage && available.length" class="flex flex-wrap items-end gap-2 text-sm" @submit.prevent="attach">
                <label class="grid min-w-0 gap-1">Новая сделка из основной воронки
                    <select v-model="transfer.deal" required class="w-full rounded-md border bg-background px-2 py-2">
                        <option value="" disabled>Выберите сделку</option>
                        <option v-for="deal in available" :key="deal.id" :value="String(deal.id)">{{ deal.title }} · #{{ deal.id }}</option>
                    </select>
                </label>
                <button :disabled="transfer.processing" class="rounded-md bg-primary px-3 py-2 text-primary-foreground">Перенести сюда</button>
                <p v-for="error in transfer.errors" :key="error" role="alert" class="w-full text-destructive">{{ error }}</p>
            </form>
            <p v-if="movement.errors.stage" role="alert" class="text-sm text-destructive">{{ movement.errors.stage }}</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                <section v-for="(stage, index) in pipeline.stages" :key="index" class="min-w-0 space-y-3 rounded-xl border bg-muted/30 p-3">
                    <h2 class="flex justify-between gap-2 text-sm font-semibold"><span class="break-words">{{ stage.name }}</span><span>{{ counts[index] }}</span></h2>
                    <p v-if="!deals[index]?.length" class="text-xs text-muted-foreground">Нет сделок</p>
                    <article v-for="deal in deals[index]" :key="deal.id" class="space-y-2 rounded-md border bg-card p-3 text-sm">
                        <Link :href="route('deals.show', { deal: deal.id })" class="break-words font-medium underline">{{ deal.title }}</Link>
                        <p>{{ money.format(Number(deal.amount)) }}</p>
                        <p v-if="deal.assignee" class="text-xs text-muted-foreground">{{ deal.assignee.name }}</p>
                        <div v-if="!['won', 'lost'].includes(stage.kind)" class="flex flex-wrap gap-2 text-xs">
                            <button :disabled="movement.processing" class="rounded border px-2 py-1" @click="move(deal, index + 1)">{{ pipeline.stages[index + 1].name }}</button>
                            <button :disabled="movement.processing" class="rounded border px-2 py-1" @click="move(deal, pipeline.stages.length - 1)">{{ pipeline.stages.at(-1)?.name }}</button>
                        </div>
                    </article>
                    <p v-if="counts[index] > 50" class="text-xs text-muted-foreground">Показаны 50 последних сделок.</p>
                </section>
            </div>
        </main>
    </AppLayout>
</template>
