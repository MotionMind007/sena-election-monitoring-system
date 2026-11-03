<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submission_evidence', function (Blueprint $table) {
            $table->string('preview_key')->nullable()->after('storage_key');
            $table->string('preview_media_type', 100)->nullable()->after('media_type');
            $table->unsignedBigInteger('preview_size_bytes')->nullable()->after('size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('submission_evidence', function (Blueprint $table) {
            $table->dropColumn(['preview_key', 'preview_media_type', 'preview_size_bytes']);
        });
    }
};
