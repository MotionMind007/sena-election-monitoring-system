<?php

namespace App\Actions\Geography;

use App\Enums\RegionLevel;
use App\Models\Region;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ParseRegionCsv
{
    /** @return array{rows: array<int, array<string, string|null>>, summary: array<string, int>} */
    public function handle(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'File import tidak dapat dibaca.']);
        }

        $header = fgetcsv($handle);
        $header = array_map(static fn ($value) => strtolower(trim((string) $value, " \t\n\r\0\x0B\xEF\xBB\xBF")), $header ?: []);

        if ($header === ['code', 'name', 'level', 'parent_code']) {
            return $this->parseOfficialRows($handle);
        }

        if ($header !== ['provinsi', 'kabupaten_kota', 'distrik', 'kelurahan_kampung']) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'Header wajib: provinsi,kabupaten_kota,distrik,kelurahan_kampung.']);
        }

        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle)) !== false) {
            $line++;

            if (count($values) === 1 && trim((string) $values[0]) === '') {
                continue;
            }

            if (count($values) !== 4) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => "Baris {$line} harus memiliki empat kolom."]);
            }

            $names = array_map(static fn ($value) => trim((string) $value), $values);

            if ($names[0] === '') {
                fclose($handle);
                throw ValidationException::withMessages(['file' => "Baris {$line} wajib memiliki nama provinsi."]);
            }

            if ($names[1] === '' || $names[2] === '') {
                fclose($handle);
                throw ValidationException::withMessages(['file' => "Baris {$line} wajib memiliki provinsi, kabupaten/kota, dan distrik."]);
            }

            $province = Region::query()->where('level', RegionLevel::Province)
                ->whereLike('name', $names[0], caseSensitive: false)->first();
            if (! $province || $province->code !== '94') {
                fclose($handle);
                throw ValidationException::withMessages(['file' => "Baris {$line}: provinsi harus menggunakan baseline Papua."]);
            }
            $regency = Region::query()->where('parent_id', $province->id)
                ->whereIn('level', [RegionLevel::Regency, RegionLevel::City])
                ->whereLike('name', $names[1], caseSensitive: false)->first();
            if (! $regency) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => "Baris {$line}: kabupaten/kota tidak ditemukan pada baseline Papua."]);
            }

            $parentCode = $regency->code;
            foreach ([2 => $names[2], 3 => $names[3]] as $index => $name) {
                if ($name === '') {
                    break;
                }
                $pathKey = $regency->code.'/'.$this->normalize($names[2]).($index === 3 ? '/'.$this->normalize($name) : '');
                $code = 'IMP-'.strtoupper(substr(hash('sha256', $pathKey), 0, 16));
                $level = $index === 2 ? RegionLevel::District : RegionLevel::Village;

                $rows[$code] ??= [
                    'code' => $code,
                    'name' => $name,
                    'level' => $level->value,
                    'parent_code' => $parentCode,
                ];
                $parentCode = $code;
            }
        }

        fclose($handle);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'File import tidak memiliki baris data.']);
        }

        $existing = Region::query()->whereIn('code', array_keys($rows))->count();

        return [
            'rows' => array_values($rows),
            'summary' => ['rows' => count($rows), 'new' => count($rows) - $existing, 'existing' => $existing],
        ];
    }

    /** @return array{rows: array<int, array<string, string|null>>, summary: array<string, int>} */
    private function parseOfficialRows($handle): array
    {
        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle)) !== false) {
            $line++;

            if (count($values) === 1 && trim((string) $values[0]) === '') {
                continue;
            }

            if (count($values) !== 4) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => "Baris {$line} harus memiliki empat kolom."]);
            }

            [$code, $name, $level, $parentCode] = array_map(static fn ($value) => trim((string) $value), $values);
            if ($code === '' || $name === '' || ! in_array($level, array_map(fn (RegionLevel $item) => $item->value, RegionLevel::cases()), true)) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => "Baris {$line} memuat kode, nama, atau level yang tidak valid."]);
            }
            if (isset($rows[$code])) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => "Baris {$line}: kode wilayah {$code} duplikat."]);
            }

            $rows[$code] = ['code' => $code, 'name' => $name, 'level' => $level, 'parent_code' => $parentCode ?: null];
        }

        fclose($handle);

        $province = $rows['94'] ?? null;
        if ($province === null || $province['level'] !== RegionLevel::Province->value || $province['parent_code'] !== null) {
            throw ValidationException::withMessages(['file' => 'CSV resmi wajib memuat akar Provinsi Papua berkode 94.']);
        }

        $allowedParents = [
            RegionLevel::Province->value => null,
            RegionLevel::Regency->value => RegionLevel::Province->value,
            RegionLevel::City->value => RegionLevel::Province->value,
            RegionLevel::District->value => [RegionLevel::Regency->value, RegionLevel::City->value],
            RegionLevel::Village->value => RegionLevel::District->value,
        ];

        foreach ($rows as $code => $row) {
            $parentCode = $row['parent_code'];
            $expected = $allowedParents[$row['level']];
            if ($expected === null) {
                continue;
            }
            $parent = $parentCode ? ($rows[$parentCode] ?? null) : null;
            $levels = is_array($expected) ? $expected : [$expected];
            if ($parent === null || ! in_array($parent['level'], $levels, true)) {
                throw ValidationException::withMessages(['file' => "Kode {$code} memiliki induk yang tidak valid."]);
            }
        }

        $existing = Region::query()->whereIn('code', array_keys($rows))->count();

        return [
            'rows' => array_values($rows),
            'summary' => ['rows' => count($rows), 'new' => count($rows) - $existing, 'existing' => $existing],
        ];
    }

    private function normalize(string $value): string
    {
        return Str::lower(preg_replace('/\s+/', ' ', trim($value)) ?? trim($value));
    }
}
