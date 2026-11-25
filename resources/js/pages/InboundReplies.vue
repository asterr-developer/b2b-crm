<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Message = { id:number; sender:string; subject:string; body:string; received_at:string; deal_id:number|null };
defineProps<{
    messages:{ data:Message[]; prev_page_url:string|null; next_page_url:string|null };
    canReview:boolean;
    sync:{status:string;imported:number;duplicates:number;skipped:number;checked_at:string;last_success:string|null;stale:boolean;error:string|null}|null;
    proposals:{ id:number; title:string; version:number; deal_id:number }[];
}>();
const manual = useForm({sender:'',subject:'',body:''});
const link = useForm({proposal:''});
const selected = ref<number|null>(null);
const syncErrors:Record<string,string> = {login:'IMAP отклонил вход',folder:'Папка IMAP недоступна',connection:'Соединение прервано',sync:'Ошибка обработки писем'};
const time = new Intl.DateTimeFormat('ru-RU',{dateStyle:'short',timeStyle:'short'});

function review(id:number) {
    link.reset();
    link.clearErrors();
    selected.value = id;
}
</script>

<template>
    <Head title="Входящие" />
    <AppLayout :breadcrumbs="[{title:'Входящие',href:'/inbox'}]">
        <main class="mx-auto w-full max-w-5xl space-y-5 p-4 md:p-8">
            <header class="moon-header space-y-2">
                <h1 class="text-3xl font-semibold tracking-tight">Входящие</h1>
                <p class="text-sm text-muted-foreground">Ответы клиентов по коммерческим предложениям</p>
            </header>
            <section v-if="canReview" class="moon-panel space-y-2 p-5">
                <h2 class="font-semibold">Синхронизация почты</h2>
                <p v-if="!sync" class="text-sm text-muted-foreground">Адаптер ещё не передал статус.</p>
                <template v-else>
                    <p class="text-sm" :class="sync.stale || sync.status === 'failed' ? 'text-destructive' : 'text-muted-foreground'">
                        {{ sync.stale ? 'Адаптер не обновлялся более 3 минут' : sync.status === 'done' ? 'Синхронизация работает' : syncErrors[sync.error ?? ''] }}
                    </p>
                    <p class="text-sm text-muted-foreground">Последний запуск {{ time.format(new Date(sync.checked_at)) }} · импортировано {{ sync.imported }} · повторов {{ sync.duplicates }} · пропущено {{ sync.skipped }}</p>
                    <p v-if="sync.last_success" class="text-sm text-muted-foreground">Последний успех {{ time.format(new Date(sync.last_success)) }}</p>
                </template>
            </section>
            <details v-if="canReview" class="moon-panel p-5">
                <summary class="cursor-pointer font-medium">Зарегистрировать письмо вручную</summary>
                <form class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="manual.post(route('inbound-replies.store'), { preserveScroll:true,onSuccess:()=>manual.reset() })">
                    <label class="grid gap-1 text-sm">Отправитель <input v-model="manual.sender" type="email" maxlength="255" required class="rounded-lg border bg-background p-2" /></label>
                    <label class="grid gap-1 text-sm">Тема <input v-model="manual.subject" maxlength="255" required class="rounded-lg border bg-background p-2" /></label>
                    <label class="grid gap-1 text-sm sm:col-span-2">Текст письма <textarea v-model="manual.body" class="min-h-24 rounded-lg border bg-background p-2" maxlength="10000" required /></label>
                    <p v-for="error in manual.errors" :key="error" class="text-sm text-destructive sm:col-span-2" role="alert">{{ error }}</p>
                    <button class="justify-self-start rounded-lg bg-primary px-3 py-2 text-sm text-primary-foreground" :disabled="manual.processing">Добавить входящее</button>
                </form>
            </details>
            <p v-if="!messages.data.length" class="moon-empty">Входящих писем пока нет.</p>
            <article v-for="message in messages.data" :key="message.id" class="moon-panel space-y-3 p-5">
                <header class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <h2 class="break-words font-semibold">{{ message.subject }}</h2>
                        <p class="break-all text-sm text-muted-foreground">{{ message.sender }} · {{ message.received_at }}</p>
                    </div>
                    <Link v-if="message.deal_id" :href="route('deals.show',{deal:message.deal_id})" class="text-sm underline">Открыть сделку</Link>
                    <span v-else class="moon-pill">Требует проверки</span>
                </header>
                <details>
                    <summary class="cursor-pointer text-sm">Текст письма</summary>
                    <p class="mt-2 whitespace-pre-wrap break-words rounded-lg border p-3 text-sm">{{ message.body }}</p>
                </details>
                <button v-if="canReview && !message.deal_id && selected !== message.id" class="rounded-lg border px-3 py-2 text-sm" @click="review(message.id)">Связать с предложением</button>
                <form v-if="selected === message.id" class="space-y-2" @submit.prevent="link.patch(route('inbound-replies.link',{reply:message.id}), {preserveScroll:true,onSuccess:()=>selected=null})">
                    <p v-if="!proposals.length" class="text-sm text-muted-foreground">В отделе пока нет отправленных предложений.</p>
                    <label v-else class="grid gap-1 text-sm">Сделка и версия КП
                        <select v-model="link.proposal" class="max-w-full rounded-lg border bg-background p-2" required>
                            <option value="" disabled>Выберите предложение</option>
                            <option v-for="proposal in proposals" :key="proposal.id" :value="String(proposal.id)">{{ proposal.title }} · сделка №{{ proposal.deal_id }} · версия {{ proposal.version }}</option>
                        </select>
                    </label>
                    <p v-if="link.errors.proposal" class="text-sm text-destructive" role="alert">{{ link.errors.proposal }}</p>
                    <button class="rounded-lg bg-primary px-3 py-2 text-sm text-primary-foreground" :disabled="link.processing || !link.proposal">Подтвердить связь</button>
                    <button type="button" class="ml-2 px-3 py-2 text-sm underline" @click="selected=null">Закрыть форму</button>
                </form>
            </article>
            <nav v-if="messages.prev_page_url || messages.next_page_url" aria-label="Страницы писем" class="flex gap-4 text-sm">
                <Link v-if="messages.prev_page_url" :href="messages.prev_page_url" class="underline">Назад</Link>
                <Link v-if="messages.next_page_url" :href="messages.next_page_url" class="underline">Далее</Link>
            </nav>
        </main>
    </AppLayout>
</template>
