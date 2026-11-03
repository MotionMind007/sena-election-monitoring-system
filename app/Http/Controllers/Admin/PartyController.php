<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Audit\WriteAuditLog;
use App\Http\Controllers\Controller;
use App\Models\Party;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PartyController extends Controller
{
    public function store(Request $request, WriteAuditLog $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:parties,name'],
            'abbreviation' => ['required', 'string', 'max:30', 'unique:parties,abbreviation'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $logo = $data['logo'] ?? null;
        unset($data['logo']);
        $party = Party::query()->create([...$data, 'active' => true]);
        if ($logo) {
            $party->update(['logo_path' => $logo->store('party-logos/'.$party->id, 'local')]);
        }
        $audit->handle($request->user(), 'party.created', $party, after: $party->fresh()->toArray());

        return back()->with('success', 'Partai berhasil ditambahkan ke master.');
    }

    public function logo(Request $request, Party $party): StreamedResponse
    {
        abort_unless($request->user(), 403);
        abort_unless($party->logo_path && Storage::disk('local')->exists($party->logo_path), 404);

        return Storage::disk('local')->response($party->logo_path, basename($party->logo_path), ['Cache-Control' => 'private, max-age=3600']);
    }
}
