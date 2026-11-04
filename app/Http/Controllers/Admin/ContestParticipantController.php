<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Audit\WriteAuditLog;
use App\Actions\Elections\DeleteContestParticipant;
use App\Http\Controllers\Controller;
use App\Models\ContestParticipant;
use App\Models\ElectionContest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContestParticipantController extends Controller
{
    public function store(Request $request, ElectionContest $contest, WriteAuditLog $audit): RedirectResponse
    {
        $data = $this->validated($request, $contest);
        $photo = $data['photo'] ?? null;
        $supportingPartyIds = $data['supporting_party_ids'] ?? [];
        unset($data['photo']);
        unset($data['supporting_party_ids']);
        $participant = $contest->participants()->create($data);
        $participant->supportingParties()->sync($supportingPartyIds);
        if ($photo) {
            $participant->update(['photo_path' => $photo->store('participant-photos/'.$participant->id, 'local')]);
        }
        $audit->handle($request->user(), 'contest_participant.created', $participant, after: $participant->fresh()->toArray());

        return back()->with('success', $participant->participant_type === 'candidate' ? 'Caleg berhasil ditambahkan.' : 'Paslon berhasil ditambahkan.');
    }

    public function update(Request $request, ContestParticipant $participant, WriteAuditLog $audit): RedirectResponse
    {
        $data = $this->validated($request, $participant->contest, $participant);
        $photo = $data['photo'] ?? null;
        $supportingPartyIds = $data['supporting_party_ids'] ?? [];
        unset($data['photo']);
        unset($data['supporting_party_ids']);
        $before = $participant->toArray();
        $participant->update($data);
        if ($photo) {
            $participant->update(['photo_path' => $photo->store('participant-photos/'.$participant->id, 'local')]);
        }
        $participant->supportingParties()->sync($supportingPartyIds);
        $audit->handle($request->user(), 'contest_participant.updated', $participant, before: $before, after: $participant->fresh()->toArray());

        return back()->with('success', $participant->participant_type === 'candidate' ? 'Data caleg berhasil diperbarui.' : 'Data paslon berhasil diperbarui.');
    }

    public function destroy(Request $request, ContestParticipant $participant, DeleteContestParticipant $action): RedirectResponse
    {
        $action->handle($request->user(), $participant);

        return back()->with('success', 'Kandidat berhasil dihapus.');
    }

    public function photo(Request $request, ContestParticipant $participant): StreamedResponse
    {
        abort_unless($request->user(), 403);
        abort_unless($participant->photo_path && Storage::disk('local')->exists($participant->photo_path), 404);

        return Storage::disk('local')->response($participant->photo_path, basename($participant->photo_path), ['Cache-Control' => 'private, max-age=3600']);
    }

    private function validated(Request $request, ElectionContest $contest, ?ContestParticipant $participant = null): array
    {
        $contest->loadMissing('election');
        $isPileg = $contest->election->type === 'pileg';
        $data = $request->validate([
            'ballot_number' => ['required', 'string', 'max:16'],
            'display_name' => ['required', 'string', 'max:150'],
            'display_order' => ['required', 'integer', 'min:1', 'max:999'],
            'active' => ['required', 'boolean'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'election_party_id' => [$isPileg ? 'required' : 'nullable', 'integer', 'exists:election_parties,id'],
            'electoral_district_id' => [$isPileg ? 'required' : 'nullable', 'integer', 'exists:electoral_districts,id'],
            'supporting_party_ids' => [$isPileg ? 'nullable' : 'array'],
            'supporting_party_ids.*' => ['integer', 'distinct', 'exists:parties,id'],
        ]);

        if ($isPileg) {
            $electionParty = $contest->election->electionParties()->with('electoralDistricts')->find($data['election_party_id']);
            $dapil = $contest->election->electoralDistricts()->find($data['electoral_district_id']);
            if (! $electionParty || ! $dapil || ! $electionParty->electoralDistricts->contains('id', $dapil->id)) {
                throw ValidationException::withMessages(['election_party_id' => 'Partai harus terdaftar sebagai peserta pada dapil yang dipilih.']);
            }
            $duplicate = $contest->participants()->where('participant_type', 'candidate')
                ->where('electoral_district_id', $dapil->id)->where('election_party_id', $electionParty->id)
                ->where('ballot_number', $data['ballot_number'])->when($participant, fn ($query) => $query->whereKeyNot($participant->id))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['ballot_number' => 'Nomor caleg sudah digunakan partai tersebut pada dapil ini.']);
            }
            $data['participant_type'] = 'candidate';
            $data['supporting_party_ids'] = [];
        } else {
            $duplicate = $contest->participants()->where('participant_type', 'pair')->where('ballot_number', $data['ballot_number'])
                ->when($participant, fn ($query) => $query->whereKeyNot($participant->id))->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['ballot_number' => 'Nomor urut paslon sudah digunakan.']);
            }
            $data['participant_type'] = 'pair';
            $data['election_party_id'] = null;
            $data['electoral_district_id'] = null;
        }

        return $data;
    }
}
