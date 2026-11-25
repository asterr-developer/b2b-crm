<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import StatusPill from '@/components/StatusPill.vue';
import { russianDate } from '@/lib/russianFormat';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

type Category = { id:number; property:string; name:string; capacity:number; available?:number; limiting_night?:string };
type Allocation = { id:number; group_request_id:number; hotel_room_type_id:number; title:string; property:string; name:string; rooms:number; guests:number; arrival_on:string; departure_on:string; status:string; expires_at:string; deal_id:number|null; confirmed_proposal_id:number|null; deal_closed_at:string|null };
const props = defineProps<{
    categories:Category[]; allocations:Allocation[]; canManage:boolean;
    lodging:boolean;
    handoffs:Record<number,{status:string;message:string|null}>;
    requests:{id:number;title:string;arrival_on:string;departure_on:string}[];
    dates:{arrival?:string;departure?:string;calendar_category?:number|string};
    calendar:{night:string;capacity:number;used:number;available:number;adjusted:boolean}[];
    rules:{hold_hours:number;handoff_task:boolean};
    runs:{id:number;action:string;reference:string;status:string;message:string|null;created_at:string}[];
}>();
const category = useForm({property:'',name:'',capacity:1});
const emptyPosition = () => ({category:'',rooms:1});
const reserve = useForm({group:'',positions:[emptyPosition()],hours:props.rules.hold_hours});
const rulesForm = useForm({hold_hours:props.rules.hold_hours,handoff_task:props.rules.handoff_task});
const availability = useForm({arrival:props.dates.arrival ?? '',departure:props.dates.departure ?? '',calendar_category:String(props.dates.calendar_category ?? props.categories[0]?.id ?? '')});
const capacity = useForm<{category:string;night:string;capacity:number|null;reason:string}>({category:'',night:'',capacity:0,reason:''});
const useBaseCapacity = ref(false);
const change = useForm({action:''});
const editing = ref<number|null>(null);
const reviewId = ref<number|null>(null);
const handoff = useForm({decision:'accepted',reason:''});
const handoffLabels:Record<string,string> = {accepted:'Принята размещением',returned:'Возвращена на уточнение'};
const amendment = useForm({action:'propose',reason:'',arrival_on:'',departure_on:'',guests:1,amount:'',positions:[emptyPosition()]});
const captions:Record<string,string> = {held:'Резерв',booked:'Бронь',released:'Освобождён',expired:'Истёк',replaced:'Перенесён',done:'Выполнено',queued:'В очереди',retried:'Повтор',sending:'Передаётся',sent:'Передано почте',failed:'Ошибка',unknown:'Доставка не подтверждена',cancelled:'Отменено'};
const actions:Record<string,string> = {'hotel.book':'Подтверждение брони','hotel.release':'Освобождение резерва','hotel.expired':'Истечение резерва','proposal.delivery':'Отправка предложения','proposal.reminder':'Напоминание об ответе'};
actions['hotel.handoff'] = 'Задача службе размещения';
actions['hotel.rules'] = 'Изменение правил отдела';
actions['hotel.capacity_updated'] = 'Изменение фонда на ночь';
actions['hotel.handoff_review'] = 'Приёмка службой размещения';

function review(item:Allocation) {
    handoff.decision = 'accepted';
    handoff.reason = '';
    handoff.clearErrors();
    reviewId.value = item.id;
}
actions['hotel.move'] = 'Перенос брони';
actions['hotel.cancel'] = 'Отмена брони';

function startAmendment(item:Allocation, action:string) {
    editing.value = item.id;
    amendment.clearErrors();
    amendment.action = action;
    amendment.reason = '';
    amendment.amount = '';
    amendment.arrival_on = item.arrival_on;
    amendment.departure_on = item.departure_on;
    amendment.guests = item.guests;
    amendment.positions = props.allocations
        .filter((position) => position.group_request_id === item.group_request_id && position.status === 'booked')
        .map((position) => ({category:String(position.hotel_room_type_id),rooms:position.rooms}));
}

function update(id:number, action:string) {
    change.action = action;
    change.patch(route('hospitality.allocation',{allocation:id}),{preserveScroll:true});
}
</script>

<template>
    <Head title="Размещение" />
    <AppLayout :breadcrumbs="[{title:'Размещение',href:'/hospitality'}]">
        <main class="workspace mx-auto w-full max-w-6xl space-y-5 p-4 md:p-8">
            <header class="moon-header"><h1 class="text-3xl font-semibold tracking-tight">Размещение</h1><p class="mt-2 text-sm text-muted-foreground">Доступность номеров, резервы и подтверждённые брони</p></header>
            <section v-if="!lodging" class="moon-panel space-y-4 p-5">
                <h2 class="text-lg font-semibold">Календарь фонда</h2>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="availability.get(route('hospitality.index'),{preserveScroll:true})">
                    <label>Заезд <input v-model="availability.arrival" type="date" required /></label>
                    <label>Выезд <input v-model="availability.departure" type="date" required /></label>
                    <label v-if="categories.length">Календарь категории <select v-model="availability.calendar_category">
                        <option v-for="item in categories" :key="item.id" :value="String(item.id)">{{ item.property }} · {{ item.name }}</option>
                    </select></label>
                    <button :disabled="availability.processing">Проверить</button>
                    <p v-for="error in availability.errors" :key="error" role="alert">{{ error }}</p>
                </form>
                <p v-if="!categories.length" class="moon-empty">Категории номеров пока не добавлены.</p>
                <ul v-else class="divide-y">
                    <li v-for="item in categories" :key="item.id" class="flex flex-wrap justify-between gap-2 py-3 text-sm">
                        <span>{{ item.property }} · {{ item.name }}</span>
                        <StatusPill :tone="item.available === 0 ? 'warning' : 'neutral'">{{ item.available === undefined ? 'Фонд ' + item.capacity : 'Свободно ' + item.available + ' · минимум ' + russianDate(item.limiting_night) }}</StatusPill>
                    </li>
                </ul>
                <div v-if="calendar.length" class="max-h-80 overflow-auto rounded-lg border">
                    <table class="w-full text-left text-sm tabular-nums">
                        <caption class="sr-only">Доступность по ночам для выбранной категории</caption>
                        <thead class="sticky top-0 bg-muted"><tr><th class="p-2">Ночь</th><th class="p-2">Фонд</th><th class="p-2">Занято</th><th class="p-2">Свободно</th></tr></thead>
                        <tbody class="divide-y">
                            <tr v-for="row in calendar" :key="row.night" :class="row.available === 0 ? 'bg-accent/50' : ''">
                                <td class="p-2">{{ russianDate(row.night) }}<span v-if="row.adjusted" class="block text-xs text-muted-foreground">Фонд изменён</span></td>
                                <td class="p-2">{{ row.capacity }}</td><td class="p-2">{{ row.used }}</td><td class="p-2 font-medium">{{ row.available }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <details v-if="canManage && categories.length" class="rounded-lg border p-3">
                    <summary class="cursor-pointer text-sm">Изменить фонд на ночь</summary>
                    <form class="mt-3 grid items-end gap-3 sm:grid-cols-2" @submit.prevent="capacity.transform(data => ({...data,capacity:useBaseCapacity ? null : data.capacity})).patch(route('hospitality.capacity'),{preserveScroll:true,onSuccess:()=>capacity.reset('reason')})">
                        <label>Категория <select v-model="capacity.category" required><option value="" disabled>Выберите категорию</option><option v-for="item in categories" :key="item.id" :value="String(item.id)">{{ item.property }} · {{ item.name }}</option></select></label>
                        <label>Ночь <input v-model="capacity.night" type="date" required /></label>
                        <label v-if="!useBaseCapacity">Номеров в фонде <input v-model="capacity.capacity" type="number" min="0" max="10000" required /></label>
                        <label>Причина <input v-model="capacity.reason" maxlength="160" required /></label>
                        <label class="!flex items-center gap-2"><input v-model="useBaseCapacity" type="checkbox" class="!w-auto" /> Вернуть базовый фонд</label>
                        <p v-for="error in capacity.errors" :key="error" role="alert" class="sm:col-span-2">{{ error }}</p>
                        <button :disabled="capacity.processing" class="justify-self-start">Сохранить фонд</button>
                    </form>
                </details>
                <details v-if="canManage" class="rounded-lg border p-3">
                    <summary class="cursor-pointer text-sm">Добавить категорию</summary>
                    <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="category.post(route('hospitality.category'),{onSuccess:()=>category.reset()})">
                        <label>Объект <input v-model="category.property" required maxlength="255" /></label>
                        <label>Категория <input v-model="category.name" required maxlength="255" /></label>
                        <label>Количество номеров <input v-model="category.capacity" type="number" min="1" max="10000" required /></label>
                        <button :disabled="category.processing">Сохранить</button>
                        <p v-for="error in category.errors" :key="error" role="alert">{{ error }}</p>
                    </form>
                </details>
            </section>
            <section class="moon-panel space-y-4 p-5">
                <h2 class="text-lg font-semibold">{{ lodging ? 'Брони к приёмке' : 'Резервы и брони' }}</h2>
                <form v-if="categories.length && requests.length" class="grid items-end gap-3 sm:grid-cols-2" @submit.prevent="reserve.post(route('hospitality.reserve'),{preserveScroll:true,onSuccess:()=>reserve.reset()})">
                    <label>Заявка <select v-model="reserve.group" required><option value="" disabled>Выберите заявку</option><option v-for="group in requests" :key="group.id" :value="String(group.id)">{{ group.title }} · {{ russianDate(group.arrival_on) }} – {{ russianDate(group.departure_on) }}</option></select></label>
                    <label>Часов резерва <input v-model="reserve.hours" type="number" min="1" max="72" required /></label>
                    <div v-for="(position, index) in reserve.positions" :key="index" class="grid items-end gap-2 sm:col-span-2 sm:grid-cols-[2fr_1fr_auto]">
                        <label>Категория <select v-model="position.category" required><option value="" disabled>Выберите категорию</option><option v-for="item in categories" :key="item.id" :value="String(item.id)" :disabled="reserve.positions.some((other) => other !== position && other.category === String(item.id))">{{ item.property }} · {{ item.name }}</option></select></label>
                        <label>Номеров <input v-model="position.rooms" type="number" min="1" max="10000" required /></label>
                        <button v-if="reserve.positions.length > 1" type="button" class="secondary" @click="reserve.positions.splice(index,1)">Убрать позицию</button>
                    </div>
                    <div class="flex flex-wrap gap-2 sm:col-span-2">
                        <button v-if="reserve.positions.length < Math.min(20,categories.length)" type="button" class="secondary" @click="reserve.positions.push(emptyPosition())">Добавить категорию</button>
                        <button :disabled="reserve.processing">Резервировать</button>
                    </div>
                    <p v-for="error in reserve.errors" :key="error" role="alert" class="sm:col-span-full">{{ error }}</p>
                </form>
                <p v-else-if="!lodging" class="moon-empty">Для резерва нужны категория номеров и заявка с датами.</p>
                <p v-if="lodging && !allocations.length" class="moon-empty">Подтверждённых броней на предстоящие даты пока нет.</p>
                <p v-for="error in change.errors" :key="error" role="alert">{{ error }}</p>
                <ul class="divide-y">
                    <li v-for="item in allocations" :key="item.id" :id="'allocation-'+item.id" class="flex scroll-mt-4 flex-wrap items-start justify-between gap-3 py-3 text-sm">
                        <div class="min-w-0 space-y-1">
                            <Link v-if="item.deal_id && !lodging" :href="route('deals.show',{deal:item.deal_id})" class="break-words font-semibold underline">{{ item.title }}</Link>
                            <p v-else class="break-words font-semibold">{{ item.title }}</p>
                            <p class="text-muted-foreground">{{ item.property }} · {{ item.name }} · {{ item.rooms }} ном. · {{ russianDate(item.arrival_on) }} – {{ russianDate(item.departure_on) }}</p>
                            <p v-if="lodging" class="text-sm text-muted-foreground">Гостей в группе: {{ item.guests }}</p>
                            <p v-if="item.status === 'held'" class="text-xs text-muted-foreground">До {{ item.expires_at }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <StatusPill :tone="item.status === 'booked' ? 'success' : item.status === 'held' ? 'warning' : 'neutral'">{{ captions[item.status] }}</StatusPill>
                            <template v-if="allocations.find((entry) => entry.group_request_id === item.group_request_id && entry.status === item.status)?.id === item.id">
                                <template v-if="item.status === 'held'">
                                    <button :disabled="change.processing" @click="update(item.id,'book')">Подтвердить бронь целиком</button>
                                    <button class="secondary" :disabled="change.processing" @click="update(item.id,'release')">Освободить резерв целиком</button>
                                </template>
                                <template v-if="item.status === 'booked'">
                                    <Link :href="route('hospitality.guests',{allocation:item.id})" class="rounded-md border px-3 py-2 text-sm underline">Список гостей</Link>
                                    <StatusPill :tone="handoffs[item.id]?.status === 'accepted' ? 'success' : handoffs[item.id]?.status === 'returned' ? 'danger' : 'info'">{{ handoffLabels[handoffs[item.id]?.status] ?? 'Ожидает приёмки' }}</StatusPill>
                                    <p v-if="handoffs[item.id]?.message" class="w-full break-words text-xs text-muted-foreground">{{ handoffs[item.id].message }}</p>
                                    <button v-if="canManage || lodging" class="secondary" @click="review(item)">Решение по приёмке</button>
                                    <template v-if="!lodging">
                                        <button v-if="!item.confirmed_proposal_id && !item.deal_closed_at" :disabled="change.processing" @click="update(item.id,'book')">Связать бронь с принятым КП</button>
                                        <button v-if="item.deal_id" class="secondary" @click="startAmendment(item,'propose')">Подготовить перенос</button>
                                        <button class="secondary" @click="startAmendment(item,'cancel')">Отменить бронь</button>
                                    </template>
                                </template>
                            </template>
                        </div>
                        <form v-if="reviewId === item.id" class="grid w-full gap-3 rounded-xl border p-3" @submit.prevent="handoff.patch(route('hospitality.handoff',{allocation:item.id}),{preserveScroll:true,onSuccess:()=>reviewId=null})">
                            <label>Решение <select v-model="handoff.decision"><option value="accepted">Принять в работу</option><option value="returned">Вернуть на уточнение</option></select></label>
                            <label v-if="handoff.decision === 'returned'">Причина <input v-model="handoff.reason" maxlength="255" required /></label>
                            <p v-for="error in handoff.errors" :key="error" role="alert">{{ error }}</p>
                            <div class="flex flex-wrap gap-2"><button :disabled="handoff.processing">Сохранить решение</button><button type="button" class="secondary" @click="reviewId=null">Закрыть</button></div>
                        </form>
                        <form v-if="editing === item.id" class="grid w-full gap-3 rounded-xl border p-3" @submit.prevent="amendment.patch(route('hospitality.amend',{allocation:item.id}),{preserveScroll:true,onSuccess:()=>editing=null})">
                            <div v-if="amendment.action === 'propose'" class="grid gap-3 sm:grid-cols-3">
                                <label>Новый заезд <input v-model="amendment.arrival_on" type="date" required /></label>
                                <label>Новый выезд <input v-model="amendment.departure_on" type="date" required /></label>
                                <label>Гостей <input v-model="amendment.guests" type="number" min="1" max="10000" required /></label>
                                <label class="sm:col-span-3">Итоговая цена размещения, ₽ <input v-model="amendment.amount" type="number" min="0.01" step="0.01" max="999999999999.99" required /></label>
                                <div v-for="(position,index) in amendment.positions" :key="index" class="grid items-end gap-2 sm:col-span-3 sm:grid-cols-[2fr_1fr_auto]">
                                    <label>Категория <select v-model="position.category" required><option value="" disabled>Выберите категорию</option><option v-for="entry in categories" :key="entry.id" :value="String(entry.id)" :disabled="amendment.positions.some((other) => other !== position && other.category === String(entry.id))">{{ entry.property }} · {{ entry.name }}</option></select></label>
                                    <label>Номеров <input v-model="position.rooms" type="number" min="1" max="10000" required /></label>
                                    <button v-if="amendment.positions.length > 1" type="button" class="secondary" @click="amendment.positions.splice(index,1)">Убрать позицию</button>
                                </div>
                                <button v-if="amendment.positions.length < Math.min(20,categories.length)" class="secondary justify-self-start" type="button" @click="amendment.positions.push(emptyPosition())">Добавить категорию</button>
                            </div>
                            <label>Причина <input v-model="amendment.reason" maxlength="500" required /></label>
                            <p v-for="error in amendment.errors" :key="error" role="alert">{{ error }}</p>
                            <div class="flex flex-wrap gap-2">
                                <button :disabled="amendment.processing">{{ amendment.action === 'propose' ? 'Создать КП на перенос' : 'Подтвердить отмену всех позиций' }}</button>
                                <button type="button" class="secondary" @click="editing = null">Закрыть форму</button>
                            </div>
                        </form>
                    </li>
                </ul>
            </section>
            <section v-if="canManage" class="moon-panel space-y-3 p-5">
                <h2 class="text-lg font-semibold">Журнал автоматизаций</h2>
                <details class="rounded-lg border p-3">
                    <summary class="cursor-pointer text-sm">Правила резервов и передачи</summary>
                <form class="mt-3 flex flex-wrap items-end gap-3" @submit.prevent="rulesForm.patch(route('hospitality.rules'), {preserveScroll:true, onSuccess: () => reserve.hours = rulesForm.hold_hours})">
                    <label>Резерв по умолчанию, часов <input v-model="rulesForm.hold_hours" type="number" min="1" max="72" required /></label>
                    <label class="!flex items-center gap-2"><input v-model="rulesForm.handoff_task" type="checkbox" /> Создавать задачу при подтверждении брони</label>
                    <button :disabled="rulesForm.processing">Сохранить правила</button>
                    <p v-for="error in rulesForm.errors" :key="error" role="alert">{{ error }}</p>
                </form>
                </details>
                <p v-if="!runs.length" class="moon-empty">Записей пока нет.</p>
                <ul class="divide-y">
                    <li v-for="run in runs" :key="run.id" class="flex flex-wrap justify-between gap-2 py-2 text-sm">
                        <div><p>{{ actions[run.action] ?? run.action }} · №{{ run.reference }}</p><p v-if="run.message" class="text-xs text-muted-foreground">{{ run.message }}</p></div>
                        <p class="text-muted-foreground">{{ captions[run.status] ?? run.status }} · {{ run.created_at }}</p>
                    </li>
                </ul>
            </section>
        </main>
    </AppLayout>
</template>

<style scoped>
label { display:grid;min-width:0;gap:.25rem;font-size:.875rem }
input,select { min-width:0;width:100%;border:1px solid hsl(var(--input));border-radius:.5rem;padding:.5rem;background:hsl(var(--background)) }
button { padding:.55rem .7rem;border-radius:.5rem;background:hsl(var(--primary));color:hsl(var(--primary-foreground));font-size:.875rem }
button.secondary { background:hsl(var(--accent));color:hsl(var(--accent-foreground)) }
button:disabled { opacity:.55 }
[role='alert'] { color:hsl(var(--destructive));font-size:.875rem }
</style>
