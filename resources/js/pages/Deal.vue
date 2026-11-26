<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import DealPaymentRow from '@/components/DealPaymentRow.vue';
import StatusPill from '@/components/StatusPill.vue';
import { russianDate } from '@/lib/russianFormat';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Activity as ActivityIcon, FileText, ListChecks } from 'lucide-vue-next';
import { computed, ref } from 'vue';

type Activity = { id: number; kind: string; body: string; created_at: string; due_at: string | null; completed_at: string | null };
type Proposal = { id: number; version: number; status: string; customer_decision: string | null; created_by: number | null; rejection_reason: string | null; follow_up_at: string | null; snapshot: { total_minor: number; discount: number; items: { name: string; quantity: string | number; price_minor: number }[]; booking_change?: { booking_ids:number[];arrival_on:string;departure_on:string;guests:number }; stay_rooms?:{name:string;rooms:number}[] } };
const props = defineProps<{
    deal: { id: number; title: string; stage: string; amount: string; revision: number; expected_close_on: string | null; loss_reason: string | null; contact: { name: string; email: string; company: { name: string } }; assignee: { id: number; name: string } | null };
    groupRequest: { id: number; arrival_on: string; departure_on: string; guests: number } | null;
    pipeline: { id: number; name: string; stage: string } | null;
    events: { id: number; action: string; created_at: string; name: string | null; details: { version?: number; reason?: string } }[];
    contacts: { id: number; name: string; email: string; role: string }[];
    activities: Activity[];
    history: { id: number; name: string | null; from_stage: string; to_stage: string; created_at: string }[];
    proposals: Proposal[];
    payments: Record<string,{amount:string|number;due_on:string;paid_at:string|null;reference:string|null;revision:number}>;
    paymentToday: string;
    activeBookingIds: number[];
    booking: {id:number;rooms:number;property:string;name:string}[];
    emailEnabled: boolean;
    deliveries: Record<number, { status: string; attempts: number; recipient: string }>;
    replies: { id:number; sender:string; subject:string; body:string; received_at:string; version:number }[];
    estimate: { status: 'new' | 'linked' | 'unavailable'; url?: string; accepted?: { number: string; total_minor: number; url: string } | null } | null;
    canApprove: boolean;
    viewerId: number;
    assignees: { id: number; name: string }[];
    catalog: { id: number; name: string; price: string }[];
    stages: Record<string, string>;
}>();

const id = props.deal.id;
const details = useForm({ amount: props.deal.amount, expected_close_on: props.deal.expected_close_on ?? '', loss_reason: props.deal.loss_reason ?? '', revision: props.deal.revision });
const contact = useForm({ name: '', email: '', role: '' });
const activity = useForm({ kind: 'note', body: '', due_at: '' });
const task = useForm({});
const estimateForm = useForm<{ estimate?: string }>({});
const proposal = useForm({ items: [{ name: '', quantity: '1', price: '' }], discount: 0 });
const delivery = useForm<{ retry: boolean; delivery?: string }>({ retry: false });
const deliveryLabels: Record<string, string> = {
    queued: 'В очереди', sending: 'Отправляется', sent: 'Принято почтовым транспортом',
    failed: 'Ошибка отправки', unknown: 'Доставка не подтверждена', cancelled: 'Отменено',
};
function queueEmail(version: number, retry: boolean) {
    if (retry && !window.confirm('Проверьте исход отправки у почтового провайдера. Повторная отправка может доставить копию. Продолжить?')) return;
    delivery.retry = retry;
    delivery.post(route('deals.proposal.email', { deal: props.deal.id, proposal: version }), { preserveScroll: true });
}
const status = useForm({ action: '', reason: '' });
const customerDecision = useForm<{ action:string; decision:string }>({ action: 'decision', decision: props.proposals[0]?.customer_decision ?? '' });
const applyMove = useForm<{action:string;proposal_id:number;rooms?:string}>({action:'move',proposal_id:0});
const decisionLabels: Record<string, string> = { accepted: 'Согласие', declined: 'Отказ', changes_requested: 'Запрос изменений', needs_review: 'Нужен разбор' };
function applyBookingChange(version: Proposal) {
    const booking = version.snapshot.booking_change;
    if (!booking) return;
    applyMove.proposal_id = version.id;
    applyMove.patch(route('hospitality.amend',{allocation:booking.booking_ids[0]}),{preserveScroll:true});
}
const assignment = useForm({ assignee_id: 0, revision: 0 });
const rejecting = ref<number | null>(null);
const money = new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' });
const paymentPlan = computed(() => Object.values(props.payments).reduce((total,payment)=>total+Number(payment.amount),0));
const dateTime = new Intl.DateTimeFormat('ru-RU', { dateStyle: 'medium', timeStyle: 'short' });
const statuses: Record<string, string> = { pending: 'На согласовании', approved: 'Согласовано', rejected: 'Отклонено', superseded: 'Заменено', sent: 'Отправлено', answered: 'Клиент ответил' };
const nextAction = computed(() => {
    const latest = props.proposals[0];
    if (props.deal.stage !== 'lost' && latest?.status === 'pending' && props.canApprove && latest.created_by !== props.viewerId) return {label:'Согласовать КП',href:'#proposals'};
    if (props.deal.stage !== 'lost' && latest?.status === 'approved') return {label:'Отправить КП',href:'#proposals'};
    if (!['won','lost'].includes(props.deal.stage) && !latest) return {label:'Подготовить КП',href:'#proposals'};
    if (props.deal.stage !== 'lost' && latest?.status === 'answered' && !latest.customer_decision) return {label:'Уточнить решение клиента',href:'#proposals'};
    if (!['won','lost'].includes(props.deal.stage) && props.groupRequest && latest?.customer_decision === 'accepted' && !props.booking.length) return {label:'Подтвердить бронь',href:'#booking'};
    const entry = props.activities.filter(task => task.kind === 'task' && !task.completed_at)
        .sort((left,right) => (left.due_at ?? '9999').localeCompare(right.due_at ?? '9999'))[0];
    return entry ? {label:entry.body,href:'#history'} : null;
});
const eventLabels: Record<string, string> = {
    'request.received': 'Заявка принята', 'request.qualified': 'Заявка квалифицирована', 'request.assigned': 'Изменён ответственный за заявку',
    'request.first_contact': 'Зафиксирован первый ответ на заявку',
    'request.converted': 'Создана из заявки', 'deal.created': 'Сделка создана', 'deal.updated': 'Обновлены параметры сделки', 'deal.assigned': 'Изменён ответственный',
    'deal.contact': 'Обновлён контакт', 'deal.activity': 'Добавлена активность', 'task.completed': 'Завершена задача',
    'proposal.created': 'Создана версия предложения', 'proposal.approve': 'Версия согласована', 'proposal.reject': 'Версия отклонена',
    'proposal.send': 'Отправка зарегистрирована', 'proposal.answer': 'Ответ зарегистрирован',
    'proposal.reply_received': 'Получено письмо клиента',
    'proposal.decision': 'Зафиксировано решение клиента',
    'hotel.move': 'Бронь перенесена', 'hotel.cancel': 'Бронь отменена',
    'hotel.handoff_accepted': 'Бронь принята службой размещения',
    'hotel.handoff_returned': 'Бронь возвращена на уточнение',
    'hotel.guest_list_saved': 'Сохранена версия списка гостей',
    'payment.plan': 'Запланирован платёж', 'payment.paid': 'Получен платёж',
    'pipeline.moved': 'Изменён этап воронки', 'pipeline.attached': 'Перенесена в другую воронку',
    'deal.client_merged': 'Компания объединена с другой записью',
    'automation.reminder_created': 'Создано напоминание об ответе',
    'automation.stage_task_created': 'Создана задача по правилу этапа',
};
const comparisonIds = ref<number[]>([]);
const comparison = computed(() => props.proposals.filter((version) => comparisonIds.value.includes(version.id)));
const timeline = computed(() => [
    ...props.events.filter((event) => event.action !== 'deal.activity').map((event) => ({
        key: 'event-' + event.id, icon: event.action.startsWith('proposal.') ? FileText : ActivityIcon,
        title: (eventLabels[event.action] ?? event.action) + (event.details.version ? ' №' + event.details.version : ''),
        date: event.created_at, actor: event.name ?? ('source' in event.details && event.details.source === 'erp' ? 'ERP' : event.action.startsWith('automation.') || event.action === 'proposal.reply_received' ? 'Автоматизация' : 'Удалённый сотрудник'),
        reason: event.details.reason,
        activity: null,
    })),
    ...props.history.map((event) => ({
        key: 'stage-' + event.id, icon: ActivityIcon, title: props.stages[event.from_stage] + ' → ' + props.stages[event.to_stage],
        date: event.created_at.replace(' ', 'T') + (event.created_at.includes('T') ? '' : 'Z'), actor: event.name, activity: null, reason: null,
    })),
    ...props.activities.map((entry) => ({
        key: 'activity-' + entry.id, icon: ListChecks, title: entry.kind === 'task' ? 'Задача' : 'Заметка',
        date: entry.created_at, actor: null, activity: entry, reason: null,
    })),
].sort((a, b) => Date.parse(b.date) - Date.parse(a.date)));

function toggleComparison(id: number) {
    const selected = comparisonIds.value;
    comparisonIds.value = selected.includes(id) ? selected.filter((value) => value !== id) : [...selected.slice(-1), id];
}

function draftFrom(version: Proposal) {
    proposal.discount = version.snapshot.discount;
    proposal.items = version.snapshot.items.map((item) => ({
        name: item.name,
        quantity: String(item.quantity),
        price: (item.price_minor / 100).toFixed(2),
    }));
}

function updateStatus(version: Proposal, action: string) {
    status.action = action;
    status.patch(route('deals.proposal.status', { deal: id, proposal: version.id }), {
        preserveScroll: true,
        onSuccess: () => {
            rejecting.value = null;
            status.reset();
        },
    });
}

function addCatalogItem(event: Event) {
    const select = event.target as HTMLSelectElement;
    const item = props.catalog.find((entry) => String(entry.id) === select.value);
    if (!item) return;

    if (proposal.items.length === 1 && !proposal.items[0].name && !proposal.items[0].price) {
        proposal.items.splice(0, 1);
    }
    proposal.items.push({ name: item.name, price: item.price, quantity: '1' });
    select.value = '';
}

function assign(event: Event) {
    const select = event.target as HTMLSelectElement;
    assignment.assignee_id = Number(select.value);
    assignment.revision = props.deal.revision;
    select.value = String(props.deal.assignee?.id ?? '');
    assignment.patch(route('deals.assign', { deal: id, return_to: 'deal' }), {
        preserveScroll: true,
        onSuccess: (page) => { details.revision = (page.props.deal as typeof props.deal).revision; },
    });
}
</script>

<template>
    <Head :title="deal.title" />
    <AppLayout :breadcrumbs="[{ title: 'Воронка продаж', href: '/dashboard' }, { title: deal.title, href: route('deals.show', { deal: id }) }]">
        <main class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-8">
            <header class="moon-header flex flex-wrap items-end justify-between gap-5">
                <div class="min-w-0 space-y-3">
                <Link :href="pipeline ? route('pipelines.show', { pipeline: pipeline.id }) : route('dashboard')" class="text-sm text-muted-foreground underline">{{ pipeline?.name ?? 'Воронка продаж' }}</Link>
                <div class="flex flex-wrap items-center gap-2"><StatusPill :tone="deal.stage === 'won' ? 'success' : deal.stage === 'lost' ? 'danger' : 'info'">{{ pipeline?.stage ?? stages[deal.stage] }}</StatusPill><span class="text-sm text-muted-foreground">{{ deal.contact.company.name }} · {{ deal.contact.name }}</span></div>
                <h1 class="break-words text-3xl font-semibold tracking-tight sm:text-4xl">{{ deal.title }}</h1>
                <label v-if="assignees.length" class="max-w-xs">Ответственный
                    <select :value="deal.assignee?.id" :disabled="assignment.processing" @change="assign">
                        <option v-for="person in assignees" :key="person.id" :value="person.id">{{ person.name }}</option>
                    </select>
                </label>
                <p v-else-if="deal.assignee" class="text-sm">Ответственный — {{ deal.assignee.name }}</p>
                <p v-if="assignment.errors.assignee_id" role="alert">{{ assignment.errors.assignee_id }}</p>
                <p v-if="groupRequest" class="text-sm">Групповая заявка №{{ groupRequest.id }} · {{ russianDate(groupRequest.arrival_on) }} – {{ russianDate(groupRequest.departure_on) }} · гостей {{ groupRequest.guests }}</p>
                </div>
                <div class="min-w-0 space-y-3">
                    <p class="text-2xl font-semibold tabular-nums tracking-tight">{{ money.format(Number(deal.amount)) }}</p>
                    <a v-if="nextAction" :href="nextAction.href" class="inline-flex max-w-full rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-primary-foreground">{{ nextAction.label }}</a>
                </div>
            </header>
            <nav aria-label="Разделы сделки" class="flex flex-wrap gap-2">
                <a class="moon-section-link" href="#proposals">Предложения</a>
                <a v-if="estimate" class="moon-section-link" href="#estimate">Смета</a>
                <a v-if="groupRequest" class="moon-section-link" href="#booking">Бронь</a>
                <a class="moon-section-link" href="#payments">Оплата</a>
                <a class="moon-section-link" href="#history">История</a>
                <a class="moon-section-link" href="#deal-details">Данные сделки</a>
            </nav>
            <div class="grid items-start gap-5 lg:grid-cols-2">
                <section v-if="estimate" id="estimate" aria-labelledby="estimate-title" class="moon-panel scroll-mt-4 space-y-3 p-5 lg:col-span-2">
                    <h2 id="estimate-title" class="text-lg font-semibold">Смета</h2>
                    <p v-if="estimate.status === 'unavailable'" class="text-sm text-muted-foreground">Связь со «Сметой» временно недоступна.</p>
                    <button v-else-if="estimate.status === 'new'" type="button" class="moon-section-link"
                        :disabled="estimateForm.processing"
                        @click="estimateForm.post(route('deals.estimate', { deal: id }), { preserveScroll: true })">Создать черновик сметы</button>
                    <template v-else>
                        <a class="moon-section-link inline-flex" :href="estimate.url" target="_blank" rel="noopener noreferrer">Открыть черновик</a>
                        <p v-if="estimate.accepted" class="text-sm">
                            <a class="underline" :href="estimate.accepted.url" target="_blank" rel="noopener noreferrer">Принятая смета №{{ estimate.accepted.number }}</a>
                            · {{ money.format(estimate.accepted.total_minor / 100) }}
                        </p>
                        <p v-else class="text-sm text-muted-foreground">Принятой версии пока нет.</p>
                    </template>
                    <p v-if="estimateForm.errors.estimate" role="alert">{{ estimateForm.errors.estimate }}</p>
                </section>
                <section id="proposals" class="moon-panel scroll-mt-4 space-y-5 p-5 lg:col-span-2">
                    <h2 class="text-lg font-semibold">Коммерческие предложения</h2>
                    <form v-if="!['won', 'lost'].includes(deal.stage)" class="grid gap-3" @submit.prevent="proposal.post(route('deals.proposal', { deal: id }), { preserveScroll: true, onSuccess: () => proposal.reset() })">
                        <label v-if="catalog.length">Добавить из каталога
                            <select :disabled="proposal.items.length >= 50" @change="addCatalogItem">
                                <option value="">Выберите услугу</option>
                                <option v-for="item in catalog" :key="item.id" :value="item.id">{{ item.name }} · {{ money.format(Number(item.price)) }}</option>
                            </select>
                        </label>
                        <div v-for="(item, index) in proposal.items" :key="index" class="grid items-end gap-2 sm:grid-cols-[2fr_1fr_1fr_auto]">
                            <label>Позиция <input v-model="item.name" required /></label>
                            <label>Количество <input v-model="item.quantity" type="number" min="1" required /></label>
                            <label>Цена, ₽ <input v-model="item.price" inputmode="decimal" required /></label>
                            <button v-if="proposal.items.length > 1" type="button" @click="proposal.items.splice(index, 1)">Убрать</button>
                        </div>
                        <label>Скидка, % <input v-model="proposal.discount" type="number" min="0" max="100" required /></label>
                        <p v-for="error in proposal.errors" :key="error" role="alert">{{ error }}</p>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" :disabled="proposal.items.length >= 50" @click="proposal.items.push({ name: '', quantity: '1', price: '' })">Добавить позицию</button>
                            <button :disabled="proposal.processing">Отправить версию на согласование</button>
                        </div>
                    </form>
                    <p v-if="status.errors.action" role="alert">{{ status.errors.action }}</p>
                    <p v-if="!proposals.length" class="moon-empty">Версий предложения пока нет</p>
                    <p v-if="delivery.errors.retry || delivery.errors.delivery" role="alert">{{ delivery.errors.retry || delivery.errors.delivery }}</p>
                    <div v-if="comparison.length === 2" class="grid gap-3 rounded-xl border bg-background/60 p-4 sm:grid-cols-2" aria-label="Сравнение версий">
                        <div v-for="version in comparison" :key="version.id" class="min-w-0 space-y-2 text-sm">
                            <p class="font-semibold">Версия {{ version.version }} · {{ statuses[version.status] }}</p>
                            <p class="text-lg font-semibold tabular-nums">{{ money.format(version.snapshot.total_minor / 100) }}</p>
                            <p class="text-muted-foreground">Скидка {{ version.snapshot.discount }}%</p>
                            <ul class="divide-y">
                                <li v-for="(item, index) in version.snapshot.items" :key="index" class="break-words py-1">{{ item.name }} · {{ item.quantity }} × {{ money.format(item.price_minor / 100) }}</li>
                            </ul>
                        </div>
                    </div>
                    <ul class="divide-y">
                        <li v-for="version in proposals" :key="version.id" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
                            <div>
                                <p class="flex flex-wrap items-center gap-2 font-medium">Версия {{ version.version }} <StatusPill :tone="version.status === 'approved' ? 'success' : version.status === 'rejected' ? 'danger' : version.status === 'pending' ? 'warning' : version.status === 'superseded' ? 'neutral' : 'info'">{{ statuses[version.status] }}</StatusPill> <span class="tabular-nums">{{ money.format(version.snapshot.total_minor / 100) }}</span></p>
                                <p v-if="version.rejection_reason" class="text-xs text-muted-foreground">Причина отклонения — {{ version.rejection_reason }}</p>
                                <p v-if="version.customer_decision" class="text-xs text-muted-foreground">Решение клиента — {{ decisionLabels[version.customer_decision] }}</p>
                                <div v-if="version.snapshot.booking_change" class="mt-2 text-sm">
                                    <p>Перенос · {{ russianDate(version.snapshot.booking_change.arrival_on) }} – {{ russianDate(version.snapshot.booking_change.departure_on) }} · гостей {{ version.snapshot.booking_change.guests }}</p>
                                    <p v-for="room in version.snapshot.stay_rooms" :key="room.name" class="text-muted-foreground">{{ room.name }} · {{ room.rooms }} ном.</p>
                                </div>
                                <p v-if="deliveries[version.id]" class="break-all text-xs text-muted-foreground">{{ deliveryLabels[deliveries[version.id].status] }} · попыток {{ deliveries[version.id].attempts }} · {{ deliveries[version.id].recipient }}</p>
                                <p v-if="version.status === 'sent' && version.follow_up_at" class="text-xs text-muted-foreground">Контроль ответа — {{ dateTime.format(new Date(version.follow_up_at)) }}</p>
                            </div>
                            <form v-if="version.id === proposals[0]?.id && version.status === 'answered' && deal.stage !== 'lost' && (deal.stage !== 'won' || version.snapshot.booking_change)"
                                class="flex w-full flex-wrap items-end gap-2" @submit.prevent="customerDecision.patch(route('deals.proposal.status', { deal: id, proposal: version.id }), { preserveScroll: true })">
                                <label>Решение клиента
                                    <select v-model="customerDecision.decision" required>
                                        <option value="" disabled>Выберите решение</option>
                                        <option v-for="(label, value) in decisionLabels" :key="value" :value="value">{{ label }}</option>
                                    </select>
                                </label>
                                <button :disabled="customerDecision.processing || !customerDecision.decision">Зафиксировать решение</button>
                                <p v-if="customerDecision.errors.decision || customerDecision.errors.action" role="alert">{{ customerDecision.errors.decision || customerDecision.errors.action }}</p>
                            </form>
                            <div class="flex flex-wrap items-center gap-2">
                                <button v-if="proposals.length > 1" type="button" class="compare-button" :aria-pressed="comparisonIds.includes(version.id)" @click="toggleComparison(version.id)">{{ comparisonIds.includes(version.id) ? 'Убрать из сравнения' : 'В сравнение' }}</button>
                                <a :href="route('deals.proposal.pdf', { deal: id, proposal: version.id })" class="underline">Скачать PDF</a>
                                <a v-if="['approved', 'sent', 'answered'].includes(version.status)" :href="route('deals.proposal.draft', { deal: id, proposal: version.id })" class="underline">Скачать черновик письма</a>
                                <button v-if="!['won', 'lost'].includes(deal.stage)" :disabled="proposal.processing" @click="draftFrom(version)">Новая версия на основе этой</button>
                                <template v-if="deal.stage !== 'lost' && (deal.stage !== 'won' || version.snapshot.booking_change) && canApprove && version.created_by !== viewerId && version.status === 'pending'">
                                    <button :disabled="status.processing" @click="updateStatus(version, 'approve')">Согласовать</button>
                                    <button :disabled="status.processing" @click="rejecting = version.id; status.reset('reason')">Отклонить</button>
                                </template>
                                <template v-if="deal.stage !== 'lost' && (deal.stage !== 'won' || version.snapshot.booking_change) && version.status === 'approved' && !['queued', 'sending'].includes(deliveries[version.id]?.status)">
                                    <button v-if="emailEnabled && (!deliveries[version.id] || ['failed', 'unknown'].includes(deliveries[version.id].status))" :disabled="delivery.processing" @click="queueEmail(version.id, !!deliveries[version.id])">{{ deliveries[version.id] ? 'Повторить отправку' : 'Отправить PDF по почте' }}</button>
                                    <button :disabled="status.processing" @click="updateStatus(version, 'send')">Отметить отправленным</button>
                                </template>
                                <button v-if="deal.stage !== 'lost' && (deal.stage !== 'won' || version.snapshot.booking_change) && version.status === 'sent'" :disabled="status.processing" @click="updateStatus(version, 'answer')">Отметить ответ клиента</button>
                                <button v-if="deal.stage !== 'lost' && version.id === proposals[0]?.id && version.customer_decision === 'accepted' && version.snapshot.booking_change && activeBookingIds.includes(version.snapshot.booking_change.booking_ids[0])" :disabled="applyMove.processing" @click="applyBookingChange(version)">Подтвердить перенос</button>
                                <p v-if="applyMove.proposal_id === version.id && (applyMove.errors.action || applyMove.errors.rooms)" role="alert">{{ applyMove.errors.action || applyMove.errors.rooms }}</p>
                            </div>
                            <form v-if="rejecting === version.id" class="grid w-full gap-2" @submit.prevent="updateStatus(version, 'reject')">
                                <label>Причина отклонения <input v-model="status.reason" maxlength="255" required /></label>
                                <p v-if="status.errors.reason" role="alert">{{ status.errors.reason }}</p>
                                <button :disabled="status.processing">Подтвердить отклонение</button>
                            </form>
                        </li>
                    </ul>
                    <section v-if="replies.length" class="space-y-2 border-t pt-4">
                        <h3 class="font-semibold">Письма клиента</h3>
                        <details v-for="reply in replies" :key="reply.id" class="rounded-lg border p-3 text-sm">
                            <summary class="cursor-pointer break-words">{{ reply.subject }} · КП №{{ reply.version }}</summary>
                            <p class="my-2 break-all text-muted-foreground">{{ reply.sender }} · {{ reply.received_at }}</p>
                            <p class="whitespace-pre-wrap break-words">{{ reply.body }}</p>
                        </details>
                    </section>
                </section>
                <section v-if="groupRequest" id="booking" class="moon-panel scroll-mt-4 space-y-4 p-5 lg:col-span-2">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="text-lg font-semibold">Бронь</h2>
                        <Link class="moon-section-link" :href="route('hospitality.index')+(booking.length ? '#allocation-'+booking[0].id : '')">Открыть размещение</Link>
                    </div>
                    <p v-if="!booking.length" class="moon-empty">Подтверждённой брони пока нет.</p>
                    <template v-else>
                        <p class="text-sm text-muted-foreground">{{ russianDate(groupRequest.arrival_on) }} – {{ russianDate(groupRequest.departure_on) }} · гостей {{ groupRequest.guests }}</p>
                        <ul class="grid gap-2 sm:grid-cols-2">
                            <li v-for="item in booking" :key="item.id" class="rounded-lg border p-3 text-sm">{{ item.property }} · {{ item.name }} · {{ item.rooms }} ном.</li>
                        </ul>
                        <Link class="moon-section-link" :href="route('hospitality.guests',{allocation:booking[0].id})">Список гостей</Link>
                    </template>
                </section>
                <section id="payments" class="moon-panel scroll-mt-4 space-y-4 p-5 lg:col-span-2">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <h2 class="text-lg font-semibold">Депозит и оплата</h2>
                        <p class="text-sm text-muted-foreground">Запланировано {{ money.format(paymentPlan) }} из {{ money.format(Number(deal.amount)) }}</p>
                    </div>
                    <p v-if="paymentPlan - Number(deal.amount) > .001" class="text-sm text-destructive">План превышает текущую сумму сделки. Сверьте условия оплаты.</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <DealPaymentRow :deal="id" kind="deposit" label="Депозит" :payment="payments.deposit" :today="paymentToday" :closed="deal.stage === 'lost'" />
                        <DealPaymentRow :deal="id" kind="balance" label="Остаток оплаты" :payment="payments.balance" :today="paymentToday" :closed="deal.stage === 'lost'" />
                    </div>
                </section>
                <section id="history" class="moon-panel scroll-mt-4 space-y-4 p-5 lg:col-span-2">
                    <h2 class="text-lg font-semibold">Хронология сделки</h2>
                    <p v-if="!timeline.length" class="moon-empty">Записей пока нет</p>
                    <ol class="timeline text-sm">
                        <li v-for="event in timeline" :key="event.key" class="relative grid min-w-0 grid-cols-[2rem_minmax(0,1fr)] gap-3 pb-5 last:pb-0">
                            <span class="z-10 grid size-8 place-items-center rounded-full border bg-accent text-accent-foreground"><component :is="event.icon" class="size-4" aria-hidden="true" /></span>
                            <div class="min-w-0 space-y-1 pt-1">
                                <div class="flex flex-wrap justify-between gap-x-3 gap-y-1">
                                    <p class="font-medium">{{ event.title }}</p>
                                    <time :datetime="event.date" class="text-xs text-muted-foreground">{{ dateTime.format(new Date(event.date)) }}</time>
                                </div>
                                <p v-if="event.actor" class="text-xs text-muted-foreground">{{ event.actor }}</p>
                                <p v-if="event.reason" class="whitespace-pre-wrap break-words text-muted-foreground">{{ event.reason }}</p>
                                <template v-if="event.activity">
                                    <p class="whitespace-pre-wrap break-words">{{ event.activity.body }}</p>
                                    <p v-if="event.activity.due_at" class="text-xs text-muted-foreground">Срок {{ dateTime.format(new Date(event.activity.due_at)) }}</p>
                                    <button v-if="event.activity.kind === 'task' && !event.activity.completed_at" :disabled="task.processing" @click="task.patch(route('deals.complete', { deal: id, activity: event.activity.id }), { preserveScroll: true })">Выполнено</button>
                                    <p v-else-if="event.activity.completed_at" class="text-xs text-muted-foreground">Задача выполнена</p>
                                </template>
                            </div>
                        </li>
                    </ol>
                </section>
                <section id="deal-details" class="moon-panel scroll-mt-4 space-y-4 p-5">
                    <h2 class="text-lg font-semibold">План закрытия</h2>
                    <form class="grid gap-3" @submit.prevent="details.patch(route('deals.update', { deal: id }), { preserveScroll: true, onSuccess: (page) => { details.revision = (page.props.deal as typeof deal).revision; } })">
                        <label>Сумма сделки, ₽ <input v-model="details.amount" inputmode="decimal" :readonly="['won', 'lost'].includes(deal.stage)" required /></label>
                        <label>Плановая дата <input v-model="details.expected_close_on" type="date" /></label>
                        <label>Причина проигрыша <input v-model="details.loss_reason" maxlength="255" /></label>
                        <p v-for="error in details.errors" :key="error" role="alert">{{ error }}</p>
                        <button :disabled="details.processing">Сохранить</button>
                    </form>
                </section>
                <section class="moon-panel space-y-4 p-5">
                    <h2 class="text-lg font-semibold">Контакты</h2>
                    <p v-if="!contacts.some((person) => person.email === deal.contact.email)" class="text-sm">{{ deal.contact.name }} · {{ deal.contact.email }} · Основной контакт</p>
                    <ul class="space-y-1 text-sm">
                        <li v-for="person in contacts" :key="person.id">{{ person.name }} · {{ person.email }} · {{ person.role }}</li>
                    </ul>
                    <form class="grid gap-3" @submit.prevent="contact.post(route('deals.contact', { deal: id }), { preserveScroll: true, onSuccess: () => contact.reset() })">
                        <label>Имя <input v-model="contact.name" required /></label>
                        <label>Электронная почта <input v-model="contact.email" type="email" required /></label>
                        <label>Роль в сделке <input v-model="contact.role" required /></label>
                        <p v-for="error in contact.errors" :key="error" role="alert">{{ error }}</p>
                        <button :disabled="contact.processing">Сохранить контакт</button>
                    </form>
                </section>
                <section class="moon-panel space-y-4 p-5 lg:col-span-2">
                    <h2 class="text-lg font-semibold">Задачи и заметки</h2>
                    <form class="grid gap-3" @submit.prevent="activity.post(route('deals.activity', { deal: id }), { preserveScroll: true, onSuccess: () => activity.reset('body', 'due_at') })">
                        <label>Тип <select v-model="activity.kind"><option value="note">Заметка</option><option value="task">Задача</option></select></label>
                        <label>Текст <textarea v-model="activity.body" required rows="2" /></label>
                        <label v-if="activity.kind === 'task'">Срок <input v-model="activity.due_at" type="datetime-local" /></label>
                        <p v-for="error in activity.errors" :key="error" role="alert">{{ error }}</p>
                        <button :disabled="activity.processing">Добавить</button>
                    </form>
                </section>
            </div>
        </main>
    </AppLayout>
</template>

<style scoped>
label { display: grid; gap: .25rem; font-size: .875rem; }
input, textarea, select { width: 100%; border-radius: .375rem; border: 1px solid hsl(var(--border)); padding: .375rem .625rem; background: hsl(var(--background)); }
button { justify-self: start; border-radius: .375rem; padding: .4rem .7rem; background: hsl(var(--primary)); color: hsl(var(--primary-foreground)); font-size: .875rem; }
button:disabled { opacity: .5; }
[role='alert'] { color: hsl(var(--destructive)); font-size: .875rem; }
.timeline { position: relative; }
.timeline::before { position: absolute; inset: 0 auto 1rem .975rem; content: ''; border-left: 1px solid hsl(var(--border)); }
.compare-button { background: hsl(var(--accent)); color: hsl(var(--accent-foreground)); }
</style>
