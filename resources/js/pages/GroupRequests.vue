<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { russianDate } from '@/lib/russianFormat';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Person = { id: number; name: string };
type Group = { id: number; title: string; status: string; source: string; notes: string | null; deal_id: number | null; arrival_on: string | null; departure_on: string | null; guests: number | null; company: { name: string }; contact: { name: string }; assignee: Person };
const props = defineProps<{
    requests: { data: Group[]; total: number; prev_page_url: string | null; next_page_url: string | null };
    companies: { id: number; name: string }[];
    search: string;
    focusedRequest: number | null;
    success?: string;
    assignees: Person[];
}>();
const shown = ref(false);
const selected = ref<number | null>(null);
const intake = useForm({ title: '', source: 'email', company_id: '', company_name: '', contact_name: '', email: '', notes: '', confirmed_distinct: false });
const lookup = useForm({ search: props.search });
const qualification = useForm({ arrival_on: '', departure_on: '', guests: '', notes: '' });
const intakeText = ref('');
const intakeError = ref('');
const intakeBusy = ref(false);
const suggestion = ref<{ arrival_on: string | null; departure_on: string | null; guests: number | null; wishes: string | null; evidence: string; source: string } | null>(null);

async function suggestDetails() {
    const requestId = selected.value;
    const sourceText = intakeText.value;
    intakeBusy.value = true;
    intakeError.value = '';
    suggestion.value = null;
    try {
        const token = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='))?.slice(11) ?? '';
        const response = await fetch(route('hospitality.suggestions'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token) },
            body: JSON.stringify({ text: sourceText }),
        });
        if (!response.ok) throw new Error();
        const result = await response.json();
        if (requestId === selected.value && sourceText === intakeText.value) suggestion.value = result;
    } catch {
        if (requestId === selected.value) intakeError.value = 'Не удалось разобрать текст. Попробуйте повторить или заполните поля вручную.';
    } finally {
        intakeBusy.value = false;
    }
}

function applySuggestion() {
    if (!suggestion.value) return;
    qualification.arrival_on = suggestion.value.arrival_on ?? qualification.arrival_on;
    qualification.departure_on = suggestion.value.departure_on ?? qualification.departure_on;
    qualification.guests = suggestion.value.guests?.toString() ?? qualification.guests;
    if (suggestion.value.wishes && !qualification.notes.includes(suggestion.value.wishes)) {
        const notes = [qualification.notes, suggestion.value.wishes].filter(Boolean).join('\n');
        if (notes.length <= 5000) qualification.notes = notes;
    }
}
const conversion = useForm<{ conversion?: string }>({});
const assignment = useForm({ assignee_id: 0 });
const sources: Record<string, string> = { email: 'Почта', phone: 'Телефон', form: 'Форма', other: 'Другое' };
const statuses: Record<string, string> = { received: 'Принята', qualified: 'Квалифицирована', converted: 'Конвертирована' };

function start(group: Group) {
    selected.value = selected.value === group.id ? null : group.id;
    qualification.clearErrors();
    intakeText.value = '';
    intakeError.value = '';
    suggestion.value = null;
    qualification.arrival_on = group.arrival_on ?? '';
    qualification.departure_on = group.departure_on ?? '';
    qualification.guests = group.guests?.toString() ?? '';
    qualification.notes = group.notes ?? '';
}

function save() {
    intake.post(route('group-requests.store'), { preserveScroll: true, onSuccess: () => { intake.reset(); shown.value = false; } });
}

function assign(group: Group, event: Event) {
    const select = event.target as HTMLSelectElement;
    assignment.assignee_id = Number(select.value);
    select.value = String(group.assignee.id);
    assignment.patch(route('group-requests.assign', { group: group.id }), { preserveScroll: true });
}
</script>

<template>
    <Head title="Заявки на размещение" />
    <AppLayout :breadcrumbs="[{ title: 'Заявки на размещение', href: '/group-requests' }]">
        <main class="mx-auto w-full max-w-6xl space-y-5 p-4 md:p-8">
            <header class="moon-header flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-semibold tracking-tight">Заявки на размещение</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Корпоративные группы, даты проживания и подготовка сделки</p>
                </div>
                <button class="primary" :aria-expanded="shown" @click="shown = !shown">{{ shown ? 'Свернуть форму' : 'Принять заявку' }}</button>
            </header>
            <Link v-if="focusedRequest" :href="route('group-requests.index')" class="text-sm underline">Все заявки</Link>
            <p v-if="success" class="rounded-lg border bg-card px-4 py-3 text-sm" role="status">{{ success }}</p>
            <section v-if="shown" class="space-y-4 rounded-xl border bg-card p-4 md:p-6">
                <h2 class="text-lg font-semibold">Входящая заявка</h2>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="lookup.get(route('group-requests.index'), { preserveState: true, preserveScroll: true })">
                    <label class="min-w-0 flex-1">Поиск компании <input v-model="lookup.search" maxlength="100" /></label>
                    <button :disabled="lookup.processing">Найти</button>
                </form>
                <form class="grid gap-3 sm:grid-cols-2" @submit.prevent="save">
                    <label>Название заявки <input v-model="intake.title" maxlength="255" required /></label>
                    <label>Источник <select v-model="intake.source"><option v-for="(label, key) in sources" :key="key" :value="key">{{ label }}</option></select></label>
                    <label>Компания <select v-model="intake.company_id">
                        <option value="">Создать новую компанию</option>
                        <option v-for="company in companies" :key="company.id" :value="String(company.id)">{{ company.name }} · #{{ company.id }}</option>
                    </select></label>
                    <label v-if="!intake.company_id">Название компании <input v-model="intake.company_name" maxlength="255" required @input="intake.confirmed_distinct = false" /></label>
                    <label>Контактное лицо <input v-model="intake.contact_name" maxlength="255" required /></label>
                    <label>Почта контакта <input v-model="intake.email" type="email" maxlength="255" required @input="intake.confirmed_distinct = false" /></label>
                    <label class="sm:col-span-2">Комментарий <textarea v-model="intake.notes" rows="2" maxlength="5000" /></label>
                    <div v-if="Object.keys(intake.errors).length" class="space-y-2 rounded-md border p-3 sm:col-span-2">
                        <p v-for="error in intake.errors" :key="error" role="alert">{{ error }}</p>
                        <label v-if="intake.errors.company_name && !intake.company_id" class="flex items-start gap-2">
                            <input v-model="intake.confirmed_distinct" type="checkbox" /> Совпадения проверены, это отдельная компания
                        </label>
                    </div>
                    <button class="primary justify-self-start" :disabled="intake.processing">Сохранить заявку</button>
                </form>
            </section>

            <p v-if="conversion.errors.conversion" role="alert">{{ conversion.errors.conversion }}</p>
            <p v-if="assignment.errors.assignee_id" role="alert">{{ assignment.errors.assignee_id }}</p>
            <div v-if="!requests.total" class="moon-empty space-y-2">
                <p class="font-medium">Входящих заявок пока нет</p>
                <button v-if="!shown" @click="shown = true">Принять первую заявку</button>
            </div>
            <div v-else class="md:moon-panel">
                <div aria-hidden="true" class="hidden grid-cols-[minmax(0,1fr)_9rem_12rem] gap-4 rounded-t-2xl border-b bg-muted/50 px-5 py-3 text-xs font-semibold text-muted-foreground md:grid">
                    <span>Заявка и клиент</span><span>Статус</span><span>Действия</span>
                </div>
            <ul class="grid gap-2 md:block md:divide-y">
                <li v-for="group in requests.data" :key="group.id" class="grid gap-3 rounded-xl border bg-card p-4 md:grid-cols-[minmax(0,1fr)_9rem_12rem] md:gap-4 md:rounded-none md:border-x-0 md:border-b-0 md:bg-transparent md:px-5 md:first:border-t-0">
                        <div class="min-w-0 space-y-1">
                            <h2 class="break-words font-semibold">{{ group.title }}</h2>
                            <p class="break-words text-sm text-muted-foreground">{{ group.company.name }} · {{ group.contact.name }} · {{ sources[group.source] }}</p>
                            <p v-if="group.guests" class="text-sm">{{ russianDate(group.arrival_on) }} – {{ russianDate(group.departure_on) }} · гостей {{ group.guests }}</p>
                            <p v-if="group.notes" class="whitespace-pre-wrap break-words text-sm">{{ group.notes }}</p>
                            <p class="text-xs text-muted-foreground">Ответственный — {{ group.assignee.name }}</p>
                        </div>
                        <div><span class="moon-pill">{{ statuses[group.status] }}</span></div>
                    <div class="space-y-2">
                    <label v-if="assignees.length && !group.deal_id" class="max-w-sm">
                        Назначить ответственного
                        <select :value="group.assignee.id" :disabled="assignment.processing" @change="assign(group, $event)">
                            <option v-for="person in assignees" :key="person.id" :value="person.id">{{ person.name }}</option>
                        </select>
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <Link v-if="group.deal_id" class="underline" :href="route('deals.show', { deal: group.deal_id })">Открыть сделку</Link>
                        <template v-else>
                            <button :aria-expanded="selected === group.id" @click="start(group)">{{ group.status === 'qualified' ? 'Уточнить данные' : 'Квалифицировать' }}</button>
                            <button v-if="group.status === 'qualified'" class="primary" :disabled="conversion.processing" @click="conversion.post(route('group-requests.convert', { group: group.id }))">Создать сделку</button>
                        </template>
                    </div>
                    </div>
                    <form v-if="selected === group.id && !group.deal_id" class="grid gap-3 rounded-xl border bg-muted/50 p-3 sm:grid-cols-3 md:col-span-3" @submit.prevent="qualification.patch(route('group-requests.qualify', { group: group.id }), { preserveScroll: true, onSuccess: () => (selected = null) })">
                        <div class="space-y-2 sm:col-span-3">
                            <label class="grid gap-1">Текст заявки
                                <textarea v-model="intakeText" class="min-h-20 rounded-md border bg-background p-2" maxlength="5000" @input="suggestion = null" />
                            </label>
                            <button type="button" :disabled="intakeBusy || !intakeText.trim()" @click="suggestDetails">{{ intakeBusy ? 'Разбираем текст…' : 'Найти даты и число гостей' }}</button>
                            <p v-if="intakeError" role="alert">{{ intakeError }}</p>
                            <div v-if="suggestion" class="space-y-2 rounded-lg border p-3 text-sm" aria-live="polite">
                                <p>{{ suggestion.source === 'ollama' ? 'Подсказка локальной модели' : 'Подсказка по тексту' }} · проверьте данные перед сохранением</p>
                                <p>Заезд {{ russianDate(suggestion.arrival_on) }} · выезд {{ russianDate(suggestion.departure_on) }} · гостей {{ suggestion.guests ?? '—' }}</p>
                                <p v-if="suggestion.wishes" class="break-words">Пожелания: {{ suggestion.wishes }}</p>
                                <blockquote class="break-words text-muted-foreground">{{ suggestion.evidence }}</blockquote>
                                <button type="button" :disabled="!suggestion.arrival_on && !suggestion.departure_on && !suggestion.guests && !suggestion.wishes" @click="applySuggestion">Подставить в форму</button>
                            </div>
                        </div>
                        <label>Заезд <input v-model="qualification.arrival_on" type="date" required /></label>
                        <label>Выезд <input v-model="qualification.departure_on" type="date" required /></label>
                        <label>Гостей <input v-model="qualification.guests" type="number" min="1" max="10000" required /></label>
                        <label class="grid gap-1 sm:col-span-3">Пожелания и заметки <textarea v-model="qualification.notes" class="min-h-16 rounded-md border bg-background p-2" maxlength="5000" /></label>
                        <p v-for="error in qualification.errors" :key="error" class="sm:col-span-3" role="alert">{{ error }}</p>
                        <button class="primary justify-self-start" :disabled="qualification.processing">Подтвердить данные</button>
                    </form>
                </li>
            </ul>
            </div>
            <nav v-if="requests.prev_page_url || requests.next_page_url" aria-label="Страницы заявок" class="flex gap-4 text-sm">
                <Link v-if="requests.prev_page_url" :href="requests.prev_page_url" class="underline">Предыдущая</Link>
                <Link v-if="requests.next_page_url" :href="requests.next_page_url" class="underline">Следующая</Link>
            </nav>
        </main>
    </AppLayout>
</template>

<style scoped>
label { display: grid; gap: .25rem; font-size: .875rem; }
input:not([type='checkbox']), select, textarea { width: 100%; min-width: 0; border: 1px solid hsl(var(--border)); border-radius: .375rem; background: hsl(var(--background)); padding: .45rem .65rem; }
button { border: 1px solid hsl(var(--border)); border-radius: .375rem; padding: .45rem .75rem; font-size: .875rem; }
button:disabled { opacity: .5; }
.primary { background: hsl(var(--primary)); color: hsl(var(--primary-foreground)); font-weight: 600; }
[role='alert'] { color: hsl(var(--destructive)); font-size: .875rem; }
</style>
