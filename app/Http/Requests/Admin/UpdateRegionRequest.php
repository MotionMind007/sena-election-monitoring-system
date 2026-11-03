<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\Region;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateRegionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(UserRole::SuperAdmin) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name'))]);
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:150']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var Region $region */
            $region = $this->route('region');
            if (Region::query()->where('parent_id', $region->parent_id)->where('level', $region->level)->whereLike('name', $this->string('name')->toString(), caseSensitive: false)->where('id', '!=', $region->id)->exists()) {
                $validator->errors()->add('name', 'Wilayah dengan nama tersebut sudah ada pada induk yang sama.');
            }
        }];
    }
}
