<?php

namespace App\Http\Requests\Admin;

use App\Enums\RegionLevel;
use App\Enums\UserRole;
use App\Models\PollingStation;
use App\Models\Region;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePollingStationRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:100'],
            'province_id' => ['required', 'integer', 'exists:regions,id'],
            'regency_id' => ['required', 'integer', 'exists:regions,id'],
            'district_id' => ['required', 'integer', 'exists:regions,id'],
            'region_id' => ['required', 'integer', 'exists:regions,id'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $province = Region::query()->find($this->integer('province_id'));
            $regency = Region::query()->find($this->integer('regency_id'));
            $district = Region::query()->find($this->integer('district_id'));
            $village = Region::query()->find($this->integer('region_id'));

            $validHierarchy = $province?->level === RegionLevel::Province
                && in_array($regency?->level, [RegionLevel::Regency, RegionLevel::City], true)
                && $regency?->parent_id === $province?->id
                && $district?->level === RegionLevel::District
                && $district?->parent_id === $regency?->id
                && $village?->level === RegionLevel::Village
                && $village?->parent_id === $district?->id;

            if (! $validHierarchy) {
                $validator->errors()->add('region_id', 'Urutan wilayah TPS tidak valid.');

                return;
            }

            $duplicate = PollingStation::query()
                ->where('region_id', $village->id)
                ->whereLike('location_label', $this->string('name')->toString(), caseSensitive: false)
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('name', 'Nama TPS tersebut sudah ada pada kelurahan/kampung yang dipilih.');
            }
        }];
    }
}
