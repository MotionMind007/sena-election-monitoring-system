<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreRecapComparisonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(UserRole::SuperAdmin) ?? false;
    }

    public function rules(): array
    {
        return [
            'election_id' => ['required', 'integer', 'exists:elections,id'],
            'election_contest_id' => ['required', 'integer', 'exists:election_contests,id'],
            'region_id' => ['required', 'integer', 'exists:regions,id'],
            'idempotency_key' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.participant_id' => ['required', 'integer', 'distinct', 'exists:contest_participants,id'],
            'items.*.official_votes' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'official_invalid_votes' => ['required', 'integer', 'min:0', 'max:99999999999'],
            'evidence' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:10240'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
