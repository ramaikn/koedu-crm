<?php

namespace Tests\Feature\Scheduling;

use App\Enums\DegreeLevel;
use App\Models\Client;
use App\Models\ExpertiseField;
use App\Services\Scheduling\TutorSuggester;

class TutorSuggesterTest extends SchedulingTestCase
{
    private ExpertiseField $akuntansi;

    private ExpertiseField $manajemen;

    private ExpertiseField $hukum;

    protected function setUp(): void
    {
        parent::setUp();
        $this->akuntansi = ExpertiseField::create(['name' => 'Akuntansi', 'group' => 'Ekonomi']);
        $this->manajemen = ExpertiseField::create(['name' => 'Manajemen', 'group' => 'Ekonomi']);
        $this->hukum = ExpertiseField::create(['name' => 'Ilmu Hukum', 'group' => 'Hukum']);
    }

    private function suggest(?Client $client, string $time = '10:00', ?int $fieldId = null, ?int $ignore = null): array
    {
        $start = $this->at($time);

        return app(TutorSuggester::class)->suggest($client?->id, $fieldId, $start, $start->addHour(), $ignore);
    }

    private function tutorIn(ExpertiseField $field, array $attributes = []): \App\Models\Tutor
    {
        $tutor = $this->tutor($attributes);
        $tutor->expertiseFields()->attach($field);

        return $tutor;
    }

    private function ids(array $suggestions): array
    {
        return array_map(fn ($s) => $s->tutorId, $suggestions);
    }

    public function test_exact_field_beats_same_group_beats_unrelated(): void
    {
        $exact = $this->tutorIn($this->akuntansi);
        $group = $this->tutorIn($this->manajemen);
        $other = $this->tutorIn($this->hukum);
        $client = Client::factory()->create(['expertise_field_id' => $this->akuntansi->id]);

        $result = $this->suggest($client);

        $this->assertSame([$exact->id, $group->id, $other->id], $this->ids($result['eligible']));
        $this->assertContains('Bidang sama persis (Akuntansi)', $result['eligible'][0]->reasons);
        $this->assertContains('Satu rumpun (Ekonomi)', $result['eligible'][1]->reasons);
    }

    public function test_busy_tutor_is_not_eligible_but_matching_one_is_listed_as_blocked(): void
    {
        $busy = $this->tutorIn($this->akuntansi);
        $free = $this->tutorIn($this->akuntansi);
        $unrelatedBusy = $this->tutorIn($this->hukum);
        $this->makeSession($busy, '10:00');
        $this->makeSession($unrelatedBusy, '10:00');
        $client = Client::factory()->create(['expertise_field_id' => $this->akuntansi->id]);

        $result = $this->suggest($client);

        $this->assertSame([$free->id], $this->ids($result['eligible']));
        $this->assertSame([$busy->id], $this->ids($result['blocked']), 'tutor tidak relevan yang sibuk tidak ditampilkan');
    }

    public function test_current_tutor_of_the_client_is_preferred(): void
    {
        $a = $this->tutorIn($this->akuntansi, ['name' => 'Aaa']);
        $current = $this->tutorIn($this->akuntansi, ['name' => 'Zzz']);
        $client = $this->clientWithPackage(primaryTutor: $current);
        $client->update(['expertise_field_id' => $this->akuntansi->id]);

        $result = $this->suggest($client);

        $this->assertSame([$current->id, $a->id], $this->ids($result['eligible']));
        $this->assertContains('Pembimbing client saat ini', $result['eligible'][0]->reasons);
    }

    public function test_tutor_within_availability_ranks_above_outside(): void
    {
        $within = $this->tutorIn($this->akuntansi);
        $outside = $this->tutor(withAvailability: false);
        $outside->expertiseFields()->attach($this->akuntansi);
        $client = Client::factory()->create(['expertise_field_id' => $this->akuntansi->id]);

        $result = $this->suggest($client);

        $this->assertSame([$within->id, $outside->id], $this->ids($result['eligible']));
        $this->assertContains('Di luar jam tersedia', $result['eligible'][1]->reasons);
    }

    public function test_lighter_workload_ranks_higher(): void
    {
        $busy = $this->tutorIn($this->akuntansi, ['name' => 'Aaa', 'max_weekly_sessions' => 4]);
        $idle = $this->tutorIn($this->akuntansi, ['name' => 'Zzz', 'max_weekly_sessions' => 4]);
        $this->makeSession($busy, '13:00');
        $this->makeSession($busy, '14:00');
        $client = Client::factory()->create(['expertise_field_id' => $this->akuntansi->id]);

        $result = $this->suggest($client);

        $this->assertSame([$idle->id, $busy->id], $this->ids($result['eligible']));
        $this->assertSame(2, $result['eligible'][1]->weeklyLoad);
    }

    public function test_s3_client_prefers_s3_tutor(): void
    {
        $s2 = $this->tutorIn($this->akuntansi, ['name' => 'Aaa', 'highest_degree' => DegreeLevel::S2]);
        $s3 = $this->tutorIn($this->akuntansi, ['name' => 'Zzz', 'highest_degree' => DegreeLevel::S3]);
        $client = Client::factory()->create(['expertise_field_id' => $this->akuntansi->id, 'degree_level' => DegreeLevel::S3]);

        $this->assertSame([$s3->id, $s2->id], $this->ids($this->suggest($client)['eligible']));
    }

    public function test_field_can_be_given_without_a_client(): void
    {
        $match = $this->tutorIn($this->hukum);
        $this->tutorIn($this->akuntansi);

        $result = $this->suggest(null, fieldId: $this->hukum->id);

        $this->assertSame($match->id, $result['eligible'][0]->tutorId);
    }

    public function test_inactive_tutors_are_never_suggested_and_editing_ignores_own_slot(): void
    {
        $inactive = $this->tutorIn($this->akuntansi, ['is_active' => false]);
        $tutor = $this->tutorIn($this->akuntansi);
        $own = $this->makeSession($tutor, '10:00');
        $client = Client::factory()->create(['expertise_field_id' => $this->akuntansi->id]);

        $this->assertNotContains($inactive->id, $this->ids($this->suggest($client)['eligible']));
        $this->assertSame([], $this->suggest($client)['eligible']);
        $this->assertSame([$tutor->id], $this->ids($this->suggest($client, ignore: $own->id)['eligible']));
    }
}
