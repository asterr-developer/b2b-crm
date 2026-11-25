<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\SalesPipeline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_custom_pipeline_keeps_analytics_categories_and_guards_transitions(): void
    {
        $lead = User::factory()->create();
        $manager = User::factory()->create();
        $other = User::factory()->create();
        $this->artisan('crm:team-member', ['email' => $lead->email, 'department' => 'Groups', 'role' => 'lead'])->assertSuccessful();
        $this->artisan('crm:team-member', ['email' => $manager->email, 'department' => 'Groups'])->assertSuccessful();

        $name = 'Конференции';
        $stages = [
            ['name' => 'Принята', 'kind' => 'new', 'requires_date' => false],
            ['name' => 'Проверка запроса', 'kind' => 'qualified', 'requires_date' => false],
            ['name' => 'Комната доступна', 'kind' => 'qualified', 'requires_date' => true],
            ['name' => 'Ожидание ответа', 'kind' => 'proposal', 'requires_date' => false],
            ['name' => 'Успех', 'kind' => 'won', 'requires_date' => false],
            ['name' => 'Отказ', 'kind' => 'lost', 'requires_date' => false],
        ];
        $this->actingAs($manager->refresh())->post(route('pipelines.store'), compact('name', 'stages'))->assertForbidden();
        $this->post(route('deals.store'), ['company' => 'Заря', 'contact' => 'Анна', 'email' => 'anna@example.test', 'title' => 'Группа', 'amount' => '500'])->assertRedirect();
        $deal = Deal::sole();
        $this->actingAs($lead->refresh())->patch(route('pipeline.settings'), [
            'labels' => ['new' => 'Новая', 'qualified' => 'Квалификация', 'proposal' => 'Предложение', 'won' => 'Выиграна', 'lost' => 'Проиграна'],
            'close_date_for_proposal' => false, 'reason_for_loss' => false, 'reminder_days' => 0,
            'auto_task_stage' => 'qualified', 'auto_task_title' => 'Уточнить состав', 'auto_task_days' => 1,
        ])->assertRedirect();
        $this->actingAs($lead->refresh())->post(route('pipelines.store'), compact('name', 'stages'))->assertRedirect();
        $board = SalesPipeline::sole();
        $this->post(route('pipelines.attach', $board), ['deal' => $deal->id, 'revision' => 0])->assertRedirect(route('pipelines.show', $board));
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->has('deals', 0));
        $this->patch(route('pipelines.update', $board), ['name' => $name, 'stages' => array_values(array_filter($stages, fn ($stage) => $stage['name'] !== 'Комната доступна'))])
            ->assertSessionHasErrors('stages');

        $path = route('pipelines.move', [$board, $deal]);
        $this->actingAs($other)->get(route('pipelines.show', $board))->assertNotFound();
        $this->patch($path, ['step' => 1, 'revision' => 1])->assertNotFound();
        $this->actingAs($manager)->patch($path, ['step' => 3, 'revision' => 1])->assertSessionHasErrors('stage');
        $this->patch(route('deals.move', $deal), ['stage' => 'qualified', 'revision' => 1])->assertNotFound();
        $this->patch($path, ['step' => 1, 'revision' => 1])->assertRedirect();
        $this->patch($path, ['step' => 2, 'revision' => 2])->assertSessionHasErrors('stage');
        $this->patch(route('deals.update', $deal), ['expected_close_on' => '2027-01-11', 'revision' => 2])->assertRedirect();
        $this->patch($path, ['step' => 2, 'revision' => 3])->assertRedirect();
        $this->assertDatabaseCount('deal_transitions', 1);
        $this->assertDatabaseCount('deal_activities', 1);
        $this->assertDatabaseHas('deal_activities', ['deal_id' => $deal->id, 'body' => 'Уточнить состав']);
        $this->patch($path, ['step' => 3, 'revision' => 4])->assertRedirect();
        $this->get(route('reports'))->assertInertia(fn (Assert $page) => $page->where('conversion.2.count', 1));
        $this->assertSame('proposal', $deal->refresh()->stage);
    }
}
