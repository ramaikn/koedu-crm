<?php

namespace Tests\Feature\Pages;

use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\ExpertiseField;
use Inertia\Testing\AssertableInertia as Assert;

class ClientPagesTest extends PageTestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge(['name' => 'Budi Santoso', 'phone' => '0812-3456-7890', 'status' => 'lead'], $overrides);
    }

    public function test_admin_lists_and_searches_clients(): void
    {
        Client::factory()->create(['name' => 'Siti Aminah']);
        Client::factory()->create(['name' => 'Budi Santoso']);

        $this->actingAs($this->admin())->get('/clients')
            ->assertOk()->assertInertia(fn (Assert $p) => $p->component('clients/index')->has('clients.data', 2)->where('can_create', true));

        $this->actingAs($this->admin())->get('/clients?q=budi')
            ->assertInertia(fn (Assert $p) => $p->has('clients.data', 1)->where('clients.data.0.name', 'Budi Santoso'));
    }

    public function test_search_by_phone_number_ignores_formatting(): void
    {
        Client::factory()->create(['name' => 'Siti', 'phone' => '6281234567890']);
        Client::factory()->create(['name' => 'Budi', 'phone' => '6285555555555']);

        $this->actingAs($this->admin())->get('/clients?q=0812-3456')
            ->assertInertia(fn (Assert $p) => $p->has('clients.data', 1)->where('clients.data.0.name', 'Siti'));
    }

    public function test_director_reads_but_cannot_create(): void
    {
        $director = $this->director();

        $this->actingAs($director)->get('/clients')->assertOk()->assertInertia(fn (Assert $p) => $p->where('can_create', false));
        $this->actingAs($director)->get('/clients/create')->assertForbidden();
        $this->actingAs($director)->post('/clients', $this->payload())->assertForbidden();
    }

    public function test_tutor_only_sees_clients_they_handle(): void
    {
        $tutor = $this->anyDayTutor();
        $mine = $this->activeClient(primaryTutor: $tutor);
        $other = $this->activeClient();

        $this->actingAs($tutor->user)->get('/clients')
            ->assertInertia(fn (Assert $p) => $p->has('clients.data', 1)->where('clients.data.0.id', $mine->id));

        $this->actingAs($tutor->user)->get("/clients/{$mine->id}")->assertOk();
        $this->actingAs($tutor->user)->get("/clients/{$other->id}")->assertForbidden();
        $this->actingAs($tutor->user)->get('/clients/create')->assertForbidden();
    }

    public function test_finance_data_is_hidden_from_tutors(): void
    {
        $tutor = $this->anyDayTutor();
        $client = $this->activeClient(primaryTutor: $tutor);

        $this->actingAs($tutor->user)->get("/clients/{$client->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('can.see_finance', false)
                ->where('enrollments.0.price', null)
                ->where('enrollments.0.payment', null));

        $this->actingAs($this->admin())->get("/clients/{$client->id}")
            ->assertInertia(fn (Assert $p) => $p
                ->where('can.see_finance', true)
                ->where('enrollments.0.payment.status', PaymentStatus::Paid->value));
    }

    public function test_store_normalizes_phone_and_blocks_duplicates(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/clients', $this->payload())->assertRedirect();
        $this->assertSame('6281234567890', Client::first()->phone);

        $this->actingAs($admin)->post('/clients', $this->payload(['name' => 'Lain', 'phone' => '+62 812 3456 7890']))
            ->assertSessionHasErrors('phone');
        $this->assertSame(1, Client::count());
    }

    public function test_store_validates_required_fields_and_phone_format(): void
    {
        $this->actingAs($this->admin())->post('/clients', ['name' => '', 'phone' => '123', 'status' => 'lead'])
            ->assertSessionHasErrors(['name', 'phone']);
    }

    public function test_update_keeps_own_phone_valid_and_destroy_soft_deletes(): void
    {
        $admin = $this->admin();
        $client = Client::factory()->create(['phone' => '6281234567890']);
        $field = ExpertiseField::create(['name' => 'Akuntansi', 'group' => 'Ekonomi']);

        $this->actingAs($admin)->put("/clients/{$client->id}", $this->payload(['phone' => '081234567890', 'expertise_field_id' => $field->id, 'degree_level' => 'S2']))
            ->assertRedirect("/clients/{$client->id}");
        $this->assertSame($field->id, $client->refresh()->expertise_field_id);

        $this->actingAs($this->director())->delete("/clients/{$client->id}")->assertForbidden();
        $this->actingAs($admin)->delete("/clients/{$client->id}")->assertRedirect('/clients');
        $this->assertSoftDeleted($client);
    }

    public function test_client_search_endpoint_is_for_staff_only(): void
    {
        Client::factory()->create(['name' => 'Siti Aminah']);
        $tutor = $this->anyDayTutor();

        $this->actingAs($this->admin())->getJson('/clients/search?q=siti')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Siti Aminah');
        $this->actingAs($tutor->user)->getJson('/clients/search?q=siti')->assertForbidden();
    }
}
