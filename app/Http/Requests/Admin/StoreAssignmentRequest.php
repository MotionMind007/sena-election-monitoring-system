<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(UserRole::SuperAdmin) ?? false;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'election_id' => ['required', 'exists:elections,id'],
            'scope_type' => ['required', Rule::in(['event', 'region', 'tps'])],
            'region_id' => ['nullable', 'required_if:scope_type,region', 'exists:regions,id'],
            'election_polling_station_id' => ['nullable', 'required_if:scope_type,tps', 'exists:election_polling_stations,id'],
        ];
    }
}
