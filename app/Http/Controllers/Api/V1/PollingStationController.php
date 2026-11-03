<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\ElectionPollingStation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PollingStationController extends Controller
{
    public function index(Request $request, Election $election): JsonResponse
    {
        abort_unless($request->user()->hasRole(UserRole::SuperAdmin) || $request->user()->eventAssignments()->where('election_id', $election->id)->exists(), 404);

        $query = ElectionPollingStation::query()
            ->where('election_id', $election->id)
            ->with(['pollingStation:id,region_id,code,number,location_label', 'pollingStation.region:id,code,name']);

        if ($request->user()->hasRole(UserRole::Witness)) {
            $query->whereHas('assignments', fn ($builder) => $builder->where('user_id', $request->user()->id));
        }

        return response()->json(['data' => $query->paginate(50)]);
    }
}
