<?php

namespace Database\Seeders;

use App\Models\CatalogItem;
use App\Models\Company;
use App\Models\DealActivity;
use App\Models\Department;
use App\Models\GroupRequest;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HospitalityDemoSeeder extends Seeder
{
    public function run(): void
    {
        $password = getenv('CRM_DEMO_PASSWORD');
        if (! app()->environment('local') || ! str_ends_with((string) config('database.connections.'.config('database.default').'.database'), 'crm_demo')
            || ! is_string($password) || strlen($password) < 12) {
            throw new RuntimeException('Use an isolated crm_demo database in local mode and set CRM_DEMO_PASSWORD (at least 12 characters).');
        }

        DB::transaction(function () use ($password) {
            $department = Department::firstOrCreate(['name' => 'Групповые продажи']);
            if ($department->pipeline_settings === null) {
                $department->forceFill(['pipeline_settings' => ['reminder_days' => 2, 'close_date_for_proposal' => true, 'reason_for_loss' => true]])->save();
            }

            foreach (['manager' => 'Мария Орлова', 'lead' => 'Алексей Соколов', 'lodging' => 'Елена Волкова'] as $role => $name) {
                $user = User::firstOrCreate(['email' => $role.'@hotel.example.test'], ['name' => $name, 'password' => $password]);
                if ($user->department_id === null) {
                    $user->forceFill(['department_id' => $department->id, 'sales_role' => $role, 'email_verified_at' => now()])->save();
                }
            }

            $manager = User::whereEmail('manager@hotel.example.test')->sole();
            CatalogItem::firstOrCreate(['department_id' => $department->id, 'name' => 'Номер на ночь'], ['price' => '3600']);
            CatalogItem::firstOrCreate(['department_id' => $department->id, 'name' => 'Завтрак'], ['price' => '650']);
            foreach (['Стандарт' => 16, 'Двухместный' => 8, 'Последний номер' => 1] as $category => $capacity) {
                $key = ['department_id' => $department->id, 'property' => 'Отель Север', 'name' => $category];
                if (! DB::table('hotel_room_types')->where($key)->exists()) {
                    DB::table('hotel_room_types')->insert([...$key, 'capacity' => $capacity]);
                }
            }
            $company = Company::firstOrCreate(['user_id' => $manager->id, 'name' => 'Северный маршрут'], ['department_id' => $department->id]);
            $contact = $company->contacts()->firstOrCreate(['email' => 'groups@travel.example.test'], ['name' => 'Вера Волкова']);

            GroupRequest::firstOrCreate(['assignee_id' => $manager->id, 'title' => 'Размещение делегации'], [
                'department_id' => $department->id, 'company_id' => $company->id, 'contact_id' => $contact->id,
                'source' => 'email', 'notes' => 'Уточнить даты заезда и выезда, количество гостей и потребность в завтраках.',
            ]);
            foreach (['Группа на выставку', 'Группа на семинар'] as $title) {
                GroupRequest::firstOrCreate(['assignee_id' => $manager->id, 'title' => $title], [
                    'department_id' => $department->id, 'company_id' => $company->id, 'contact_id' => $contact->id,
                    'source' => 'email', 'status' => 'qualified', 'arrival_on' => today()->addWeeks(2)->toDateString(),
                    'departure_on' => today()->addWeeks(2)->addDays(2)->toDateString(), 'guests' => 2,
                ]);
            }
            GroupRequest::firstOrCreate(['assignee_id' => $manager->id, 'title' => 'Деловая делегация · сентябрь 2026'], [
                'department_id' => $department->id, 'company_id' => $company->id, 'contact_id' => $contact->id,
                'source' => 'email', 'status' => 'qualified', 'arrival_on' => '2026-09-22', 'departure_on' => '2026-09-25',
                'guests' => 12, 'notes' => 'Синтетическая заявка для демонстрации дат.',
            ]);

            $deal = $contact->deals()->firstOrCreate(['title' => 'Команда на конференцию', 'assignee_id' => $manager->id], [
                'department_id' => $department->id, 'amount' => '86400',
            ]);
            if ($deal->wasRecentlyCreated) {
                $deal->forceFill(['stage' => 'proposal', 'expected_close_on' => today()->addDays(7)->toDateString()])->save();
                foreach ([['new', 'qualified'], ['qualified', 'proposal']] as [$from, $to]) {
                    DB::table('deal_transitions')->insert([
                        'deal_id' => $deal->id, 'user_id' => $manager->id,
                        'from_stage' => $from, 'to_stage' => $to, 'created_at' => now()->subDays(4),
                    ]);
                }

                Proposal::create([
                    'deal_id' => $deal->id, 'version' => 1, 'status' => 'sent', 'sent_at' => now()->subDays(3),
                    'created_by' => $manager->id, 'follow_up_at' => now()->subDay(),
                    'approved_by' => User::whereEmail('lead@hotel.example.test')->value('id'),
                    'snapshot' => [
                        'company' => $company->name, 'title' => $deal->title, 'discount' => 0,
                        'items' => [['name' => 'Номер на ночь', 'quantity' => 24, 'price_minor' => 360000]],
                        'subtotal_minor' => 8640000, 'total_minor' => 8640000,
                    ],
                ]);
                DealActivity::create([
                    'deal_id' => $deal->id, 'user_id' => $manager->id, 'kind' => 'task',
                    'body' => 'Подтвердить состав группы', 'due_at' => now()->subDay(),
                ]);
            }
        });
    }
}
