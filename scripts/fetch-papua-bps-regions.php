<?php

declare(strict_types=1);

/**
 * Build an ElectCount geography-import CSV from the official BPS SIG bridge.
 *
 * The snapshot is pinned so a future endpoint update cannot silently change an
 * already-reviewed import batch.
 */
const BASE_URL = 'https://sig.bps.go.id';
const PERIOD = '2025_2.2025';
const PROVINCE_CODE = '94';
const OUTPUT_DIRECTORY = __DIR__.'/../storage/app/private/geography-imports/source';
const CA_BUNDLE = 'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\certs\\ca-bundle.crt';

$sourceDuplicates = [];

function endpoint(string $level, string $parent): string
{
    return BASE_URL.'/rest-drop-down/getwilayah?'.http_build_query([
        'level' => $level,
        'parent' => $parent,
        'periode_merge' => PERIOD,
    ]);
}

/** @return array<int, array{kode: string, nama: string}> */
function fetchJson(string $url): array
{
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $handle = curl_init($url);
        curl_setopt_array($handle, curlOptions());
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body !== false && $status === 200) {
            $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

            if (is_array($decoded)) {
                return normalizeRows($decoded, $url);
            }
        }

        if ($attempt < 3) {
            usleep(500_000 * $attempt);
        }
    }

    throw new RuntimeException("BPS request failed ({$status}): {$url} {$error}");
}

/** @return array<int, mixed> */
function curlOptions(): array
{
    return [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CAINFO => CA_BUNDLE,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Referer: https://sig.bps.go.id/bridging-kode/',
            'User-Agent: ElectCount geography importer/1.0',
        ],
    ];
}

/**
 * Remove only byte-for-byte-equivalent code/name duplicates returned by BPS.
 * Conflicting names for one code are never resolved automatically.
 *
 * @param  array<int, array{kode: string, nama: string}>  $rows
 * @return array<int, array{kode: string, nama: string}>
 */
function normalizeRows(array $rows, string $url): array
{
    global $sourceDuplicates;

    $normalized = [];
    foreach ($rows as $row) {
        $code = (string) ($row['kode'] ?? '');
        $name = (string) ($row['nama'] ?? '');

        if ($code === '' || $name === '') {
            throw new RuntimeException("BPS returned an incomplete row: {$url}");
        }

        if (isset($normalized[$code]) && $normalized[$code]['nama'] !== $name) {
            throw new RuntimeException("BPS returned conflicting names for code {$code}: {$url}");
        }

        if (isset($normalized[$code])) {
            $sourceDuplicates[] = ['code' => $code, 'name' => $name, 'url' => $url];

            continue;
        }

        $normalized[$code] = ['kode' => $code, 'nama' => $name];
    }

    return array_values($normalized);
}

/**
 * @param  array<string, string>  $urls
 * @return array<string, array<int, array{kode: string, nama: string}>>
 */
function fetchMany(array $urls): array
{
    $results = [];

    foreach (array_chunk($urls, 8, true) as $chunk) {
        $multi = curl_multi_init();
        $handles = [];

        foreach ($chunk as $key => $url) {
            $handle = curl_init($url);
            curl_setopt_array($handle, curlOptions());
            curl_multi_add_handle($multi, $handle);
            $handles[$key] = $handle;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $key => $handle) {
            $body = curl_multi_getcontent($handle);
            $httpStatus = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $url = $chunk[$key];
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);

            $results[$key] = $httpStatus === 200
                ? normalizeRows(json_decode($body, true, flags: JSON_THROW_ON_ERROR), $url)
                : fetchJson($url);
        }

        curl_multi_close($multi);
    }

    return $results;
}

function displayName(string $name): string
{
    return mb_convert_case(trim($name), MB_CASE_TITLE, 'UTF-8');
}

function csvRow($handle, string $code, string $name, string $level, ?string $parentCode): void
{
    fputcsv($handle, [$code, $name, $level, $parentCode ?? ''], ',', '"', '');
}

$regencies = fetchJson(endpoint('kabupaten', PROVINCE_CODE));

if (count($regencies) !== 9) {
    throw new RuntimeException('Expected 9 regencies/cities in Papua, received '.count($regencies).'.');
}

$districtUrls = [];
foreach ($regencies as $regency) {
    $districtUrls[$regency['kode']] = endpoint('kecamatan', $regency['kode']);
}
$districtsByRegency = fetchMany($districtUrls);

$villageUrls = [];
foreach ($districtsByRegency as $districts) {
    foreach ($districts as $district) {
        $villageUrls[$district['kode']] = endpoint('desa', $district['kode']);
    }
}
$villagesByDistrict = fetchMany($villageUrls);

$districtCount = array_sum(array_map('count', $districtsByRegency));
$villageCount = array_sum(array_map('count', $villagesByDistrict));

if ($districtCount !== 106) {
    throw new RuntimeException("Expected 106 districts in Papua, received {$districtCount}.");
}

if ($villageCount < 1000) {
    throw new RuntimeException("Unexpected village count for Papua: {$villageCount}.");
}

if (! is_dir(OUTPUT_DIRECTORY) && ! mkdir(OUTPUT_DIRECTORY, 0775, true) && ! is_dir(OUTPUT_DIRECTORY)) {
    throw new RuntimeException('Unable to create output directory.');
}

$csvPath = OUTPUT_DIRECTORY.'/papua-bps-2025-semester-2.csv';
$handle = fopen($csvPath, 'wb');

if ($handle === false) {
    throw new RuntimeException('Unable to create CSV output.');
}

fputcsv($handle, ['code', 'name', 'level', 'parent_code'], ',', '"', '');
csvRow($handle, PROVINCE_CODE, 'Papua', 'province', null);

$seen = [PROVINCE_CODE => true];

foreach ($regencies as $regency) {
    $code = $regency['kode'];
    $isCity = $code === '9471';
    $name = ($isCity ? 'Kota ' : 'Kabupaten ').displayName($regency['nama']);
    csvRow($handle, $code, $name, $isCity ? 'city' : 'regency', PROVINCE_CODE);

    if (isset($seen[$code])) {
        throw new RuntimeException("Duplicate region code: {$code}");
    }
    $seen[$code] = true;

    foreach ($districtsByRegency[$code] as $district) {
        $districtCode = $district['kode'];
        csvRow($handle, $districtCode, 'Distrik '.displayName($district['nama']), 'district', $code);

        if (isset($seen[$districtCode])) {
            throw new RuntimeException("Duplicate region code: {$districtCode}");
        }
        $seen[$districtCode] = true;

        foreach ($villagesByDistrict[$districtCode] as $village) {
            $villageCode = $village['kode'];
            csvRow($handle, $villageCode, displayName($village['nama']), 'village', $districtCode);

            if (isset($seen[$villageCode])) {
                throw new RuntimeException("Duplicate region code: {$villageCode}");
            }
            $seen[$villageCode] = true;
        }
    }
}

fclose($handle);

$manifest = [
    'source' => 'BPS SIG - Kode Relasi BPS dengan Kemendagri',
    'source_url' => 'https://sig.bps.go.id/bridging-kode/',
    'period' => PERIOD,
    'province_code' => PROVINCE_CODE,
    'generated_at' => date(DATE_ATOM),
    'sha256' => hash_file('sha256', $csvPath),
    'source_duplicate_rows_removed' => $sourceDuplicates,
    'counts' => [
        'province' => 1,
        'regency' => 8,
        'city' => 1,
        'district' => $districtCount,
        'village' => $villageCount,
        'total' => count($seen),
    ],
];

file_put_contents(
    OUTPUT_DIRECTORY.'/papua-bps-2025-semester-2.manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
);

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
