<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_evidence_id')->unique()->constrained('submission_evidence')->cascadeOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->string('engine', 64)->default('tesseract-local');
            $table->string('engine_version', 64)->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->text('raw_text')->nullable();
            $table->json('extracted_data')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_jobs');
    }
};
