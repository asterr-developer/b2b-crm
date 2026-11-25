<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import StatusPill from '@/components/StatusPill.vue';
import { AlarmClock, BedDouble, FileCheck } from 'lucide-vue-next';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Row = { id:number; title:string; assignee:string; deal_id:number|null; deadline:string; contacted_at:string|null; late:boolean };
const props = defineProps<{
    requests:{data:Row[];prev_page_url:string|null;next_page_url:string|null};
    filter:string;hours:number;waiting:number;late:number;canManage:boolean;
    approvalCount:number;holdCount:number;
    metrics:{total:number;contacted:number;breached:number;median:number|null;p90:number|null};
    tasks:{id:number;body:string;due_at:string|null;deal:{id:number;title:string}}[];
    approvals:{id:number;deal_id:number;title:string;version:number}[];
    holds:{id:number;title:string;expires_at:string}[];
}>();
const settings = useForm({first_response_hours:props.hours});
const contact = useForm({channel:'email',summary:''});
const selected = ref<number|null>(null);
const filters = [
    { key:'waiting', label:'Ждут ответа' },
    { key:'late', label:'Просрочены' },
    { key:'contacted', label:'Ответ зафиксирован' },
];
const date = new Intl.DateTimeFormat('ru-RU', {dateStyle:'short',timeStyle:'short'});
const number = new Intl.NumberFormat('ru-RU', {maximumFractionDigits:1});
const duration = (minutes:number|null) => minutes === null ? '—' : number.format(minutes) + ' мин';
const when = (stamp:string) => date.format(new Date(stamp.includes('T') ? stamp : stamp.replace(' ','T')+'Z'));

function startContact(id:number) {
    contact.reset();
    contact.clearErrors();
    selected.value = id;
}
</script>

<template>
    <Head title="Моя работа" />
    <AppLayout :breadcrumbs="[{title:'Моя работа',href:'/work'}]">
        <main class="mx-auto w-full max-w-7xl space-y-5 p-4 md:p-8">
            <header class="moon-header space-y-2">
                <h1 class="text-3xl font-semibold tracking-tight">Моя работа</h1>
                <p class="text-sm text-muted-foreground">Заявки, задачи, согласования и резервы в одном месте</p>
            </header>
            <div class="grid gap-3 sm:grid-cols-3">
                <Link :href="route('work.index',{filter:'late'})+'#first-response'" class="moon-panel space-y-2 p-4 hover:border-primary/60">
                    <div class="flex items-center justify-between gap-2"><AlarmClock class="size-5 text-destructive" aria-hidden="true" /><StatusPill v-if="late" tone="danger">Срок нарушен</StatusPill></div>
                    <p class="text-2xl font-semibold">{{ late }}</p><p class="text-sm text-muted-foreground">Просроченные заявки</p>
                </Link>
                <a href="#expiring-holds" class="moon-panel space-y-2 p-4 hover:border-primary/60">
                    <BedDouble class="size-5 text-muted-foreground" aria-hidden="true" />
                    <p class="text-2xl font-semibold">{{ holdCount }}</p><p class="text-sm text-muted-foreground">Резервы истекают за 24 часа</p>
                </a>
                <a href="#approval-queue" class="moon-panel space-y-2 p-4 hover:border-primary/60">
                    <FileCheck class="size-5 text-muted-foreground" aria-hidden="true" />
                    <p class="text-2xl font-semibold">{{ approvalCount }}</p><p class="text-sm text-muted-foreground">КП на согласовании</p>
                </a>
            </div>
            <div class="grid items-start gap-4 xl:grid-cols-[2fr_1fr]">
                <section id="first-response" class="moon-panel min-w-0 scroll-mt-4 space-y-4 p-5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-lg font-semibold">Первый ответ на заявку</h2>
                        <StatusPill>{{ waiting }} ждут ответа · срок {{ hours }} ч</StatusPill>
                    </div>
                    <nav aria-label="Фильтр заявок" class="flex flex-wrap gap-2">
                        <Link v-for="entry in filters" :key="entry.key" :href="route('work.index',{filter:entry.key})"
                            :aria-current="filter === entry.key ? 'page' : undefined"
                            :class="filter === entry.key ? 'bg-primary text-primary-foreground' : 'border hover:bg-muted'" class="rounded-lg px-3 py-2 text-sm">{{ entry.label }}</Link>
                    </nav>
                    <p v-if="!requests.data.length" class="moon-empty">Заявок в этой выборке пока нет.</p>
                    <ul v-else class="divide-y">
                        <li v-for="row in requests.data" :key="row.id" class="space-y-2 py-3">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div class="min-w-0 space-y-1">
                                    <Link :href="route('group-requests.index',{request:row.id})" class="break-words font-medium underline underline-offset-4">{{ row.title }}</Link>
                                    <p class="text-sm text-muted-foreground">{{ row.assignee }} · срок {{ when(row.deadline) }}</p>
                                    <p v-if="row.contacted_at" class="text-sm text-muted-foreground">Первый ответ {{ when(row.contacted_at) }}</p>
                                </div>
                                <StatusPill v-if="row.late" tone="danger">Срок нарушен</StatusPill>
                            </div>
                            <div class="flex flex-wrap items-center gap-3 text-sm">
                                <Link v-if="row.deal_id" :href="route('deals.show',{deal:row.deal_id})" class="underline">Сделка</Link>
                                <button v-if="!row.contacted_at && selected !== row.id" class="rounded-lg border px-3 py-2" @click="startContact(row.id)">Зафиксировать ответ</button>
                            </div>
                            <form v-if="selected === row.id" class="grid gap-2 rounded-lg border p-3" @submit.prevent="contact.post(route('work.contact',{group:row.id}),{preserveScroll:true,onSuccess:()=>selected=null})">
                                <label class="grid gap-1 text-sm">Канал <select v-model="contact.channel" class="rounded-lg border bg-background p-2"><option value="email">Почта</option><option value="phone">Телефон</option><option value="other">Другой канал</option></select></label>
                                <label class="grid gap-1 text-sm">Что сообщили клиенту <textarea v-model="contact.summary" class="min-h-16 rounded-lg border bg-background p-2" maxlength="500" required /></label>
                                <p v-for="error in contact.errors" :key="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
                                <div class="flex gap-2"><button class="rounded-lg bg-primary px-3 py-2 text-sm text-primary-foreground" :disabled="contact.processing">Сохранить</button><button type="button" class="px-3 py-2 text-sm underline" @click="selected=null">Закрыть</button></div>
                            </form>
                        </li>
                    </ul>
                    <nav v-if="requests.prev_page_url || requests.next_page_url" aria-label="Страницы заявок" class="flex gap-4 text-sm">
                        <Link v-if="requests.prev_page_url" :href="requests.prev_page_url" class="underline">Назад</Link>
                        <Link v-if="requests.next_page_url" :href="requests.next_page_url" class="underline">Далее</Link>
                    </nav>
                </section>
                <aside class="min-w-0 space-y-4">
                    <section id="approval-queue" class="moon-panel scroll-mt-4 space-y-2 p-5">
                        <h2 class="font-semibold">{{ canManage ? 'КП требуют согласования' : 'КП на согласовании' }}</h2>
                        <p v-if="!approvals.length" class="text-sm text-muted-foreground">Ожидающих предложений нет.</p>
                        <Link v-for="entry in approvals" :key="entry.id" :href="route('deals.show',{deal:entry.deal_id})+'#proposals'" class="block break-words text-sm underline">{{ entry.title }} · КП №{{ entry.version }}</Link>
                    </section>
                    <section id="expiring-holds" class="moon-panel scroll-mt-4 space-y-2 p-5">
                        <h2 class="font-semibold">Резервы истекают за 24 часа</h2>
                        <p v-if="!holds.length" class="text-sm text-muted-foreground">Срочных резервов нет.</p>
                        <Link v-for="hold in holds" :key="hold.id" :href="route('hospitality.index')" class="block break-words text-sm underline">{{ hold.title }} · {{ when(hold.expires_at) }}</Link>
                    </section>
                    <section class="moon-panel space-y-2 p-5">
                        <h2 class="font-semibold">Открытые задачи</h2>
                        <p v-if="!tasks.length" class="text-sm text-muted-foreground">Открытых задач нет.</p>
                        <Link v-for="task in tasks" :key="task.id" :href="route('deals.show',{deal:task.deal.id})" class="block break-words text-sm underline">{{ task.body }}<span v-if="task.due_at"> · {{ when(task.due_at) }}</span></Link>
                        <Link :href="route('tasks')" class="inline-block text-sm text-muted-foreground underline">Все задачи</Link>
                    </section>
                    <section class="moon-panel space-y-3 p-5">
                        <h2 class="font-semibold">Результаты за 30 дней</h2>
                        <dl class="grid grid-cols-[1fr_auto] gap-x-3 gap-y-2 text-sm">
                            <dt>Получено заявок</dt><dd>{{ metrics.total }}</dd>
                            <dt>Ответ зафиксирован</dt><dd>{{ metrics.contacted }}</dd>
                            <dt>Ответили поздно или ждут сверх срока</dt><dd>{{ metrics.breached }}</dd>
                            <dt>Медиана ответа</dt><dd>{{ duration(metrics.median) }}</dd>
                            <dt>90-й перцентиль</dt><dd>{{ duration(metrics.p90) }}</dd>
                        </dl>
                        <form v-if="canManage" class="flex flex-wrap items-end gap-2 border-t pt-3" @submit.prevent="settings.patch(route('work.settings'),{preserveScroll:true})">
                            <label class="grid gap-1 text-sm">Срок первого ответа, часов <input v-model="settings.first_response_hours" type="number" min="1" max="168" required class="w-24 rounded-lg border bg-background p-2" /></label>
                            <button class="rounded-lg border px-3 py-2 text-sm" :disabled="settings.processing">Сохранить срок</button>
                            <p v-if="settings.errors.first_response_hours" class="text-sm text-destructive" role="alert">{{ settings.errors.first_response_hours }}</p>
                        </form>
                    </section>
                </aside>
            </div>
        </main>
    </AppLayout>
</template>
