<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\Region;
use App\Services\ScopeAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegionController extends Controller
{
    public function children(Request $request, Election $election, Region $region, ScopeAccess $scope): JsonResponse
    {
        abort_unless($scope->canAccessRegion($request->user(), $election, $region), 404);
        abort_unless($election->regionSnapshots()->where('region_id', $region->id)->exists(), 404);

        $children = $region->children()
            ->whereHas('electionRegions', fn ($query) => $query->where('election_id', $election->id))
            ->get(['id', 'code', 'name', 'level', 'parent_id']);

        return response()->json(['data' => $children]);
    }
}
