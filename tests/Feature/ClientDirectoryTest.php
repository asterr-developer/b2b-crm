<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ClientDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_preview_and_retry_share_clients_only_within_a_department(): void
    {
        $lead = User::factory()->create();
        $manager = User::factory()->create();
        $outsider = User::factory()->create();
        $this->artisan('crm:team-member', ['email' => $lead->email, 'department' => 'Groups', 'role' => 'lead'])->assertSuccessful();
        $this->artisan('crm:team-member', ['email' => $manager->email, 'department' => 'Groups'])->assertSuccessful();

        $file = fn () => UploadedFile::fake()->createWithContent('clients.csv', "company,contact,email\nNord,Anna,anna@example.test\nBroken,Olga,invalid\n");
        $path = route('clients.import');

        $this->actingAs($manager->refresh())->post($path, ['file' => $file(), 'mode' => 'preview'])->assertForbidden();
        $this->actingAs($lead->refresh())->post($path, ['file' => $file(), 'mode' => 'apply'])->assertSessionHasErrors('file');
        for ($i = 0; $i < 2; $i++) {
            $this->post($path, ['file' => $file(), 'mode' => 'preview'])->assertRedirect(route('clients.index'));
            $this->get(route('clients.index'))->assertInertia(fn (Assert $page) => $page
                ->component('Clients')->where('preview.valid', 1)->has('preview.errors', 1));
            $this->assertDatabaseCount('companies', $i);
            $this->post($path, ['file' => $file(), 'mode' => 'apply'])->assertRedirect(route('clients.index'))
                ->assertSessionHas('client_import_result.contacts', 1 - $i);
        }

        $client = Company::sole();
        $this->assertDatabaseCount('contacts', 1);
        $this->actingAs($manager)->get(route('clients.show', $client))->assertInertia(fn (Assert $page) => $page
            ->component('Client')->where('company.name', 'Nord')->has('contacts.data', 1));
        $this->actingAs($outsider)->get(route('clients.show', $client))->assertNotFound();
    }
}
