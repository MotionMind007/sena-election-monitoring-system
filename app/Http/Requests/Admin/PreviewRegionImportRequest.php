<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewRegionImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(UserRole::SuperAdmin) ?? false;
    }

    public function rules(): array
    {
        return [
            'source' => ['required', Rule::in(['CSV', 'BPS', 'KPU'])],
            'source_version' => ['required', 'string', 'max:100'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ];
    }
}
