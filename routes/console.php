<?php

use App\Models\Department;
use App\Models\Company;
use App\Models\Deal;
use App\Models\GroupRequest;
use App\Jobs\CreateProposalReminders;
use App\Models\User;
use App\Services\SalesForecast;
use App\Services\HotelInventory;
use App\Services\ProposalDelivery;
use App\Services\ProposalPdf;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new CreateProposalReminders, 'default', 'redis')->hourly();
Schedule::command('crm:capture-forecast')->dailyAt('00:15');
Schedule::command('crm:expire-reserves')->everyMinute()->withoutOverlapping();
Schedule::command('crm:deliver-proposals')->everyMinute()->withoutOverlapping(15);

Artisan::command('crm:expire-reserves', function (HotelInventory $inventory): void {
    $inventory->expire();
})->purpose('Освободить просроченные резервы размещения');

Artisan::command('crm:deliver-proposals', function (ProposalDelivery $delivery, ProposalPdf $pdf, HotelInventory $journal): void {
    $delivery->drain($pdf, $journal);
})->purpose('Обработать очередь писем с предложениями');

Artisan::command('crm:capture-forecast', function (SalesForecast $forecast): void {
    $count = $forecast->capture();
    $this->info($count === null ? 'Снимок за сегодня уже существует.' : 'Сохранено строк прогноза: '.$count);
})->purpose('Сохранить ежедневный срез прогноза продаж');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('crm:team-member {email} {department} {role=manager}', function (): int {
    $role = $this->argument('role');
    $name = trim($this->argument('department'));
    $user = User::where('email', $this->argument('email'))->first();

    if (! $user || $name === '' || mb_strlen($name) > 255 || ! in_array($role, ['manager', 'lead', 'lodging'], true)) {
        $this->error('Укажите email зарегистрированного пользователя, название отдела и роль manager, lead или lodging.');

        return 1;
    }

    $department = Department::where('name', $name)->first();

    if ($user->department_id !== null && $user->department_id !== $department?->id) {
        $this->error('Пользователь уже состоит в другом отделе.');

        return 1;
    }

    $department ??= Department::firstOrCreate(['name' => $name]);
    DB::transaction(function () use ($user, $department, $role) {
        $user = User::lockForUpdate()->findOrFail($user->id);
        if ($role === 'lodging' && (Deal::where('assignee_id', $user->id)->exists() || GroupRequest::where('assignee_id', $user->id)->exists())) {
            throw new RuntimeException('Переназначьте сделки и заявки перед переводом сотрудника в размещение.');
        }
        if ($user->department_id !== null && $user->department_id !== $department->id) {
            throw new RuntimeException('Пользователь уже состоит в другом отделе.');
        }
        if ($user->department_id === null) {
            Deal::where('assignee_id', $user->id)->whereNull('department_id')->update(['department_id' => $department->id]);
            Company::where('user_id', $user->id)->whereNull('department_id')->update(['department_id' => $department->id]);
            GroupRequest::where('assignee_id', $user->id)->whereNull('department_id')->update(['department_id' => $department->id]);
        }
        $user->forceFill(['department_id' => $department->id, 'sales_role' => $role])->save();
    });
    $this->info('Сотрудник настроен.');

    return 0;
})->purpose('Назначить отдел и роль зарегистрированному сотруднику CRM');
