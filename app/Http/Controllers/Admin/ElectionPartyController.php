<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Audit\WriteAuditLog;
use App\Http\Controllers\Controller;
use App\Models\Election;
use App\Models\ElectionParty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ElectionPartyController extends Controller
{
    public function store(Request $request, Election $election, WriteAuditLog $audit): RedirectResponse
    {
        abort_unless($election->type === 'pileg', 404);
        $data = $request->validate([
            'party_id' => ['required', 'integer', 'exists:parties,id', Rule::unique('election_parties')->where('election_id', $election->id)],
            'ballot_number' => ['required', 'string', 'max:16', Rule::unique('election_parties')->where('election_id', $election->id)],
            'electoral_district_ids' => ['required', 'array', 'min:1'],
            'electoral_district_ids.*' => ['required', 'integer', 'distinct', 'exists:electoral_districts,id'],
        ]);
        $dapilIds = collect($data['electoral_district_ids'])->map(fn ($id) => (int) $id);
        if ($election->electoralDistricts()->whereIn('id', $dapilIds)->count() !== $dapilIds->count()) {
            throw ValidationException::withMessages(['electoral_district_ids' => 'Semua dapil harus berasal dari event yang sama.']);
        }

        $electionParty = DB::transaction(function () use ($election, $data, $dapilIds): ElectionParty {
            $electionParty = $election->electionParties()->create([
                'party_id' => $data['party_id'],
                'ballot_number' => $data['ballot_number'],
                'active' => true,
            ]);
            $electionParty->electoralDistricts()->sync($dapilIds);
            foreach ($election->contests()->where('type', 'legislative')->get() as $contest) {
                $party = $electionParty->party;
                $contest->participants()->create([
                    'participant_type' => 'party',
                    'ballot_number' => 'PARTAI-'.$data['ballot_number'],
                    'display_name' => $party->name,
                    'election_party_id' => $electionParty->id,
                    'display_order' => (int) $data['ballot_number'],
                    'active' => true,
                ]);
            }

            return $electionParty;
        });
        $audit->handle($request->user(), 'election_party.created', $electionParty, after: $electionParty->load(['party', 'electoralDistricts'])->toArray());

        return back()->with('success', 'Partai peserta dan dapil yang diikuti berhasil disimpan.');
    }
}
