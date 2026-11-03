<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Elections\CreatePollingStation;
use App\Actions\Elections\ProvisionPollingStations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProvisionPollingStationsRequest;
use App\Http\Requests\Admin\StorePollingStationRequest;
use App\Models\Election;
use App\Models\Region;
use Illuminate\Http\RedirectResponse;

class PollingStationController extends Controller
{
    public function store(StorePollingStationRequest $request, CreatePollingStation $action): RedirectResponse
    {
        $data = $request->validated();
        $station = $action->handle(
            $request->user(),
            Region::query()->findOrFail($data['region_id']),
            $data['name'],
        );

        return back()->with('success', "{$station->location_label} berhasil ditambahkan.");
    }

    public function provision(ProvisionPollingStationsRequest $request, ProvisionPollingStations $action): RedirectResponse
    {
        $data = $request->validated();
        $created = $action->handle(
            $request->user(),
            Election::query()->findOrFail($data['election_id']),
            Region::query()->findOrFail($data['region_id']),
            $data['count'],
        );

        return back()->with('success', "{$created} TPS baru ditambahkan; data yang sudah ada dipertahankan.");
    }
}
