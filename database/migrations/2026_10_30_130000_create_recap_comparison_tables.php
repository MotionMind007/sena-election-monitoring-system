<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recap_comparisons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('election_id')->constrained()->restrictOnDelete();
            $table->foreignId('election_contest_id')->constrained()->restrictOnDelete();
            $table->foreignId('region_id')->constrained()->restrictOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->unsignedInteger('version');
            $table->string('level', 24);
            $table->unsignedInteger('snapshot_verified_tps_count');
            $table->timestampTz('snapshot_at');
            $table->unsignedBigInteger('official_valid_votes');
            $table->unsignedBigInteger('official_invalid_votes');
            $table->unsignedBigInteger('official_total_votes');
            $table->unsignedBigInteger('automatic_valid_votes');
            $table->unsignedBigInteger('automatic_invalid_votes');
            $table->unsignedBigInteger('automatic_total_votes');
            $table->boolean('has_discrepancy')->index();
            $table->string('evidence_disk', 32);
            $table->string('evidence_key');
            $table->string('evidence_original_name');
            $table->string('evidence_sha256', 64);
            $table->string('evidence_media_type', 100);
            $table->unsignedBigInteger('evidence_size_bytes');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['submitted_by', 'idempotency_key'], 'recap_comparison_idempotency_unique');
            $table->unique(['election_id', 'election_contest_id', 'region_id', 'version'], 'recap_comparison_version_unique');
            $table->index(['election_id', 'election_contest_id', 'region_id', 'id'], 'recap_comparison_scope_index');
        });

        Schema::create('recap_comparison_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recap_comparison_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contest_participant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('official_votes');
            $table->unsignedBigInteger('automatic_votes');
            $table->bigInteger('delta');
            $table->string('ballot_dimension', 32);

            $table->unique(['recap_comparison_id', 'contest_participant_id'], 'recap_comparison_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recap_comparison_items');
        Schema::dropIfExists('recap_comparisons');
    }
};
