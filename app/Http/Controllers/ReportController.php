<?php

namespace App\Http\Controllers;

use App\Actions\Reporting\SaveVoteSubmission;
use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Http\Requests\StoreVoteSubmissionRequest;
use App\Models\Election;
use App\Models\ElectionContest;
use App\Models\ElectionPollingStation;
use App\Models\RecapComparison;
use App\Models\Region;
use App\Models\SubmissionEvidence;
use App\Models\VoteSubmission;
use App\Services\ScopeAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request, ScopeAccess $scope): Response
    {
        $events = $this->accessibleEvents($request)->get();
        $event = $request->integer('event') ? $events->firstWhere('id', $request->integer('event')) : $events->first();
        abort_if($request->integer('event') && ! $event, 404);
        if (! $event) {
            return Inertia::render('Reports/Index', [
                'events' => [], 'event' => null, 'contests' => [], 'electoralDistricts' => [], 'stations' => [],
                'submissions' => [], 'recapRegions' => ['district' => [], 'regency' => [], 'province' => []],
                'recapComparisons' => [], 'canViewRecaps' => false, 'canManageRecaps' => false,
            ]);
        }
        $event->loadMissing('scopeRegion');

        $contests = $event->contests()->where('status', 'active')->with([
            'participants' => fn ($query) => $query->where('active', true),
            'participants.supportingParties:id,name,abbreviation',
            'participants.electionParty.party:id,name,abbreviation',
            'participants.electionParty.electoralDistricts:id,name',
            'participants.electoralDistrict:id,name',
        ])->get();
        $electoralDistricts = $event->electoralDistricts()->where('active', true)->with('regions:id')->orderBy('name')->get();

        $stations = ElectionPollingStation::query()->where('election_id', $event->id)->where('eligible', true)
            ->with(['election', 'pollingStation.region.parent.parent', 'pollingStation.region.parent'])->orderBy('id')->get()
            ->filter(fn (ElectionPollingStation $station) => $scope->canAccessPollingStation($request->user(), $station))->values();
        $submissions = VoteSubmission::query()->where('election_id', $event->id)->whereIn('election_polling_station_id', $stations->pluck('id'))
            ->with(['contest:id,name', 'eventTps.pollingStation.region:id,name', 'totals', 'submitter:id,name'])->latest('id')->limit(30)->get();
        $canViewRecaps = $request->user()->hasAnyRole(UserRole::SuperAdmin, UserRole::Coordinator);
        $recapRegions = collect();
        if ($canViewRecaps) {
            $recapRegionIds = $stations->flatMap(function (ElectionPollingStation $station): array {
                $village = $station->pollingStation->region;

                return array_values(array_filter([$village->parent_id, $village->parent?->parent_id]));
            });
            if ($event->scopeRegion?->level?->value === 'province') {
                $recapRegionIds->push($event->scope_region_id);
            }
            $recapRegions = Region::query()->whereIn('id', $recapRegionIds->unique())
                ->orderBy('name')->get()
                ->filter(fn (Region $region) => $scope->canAccessRegion($request->user(), $event, $region))
                ->values();
        }
        $visibleRecapRegionIds = $recapRegions->pluck('id');
        $recapComparisons = $canViewRecaps
            ? RecapComparison::query()->where('election_id', $event->id)->whereIn('region_id', $visibleRecapRegionIds)
                ->with(['contest:id,name', 'region:id,name,level', 'submitter:id,name'])->latest('id')->limit(30)->get()
            : collect();

        return Inertia::render('Reports/Index', [
            'events' => $events->map(fn (Election $item) => ['id' => $item->id, 'name' => $item->name, 'year' => $item->year, 'type' => $item->type]),
            'event' => [
                'id' => $event->id, 'name' => $event->name, 'year' => $event->year, 'type' => $event->type,
                'scope_level' => $event->scopeRegion?->level?->value,
            ],
            'contests' => $contests->map(fn (ElectionContest $contest) => [
                'id' => $contest->id,
                'name' => $contest->name,
                'type' => $contest->type,
                'participants' => $contest->participants->map(fn ($participant) => [
                    'id' => $participant->id,
                    'participant_type' => $participant->participant_type,
                    'ballot_number' => $participant->ballot_number,
                    'display_name' => $participant->display_name,
                    'has_photo' => filled($participant->photo_path),
                    'electoral_district_id' => $participant->electoral_district_id,
                    'electoral_district_name' => $participant->electoralDistrict?->name,
                    'dapil_ids' => $participant->electionParty?->electoralDistricts->pluck('id')->values() ?? collect(),
                    'party' => $participant->electionParty?->party ? [
                        'name' => $participant->electionParty->party->name,
                        'abbreviation' => $participant->electionParty->party->abbreviation,
                    ] : null,
                    'supporting_parties' => $participant->supportingParties->map(fn ($party) => [
                        'name' => $party->name,
                        'abbreviation' => $party->abbreviation,
                    ])->values(),
                ])->values(),
            ])->values(),
            'electoralDistricts' => $electoralDistricts->map(fn ($dapil) => [
                'id' => $dapil->id,
                'name' => $dapil->name,
                'region_ids' => $dapil->regions->pluck('id')->values(),
            ])->values(),
            'stations' => $stations->map(fn (ElectionPollingStation $station) => $this->stationData($station)),
            'submissions' => $submissions->map(fn (VoteSubmission $submission) => $this->submissionSummary($submission)),
            'recapRegions' => [
                'district' => $recapRegions->where('level.value', 'district')->map(fn (Region $region) => ['id' => $region->id, 'name' => $region->name, 'level' => $region->level->value])->values(),
                'regency' => $recapRegions->filter(fn (Region $region) => in_array($region->level->value, ['regency', 'city'], true))->map(fn (Region $region) => ['id' => $region->id, 'name' => $region->name, 'level' => $region->level->value])->values(),
                'province' => $recapRegions->where('level.value', 'province')->map(fn (Region $region) => ['id' => $region->id, 'name' => $region->name, 'level' => $region->level->value])->values(),
            ],
            'recapComparisons' => $recapComparisons->map(fn (RecapComparison $comparison) => [
                'id' => $comparison->id,
                'contest' => $comparison->contest->name,
                'region' => $comparison->region->name,
                'level' => $comparison->level,
                'version' => $comparison->version,
                'has_discrepancy' => $comparison->has_discrepancy,
                'automatic_total_votes' => $comparison->automatic_total_votes,
                'official_total_votes' => $comparison->official_total_votes,
                'delta' => $comparison->official_total_votes - $comparison->automatic_total_votes,
                'verified_tps_count' => $comparison->snapshot_verified_tps_count,
                'submitter' => $comparison->submitter->name,
                'snapshot_at' => $comparison->snapshot_at?->timezone('Asia/Jayapura')->format('d M Y, H:i').' WIT',
            ])->values(),
            'canViewRecaps' => $canViewRecaps,
            'canManageRecaps' => $request->user()->hasRole(UserRole::SuperAdmin),
        ]);
    }

    public function create(Request $request, ScopeAccess $scope): Response
    {
        $event = Election::query()->with('contests')->findOrFail($request->integer('event'));
        abort_unless($scope->canAccessElection($request->user(), $event), 404);
        $eventTps = ElectionPollingStation::query()->with(['election', 'pollingStation.region.parent.parent', 'pollingStation.region.parent'])->findOrFail($request->integer('tps'));
        abort_unless($eventTps->election_id === $event->id && $scope->canAccessPollingStation($request->user(), $eventTps), 404);
        $contest = $event->contests->firstWhere('id', $request->integer('contest'));
        abort_unless($contest, 404);
        $participants = $contest->participantsForDistrict($eventTps->pollingStation->region->parent_id);

        $draft = null;
        if ($request->integer('draft')) {
            $draft = VoteSubmission::query()->with(['items', 'totals', 'evidence.ocrJob'])->findOrFail($request->integer('draft'));
            abort_unless($draft->submitter_id === $request->user()->id && $draft->status === SubmissionStatus::Draft, 404);
        }

        return Inertia::render('Reports/Create', [
            'event' => ['id' => $event->id, 'name' => $event->name, 'year' => $event->year],
            'contest' => ['id' => $contest->id, 'name' => $contest->name, 'participants' => $participants],
            'station' => $this->stationData($eventTps),
            'draft' => $draft ? ['id' => $draft->id, 'idempotency_key' => $draft->idempotency_key, 'items' => $draft->items->map(fn ($item) => ['participant_id' => $item->contest_participant_id, 'votes' => $item->votes]), 'invalid_votes' => $draft->totals->invalid_votes, 'source_name' => $draft->source_name, 'source_method' => $draft->source_method, 'source_notes' => $draft->source_notes, 'evidence_count' => $draft->evidence->count()] : null,
            'ocrSuggestion' => $draft ? $this->ocrSuggestion($draft, $participants->count()) : null,
            'isProxy' => ! $request->user()->hasRole(UserRole::Witness),
        ]);
    }

    public function store(StoreVoteSubmissionRequest $request, ScopeAccess $scope, SaveVoteSubmission $action): RedirectResponse
    {
        $contest = ElectionContest::query()->with('participants')->findOrFail($request->integer('election_contest_id'));
        $eventTps = ElectionPollingStation::query()->with(['election', 'pollingStation.region.parent'])->findOrFail($request->integer('election_polling_station_id'));
        abort_unless($scope->canAccessPollingStation($request->user(), $eventTps), 404);
        $submission = $action->handle($request->user(), $contest, $eventTps, $request->validated(), $request->file('evidence', []));

        return redirect()->route('reports.show', $submission)->with('success', $submission->status === SubmissionStatus::Submitted ? 'Laporan berhasil dikirim.' : 'Draft laporan berhasil disimpan.');
    }

    public function show(Request $request, VoteSubmission $voteSubmission, ScopeAccess $scope): Response
    {
        $voteSubmission->load(['election', 'contest', 'eventTps.election', 'eventTps.pollingStation.region.parent.parent', 'eventTps.pollingStation.region.parent', 'items.participant', 'totals', 'evidence.ocrJob', 'submitter:id,name']);
        abort_unless($scope->canAccessPollingStation($request->user(), $voteSubmission->eventTps), 404);
        $versions = VoteSubmission::query()->where('election_id', $voteSubmission->election_id)->where('election_contest_id', $voteSubmission->election_contest_id)->where('election_polling_station_id', $voteSubmission->election_polling_station_id)->with('submitter:id,name')->orderByDesc('version')->get();

        return Inertia::render('Reports/Show', [
            'event' => ['id' => $voteSubmission->election->id, 'name' => $voteSubmission->election->name, 'year' => $voteSubmission->election->year],
            'submission' => [...$this->submissionSummary($voteSubmission), 'event' => $voteSubmission->election->only(['id', 'name', 'year']), 'station' => $this->stationData($voteSubmission->eventTps), 'items' => $voteSubmission->items->map(fn ($item) => ['label' => $item->participant->display_name, 'number' => $item->participant->ballot_number, 'votes' => $item->votes]), 'totals' => $voteSubmission->totals, 'source_name' => $voteSubmission->source_name, 'source_method' => $voteSubmission->source_method, 'source_notes' => $voteSubmission->source_notes, 'evidence' => $voteSubmission->evidence->map(fn (SubmissionEvidence $item) => ['id' => $item->id, 'name' => $item->original_name, 'media_type' => $item->media_type, 'size' => $item->size_bytes, 'ocr' => $item->ocrJob ? ['id' => $item->ocrJob->id, 'status' => $item->ocrJob->status, 'confidence' => $item->ocrJob->confidence, 'numbers' => $item->ocrJob->extracted_data['numbers'] ?? [], 'error' => $item->ocrJob->error_message] : null])],
            'versions' => $versions->map(fn (VoteSubmission $item) => [...$this->submissionSummary($item), 'created_at' => $item->created_at->format('d M Y, H:i')]),
            'canEditDraft' => $voteSubmission->status === SubmissionStatus::Draft && $voteSubmission->submitter_id === $request->user()->id,
            'canReview' => $request->user()->hasRole(UserRole::Coordinator) && $voteSubmission->submitter_id !== $request->user()->id && in_array($voteSubmission->status, [SubmissionStatus::Submitted, SubmissionStatus::Recommended], true),
            'canDecide' => $request->user()->hasRole(UserRole::SuperAdmin)
                && ($voteSubmission->submitter_id !== $request->user()->id || $voteSubmission->entry_mode === 'admin_proxy')
                && in_array($voteSubmission->status, [SubmissionStatus::Submitted, SubmissionStatus::Recommended], true),
        ]);
    }

    public function evidence(Request $request, SubmissionEvidence $evidence, ScopeAccess $scope): StreamedResponse
    {
        $evidence->load('submission.eventTps.election', 'submission.eventTps.pollingStation.region.parent');
        abort_unless($scope->canAccessPollingStation($request->user(), $evidence->submission->eventTps), 404);
        $isPreview = $request->query('variant') === 'preview' && filled($evidence->preview_key);
        $storageKey = $isPreview ? $evidence->preview_key : $evidence->storage_key;
        $mediaType = $isPreview ? $evidence->preview_media_type : $evidence->media_type;
        $name = $isPreview ? pathinfo($evidence->original_name, PATHINFO_FILENAME).'.webp' : $evidence->original_name;
        abort_unless(Storage::disk($evidence->disk)->exists($storageKey), 404);

        return Storage::disk($evidence->disk)->response($storageKey, $name, ['Content-Type' => $mediaType, 'Cache-Control' => 'private, no-store']);
    }

    private function accessibleEvents(Request $request)
    {
        $query = Election::query()->orderByDesc('year')->orderBy('id');
        if (! $request->user()->hasRole(UserRole::SuperAdmin)) {
            $query->whereHas('eventAssignments', fn ($item) => $item->where('user_id', $request->user()->id));
        }

        return $query;
    }

    private function stationData(ElectionPollingStation $station): array
    {
        $region = $station->pollingStation->region;
        $district = $region->parent;
        $regency = $district?->parent;
        $stationName = $station->pollingStation->source === 'manual' && filled($station->pollingStation->location_label)
            ? $station->pollingStation->location_label
            : sprintf('TPS %03d', $station->pollingStation->number);

        return [
            'id' => $station->id,
            'code' => $stationName,
            'internal_code' => $station->pollingStation->code,
            'number' => $station->pollingStation->number,
            'location' => $station->pollingStation->location_label,
            'village_id' => $region->id,
            'village' => $region->name,
            'district_id' => $district?->id,
            'district' => $district?->name,
            'regency_id' => $regency?->id,
            'regency' => $regency?->name,
        ];
    }

    private function submissionSummary(VoteSubmission $submission): array
    {
        return ['id' => $submission->id, 'contest_id' => $submission->election_contest_id, 'event_tps_id' => $submission->election_polling_station_id, 'contest' => $submission->contest?->name, 'tps' => $submission->eventTps?->pollingStation?->code, 'area' => $submission->eventTps?->pollingStation?->region?->name, 'status' => $submission->status->value, 'version' => $submission->version, 'entry_mode' => $submission->entry_mode, 'submitter' => $submission->submitter?->name, 'total_votes' => $submission->totals?->total_votes, 'submitted_at' => $submission->submitted_at?->format('d M Y, H:i')];
    }

    private function ocrSuggestion(VoteSubmission $draft, int $participantCount): ?array
    {
        $ocrJob = $draft->evidence->pluck('ocrJob')->filter(fn ($job) => $job?->status === 'completed')->sortByDesc('confidence')->first();
        $numbers = $ocrJob?->extracted_data['numbers'] ?? [];
        if (! $ocrJob || count($numbers) < $participantCount + 1) {
            return null;
        }

        return ['id' => $ocrJob->id, 'confidence' => $ocrJob->confidence, 'numbers' => array_slice($numbers, 0, $participantCount + 1)];
    }
}
