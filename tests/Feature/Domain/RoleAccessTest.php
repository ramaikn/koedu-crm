<?php

namespace Tests\Feature\Domain;

use App\Enums\ConsultationType;
use App\Models\Client;
use App\Models\Consultation;
use App\Models\Tutor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_director_manages_users(): void
    {
        $director = User::factory()->director()->create();

        $this->assertTrue($director->can('create', User::class));
        $this->assertFalse(User::factory()->admin()->create()->can('create', User::class));
        $this->assertFalse(User::factory()->tutor()->create()->can('create', User::class));
        $this->assertFalse($director->can('deactivate', $director), 'director tidak boleh menonaktifkan diri sendiri');
    }

    public function test_tutor_sees_only_own_sessions_and_clients(): void
    {
        $tutor = Tutor::factory()->create();
        $otherTutor = Tutor::factory()->create();
        $ownClient = Client::factory()->create();
        $otherClient = Client::factory()->create();

        $own = $this->sessionWith($tutor, $ownClient);
        $other = $this->sessionWith($otherTutor, $otherClient);
        $user = $tutor->user;

        $this->assertTrue($user->can('view', $own));
        $this->assertTrue($user->can('complete', $own));
        $this->assertFalse($user->can('view', $other));
        $this->assertFalse($user->can('complete', $other));
        $this->assertFalse($user->can('create', Consultation::class));

        $this->assertTrue($user->can('view', $ownClient));
        $this->assertFalse($user->can('view', $otherClient));
    }

    public function test_director_reads_but_does_not_operate(): void
    {
        $director = User::factory()->director()->create();
        $session = $this->sessionWith(Tutor::factory()->create(), Client::factory()->create());

        $this->assertTrue($director->can('view', $session));
        $this->assertFalse($director->can('create', Consultation::class));
        $this->assertFalse($director->can('create', Client::class));
    }

    public function test_role_middleware_blocks_other_roles(): void
    {
        Route::middleware(['web', 'auth', 'role:director'])->get('/_test/director-only', fn () => 'ok');

        $this->actingAs(User::factory()->director()->create())->get('/_test/director-only')->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get('/_test/director-only')->assertForbidden();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
    }

    private function sessionWith(Tutor $tutor, Client $client): Consultation
    {
        $consultation = Consultation::create([
            'type' => ConsultationType::Private,
            'tutor_id' => $tutor->id,
            'title' => 'Bimbingan',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);
        $consultation->participants()->create(['client_id' => $client->id]);

        return $consultation;
    }
}
