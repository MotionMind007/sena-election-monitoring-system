<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVoteSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(UserRole::SuperAdmin, UserRole::Coordinator, UserRole::Witness) ?? false;
    }

    public function rules(): array
    {
        $isProxy = ! ($this->user()?->hasRole(UserRole::Witness) ?? false);
        $requiresOcrConfirmation = $this->input('intent') === 'submit' && $this->filled('ocr_job_id');

        return [
            'election_id' => ['required', 'integer', 'exists:elections,id'],
            'election_contest_id' => ['required', 'integer', 'exists:election_contests,id'],
            'election_polling_station_id' => ['required', 'integer', 'exists:election_polling_stations,id'],
            'correction_of_submission_id' => ['nullable', 'integer', 'exists:vote_submissions,id'],
            'intent' => ['required', Rule::in(['draft', 'submit'])],
            'idempotency_key' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.participant_id' => ['required', 'integer', 'distinct', 'exists:contest_participants,id'],
            'items.*.votes' => ['required', 'integer', 'min:0', 'max:9999999'],
            'invalid_votes' => ['required', 'integer', 'min:0', 'max:9999999'],
            'evidence' => ['nullable', 'array', 'max:1'],
            'evidence.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
            'source_name' => [$isProxy ? 'required' : 'nullable', 'string', 'max:150'],
            'source_method' => [$isProxy ? 'required' : 'nullable', Rule::in(['raw_photo', 'physical_form'])],
            'source_notes' => ['nullable', 'string', 'max:1000'],
            'ocr_job_id' => ['nullable', 'integer', 'exists:ocr_jobs,id'],
            'ocr_confirmed' => $requiresOcrConfirmation ? ['required', 'accepted'] : ['nullable', 'boolean'],
        ];
    }
}
