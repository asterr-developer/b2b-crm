<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ChartNoAxesColumn, Layers3, Plus, Search, UsersRound } from 'lucide-vue-next';
import { ref } from 'vue';

type Assignee = { id: number; name: string };

type Deal = {
    id: number;
    title: string;
    amount: string;
    stage: string;
    revision: number;
    created_at: string;
    stage_entered_at: string | null;
    open_tasks_count: number;
    next_task_due_at: string | null;
    assignee: Assignee | null;
    contact: { name: string; company: { name: string } };
};

type PipelineSettings = { close_date_for_proposal?: boolean; reason_for_loss?: boolean; reminder_days?: number; auto_task_stage?: string; auto_task_title?: string; auto_task_days?: number };
const props = defineProps<{ deals: Deal[]; stages: Record<string, string>; transitions: Record<string, string[]>; assignees: Assignee[]; settings: PipelineSettings | null; stageCounts: Record<string, number>; search: string; hasDepartment: boolean }>();
const breadcrumbs = [{ title: 'Воронка продаж', href: '/dashboard' }];
const showForm = ref(false);
const dragged = ref<Deal>();
const newDeal = useForm({ company: '', contact: '', email: '', title: '', amount: '' });
const transition = useForm({ stage: '', revision: 0 });
const assignment = useForm({ assignee_id: 0, revision: 0 });
const lookup = useForm({ search: props.search });
const stageTone: Record<string, string> = { new: 'sky', qualified: 'violet', proposal: 'amber', won: 'emerald', lost: 'rose' };
const settingsForm = useForm({
    labels: { ...props.stages },
    close_date_for_proposal: props.settings?.close_date_for_proposal ?? false,
    reason_for_loss: props.settings?.reason_for_loss ?? false,
    reminder_days: props.settings?.reminder_days ?? 0,
    auto_task_stage: props.settings?.auto_task_stage ?? '',
    auto_task_title: props.settings?.auto_task_title ?? '',
    auto_task_days: props.settings?.auto_task_days ?? 0,
});
const fields = [
    { key: 'title', label: 'Название сделки', type: 'text' },
    { key: 'company', label: 'Компания', type: 'text' },
    { key: 'contact', label: 'Контактное лицо', type: 'text' },
    { key: 'email', label: 'Электронная почта', type: 'email' },
    { key: 'amount', label: 'Сумма, ₽', type: 'number' },
] as const;
const currency = new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' });

function createDeal() {
    newDeal.post(route('deals.store'), {
        preserveScroll: true,
        onSuccess: () => {
            newDeal.reset();
            showForm.value = false;
        },
    });
}

function moveDeal(deal: Deal, stage: string) {
    if (stage === deal.stage || transition.processing) return;

    transition.clearErrors();
    transition.stage = stage;
    transition.revision = deal.revision;
    transition.patch(route('deals.move', { deal: deal.id }), { preserveScroll: true });
}

function drop(stage: string) {
    if (dragged.value) moveDeal(dragged.value, stage);
    dragged.value = undefined;
}

function assignDeal(deal: Deal, event: Event) {
    const select = event.target as HTMLSelectElement;
    const assignee = Number(select.value);
    select.value = String(deal.assignee?.id ?? '');

    assignment.clearErrors();
    assignment.assignee_id = assignee;
    assignment.revision = deal.revision;
    assignment.patch(route('deals.assign', { deal: deal.id }), { preserveScroll: true });
}
</script>

<template>
    <Head title="Воронка продаж" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <main class="sales-workspace mx-auto w-full min-w-0 max-w-[100rem] space-y-5 p-4 md:p-8">
            <header class="sales-hero flex flex-wrap items-end justify-between gap-6 rounded-2xl px-5 py-7 sm:px-8 sm:py-9">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">Воронка продаж</h1>
                    <p class="mt-2 text-sm text-indigo-100">Сделок в выборке — {{ Object.values(stageCounts).reduce((sum, count) => sum + Number(count), 0) }}</p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-2.5 text-sm font-semibold text-slate-950 shadow-sm hover:bg-indigo-50 focus-visible:outline-white"
                    :aria-expanded="showForm"
                    @click="showForm = !showForm"
                >
                    <Plus class="size-4" aria-hidden="true" />
                    {{ showForm ? 'Свернуть форму' : 'Новая сделка' }}
                </button>
            </header>

            <div class="flex flex-wrap items-end justify-between gap-4 rounded-xl border bg-card p-3 sm:p-4">
            <form class="flex flex-wrap items-end gap-2 text-sm" @submit.prevent="lookup.get(route('dashboard'), { preserveScroll: true })">
                <label class="relative grid min-w-0 gap-1">
                    <span class="sr-only">Сделка или компания</span>
                    <Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-muted-foreground" aria-hidden="true" />
                    <input v-model="lookup.search" type="search" placeholder="Сделка или компания" maxlength="100" class="w-full rounded-lg border border-input bg-background py-2 pl-9 pr-3 sm:w-64" />
                </label>
                <button class="rounded-md border px-3 py-2" :disabled="lookup.processing">Найти</button>
                <Link v-if="search" :href="route('dashboard')" class="px-2 py-2 underline">Сбросить</Link>
                <p v-if="lookup.errors.search" role="alert" class="text-destructive">{{ lookup.errors.search }}</p>
            </form>
            <nav aria-label="Разделы продаж" class="flex flex-wrap gap-1 text-sm">
                <Link :href="route('reports')" class="sales-shortcut"><ChartNoAxesColumn class="size-4" aria-hidden="true" /> Отчёты</Link>
                <Link v-if="settings !== null" :href="route('team.index')" class="sales-shortcut"><UsersRound class="size-4" aria-hidden="true" /> Отдел продаж</Link>
                <Link v-if="hasDepartment" :href="route('pipelines.index')" class="sales-shortcut"><Layers3 class="size-4" aria-hidden="true" /> Воронки отдела</Link>
            </nav>
            </div>
            <details v-if="settings !== null" class="rounded-xl border bg-card p-4">
                <summary class="cursor-pointer font-medium">Настройки воронки отдела</summary>
                <form class="mt-4 grid gap-3 text-sm" @submit.prevent="settingsForm.patch(route('pipeline.settings'), { preserveScroll: true })">
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                        <label v-for="(label, stage) in stages" :key="stage" class="grid gap-1">
                            {{ label }}
                            <input v-model="settingsForm.labels[stage]" required maxlength="50" class="w-full rounded-md border bg-background px-2 py-1" />
                        </label>
                    </div>
                    <label><input v-model="settingsForm.close_date_for_proposal" type="checkbox" /> Требовать дату закрытия на этапе предложения</label>
                    <label><input v-model="settingsForm.reason_for_loss" type="checkbox" /> Требовать причину при проигрыше</label>
                    <label class="flex flex-wrap items-center gap-2">
                        Напомнить через дней после отправки (0 — выключено)
                        <input v-model="settingsForm.reminder_days" type="number" min="0" max="90" required class="w-20 rounded-md border bg-background px-2 py-1" />
                    </label>
                    <fieldset class="grid gap-2 rounded-md border p-3 sm:grid-cols-3">
                        <legend class="px-1 font-medium">Задача при переходе на этап</legend>
                        <label class="grid gap-1">Этап
                            <select v-model="settingsForm.auto_task_stage" class="rounded-md border bg-background px-2 py-1">
                                <option value="">Выключено</option>
                                <option value="qualified">{{ stages.qualified }}</option>
                                <option value="proposal">{{ stages.proposal }}</option>
                            </select>
                        </label>
                        <template v-if="settingsForm.auto_task_stage">
                            <label class="grid gap-1">Задача <input v-model="settingsForm.auto_task_title" required maxlength="255" class="rounded-md border bg-background px-2 py-1" /></label>
                            <label class="grid gap-1">Выполнить через дней <input v-model="settingsForm.auto_task_days" required type="number" min="0" max="90" class="rounded-md border bg-background px-2 py-1" /></label>
                        </template>
                    </fieldset>
                    <p v-for="error in settingsForm.errors" :key="error" role="alert" class="text-destructive">{{ error }}</p>
                    <button :disabled="settingsForm.processing" class="justify-self-start rounded-lg bg-primary px-4 py-2 text-primary-foreground">Сохранить настройки</button>
                </form>
            </details>

            <form v-if="showForm" class="grid gap-4 rounded-xl border bg-card p-5 sm:grid-cols-2" @submit.prevent="createDeal">
                <label v-for="field in fields" :key="field.key" class="space-y-1.5 text-sm">
                    <span>{{ field.label }}</span>
                    <input
                        v-model="newDeal[field.key]"
                        :type="field.type === 'number' ? 'text' : field.type"
                        :inputmode="field.type === 'number' ? 'decimal' : undefined"
                        required
                        class="block w-full rounded-md border bg-background px-3 py-2"
                    />
                    <span v-if="newDeal.errors[field.key]" class="block text-destructive">{{ newDeal.errors[field.key] }}</span>
                </label>
                <div class="sm:col-span-2">
                    <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm text-primary-foreground disabled:opacity-50" :disabled="newDeal.processing">
                        Создать сделку
                    </button>
                </div>
            </form>

            <p v-if="transition.errors.stage" role="alert" class="rounded-lg border border-destructive p-3 text-sm text-destructive">
                {{ transition.errors.stage }}
            </p>
            <p v-if="assignment.errors.assignee_id" role="alert" class="rounded-lg border border-destructive p-3 text-sm text-destructive">
                {{ assignment.errors.assignee_id }}
            </p>

            <div class="sales-board grid grid-cols-1 items-stretch gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <section
                    v-for="(label, stage) in stages"
                    :key="stage"
                    class="sales-column min-w-0 rounded-xl border p-3"
                    :data-tone="stageTone[stage]"
                    :class="{ 'ring-2 ring-primary/40': dragged && props.transitions[dragged.stage]?.includes(stage) }"
                    @dragover.prevent
                    @drop.prevent="drop(stage)"
                >
                    <h2 class="mb-3 flex items-center justify-between gap-2 px-1 text-sm font-semibold">
                        <span class="flex min-w-0 items-center gap-2 break-words"><span class="sales-stage-dot size-2 shrink-0 rounded-full" aria-hidden="true" />{{ label }}</span>
                        <span class="rounded-md bg-background px-1.5 py-0.5 text-xs tabular-nums text-muted-foreground">{{ stageCounts[stage] ?? 0 }}</span>
                    </h2>
                    <ul class="space-y-2">
                        <li
                            v-for="deal in deals.filter((item) => item.stage === stage)"
                            :key="deal.id"
                            class="space-y-3 rounded-lg border bg-card p-3 shadow-sm transition-colors hover:border-primary/50"
                            :draggable="Boolean(transitions[deal.stage]?.length)"
                            @dragstart="dragged = deal"
                            @dragend="dragged = undefined"
                        >
                            <div>
                                <Link :href="route('deals.show', { deal: deal.id })" class="break-words text-sm font-semibold hover:underline">{{ deal.title }}</Link>
                                <p class="mt-1 break-words text-xs text-muted-foreground">{{ deal.contact.company.name }} · {{ deal.contact.name }}</p>
                            </div>
                            <p class="text-sm font-medium tabular-nums">{{ currency.format(Number(deal.amount)) }}</p>
                            <p v-if="transitions[deal.stage]?.length" class="text-xs text-muted-foreground">
                                {{ deal.next_task_due_at ? 'Следующая задача — ' + deal.next_task_due_at.slice(0, 10) : deal.open_tasks_count ? 'Есть задача без срока' : 'Нет следующей задачи' }}
                            </p>
                            <label v-if="assignees.length" class="block space-y-1 text-xs text-muted-foreground">
                                <span>Ответственный</span>
                                <select
                                    :aria-label="'Ответственный за сделку «' + deal.title + '»'"
                                    :value="deal.assignee?.id"
                                    :disabled="assignment.processing"
                                    class="w-full rounded-md border bg-background px-2 py-1.5"
                                    @change="assignDeal(deal, $event)"
                                >
                                    <option v-for="person in assignees" :key="person.id" :value="person.id">{{ person.name }}</option>
                                </select>
                            </label>
                            <p v-else-if="deal.assignee" class="text-xs text-muted-foreground">Ответственный — {{ deal.assignee.name }}</p>
                            <select
                                v-if="transitions[deal.stage]?.length"
                                :aria-label="'Переместить сделку «' + deal.title + '»'"
                                :disabled="transition.processing"
                                class="w-full rounded-md border bg-background px-2 py-1.5 text-xs"
                                @change="moveDeal(deal, ($event.target as HTMLSelectElement).value); ($event.target as HTMLSelectElement).value = ''"
                            >
                                <option disabled value="" selected>Переместить</option>
                                <option v-for="target in transitions[deal.stage]" :key="target" :value="target">{{ stages[target] }}</option>
                            </select>
                        </li>
                    </ul>
                    <p v-if="!deals.some((deal) => deal.stage === stage)" class="rounded-lg border border-dashed px-2 py-5 text-center text-xs text-muted-foreground">Нет сделок</p>
                    <p v-if="(stageCounts[stage] ?? 0) > 50" class="px-1 py-3 text-xs text-muted-foreground">Показаны 50 последних. Для остальных используйте поиск.</p>
                </section>
            </div>
        </main>
    </AppLayout>
</template>

<style scoped>
.sales-workspace { container-type: inline-size; }
@container (min-width: 820px) {
    .sales-board { grid-template-columns: repeat(5, minmax(0, 1fr)); }
}
.sales-hero {
    color: #fff;
    background:
        repeating-radial-gradient(ellipse at 100% 180%, transparent 0 35px, rgb(190 202 255 / 12%) 36px 37px, transparent 38px 60px),
        linear-gradient(110deg, #14192f, #303d66);
}
.sales-shortcut {
    @apply inline-flex items-center gap-2 rounded-lg px-2.5 py-2 font-medium text-muted-foreground hover:bg-accent hover:text-accent-foreground;
}
.sales-column { background: color-mix(in srgb, hsl(var(--muted)) 80%, var(--stage-color) 5%); }
.sales-stage-dot { background: var(--stage-color); }
.sales-column { --stage-color: #5599cc; }
.sales-column[data-tone='violet'] { --stage-color: #9273cd; }
.sales-column[data-tone='amber'] { --stage-color: #bf8b31; }
.sales-column[data-tone='emerald'] { --stage-color: #389b73; }
.sales-column[data-tone='rose'] { --stage-color: #cd707d; }
</style>
