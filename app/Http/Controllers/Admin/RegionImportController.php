<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Geography\CommitRegionImport;
use App\Actions\Geography\ParseRegionCsv;
use App\Enums\ImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PreviewRegionImportRequest;
use App\Models\GeographyImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class RegionImportController extends Controller
{
    public function preview(PreviewRegionImportRequest $request, ParseRegionCsv $parser): RedirectResponse
    {
        $file = $request->file('file');
        $parsed = $parser->handle($file->getRealPath());
        $path = $file->storeAs('geography-imports', Str::uuid().'.csv', 'local');

        GeographyImport::query()->create([
            'actor_id' => $request->user()->id,
            'source' => $request->string('source')->toString(),
            'source_version' => $request->string('source_version')->toString(),
            'original_name' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'status' => ImportStatus::Previewed,
            'summary' => $parsed['summary'],
        ]);

        return back()->with('success', 'Preview valid. Periksa ringkasan lalu commit batch.');
    }

    public function commit(GeographyImport $geographyImport, CommitRegionImport $action): RedirectResponse
    {
        $action->handle($geographyImport);

        return back()->with('success', 'Master wilayah berhasil diperbarui.');
    }
}
