<?php

namespace App\Services;

use App\Enums\RegionLevel;
use App\Models\Election;
use App\Models\ElectionContest;
use App\Models\ElectionPollingStation;
use App\Models\Region;
use App\Models\VerifiedResult;
use Illuminate\Support\Collection;

class RecapAggregate
{
    public function __construct(private readonly RegionHierarchy $hierarchy) {}

    /** @return array{participants: Collection, items: Collection, verified_tps_count: int, valid_votes: int, invalid_votes: int, total_votes: int} */
    public function build(Election $election, ElectionContest $contest, Region $region): array
    {
        $regionIds = $this->hierarchy->descendantIds($region->id);
        $eventTpsIds = ElectionPollingStation::query()
            ->where('election_id', $election->id)
            ->where('eligible', true)
            ->where('status', 'active')
            ->whereHas('pollingStation', fn ($query) => $query->whereIn('region_id', $regionIds))
            ->pluck('id');

        $participants = $contest->type === 'legislative' && $region->level === RegionLevel::Village
            ? $contest->participantsForRegion($region)
            : $contest->participants()->where('active', true)->get();
        $participantIds = $participants->pluck('id')->map(fn ($id) => (int) $id);
        $votes = $participantIds->mapWithKeys(fn (int $id) => [$id => 0])->all();

        $results = VerifiedResult::query()
            ->where('election_id', $election->id)
            ->where('election_contest_id', $contest->id)
            ->where('active', true)
            ->whereIn('election_polling_station_id', $eventTpsIds)
            ->with(['items', 'sourceSubmission.totals'])
            ->get();

        foreach ($results as $result) {
            foreach ($result->items as $item) {
                if (array_key_exists($item->contest_participant_id, $votes)) {
                    $votes[$item->contest_participant_id] += (int) $item->votes;
                }
            }
        }

        $items = $participants->map(fn ($participant) => [
            'participant_id' => $participant->id,
            'ballot_number' => $participant->ballot_number,
            'display_name' => $participant->display_name,
            'ballot_dimension' => $participant->participant_type === 'party' ? 'party' : 'candidate',
            'automatic_votes' => $votes[$participant->id] ?? 0,
        ])->values();
        $validVotes = (int) $items->sum('automatic_votes');
        $invalidVotes = (int) $results->sum(fn ($result) => $result->sourceSubmission?->totals?->invalid_votes ?? 0);

        return [
            'participants' => $participants,
            'items' => $items,
            'verified_tps_count' => $results->count(),
            'valid_votes' => $validVotes,
            'invalid_votes' => $invalidVotes,
            'total_votes' => $validVotes + $invalidVotes,
        ];
    }
}
