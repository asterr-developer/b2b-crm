<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { rubles, russianDate } from '@/lib/russianFormat';
import StatusPill from '@/components/StatusPill.vue';

const props = defineProps<{
    deal:number; kind:string; label:string; today:string; closed:boolean;
    payment?:{ amount:string|number; due_on:string; paid_at:string|null; reference:string|null; revision:number };
}>();
const editing = ref(false);
const form = useForm<{action:string;amount:string;due_on:string;reference:string;revision:number}>({action:'plan',amount:'',due_on:'',reference:'',revision:0});
function start(action:string) {
    form.clearErrors();
    form.action = action;
    form.revision = props.payment?.revision ?? 0;
    form.amount = String(props.payment?.amount ?? '');
    form.due_on = props.payment?.due_on ?? '';
    form.reference = '';
    editing.value = true;
}
function save() {
    form.transform(data => ({...data,amount:data.amount.trim().replace(',','.')})).patch(route('deals.payments',{deal:props.deal,kind:props.kind}),{
        preserveScroll:true,onSuccess:()=>editing.value=false,
    });
}
</script>

<template>
    <div class="min-w-0 space-y-3 rounded-xl border bg-background/60 p-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <h3 class="font-semibold">{{ label }}</h3>
            <StatusPill :tone="payment?.paid_at ? 'success' : payment && payment.due_on < today ? 'danger' : payment ? 'warning' : 'neutral'">
                {{ payment?.paid_at ? 'Получен' : payment && payment.due_on < today ? 'Просрочен' : payment ? 'Запланирован' : 'Не запланирован' }}
            </StatusPill>
        </div>
        <template v-if="payment">
            <p class="text-xl font-semibold tabular-nums">{{ rubles.format(Number(payment.amount)) }}</p>
            <p class="text-sm text-muted-foreground">Срок {{ russianDate(payment.due_on) }}<template v-if="payment.paid_at"> · получен {{ russianDate(payment.paid_at) }}</template></p>
            <p v-if="payment.reference" class="break-words text-sm text-muted-foreground">Реквизит платежа {{ payment.reference }}</p>
        </template>
        <div v-if="!closed && !payment?.paid_at && !editing" class="flex flex-wrap gap-2">
            <button type="button" class="rounded-lg border px-3 py-2 text-sm hover:bg-accent" @click="start('plan')">{{ payment ? 'Изменить план' : 'Запланировать' }}</button>
            <button v-if="payment" type="button" class="rounded-lg bg-primary px-3 py-2 text-sm text-primary-foreground" @click="start('paid')">Отметить оплату</button>
        </div>
        <form v-if="editing" class="grid gap-3" @submit.prevent="save">
            <template v-if="form.action === 'plan'">
                <label class="grid gap-1 text-sm">Сумма, ₽ <input v-model="form.amount" inputmode="decimal" required maxlength="18" class="rounded-lg border bg-background p-2" /></label>
                <label class="grid gap-1 text-sm">Оплатить до <input v-model="form.due_on" type="date" required class="rounded-lg border bg-background p-2" /></label>
            </template>
            <label v-else class="grid gap-1 text-sm">Номер платёжного поручения или примечание <input v-model="form.reference" required maxlength="255" class="rounded-lg border bg-background p-2" /></label>
            <p v-for="error in form.errors" :key="error" role="alert" class="text-sm text-destructive">{{ error }}</p>
            <div class="flex flex-wrap gap-2">
                <button class="rounded-lg bg-primary px-3 py-2 text-sm text-primary-foreground" :disabled="form.processing">Сохранить</button>
                <button type="button" class="px-3 py-2 text-sm underline" @click="editing=false">Закрыть</button>
            </div>
        </form>
    </div>
</template>
