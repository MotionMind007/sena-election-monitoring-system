<?php

namespace App\Http\Requests\Admin;

use App\Enums\RegionLevel;
use App\Enums\UserRole;
use App\Models\Region;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRegionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(UserRole::SuperAdmin) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'level' => ['required', Rule::in([
                RegionLevel::District->value,
                RegionLevel::Village->value,
            ])],
            'parent_id' => ['required', 'integer', 'exists:regions,id'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('level')) {
                return;
            }

            $level = RegionLevel::tryFrom((string) $this->input('level'));

            if ($validator->errors()->has('parent_id')) {
                return;
            }

            $parent = Region::query()->find($this->integer('parent_id'));
            $validParentLevels = match ($level) {
                RegionLevel::District => [RegionLevel::Regency, RegionLevel::City],
                RegionLevel::Village => [RegionLevel::District],
                default => [],
            };

            if (! $parent || ! in_array($parent->level, $validParentLevels, true)) {
                $validator->errors()->add('parent_id', 'Induk wilayah tidak sesuai dengan level yang dipilih.');
            }

            $duplicate = Region::query()
                ->where('parent_id', $this->integer('parent_id'))
                ->where('level', $level)
                ->whereLike('name', $this->string('name')->toString(), caseSensitive: false)
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('name', 'Wilayah dengan nama tersebut sudah ada pada induk yang dipilih.');
            }
        }];
    }
}
