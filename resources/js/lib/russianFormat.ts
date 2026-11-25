export const rubles = new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' });

const calendarDate = new Intl.DateTimeFormat('ru-RU', {
    day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC',
});

export function russianDate(value: string | null | undefined): string {
    if (!value) return '—';
    return calendarDate.format(new Date(value.slice(0, 10) + 'T12:00:00Z'));
}
