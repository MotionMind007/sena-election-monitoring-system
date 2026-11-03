<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreElectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(UserRole::SuperAdmin) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'year' => ['required', 'integer', 'between:2000,'.(now()->year + 10)],
            'type' => ['required', Rule::in(['pilkada', 'pileg'])],
            'scope_region_id' => ['required', 'integer', Rule::exists('regions', 'id')->where(fn ($query) => $query->whereIn('level', ['province', 'regency', 'city']))],
            'contest_name' => ['nullable', 'required_with:contest_type', 'string', 'max:150'],
            'contest_type' => ['nullable', 'required_with:contest_name', Rule::in(['executive', 'legislative'])],
        ];
    }
}
