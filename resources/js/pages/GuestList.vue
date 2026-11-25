<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { russianDate } from '@/lib/russianFormat';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Guest = { name:string; allocation:number; room:number };
const props = defineProps<{
    booking:{id:number;title:string;guests:number;arrival_on:string;departure_on:string};
    rooms:{id:number;property:string;name:string;rooms:number}[];
    guests:Guest[];
    versions:{version:number;created_at:string;name:string|null}[];
    selectedVersion:number|null;
}>();
const form = useForm({csv:'',version:0});
const proposed = ref<Guest[]>([]);
const previewError = ref('');
const checking = ref(false);
const roomLabel = (id:number) => props.rooms.find(room => room.id === id)?.name ?? String(id);

async function loadCsv(event:Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    form.csv = '';
    proposed.value = [];
    previewError.value = '';
    if (!file) return;
    if (file.size > 600000) {
        previewError.value = 'Размер файла превышает 600 КБ.';
        return;
    }
    try {
        form.csv = new TextDecoder('utf-8', {fatal:true}).decode(await file.arrayBuffer());
    } catch {
        form.csv = '';
        previewError.value = 'Сохраните CSV в кодировке UTF-8.';
    }
}

async function preview() {
    checking.value = true;
    proposed.value = [];
    previewError.value = '';
    form.clearErrors();
    try {
        const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))?.slice(11) ?? '';
        const response = await fetch(route('hospitality.guests.preview',{allocation:props.booking.id}), {
            method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json','X-XSRF-TOKEN':decodeURIComponent(token)},
            body:JSON.stringify({csv:form.csv}),
        });
        const data = await response.json();
        if (!response.ok) {
            previewError.value = data.errors?.csv?.[0] ?? 'Предпросмотр недоступен. Обновите страницу.';
            return;
        }
        form.version = data.version;
        proposed.value = data.guests;
    } catch {
        previewError.value = 'Предпросмотр недоступен. Обновите страницу.';
    } finally {
        checking.value = false;
    }
}

function save() {
    form.post(route('hospitality.guests.store',{allocation:props.booking.id}), {
        preserveScroll:true,
        onSuccess:() => { form.reset(); proposed.value = []; },
        onError:() => { proposed.value = []; },
    });
}
</script>

<template>
    <Head title="Список гостей" />
    <AppLayout :breadcrumbs="[{title:'Размещение',href:'/hospitality'},{title:'Список гостей',href:route('hospitality.guests',{allocation:booking.id})}]">
        <main class="mx-auto w-full max-w-5xl space-y-4 p-4 md:p-8">
            <header class="moon-header space-y-2"><h1 class="break-words text-2xl font-semibold">Список гостей · {{ booking.title }}</h1><p class="text-sm text-muted-foreground">{{ russianDate(booking.arrival_on) }} – {{ russianDate(booking.departure_on) }} · гостей по брони {{ booking.guests }}</p></header>
            <section class="moon-panel space-y-3 p-5">
                <h2 class="font-semibold">Импорт CSV</h2>
                <p class="text-sm text-muted-foreground">Первая строка <code>name,allocation,room</code>. Укажи имя, позицию брони и номер комнаты в этой позиции.</p>
                <ul class="space-y-1 text-sm"><li v-for="room in rooms" :key="room.id">Позиция <strong>{{ room.id }}</strong> · {{ room.property }} · {{ room.name }} · комнаты 1–{{ room.rooms }}</li></ul>
                <label class="grid gap-1 text-sm">Файл CSV в UTF-8 <input type="file" accept=".csv,text/csv" class="max-w-full text-sm" :disabled="checking || form.processing" @change="loadCsv" /></label>
                <label class="grid gap-1 text-sm">Текст CSV <textarea v-model="form.csv" rows="4" maxlength="150000" class="rounded-lg border bg-background p-2 font-mono" :disabled="checking || form.processing" @input="proposed=[]" /></label>
                <p v-if="previewError || form.errors.csv" class="text-sm text-destructive" role="alert">{{ previewError || form.errors.csv }}</p>
                <button class="rounded-lg border px-3 py-2 text-sm" :disabled="checking || form.processing || !form.csv.trim()" @click="preview">{{ checking ? 'Проверяем…' : 'Предпросмотр' }}</button>
                <div v-if="proposed.length" class="space-y-2 border-t pt-3">
                    <h3 class="font-medium">К сохранению {{ proposed.length }} из {{ booking.guests }}</h3>
                    <ul class="max-h-60 divide-y overflow-auto">
                        <li v-for="(guest,index) in proposed" :key="index" class="break-words py-2 text-sm">{{ guest.name }} · {{ roomLabel(guest.allocation) }} · комната {{ guest.room }}</li>
                    </ul>
                    <button class="rounded-lg bg-primary px-3 py-2 text-sm text-primary-foreground" :disabled="form.processing" @click="save">Сохранить версию</button>
                </div>
            </section>
            <section class="moon-panel space-y-3 p-5">
                <h2 class="font-semibold">Сохранённые версии</h2>
                <p v-if="!versions.length" class="text-sm text-muted-foreground">Список пока пуст.</p>
                <nav v-else class="flex flex-wrap gap-2" aria-label="Версии списка">
                    <Link v-for="entry in versions" :key="entry.version" :href="route('hospitality.guests',{allocation:booking.id,version:entry.version})" :aria-current="entry.version === selectedVersion ? 'page' : undefined" class="rounded-lg border px-3 py-2 text-sm" :class="entry.version === selectedVersion ? 'bg-accent' : ''">Версия {{ entry.version }} · {{ entry.name ?? 'Сотрудник' }} · {{ entry.created_at }}</Link>
                </nav>
                <p v-if="selectedVersion" class="text-sm text-muted-foreground">{{ guests.length }} из {{ booking.guests }} гостей</p>
                <ul class="divide-y"><li v-for="(guest,index) in guests" :key="index" class="break-words py-2 text-sm">{{ guest.name }} · {{ roomLabel(guest.allocation) }} · комната {{ guest.room }}</li></ul>
            </section>
        </main>
    </AppLayout>
</template>
