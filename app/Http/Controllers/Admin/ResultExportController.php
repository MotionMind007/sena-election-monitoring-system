<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Audit\WriteAuditLog;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\ElectionContest;
use App\Models\VerifiedResult;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResultExportController extends Controller
{
    public function __invoke(Request $request, WriteAuditLog $audit): StreamedResponse
    {
        $data = $request->validate(['event' => ['required', 'integer', 'exists:elections,id'], 'contest' => ['required', 'integer', 'exists:election_contests,id']]);
        $event = Election::query()->findOrFail($data['event']);
        $contest = ElectionContest::query()->with('participants')->where('election_id', $event->id)->findOrFail($data['contest']);
        $results = VerifiedResult::query()->where('election_id', $event->id)->where('election_contest_id', $contest->id)->where('active', true)
            ->with(['items', 'sourceSubmission.totals', 'eventTps.pollingStation.region.parent.parent'])->orderBy('election_polling_station_id')->get();
        $audit->handle($request->user(), 'results.exported', $event, metadata: ['contest_id' => $contest->id, 'rows' => $results->count()]);
        $filename = str($event->name.'-'.$contest->name)->slug().'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($event, $contest, $results): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['Event', 'Tahun', 'Kontes', 'TPS', 'Desa/Kelurahan', 'Distrik', 'Kabupaten/Kota', ...$contest->participants->pluck('display_name')->all(), 'Suara Sah', 'Tidak Sah', 'Total', 'Terverifikasi']);
            foreach ($results as $result) {
                $station = $result->eventTps->pollingStation;
                $votes = $contest->participants->map(fn ($participant) => $result->items->firstWhere('contest_participant_id', $participant->id)?->votes ?? 0)->all();
                $totals = $result->sourceSubmission->totals;
                fputcsv($stream, [$event->name, $event->year, $contest->name, $station->code, $station->region->name, $station->region->parent?->name, $station->region->parent?->parent?->name, ...$votes, $totals?->valid_votes ?? array_sum($votes), $totals?->invalid_votes ?? 0, $totals?->total_votes ?? array_sum($votes), $result->verified_at?->timezone('Asia/Jayapura')->toIso8601String()]);
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
