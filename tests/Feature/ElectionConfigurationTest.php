<?php

namespace Tests\Feature;

use App\Actions\Elections\CreateElection;
use App\Enums\RegionLevel;
use App\Enums\UserRole;
use App\Models\ElectionPollingStation;
use App\Models\Party;
use App\Models\PollingStation;
use App\Models\Region;
use App\Models\User;
use App\Models\VerifiedResult;
use App\Models\VoteSubmission;
use App\Services\DashboardReadModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ElectionConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pileg_configuration_locks_candidate_to_one_party_and_one_dapil(): void
    {
        [$admin, $event, $district] = $this->pilegFixture();
        $party = Party::query()->create(['name' => 'Partai Sintetis', 'abbreviation' => 'PS', 'active' => true]);

        $this->actingAs($admin)->post(route('admin.electoral-districts.store', $event), [
            'name' => 'Dapil Sintetis 1',
            'region_ids' => [$district->id],
        ])->assertSessionHasNoErrors();
        $dapil = $event->electoralDistricts()->firstOrFail();

        $this->actingAs($admin)->post(route('admin.election-parties.store', $event), [
            'party_id' => $party->id,
            'ballot_number' => '7',
            'electoral_district_ids' => [$dapil->id],
        ])->assertSessionHasNoErrors();
        $eventParty = $event->electionParties()->firstOrFail();
        $contest = $event->contests()->firstOrFail();

        $this->actingAs($admin)->post(route('admin.participants.store', $contest), [
            'ballot_number' => '1',
            'display_name' => 'Caleg Sintetis',
            'display_order' => 1,
            'active' => true,
            'election_party_id' => $eventParty->id,
            'electoral_district_id' => $dapil->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('contest_participants', [
            'participant_type' => 'candidate',
            'display_name' => 'Caleg Sintetis',
            'election_party_id' => $eventParty->id,
            'electoral_district_id' => $dapil->id,
        ]);
        $this->assertDatabaseHas('contest_participants', [
            'participant_type' => 'party',
            'election_party_id' => $eventParty->id,
        ]);
    }

    public function test_pilkada_pair_can_have_supporting_parties_or_be_independent(): void
    {
        [$admin, $province] = $this->regionFixture();
        $event = app(CreateElection::class)->handle($admin, [
            'name' => 'Pilkada Sintetis', 'year' => 2032, 'type' => 'pilkada', 'scope_region_id' => $province->id,
        ]);
        $party = Party::query()->create(['name' => 'Partai Pendukung Sintetis', 'abbreviation' => 'PPS', 'active' => true]);
        $contest = $event->contests()->firstOrFail();

        $this->actingAs($admin)->post(route('admin.participants.store', $contest), [
            'ballot_number' => '1', 'display_name' => 'Paslon Sintetis', 'display_order' => 1, 'active' => true,
            'supporting_party_ids' => [$party->id],
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.participants.store', $contest), [
            'ballot_number' => '2', 'display_name' => 'Paslon Independen Sintetis', 'display_order' => 2, 'active' => true,
            'supporting_party_ids' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame([$party->id], $contest->participants()->where('ballot_number', '1')->firstOrFail()->supportingParties()->pluck('parties.id')->all());
        $this->assertSame(0, $contest->participants()->where('ballot_number', '2')->firstOrFail()->supportingParties()->count());
    }

    public function test_super_admin_can_correct_or_delete_unused_pilkada_participant(): void
    {
        [$admin, $province] = $this->regionFixture();
        $event = app(CreateElection::class)->handle($admin, ['name' => 'Pilkada Koreksi', 'year' => 2032, 'type' => 'pilkada', 'scope_region_id' => $province->id]);
        $contest = $event->contests()->firstOrFail();
        $party = Party::query()->create(['name' => 'Partai Koreksi', 'abbreviation' => 'PK', 'active' => true]);
        $participant = $contest->participants()->create(['participant_type' => 'pair', 'ballot_number' => '1', 'display_name' => 'Nama Salah', 'display_order' => 1, 'active' => true]);

        $this->actingAs($admin)->post(route('admin.participants.update', $participant), [
            'ballot_number' => '1', 'display_name' => 'Nama Benar', 'display_order' => 1, 'active' => true, 'supporting_party_ids' => [$party->id],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('contest_participants', ['id' => $participant->id, 'display_name' => 'Nama Benar']);
        $this->assertSame([$party->id], $participant->fresh()->supportingParties()->pluck('parties.id')->all());

        $this->actingAs($admin)->delete(route('admin.participants.destroy', $participant))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('contest_participants', ['id' => $participant->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'contest_participant.deleted', 'object_id' => (string) $participant->id]);
    }

    public function test_pileg_party_total_is_direct_party_votes_plus_all_candidate_votes_without_double_counting(): void
    {
        [$admin, $event, $district, $village] = $this->pilegFixture(withPollingStation: true);
        $party = Party::query()->create(['name' => 'Partai Agregat Sintetis', 'abbreviation' => 'PAS', 'active' => true]);
        $this->actingAs($admin)->post(route('admin.electoral-districts.store', $event), ['name' => 'Dapil Agregat', 'region_ids' => [$district->id]])->assertSessionHasNoErrors();
        $dapil = $event->electoralDistricts()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.election-parties.store', $event), ['party_id' => $party->id, 'ballot_number' => '3', 'electoral_district_ids' => [$dapil->id]])->assertSessionHasNoErrors();
        $eventParty = $event->electionParties()->firstOrFail();
        $contest = $event->contests()->firstOrFail();
        $this->actingAs($admin)->post(route('admin.participants.store', $contest), [
            'ballot_number' => '1', 'display_name' => 'Caleg Agregat', 'display_order' => 1, 'active' => true,
            'election_party_id' => $eventParty->id, 'electoral_district_id' => $dapil->id,
        ])->assertSessionHasNoErrors();

        $eventTps = ElectionPollingStation::query()->where('election_id', $event->id)->firstOrFail();
        $submitter = User::factory()->role(UserRole::Witness)->create();
        $submission = VoteSubmission::query()->create([
            'election_id' => $event->id, 'election_contest_id' => $contest->id, 'election_polling_station_id' => $eventTps->id,
            'submitter_id' => $submitter->id, 'status' => 'approved', 'entry_mode' => 'direct', 'idempotency_key' => Str::uuid(), 'version' => 1,
        ]);
        $submission->totals()->create(['valid_votes' => 100, 'invalid_votes' => 5, 'total_votes' => 105]);
        $verified = VerifiedResult::query()->create([
            'election_id' => $event->id, 'election_contest_id' => $contest->id, 'election_polling_station_id' => $eventTps->id,
            'source_submission_id' => $submission->id, 'active' => true, 'verified_at' => now(), 'verified_by' => $admin->id,
        ]);
        $partyParticipant = $contest->participants()->where('participant_type', 'party')->firstOrFail();
        $candidate = $contest->participants()->where('participant_type', 'candidate')->firstOrFail();
        $verified->items()->create(['contest_participant_id' => $partyParticipant->id, 'votes' => 40, 'ballot_dimension' => 'party']);
        $verified->items()->create(['contest_participant_id' => $candidate->id, 'votes' => 60, 'ballot_dimension' => 'candidate']);

        $event->load('contests.participants.electionParty.party', 'contests.participants.electoralDistrict');
        $contest = $event->contests->first();
        $eventTps->load('pollingStation.region.parent');
        $result = app(DashboardReadModel::class)->build($event, $contest, collect([$eventTps]), $event->scopeRegion);

        $this->assertSame(100, $result['votes']['valid']);
        $this->assertSame(100, $result['candidates'][0]['votes']);
        $this->assertSame(60, $result['legislative_candidates'][0]['votes']);
        $this->actingAs($admin)->delete(route('admin.participants.destroy', $candidate))->assertSessionHasErrors('participant');
        $this->assertDatabaseHas('contest_participants', ['id' => $candidate->id]);
    }

    public function test_super_admin_deletes_an_event_without_operational_data_and_keeps_master_tps(): void
    {
        [$admin, $event, , $village] = $this->pilegFixture(withPollingStation: true);
        $this->actingAs($admin)->delete(route('admin.events.destroy', $event))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('elections', ['id' => $event->id]);
        $this->assertDatabaseHas('polling_stations', ['region_id' => $village->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'election.deleted', 'object_id' => (string) $event->id]);
    }

    public function test_event_with_a_report_cannot_be_deleted(): void
    {
        [$admin, $event] = $this->pilegFixture(withPollingStation: true);
        $contest = $event->contests()->firstOrFail();
        $eventTps = ElectionPollingStation::query()->where('election_id', $event->id)->firstOrFail();
        VoteSubmission::query()->create([
            'election_id' => $event->id, 'election_contest_id' => $contest->id, 'election_polling_station_id' => $eventTps->id,
            'submitter_id' => User::factory()->role(UserRole::Witness)->create()->id, 'status' => 'draft', 'entry_mode' => 'direct', 'idempotency_key' => Str::uuid(), 'version' => 1,
        ]);

        $this->actingAs($admin)->delete(route('admin.events.destroy', $event))->assertSessionHasErrors('event');
        $this->assertDatabaseHas('elections', ['id' => $event->id]);
    }

    private function pilegFixture(bool $withPollingStation = false): array
    {
        [$admin, $province, $regency, $district, $village] = $this->regionFixture();
        if ($withPollingStation) {
            PollingStation::query()->create(['region_id' => $village->id, 'code' => 'TPS-SINTETIS-001', 'number' => 1, 'location_label' => 'TPS 001', 'source' => 'manual', 'created_by' => $admin->id]);
        }
        $event = app(CreateElection::class)->handle($admin, [
            'name' => 'Pileg Sintetis', 'year' => 2032, 'type' => 'pileg', 'scope_region_id' => $province->id,
        ]);

        return [$admin, $event, $district, $village];
    }

    private function regionFixture(): array
    {
        $admin = User::factory()->role(UserRole::SuperAdmin)->create();
        $province = Region::query()->create(['code' => 'P-SYN', 'name' => 'Provinsi Sintetis', 'level' => RegionLevel::Province, 'source' => 'manual']);
        $regency = Region::query()->create(['code' => 'R-SYN', 'name' => 'Kabupaten Sintetis', 'level' => RegionLevel::Regency, 'parent_id' => $province->id, 'source' => 'manual']);
        $district = Region::query()->create(['code' => 'D-SYN', 'name' => 'Distrik Sintetis', 'level' => RegionLevel::District, 'parent_id' => $regency->id, 'source' => 'manual']);
        $village = Region::query()->create(['code' => 'V-SYN', 'name' => 'Kampung Sintetis', 'level' => RegionLevel::Village, 'parent_id' => $district->id, 'source' => 'manual']);

        return [$admin, $province, $regency, $district, $village];
    }
}
