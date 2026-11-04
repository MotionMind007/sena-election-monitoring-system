<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Models\ContestParticipant;
use App\Models\Election;
use App\Models\ElectionContest;
use App\Models\ElectionPollingStation;
use App\Models\Region;
use App\Models\VerifiedResult;
use App\Models\VoteSubmission;
use App\Models\VoteSubmissionTotal;
use Illuminate\Support\Collection;

class DashboardReadModel
{
    private const TONES = ['blue', 'orange', 'violet', 'emerald', 'rose'];

    /**
     * @param  Collection<int, ElectionPollingStation>  $eventTps
     * @return array<string, mixed>
     */
    public function build(Election $election, ElectionContest $contest, Collection $eventTps, Region $scope): array
    {
        $tpsIds = $eventTps->pluck('id')->map(fn ($id) => (int) $id)->all();
        $participants = $contest->participants->where('active', true)->values();
        $groups = $this->resultGroups($contest, $participants);

        $submissions = VoteSubmission::query()
            ->where('election_id', $election->id)
            ->where('election_contest_id', $contest->id)
            ->whereIn('election_polling_station_id', $tpsIds)
            ->with('totals')
            ->latest('id')
            ->get();
        $latestSubmissions = $submissions->unique('election_polling_station_id')->keyBy('election_polling_station_id');

        $verifiedResults = VerifiedResult::query()
            ->where('election_id', $election->id)
            ->where('election_contest_id', $contest->id)
            ->whereIn('election_polling_station_id', $tpsIds)
            ->where('active', true)
            ->with('items')
            ->orderBy('verified_at')
            ->get();
        $verifiedByTps = $verifiedResults->keyBy('election_polling_station_id');

        $submittedTps = $submissions
            ->reject(fn (VoteSubmission $submission) => $submission->status === SubmissionStatus::Draft)
            ->pluck('election_polling_station_id')->unique();
        $reviewTps = $latestSubmissions->filter(fn (VoteSubmission $submission) => in_array($submission->status, [SubmissionStatus::Submitted, SubmissionStatus::Recommended], true));
        $revisionTps = $latestSubmissions->filter(fn (VoteSubmission $submission) => $submission->status === SubmissionStatus::RevisionRequested);

        $voteTotals = $participants->mapWithKeys(fn (ContestParticipant $participant) => [$participant->id => 0])->all();
        foreach ($verifiedResults as $result) {
            foreach ($result->items as $item) {
                if (array_key_exists($item->contest_participant_id, $voteTotals)) {
                    $voteTotals[$item->contest_participant_id] += $item->votes;
                }
            }
        }

        $validVotes = array_sum($voteTotals);
        $invalidVotes = (int) VoteSubmissionTotal::query()
            ->whereIn('vote_submission_id', $verifiedResults->pluck('source_submission_id'))
            ->sum('invalid_votes');
        $stats = [
            'total' => count($tpsIds),
            'submitted' => $submittedTps->count(),
            'verified' => $verifiedResults->count(),
            'review' => $reviewTps->count(),
            'revision' => $revisionTps->count(),
            'unreported' => max(count($tpsIds) - $submittedTps->count(), 0),
        ];

        $regionRows = $this->regionRows($scope, $eventTps, $submittedTps, $verifiedByTps);
        $candidateRows = $groups->values()->map(function (array $group, int $index) use ($voteTotals, $validVotes): array {
            $votes = collect($group['participant_ids'])->sum(fn ($id) => $voteTotals[$id] ?? 0);
            $directPartyParticipantId = $group['direct_party_participant_id'] ?? null;
            $directPartyVotes = $directPartyParticipantId
                ? ($voteTotals[$directPartyParticipantId] ?? 0)
                : 0;

            return [
                'id' => $group['id'],
                'label' => $group['label'],
                'initials' => $group['initials'],
                'photo_url' => $group['photo_url'],
                'votes' => $votes,
                'direct_party_votes' => $directPartyVotes,
                'candidate_votes' => $votes - $directPartyVotes,
                'percentage' => $validVotes > 0 ? round(($votes / $validVotes) * 100, 1) : 0,
                'tone' => self::TONES[$index % count(self::TONES)],
            ];
        })->sortByDesc('votes')->values()->all();

        $legislativeCandidates = $contest->type === 'legislative'
            ? $participants->where('participant_type', 'candidate')->map(function (ContestParticipant $participant) use ($voteTotals, $validVotes): array {
                $votes = $voteTotals[$participant->id] ?? 0;

                return [
                    'id' => $participant->id,
                    'name' => $participant->display_name,
                    'number' => $participant->ballot_number,
                    'photo_url' => $participant->photo_path ? route('participants.photo', $participant) : null,
                    'party' => $participant->electionParty?->party?->abbreviation,
                    'dapil_id' => $participant->electoral_district_id,
                    'dapil' => $participant->electoralDistrict?->name,
                    'votes' => $votes,
                    'percentage' => $validVotes > 0 ? round(($votes / $validVotes) * 100, 1) : 0,
                ];
            })->sortByDesc('votes')->values()->all()
            : [];

        $latestUpdate = collect([
            $verifiedResults->max('verified_at'),
            $submissions->max('updated_at'),
        ])->filter()->sortDesc()->first();

        return [
            'updated_at' => $latestUpdate?->timezone('Asia/Jayapura')->translatedFormat('d M Y, H:i').' WIT',
            'stats' => $stats,
            'regions' => $regionRows,
            'candidates' => $candidateRows,
            'result_mode' => $contest->type === 'legislative' ? 'party' : 'candidate',
            'legislative_candidates' => $legislativeCandidates,
            'votes' => ['valid' => $validVotes, 'invalid' => $invalidVotes, 'total' => $validVotes + $invalidVotes],
            'trend' => $this->trend($verifiedResults, $groups),
            'activities' => $latestSubmissions->take(5)->map(fn (VoteSubmission $submission) => $this->activity($submission, $eventTps))->values()->all(),
            'monitoring' => $eventTps->map(fn (ElectionPollingStation $eventStation) => $this->monitoringRow($eventStation, $latestSubmissions->get($eventStation->id), $verifiedByTps->get($eventStation->id), $groups))->values()->all(),
        ];
    }

    private function regionRows(Region $scope, Collection $eventTps, Collection $submittedTps, Collection $verifiedByTps): array
    {
        $children = $scope->children()->get()->keyBy('id');
        if ($children->isEmpty()) {
            return [];
        }

        return $children->map(function (Region $child) use ($eventTps, $submittedTps, $verifiedByTps): array {
            $stations = $eventTps->filter(fn (ElectionPollingStation $station) => $this->isWithin($station->pollingStation->region, $child));
            $ids = $stations->pluck('id');

            return [
                'id' => $child->id,
                'name' => $child->name,
                'level' => $child->level->value,
                'total' => $stations->count(),
                'submitted' => $ids->intersect($submittedTps)->count(),
                'verified' => $ids->filter(fn ($id) => $verifiedByTps->has($id))->count(),
            ];
        })->values()->all();
    }

    private function isWithin(Region $region, Region $scope): bool
    {
        $current = $region;
        while ($current) {
            if ($current->id === $scope->id) {
                return true;
            }
            $current = $current->parent;
        }

        return false;
    }

    private function trend(Collection $results, Collection $groups): array
    {
        if ($results->isEmpty()) {
            return [];
        }

        $participantIds = $groups->pluck('participant_ids')->flatten()->unique();
        $running = $participantIds->mapWithKeys(fn ($id) => [$id => 0])->all();
        $series = $groups->mapWithKeys(fn ($group) => [$group['id'] => [0]])->all();
        foreach ($results as $result) {
            foreach ($result->items as $item) {
                if (array_key_exists($item->contest_participant_id, $running)) {
                    $running[$item->contest_participant_id] += $item->votes;
                }
            }
            $max = max(array_sum($running), 1);
            foreach ($groups as $group) {
                $votes = collect($group['participant_ids'])->sum(fn ($id) => $running[$id] ?? 0);
                $series[$group['id']][] = round(($votes / $max) * 100, 1);
            }
        }

        return $groups->values()->map(fn ($group, $index) => [
            'label' => $group['label'],
            'tone' => ['#2f9cff', '#fb923c', '#8b5cf6', '#10b981', '#f43f5e'][$index % 5],
            'values' => $series[$group['id']],
        ])->all();
    }

    private function activity(VoteSubmission $submission, Collection $eventTps): array
    {
        $station = $eventTps->firstWhere('id', $submission->election_polling_station_id)?->pollingStation;
        [$detail, $tone] = match ($submission->status) {
            SubmissionStatus::Approved => ['Terverifikasi oleh Super Admin', 'emerald'],
            SubmissionStatus::Recommended => ['Direkomendasikan koordinator', 'violet'],
            SubmissionStatus::RevisionRequested => ['Perlu revisi laporan', 'orange'],
            SubmissionStatus::Rejected, SubmissionStatus::Conflict => ['Laporan tidak aktif', 'rose'],
            default => ['Laporan masuk dan menunggu review', 'blue'],
        };

        return [
            'title' => ($station?->code ?? 'TPS').' · '.($station?->region?->name ?? 'Wilayah'),
            'detail' => $detail,
            'time' => $submission->updated_at?->diffForHumans(),
            'tone' => $tone,
        ];
    }

    private function monitoringRow(ElectionPollingStation $eventStation, ?VoteSubmission $submission, ?VerifiedResult $verified, Collection $groups): array
    {
        $station = $eventStation->pollingStation;
        if ($verified) {
            $votes = $groups->map(function ($group) use ($verified) {
                $items = $verified->items->whereIn('contest_participant_id', $group['participant_ids']);

                return $items->isEmpty() ? null : $items->sum('votes');
            });
            [$status, $tone, $updated] = ['Terverifikasi', 'emerald', $verified->verified_at?->timezone('Asia/Jayapura')->format('H:i').' WIT'];
        } elseif ($submission) {
            $votes = $groups->map(fn () => null);
            [$status, $tone] = match ($submission->status) {
                SubmissionStatus::Submitted => ['Menunggu Review', 'blue'],
                SubmissionStatus::Recommended => ['Menunggu Approval', 'amber'],
                SubmissionStatus::RevisionRequested => ['Perlu Revisi', 'orange'],
                SubmissionStatus::Rejected, SubmissionStatus::Conflict => ['Tidak Aktif', 'rose'],
                SubmissionStatus::Draft => ['Draft', 'slate'],
                default => ['Diproses', 'blue'],
            };
            $updated = $submission->updated_at?->timezone('Asia/Jayapura')->format('H:i').' WIT';
        } else {
            $votes = $groups->map(fn () => null);
            [$status, $tone, $updated] = ['Belum Melapor', 'rose', '—'];
        }

        return [
            'tps' => filled($station->location_label) ? $station->location_label : sprintf('TPS %03d', $station->number),
            'area' => $station->region->name.($station->region->parent ? ', '.$station->region->parent->name : ''),
            'votes' => $votes->values()->all(),
            'status' => $status,
            'tone' => $tone,
            'updated' => $updated,
        ];
    }

    private function resultGroups(ElectionContest $contest, Collection $participants): Collection
    {
        if ($contest->type !== 'legislative') {
            return $participants->map(fn (ContestParticipant $participant) => [
                'id' => $participant->id,
                'label' => $participant->display_name,
                'initials' => $participant->ballot_number,
                'photo_url' => $participant->photo_path ? route('participants.photo', $participant) : null,
                'participant_ids' => [$participant->id],
            ])->values();
        }

        return $participants->filter(fn (ContestParticipant $participant) => $participant->election_party_id)
            ->groupBy('election_party_id')->map(function (Collection $members, int $electionPartyId): array {
                $partyParticipant = $members->firstWhere('participant_type', 'party');
                $party = $members->first()?->electionParty?->party;

                return [
                    'id' => $electionPartyId,
                    'label' => $party?->name ?? 'Partai',
                    'initials' => $members->first()?->electionParty?->ballot_number ?? '-',
                    'photo_url' => $party?->logo_path ? route('parties.logo', $party) : null,
                    'participant_ids' => $members->pluck('id')->all(),
                    'direct_party_participant_id' => $partyParticipant?->id,
                ];
            })->values();
    }
}
