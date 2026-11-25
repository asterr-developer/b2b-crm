<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';

type Stage = { name: string; kind: string; requires_date: boolean };
const props = defineProps<{ pipeline?: { id: number; name: string; stages: Stage[] }; locked?: boolean }>();
const defaults: Stage[] = [
    { name: 'Новая', kind: 'new', requires_date: false },
    { name: 'Квалификация', kind: 'qualified', requires_date: false },
    { name: 'Предложение', kind: 'proposal', requires_date: false },
    { name: 'Выиграна', kind: 'won', requires_date: false },
    { name: 'Проиграна', kind: 'lost', requires_date: false },
];
const form = useForm({ name: props.pipeline?.name ?? '', stages: (props.pipeline?.stages ?? defaults).map((stage) => ({ ...stage })) });
const kinds: Record<string, string> = { new: 'Начальная', qualified: 'Квалификация', proposal: 'Предложение', won: 'Выигрыш', lost: 'Проигрыш' };

function add(kind: 'qualified' | 'proposal') {
    const last = form.stages.findLastIndex((stage) => stage.kind === kind);
    form.stages.splice(last + 1, 0, { name: '', kind, requires_date: false });
}
function save() {
    if (props.pipeline) {
        form.patch(route('pipelines.update', { pipeline: props.pipeline.id }), { preserveScroll: true });
    } else {
        form.post(route('pipelines.store'));
    }
}
</script>

<template>
    <form class="grid gap-3 text-sm" @submit.prevent="save">
        <label class="grid max-w-lg gap-1">Название воронки <input v-model="form.name" required maxlength="100" class="w-full rounded-md border bg-background px-2 py-1.5" /></label>
        <p v-if="locked" class="text-muted-foreground">В занятой воронке можно менять названия и требования к этапам.</p>
        <div v-for="(stage, index) in form.stages" :key="index" class="grid items-end gap-2 rounded-md border p-2 sm:grid-cols-[1fr_2fr_auto]">
            <label class="grid gap-1">{{ kinds[stage.kind] }}
                <input v-model="stage.name" required maxlength="50" class="min-w-0 rounded-md border bg-background px-2 py-1.5" />
            </label>
            <label class="flex gap-2 py-2"><input v-model="stage.requires_date" type="checkbox" /> Требовать плановую дату</label>
            <button v-if="!locked && form.stages.filter((entry) => entry.kind === stage.kind).length > 1" type="button" class="py-2 underline" @click="form.stages.splice(index, 1)">Убрать</button>
        </div>
        <p v-for="error in form.errors" :key="error" role="alert" class="text-destructive">{{ error }}</p>
        <div class="flex flex-wrap gap-2">
            <button v-if="!locked" type="button" :disabled="form.stages.length >= 12" class="rounded-md border px-3 py-2" @click="add('qualified')">Добавить этап квалификации</button>
            <button v-if="!locked" type="button" :disabled="form.stages.length >= 12" class="rounded-md border px-3 py-2" @click="add('proposal')">Добавить этап предложения</button>
            <button :disabled="form.processing" class="rounded-md bg-primary px-3 py-2 text-primary-foreground">Сохранить воронку</button>
        </div>
    </form>
</template>
