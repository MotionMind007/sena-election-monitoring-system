<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class ProvisionPollingStationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(UserRole::SuperAdmin) ?? false;
    }

    public function rules(): array
    {
        return ['election_id' => ['required', 'exists:elections,id'], 'region_id' => ['required', 'exists:regions,id'], 'count' => ['required', 'integer', 'between:1,1000']];
    }
}
