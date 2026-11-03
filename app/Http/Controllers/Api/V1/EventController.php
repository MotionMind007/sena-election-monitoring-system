<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Election;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Election::query()->with('scopeRegion:id,code,name');

        if (! $request->user()->hasRole(UserRole::SuperAdmin)) {
            $query->whereHas('eventAssignments', fn ($builder) => $builder->where('user_id', $request->user()->id));
        }

        return response()->json(['data' => $query->orderByDesc('year')->paginate(25)]);
    }
}
